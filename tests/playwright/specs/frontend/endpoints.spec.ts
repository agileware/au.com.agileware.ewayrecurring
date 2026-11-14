import type { APIResponse, Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl, appendQuery, frontUrl } from '../../fixtures/cms';
import { api4, evaluate } from '../../fixtures/civi';
import { responses, type EwayStub } from '../../fixtures/eway-stub';
import {
  newAccessCode,
  newCustomerToken,
  queueRow,
  runVerificationJob,
  setOtherQueueRowsAside,
  transactionApproved,
  transactionNotProcessedYet,
  uniqueDonor,
} from '../../fixtures/contribution-page';
import {
  continueFromEventConfirm,
  contributionOfParticipant,
  eventUrl,
  fillPaidRegistration,
  paidEventId,
  participantsOf,
  pressReview,
} from '../../fixtures/event-registration';
import { contributionState, createPendingPayment, queueRowById } from '../../fixtures/queue-records';
import { liveProcessorId } from '../../fixtures/recurring-records';
import testData from '../../fixtures/test-data.json';

/**
 * PW-F6: the extension's public routes, hit directly with missing, wrong, replayed or hostile input. Test plan:
 * developer/TEST_PLAN.md section 5.1.
 *
 *   civicrm/ewayrecurring/verifypayment   where eWAY sends the browser back (events use it; contribution pages do not)
 *   civicrm/ewayrecurring/createtoken     the back-office "Add Credit Card" button asks for eWAY's hosted page
 *   civicrm/ewayrecurring/savetoken       eWAY sends the browser here after a card was added, and the back-office
 *                                         script asks it to read the card back
 *
 * Most checks are plain HTTP requests made with the page's session (page.request), not browser navigations, so the
 * status, headers and body are seen exactly. Where a refusal is CiviCRM's error page the status depends on the site
 * (403 here, 500 on a fresh site), so those checks accept any status of 400 or more.
 */

/** Text that means PHP printed a warning, notice or trace into the response. */
const PHP_OUTPUT = /(Warning|Notice|Deprecated|Fatal error|Parse error)(<\/b>)?:\s|Undefined (array key|variable|index|offset)|Stack trace:|\bon line \d+/;

/** What the extension's own JSON answers look like. */
interface Json {
  is_error: number;
  message?: any;
  url?: string;
  token_id?: number | string;
}

interface Answer {
  status: number;
  type: string;
  body: string;
  location?: string;
}

/** The headers a script makes with jQuery have, and that a plain browser navigation or a cross-site form post lack. */
const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

async function answer(response: APIResponse): Promise<Answer> {
  return {
    status: response.status(),
    type: response.headers()['content-type'] || '',
    body: await response.text(),
    location: response.headers()['location'],
  };
}

/** GET an address with a page's session, without following redirects. */
async function get(page: Page, url: string, headers: Record<string, string> = {}): Promise<Answer> {
  return answer(await page.request.get(url, { headers, maxRedirects: 0 }));
}

/** POST a form the way the back-office script does (jQuery sends the header), without following redirects. */
async function post(page: Page, url: string, form: Record<string, string | number>, headers: Record<string, string> = XHR): Promise<Answer> {
  return answer(await page.request.post(url, { headers, form, maxRedirects: 0 }));
}

/** Parse an answer as the extension's JSON, failing with the text if it is not JSON (a PHP warning in front, say). */
function json(result: Answer): Json {
  try {
    return JSON.parse(result.body);
  } catch {
    throw new Error(`Not JSON (status ${result.status}, ${result.type}): ${result.body.slice(0, 400)}`);
  }
}

/** True when the text is a JSON document, which a refused request must not be (the page is HTML). */
function isJsonText(text: string): boolean {
  try {
    JSON.parse(text);
    return true;
  } catch {
    return false;
  }
}

/** The visible text of an HTML answer, without scripts and styles. */
function visibleText(html: string): string {
  return html
    .replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&#0?39;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/\s+/g, ' ')
    .trim();
}

/** How many contributions the site has, live and test, so a test can show a request changed nothing. */
function contributionTotal(): number {
  return Number(evaluate("echo (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contribution');"));
}

/** How many saved cards (payment tokens) the whole site has. */
function tokenTotal(): number {
  return api4('PaymentToken', 'get', { select: ['id'] }).length;
}

/** The id of a payment processor by name and mode. */
function processorId(name: string, isTest: boolean): number {
  const rows = api4<{ id: number }>('PaymentProcessor', 'get', {
    select: ['id'],
    where: [['name', '=', name], ['is_test', '=', isTest]],
  });
  if (!rows.length) {
    throw new Error(`The seeded processor "${name}" (test: ${isTest}) does not exist. Run the setup script.`);
  }
  return rows[0].id;
}

/** A contact a card can be added for. Made fresh, so a test does not depend on what an earlier run left. */
function newContact(label: string): { id: number; email: string } {
  const donor = uniqueDonor(label);
  const [contact] = api4<{ id: number }>('Contact', 'create', {
    values: { contact_type: 'Individual', first_name: donor.firstName, last_name: donor.lastName },
  });
  api4('Email', 'create', { values: { contact_id: contact.id, email: donor.email, is_primary: true } });
  return { id: contact.id, email: donor.email };
}

/** Ids of Australia and the Australian Capital Territory, which the billing fields of the forms carry. */
function australia(): { country: number; state: number } {
  const [country] = api4<{ id: number }>('Country', 'get', { select: ['id'], where: [['name', '=', 'Australia']] });
  const [state] = api4<{ id: number }>('StateProvince', 'get', {
    select: ['id'],
    where: [['name', '=', 'Australian Capital Territory'], ['country_id', '=', country.id]],
  });
  return { country: country.id, state: state.id };
}

/**
 * The billing fields as the back-office form posts them (jQuery serialises the whole form). The fields carry a
 * suffix, the id of the billing location type ("-5"), which the extension matches by prefix.
 */
function billingForm(overrides: Record<string, string | number | undefined> = {}): Record<string, string | number> {
  const { country, state } = australia();
  const form: Record<string, string | number | undefined> = {
    billing_first_name: 'Pat',
    billing_last_name: 'Tester',
    'billing_street_address-5': '1 Test Street',
    'billing_city-5': 'Canberra',
    'billing_postal_code-5': '2600',
    'billing_country_id-5': country,
    'billing_state_province_id-5': state,
    ...overrides,
  };
  return Object.fromEntries(Object.entries(form).filter(([, value]) => value !== undefined)) as Record<string, string | number>;
}

/** The address of a public route, as the back-office script builds it with CRM.url(path, query, 'front'). */
function routeUrl(route: 'createtoken' | 'savetoken' | 'verifypayment', query: Record<string, string | number> = {}): string {
  return frontUrl(`ewayrecurring/${route}`, query);
}

/** True when this CiviCRM core knows the is_ajax flag of a menu item (newer security releases enforce it). */
function coreKnowsIsAjax(): boolean {
  return evaluate(`
    $found = FALSE;
    foreach (['CRM', 'Civi'] as $dir) {
      $root = Civi::paths()->getPath('[civicrm.root]/' . $dir);
      foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && strpos(file_get_contents($file->getPathname()), 'is_ajax') !== FALSE) {
          $found = TRUE;
          break 2;
        }
      }
    }
    echo $found ? 'yes' : 'no';
  `) === 'yes';
}

/** The payment processor a seeded eWAY processor is, for the routes that take pp_id. */
let ewayId: number;
let ewayTestId: number;
let dummyId: number;

test.beforeAll(() => {
  ewayId = liveProcessorId();
  ewayTestId = processorId(testData.processors.live, true);
  dummyId = processorId(testData.processors.dummy, false);
});

/**
 * PW-F6-01: the return page, civicrm/ewayrecurring/verifypayment. It answers 400 with an empty body when the request is
 * not a valid return from eWAY. (A missing parameter is refused by the extension; so are an unknown invoice and an
 * unknown processor.)
 */
test.describe('the return page: bad requests', () => {
  test('PW-F6-01 missing, partial and unmatched parameters answer 400 with an empty body, and eWAY is not asked', async ({ anonPage, stub }) => {
    const invoice = `no-such-invoice-${Date.now()}`;
    const cases: [string, Record<string, string | number>][] = [
      ['no parameters', {}],
      ['only the invoice id', { contributionInvoiceID: invoice }],
      ['only the processor id', { paymentProcessorID: ewayId }],
      ['only the component', { component: 'event' }],
      ['an invoice id and a processor id, no component', { contributionInvoiceID: invoice, paymentProcessorID: ewayId }],
      ['an invoice id and a component, no processor id', { contributionInvoiceID: invoice, component: 'event' }],
      ['a processor id and a component, no invoice id', { paymentProcessorID: ewayId, component: 'event' }],
      ['every parameter present but empty', { contributionInvoiceID: '', paymentProcessorID: '', component: '', qfKey: '', AccessCode: '' }],
      ['processor id 0', { contributionInvoiceID: invoice, paymentProcessorID: 0, component: 'event' }],
      ['a negative processor id', { contributionInvoiceID: invoice, paymentProcessorID: -1, component: 'event' }],
      ['a decimal processor id', { contributionInvoiceID: invoice, paymentProcessorID: 7.5, component: 'event' }],
      ['an invoice id nothing matches', { contributionInvoiceID: invoice, paymentProcessorID: ewayId, component: 'event', AccessCode: 'x', qfKey: 'x' }],
      ['an invoice id nothing matches, another component', { contributionInvoiceID: invoice, paymentProcessorID: ewayId, component: 'contribute', AccessCode: 'x' }],
      ['a processor id nothing matches', { contributionInvoiceID: invoice, paymentProcessorID: 99999999, component: 'event', AccessCode: 'x' }],
      ['a list where a value is expected (invoice id)', { 'contributionInvoiceID[]': invoice, paymentProcessorID: ewayId, component: 'event' }],
      ['a list where a value is expected (component)', { contributionInvoiceID: invoice, paymentProcessorID: ewayId, 'component[]': 'event' }],
    ];
    const before = contributionTotal();
    for (const [name, query] of cases) {
      const result = await get(anonPage, routeUrl('verifypayment', query));
      expect(result.status, `${name}: status`).toBe(400);
      expect(result.body.trim(), `${name}: body`).toBe('');
    }
    // No gateway call and no change on the site: a refusal costs nothing.
    expect(stub.requests()).toEqual([]);
    expect(contributionTotal()).toBe(before);
  });

  test('PW-F6-01 hostile values in every parameter are refused with 400, nothing is reflected, nothing changes', async ({ anonPage, stub }) => {
    const hostile = [
      '<script>alert(1)</script>',
      "' OR '1'='1",
      "x'; DROP TABLE civicrm_contribution; --",
      '"><img src=x onerror=alert(1)>',
      '../../../../etc/passwd',
      '%00%0d%0aSet-Cookie: pwned=1',
      'A'.repeat(1000),
      'zoë 日本語',
    ];
    const before = contributionTotal();
    for (const value of hostile) {
      const result = await get(anonPage, routeUrl('verifypayment', {
        contributionInvoiceID: value,
        paymentProcessorID: ewayId,
        component: value,
        qfKey: value,
        AccessCode: value,
      }));
      const label = value.slice(0, 40);
      expect(result.status, `${label}: status`).toBe(400);
      expect(result.body, `${label}: body`).toBe('');
      expect(result.type).not.toContain('json');
    }
    expect(stub.requests()).toEqual([]);
    expect(contributionTotal()).toBe(before);
  });

  // The row says 400, but a processor id that is not a whole number never reaches the check: the extension hands it to
  // the CiviCRM API, which throws, so CiviCRM answers with its own error page. Pinned here as the real behaviour.
  test('PW-F6-01 a processor id that is not a whole number gets CiviCRM\'s error page (no 400, no trace)', async ({ anonPage, stub }) => {
    const invoice = `no-such-invoice-${Date.now()}`;
    const values: [string, string, RegExp][] = [
      ['letters', 'abc', /id is not a valid integer/],
      ['a blank', '%20', /id is not a valid integer/],
      ['an injection attempt', "7 OR 1=1", /id is not a valid integer/],
      // A list reaches the database layer, whose own message is shown (a PHP TypeError from core, not a trace).
      ['a list', '', /mysqli_real_escape_string|not a valid integer|must be of type string/],
    ];
    const before = contributionTotal();
    for (const [name, value, message] of values) {
      const base = routeUrl('verifypayment', { contributionInvoiceID: invoice, component: 'event' });
      const url = name === 'a list'
        ? `${base}${base.includes('?') ? '&' : '?'}paymentProcessorID%5B%5D=7`
        : appendQuery(base, { paymentProcessorID: decodeURIComponent(value) });
      const result = await get(anonPage, url);
      expect([200, 500], `${name}: status ${result.status}`).toContain(result.status);
      const text = visibleText(result.body);
      expect(text, name).toMatch(message);
      expect(result.body, `${name}: no PHP output or trace`).not.toMatch(/Stack trace:|\bon line \d+/);
      expect(result.status === 302 || result.location, `${name}: not a redirect`).toBeFalsy();
    }
    expect(stub.requests()).toEqual([]);
    expect(contributionTotal()).toBe(before);
  });

  test('PW-F6-01 the test-mode processor does not match a live contribution, and the live processor does not match a test-mode one', async ({ anonPage, stub }) => {
    const payment = createPendingPayment({ label: 'f601mode' });
    const [{ invoice_id: invoice }] = api4('Contribution', 'get', { select: ['invoice_id'], where: [['id', '=', payment.contributionId]] });
    const result = await get(anonPage, routeUrl('verifypayment', {
      contributionInvoiceID: invoice,
      paymentProcessorID: ewayTestId,
      component: 'event',
      AccessCode: payment.accessCode,
    }));
    expect(result.status).toBe(400);
    expect(result.body.trim()).toBe('');
    expect(contributionState(payment.contributionId).status).toBe('Pending');
    expect(stub.requests()).toEqual([]);
  });

  // The access code is not checked before eWAY is asked. With none in the address the extension asks eWAY about an
  // empty reference; when eWAY has no result (the stub says so, as eWAY does for a code it does not know of yet) the
  // visitor is sent on to the thank-you step and the contribution stays Pending.
  test('PW-F6-01 a return with no access code asks eWAY about an empty reference and changes nothing while eWAY has no result', async ({ anonPage, stub }) => {
    const payment = createPendingPayment({ label: 'f601none' });
    const [{ invoice_id: invoice }] = api4('Contribution', 'get', { select: ['invoice_id'], where: [['id', '=', payment.contributionId]] });
    stub.respond('getTransaction', transactionNotProcessedYet());

    const result = await get(anonPage, routeUrl('verifypayment', {
      contributionInvoiceID: invoice,
      paymentProcessorID: ewayId,
      component: 'event',
      qfKey: 'no-such-key',
    }));
    expect(result.status).toBe(302);
    expect(result.location).toContain('_qf_ThankYou_display=true');
    expect(result.body).not.toMatch(PHP_OUTPUT);

    const asked = stub.requests();
    expect(asked.map((r) => r.method)).toEqual(['getTransaction']);
    expect(asked[0].args[0] ?? '').toBe('');
    expect(contributionState(payment.contributionId).status).toBe('Pending');
    expect(Number(queueRowById(payment.queueId).status)).toBe(0);
  });
});

/**
 * PW-F6-01 (continued): replay. Hitting the return address again, in the same browser and in another one, must not pay
 * or register anyone twice, and must not show the registration to a visitor who has no session.
 */
test.describe('the return page: replay', () => {
  /** An event registration taken as far as eWAY's hosted page. */
  async function reachHostedPage(page: Page, stub: EwayStub, label: string) {
    const person = uniqueDonor(label);
    const code = newAccessCode(label.toUpperCase().slice(0, 6));
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(code));
    await page.goto(eventUrl(paidEventId()));
    await fillPaidRegistration(page, person);
    await pressReview(page);
    await page.locator('#_qf_Confirm_next-bottom').waitFor();
    await continueFromEventConfirm(page);
    const asked = stub.requests('postAccessCodeShared')[0].args[0] as Record<string, any>;
    return { person, code, redirectUrl: String(asked.RedirectUrl), amount: Number(asked.Payment.TotalAmount) / 100 };
  }

  // After the first return CiviCRM has finished with the registration, so a second visit to the same thank-you step gets
  // CiviCRM's own refusal ("Missing Event ID", HTTP 400 here). The extension asks eWAY again each time but changes nothing.
  test.describe('after the registration was paid', () => {
    test.use({ allowedProblems: [/Failed to load resource: the server responded with a status of (400|500)/] });

    test('PW-F6-01 returning twice with the same access code completes the registration once, and another browser sees none of it', async ({ anonPage, stub, browser }) => {
      const { person, code, redirectUrl, amount } = await reachHostedPage(anonPage, stub, 'f601rep');
      stub.respond('getTransaction', transactionApproved(amount), { repeat: true });
      const returnUrl = appendQuery(redirectUrl, { AccessCode: code });

      // First return: the thank-you step.
      await anonPage.goto(returnUrl);
      await expect(anonPage.locator('body')).toContainText(/thank you for registering/i);
      const [first] = participantsOf(person.email);
      expect(first['status_id:name']).toBe('Registered');
      const paid = contributionOfParticipant(first.id)!;
      expect(paid.status).toBe('Completed');
      const trxnId = paid.trxn_id;
      const total = contributionTotal();

      // Same browser, same address again: no second thank-you page, no second registration or payment.
      const again = await anonPage.goto(returnUrl);
      expect(again!.status()).toBeGreaterThanOrEqual(400);
      await expect(anonPage.locator('body')).toContainText(/Missing Event ID|session has expired/i);
      await expect(anonPage.locator('body')).not.toContainText(person.email);

      // Another browser with no session: CiviCRM says the session has expired, and shows nothing of the registration.
      const stranger = await browser.newContext({ ignoreHTTPSErrors: true, baseURL: test.info().project.use.baseURL });
      const other = await stranger.newPage();
      await other.goto(returnUrl);
      await expect(other.locator('body')).toContainText(/session has expired/i);
      await expect(other.locator('body')).not.toContainText(person.email);
      await stranger.close();

      const all = participantsOf(person.email);
      expect(all.map((p) => p.id)).toEqual([first.id]);
      expect(all[0]['status_id:name']).toBe('Registered');
      const after = contributionOfParticipant(first.id)!;
      expect(after.status).toBe('Completed');
      expect(after.trxn_id).toBe(trxnId);
      expect(contributionTotal()).toBe(total);
      expect(Number(queueRow(after.id)?.status)).toBe(1);
      // Only questions about the payment were put to eWAY; nobody was charged or refunded.
      expect(stub.requests().map((r) => r.method).filter((m) => !['postAccessCodeShared', 'getTransaction'].includes(m))).toEqual([]);
    });
  });

  // KNOWN DEFECT: the return page takes the invoice id and the access code from the address and never checks that eWAY's
  // transaction is the one for that invoice (its InvoiceNumber or InvoiceReference) or for that amount.
  //   CRM/eWAYRecurring/Utils.php, validateContribution() and validateEwayAccessCode(): the transaction is only checked
  //   for TransactionStatus; its InvoiceNumber and TotalAmount are never compared with the contribution.
  // So any approved access code (a small payment of the visitor's own, or one paid earlier) completes any Pending
  // contribution whose invoice id the visitor holds, for any amount. Pinned with test.fail().
  test('PW-F6-01 an approved access code that belongs to another payment does not complete this contribution', async ({ anonPage, stub }) => {
    test.fail();
    const payment = createPendingPayment({ label: 'f601swap', amount: 25 });
    const [{ invoice_id: invoice }] = api4('Contribution', 'get', { select: ['invoice_id'], where: [['id', '=', payment.contributionId]] });
    // eWAY approved a payment of one dollar, for a different invoice.
    stub.respond('getTransaction', responses.transactionFound({
      TransactionID: Number(`9${Date.now().toString().slice(-8)}`),
      TransactionStatus: true,
      TotalAmount: 100,
      InvoiceNumber: 'someone-elses-invoice',
      InvoiceReference: 'someone-elses-invoice',
      TokenCustomerID: '',
    }), { repeat: true });

    await get(anonPage, routeUrl('verifypayment', {
      contributionInvoiceID: invoice,
      paymentProcessorID: ewayId,
      component: 'event',
      qfKey: 'no-such-key',
      AccessCode: 'ACCESS-CODE-OF-ANOTHER-PAYMENT',
    }));
    expect(contributionState(payment.contributionId).status).toBe('Pending');
  });

  // KNOWN DEFECT: the processor id in the address is not checked against the processor the contribution was made with.
  // Naming a processor that is not eWAY's (here the seeded Dummy one) makes the extension build an eWAY client with no
  // credentials, which prints two PHP warnings (with the server's file path) into the page, treats the missing client
  // as a declined payment, marks the Pending contribution Failed and redirects to the contribution page's cancel step.
  //   CRM/eWAYRecurring/Utils.php, getEWayClient() (lines 166 and 167 read user_name and password unguarded) and
  //   validateEwayAccessCode() (the missing client is reported as "Extension error code: 1001").
  // Pinned with test.fail().
  test('PW-F6-01 a processor id that is not an eWAY processor does not fail the contribution or print PHP warnings', async ({ anonPage, stub }) => {
    test.fail();
    const payment = createPendingPayment({ label: 'f601dummy' });
    const [{ invoice_id: invoice }] = api4('Contribution', 'get', { select: ['invoice_id'], where: [['id', '=', payment.contributionId]] });
    const result = await get(anonPage, routeUrl('verifypayment', {
      contributionInvoiceID: invoice,
      paymentProcessorID: dummyId,
      component: 'event',
      AccessCode: 'x',
    }));
    expect(result.body).not.toMatch(PHP_OUTPUT);
    expect(contributionState(payment.contributionId).status).toBe('Pending');
    expect(stub.requests()).toEqual([]);
  });
});

/**
 * PW-F6-03: civicrm/ewayrecurring/savetoken, the page eWAY sends the browser to after a card was added, and the URL the
 * back-office script asks to read the card back. It is open to anyone who may "make online contributions", which the
 * anonymous visitor has (eWAY's redirect arrives with no login).
 */
test.describe('save token: bad requests', () => {
  test('PW-F6-03 without pp_id it answers with the window-close script only, to a visitor and to staff, and eWAY is not asked', async ({ anonPage, adminPage, stub }) => {
    for (const page of [anonPage, adminPage]) {
      const queries: Record<string, string | number>[] = [{}, { pp_id: '' }, { pp_id: 0 }, { AccessCode: 'x' }, { cid: 1, AccessCode: 'x' }];
      for (const query of queries) {
        const result = await get(page, routeUrl('savetoken', query));
        expect(result.status, JSON.stringify(query)).toBe(200);
        expect(result.type).toContain('text/html');
        expect(result.body.trim(), JSON.stringify(query)).toBe('<script>window.close();</script>');
      }
    }
    expect(stub.requests()).toEqual([]);
  });

  test('PW-F6-03 a visitor who lands on it without pp_id sees a blank page', async ({ anonPage }) => {
    await anonPage.goto(routeUrl('savetoken'));
    await expect(anonPage.locator('body')).toHaveText('');
  });

  test('PW-F6-03 with a processor but no access code (in the address or the session) it says "no access code." and eWAY is not asked', async ({ anonPage, stub }) => {
    const before = tokenTotal();
    const result = await get(anonPage, routeUrl('savetoken', { pp_id: ewayId }));
    expect(result.status).toBe(200);
    expect(result.type).toContain('json');
    expect(json(result)).toEqual({ is_error: 1, message: 'no access code.' });
    expect(stub.requests()).toEqual([]);
    expect(tokenTotal()).toBe(before);
  });

  test('PW-F6-03 each thing eWAY or the site cannot match is refused with a JSON message, and no card is saved', async ({ anonPage, stub }) => {
    const before = tokenTotal();
    const donor = newContact('f603refuse');
    const token = newCustomerToken();
    const card = { Number: '444433XXXXXX1111', Name: 'Test Donor', ExpiryMonth: '12', ExpiryYear: '31' };

    /** Queue what eWAY answers, make the request, and return the extension's message. */
    async function refused(name: string, query: Record<string, string | number>, script: (() => void) | null): Promise<string> {
      script?.();
      const result = await get(anonPage, routeUrl('savetoken', query));
      expect(result.status, name).toBe(200);
      expect(result.body, `${name}: no PHP output`).not.toMatch(PHP_OUTPUT);
      const answer = json(result);
      expect(answer.is_error, name).toBe(1);
      return String(answer.message);
    }

    expect(await refused('unknown processor', { pp_id: 99999999, AccessCode: 'x' }, null)).toBe('no payment processor.');
    expect(await refused('eWAY knows no transaction for the code', { pp_id: ewayId, AccessCode: 'x' }, () => {
      stub.respond('getTransaction', { Transactions: [], Errors: null });
    })).toBe('no transaction.');
    expect(await refused('the transaction saved no card', { pp_id: ewayId, AccessCode: 'x' }, () => {
      stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: '' }));
    })).toBe('no token.');
    expect(await refused('eWAY knows no customer for the token', { pp_id: ewayId, AccessCode: 'x' }, () => {
      stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }));
      stub.respond('getCustomer', { Customers: [], Errors: null });
    })).toBe('no customer.');
    expect(await refused('the customer reference is not one the extension wrote', { pp_id: ewayId, AccessCode: 'x' }, () => {
      stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }));
      stub.respond('getCustomer', responses.customerFound(token, `not-ours-${donor.id}`, card));
    })).toBe('no contact.');
    expect(await refused('the customer reference names a contact that does not exist', { pp_id: ewayId, AccessCode: 'x' }, () => {
      stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }));
      stub.respond('getCustomer', responses.customerFound(token, 'civi-987654321', card));
    })).toBe('no contact.');
    expect(await refused('the customer has no card details', { pp_id: ewayId, AccessCode: 'x' }, () => {
      stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }));
      const customer = responses.customerFound(token, `civi-${donor.id}`, card) as any;
      delete customer.Customers[0].CardDetails;
      stub.respond('getCustomer', customer);
    })).toBe('no card details.');

    expect(tokenTotal()).toBe(before);
    expect(api4('PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', donor.id]] })).toHaveLength(0);
  });
});

/**
 * PW-F6-04: civicrm/ewayrecurring/createtoken. The back-office "Add Credit Card" button posts the form's billing
 * fields to it and gets back eWAY's hosted page address as JSON. It needs "access CiviContribute".
 */
test.describe('create token', () => {
  test('PW-F6-04 an anonymous visitor is refused, whether the request looks like the script\'s or not', async ({ anonPage, stub }) => {
    const contact = newContact('f604anon');
    const query = { contact_id: contact.id, pp_id: ewayId };
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F604A')), { repeat: true });

    const attempts: [string, Answer][] = [
      ['a script post', await post(anonPage, routeUrl('createtoken', query), billingForm())],
      ['a script post with the fields in the address', await post(anonPage, routeUrl('createtoken', { ...query, ...billingForm() }), {})],
      ['a plain post', await post(anonPage, routeUrl('createtoken', query), billingForm(), {})],
      ['a plain GET', await get(anonPage, routeUrl('createtoken', query), {})],
    ];
    for (const [name, result] of attempts) {
      expect(result.status, `${name}: status`).toBeGreaterThanOrEqual(400);
      expect(result.body, `${name}: nothing from eWAY or the extension`).not.toMatch(/eway-stub\.test/);
      expect(isJsonText(result.body), `${name}: not a JSON answer`).toBe(false);
      expect(result.body, `${name}: no PHP output`).not.toMatch(PHP_OUTPUT);
    }
    expect(stub.requests()).toEqual([]);
  });

  // The browser logs the refusal's HTTP status (403 here, 500 on a fresh site) as a console error.
  test.describe('in a browser', () => {
    test.use({ allowedProblems: [/Failed to load resource: the server responded with a status of (403|500)|HTTP (403|500) from/] });

    test('PW-F6-04 a visitor sent there in a browser sees "You do not have permission", not a form or a JSON answer', async ({ anonPage }) => {
      await anonPage.goto(routeUrl('createtoken', { contact_id: 1, pp_id: ewayId }));
      await expect(anonPage.locator('body')).toContainText(/You do not have permission to access this page/);
    });
  });

  test('PW-F6-04 staff who lack "access CiviContribute" are refused, and the finance persona, who has it, is let in', async ({ limitedPage, tokenviewerPage, financePage, stub }) => {
    const contact = newContact('f604staff');
    const query = { contact_id: contact.id, pp_id: ewayId };
    for (const [name, page] of [['limited', limitedPage], ['tokenviewer', tokenviewerPage]] as const) {
      const result = await post(page, routeUrl('createtoken', query), billingForm());
      expect(result.status, name).toBeGreaterThanOrEqual(400);
      expect(result.body, name).not.toMatch(/eway-stub\.test/);
      expect(isJsonText(result.body), `${name}: not a JSON answer`).toBe(false);
    }
    expect(stub.requests()).toEqual([]);

    const code = newAccessCode('F604S');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(code));
    const allowed = await post(financePage, routeUrl('createtoken', query), billingForm());
    expect(allowed.status).toBe(200);
    const body = json(allowed);
    expect(body.is_error).toBe(0);
    expect(body.url).toContain('/pay?');
    expect(new URL(body.url!).searchParams.get('AccessCode')).toBe(code);
  });

  test('PW-F6-04 with a required billing field missing it answers "Missing field: ..." as JSON, naming the first one missing, and eWAY is not asked', async ({ financePage, stub }) => {
    const contact = newContact('f604miss');
    const url = routeUrl('createtoken', { contact_id: contact.id, pp_id: ewayId });
    const full = billingForm();
    // The fields in the order the extension checks them, with the name its message uses.
    const order: [string, string[]][] = [
      ['first_name', ['billing_first_name']],
      ['last_name', ['billing_last_name']],
      ['billing_street_address', ['billing_street_address-5']],
      ['billing_city', ['billing_city-5']],
      ['billing_postal_code', ['billing_postal_code-5']],
      ['billing_country', ['billing_country_id-5']],
      ['billing_state_province', ['billing_state_province_id-5']],
    ];
    const sent: Record<string, string | number> = {};
    for (const [message, keys] of order) {
      const result = await post(financePage, url, sent);
      expect(result.status, message).toBe(200);
      expect(result.type, message).toContain('json');
      expect(json(result), message).toEqual({ is_error: 1, message: `Missing field: ${message}` });
      for (const key of keys) {
        sent[key] = full[key];
      }
    }
    // A field that is there but blank counts as missing.
    const blank = await post(financePage, url, { ...full, 'billing_city-5': '' });
    expect(json(blank)).toEqual({ is_error: 1, message: 'Missing field: billing_city' });
    expect(stub.requests()).toEqual([]);
  });

  test('PW-F6-04 an unknown processor or contact is answered with a JSON message, and eWAY is not asked', async ({ financePage, stub }) => {
    const contact = newContact('f604unknown');
    const unknownProcessor = await post(financePage, routeUrl('createtoken', { contact_id: contact.id, pp_id: 99999999 }), billingForm());
    expect(json(unknownProcessor)).toEqual({ is_error: 1, message: 'no payment processor found.' });

    for (const unknown of [987654321, 'abc']) {
      const result = await post(financePage, routeUrl('createtoken', { contact_id: unknown, pp_id: ewayId }), billingForm());
      expect(result.body, String(unknown)).not.toMatch(PHP_OUTPUT);
      expect(json(result), String(unknown)).toEqual({ is_error: 1, message: 'no contact found.' });
    }
    expect(stub.requests()).toEqual([]);
  });

  // When contact_id or pp_id is missing CiviCRM's own "required" check fires first. The script reads JSON, so what it
  // gets is not an answer it can use: the site's page, with the reason queued as a status message.
  test('PW-F6-04 without contact_id or pp_id the answer is CiviCRM\'s page naming the missing value, not JSON, and eWAY is not asked', async ({ financePage, stub }) => {
    const contact = newContact('f604req');
    const cases: [string, Record<string, string | number>, string][] = [
      ['no contact_id', { pp_id: ewayId }, 'contact_id'],
      ['no pp_id', { contact_id: contact.id }, 'pp_id'],
    ];
    for (const [name, query, missing] of cases) {
      const result = await post(financePage, routeUrl('createtoken', query), billingForm());
      expect(result.type, name).toContain('text/html');
      expect(result.body, name).toContain(`Could not find valid value for ${missing}`);
      expect(result.body, `${name}: no PHP output`).not.toMatch(/Warning:|Fatal error|Stack trace:/);
    }
    expect(stub.requests()).toEqual([]);
  });

  // KNOWN DEFECT: a country or state id that does not exist (the form only ever sends real ones, so this is a tampered
  // or stale request) is looked up and the result used unguarded, so PHP prints "Trying to access array offset on null"
  // in front of the JSON answer, which then cannot be parsed:
  //   CRM/eWAYRecurring/PaymentToken.php, createToken(): the Country.get and StateProvince.get results are read with
  //   array_shift() and then indexed (iso_code, name) without checking that a row came back (lines 45 and 52).
  // The test only expects a failure while the warnings are there, so a site that hides PHP warnings passes it.
  test('PW-F6-04 a country or state id that does not exist is answered with clean JSON', async ({ financePage, stub }) => {
    const contact = newContact('f604badid');
    const url = routeUrl('createtoken', { contact_id: contact.id, pp_id: ewayId });
    const bad: [string, Record<string, string | number>][] = [
      ['a country id that does not exist', { 'billing_country_id-5': 99999999 }],
      ['a state id that does not exist', { 'billing_state_province_id-5': 99999999 }],
      ['an empty country id', { 'billing_country_id-5': '' }],
    ];
    const seen: string[] = [];
    for (const [name, override] of bad) {
      const result = await post(financePage, url, billingForm(override));
      if (PHP_OUTPUT.test(result.body)) {
        seen.push(`${name}: ${result.body.replace(/\s+/g, ' ').slice(0, 160)}`);
      } else {
        expect(json(result).is_error, name).toBe(1);
      }
    }
    test.fail(seen.length > 0, 'PHP warnings are printed in front of the JSON');
    expect(seen, 'PHP output in the JSON answers').toEqual([]);
    expect(stub.requests()).toEqual([]);
  });

  // KNOWN DEFECT: a processor id that is not an eWAY processor (the seeded Dummy one) is accepted: the extension builds an
  // eWAY client from it, and reading its missing credentials prints two PHP warnings in front of the JSON answer:
  //   CRM/eWAYRecurring/Utils.php, getEWayClient(), lines 166 and 167 (user_name and password read unguarded), reached from
  //   PaymentToken::createToken() through getPaymentProcessorById(), which does not check the processor's type.
  // The JSON message that follows is right ("eWAY library does not have API Key or password, or are invalid"), so the
  // test only expects a failure while the warnings are there.
  test('PW-F6-04 a processor that is not eWAY\'s is refused with clean JSON', async ({ financePage, stub }) => {
    const contact = newContact('f604dummy');
    const result = await post(financePage, routeUrl('createtoken', { contact_id: contact.id, pp_id: dummyId }), billingForm());
    test.fail(PHP_OUTPUT.test(result.body), 'PHP warnings are printed in front of the JSON');
    expect(result.body).not.toMatch(PHP_OUTPUT);
    expect(json(result).is_error).toBe(1);
    expect(stub.requests()).toEqual([]);
  });
});

/**
 * PW-F6-05: createtoken is flagged is_ajax, so a request that is not an XHR is refused (CIVI-SA-2026-49). The flag is
 * checked from the menu table on any core. Whether the refusal happens depends on the CiviCRM core: releases that
 * predate the security fix do not know the flag, and then a plain GET is answered like any other request (its answer
 * is the "Missing field" JSON, because a GET carries no billing fields in $_POST). The test records which it met.
 */
test.describe('create token: only an XHR', () => {
  test('PW-F6-05 the extension flags createtoken as an AJAX route and savetoken as not', async () => {
    const menu = JSON.parse(evaluate(`
      $out = [];
      $dao = CRM_Core_DAO::executeQuery("SELECT path, module_data FROM civicrm_menu WHERE path IN ('civicrm/ewayrecurring/createtoken', 'civicrm/ewayrecurring/savetoken')");
      while ($dao->fetch()) {
        $data = $dao->module_data ? unserialize($dao->module_data) : [];
        $out[$dao->path] = array_key_exists('is_ajax', $data) ? filter_var($data['is_ajax'], FILTER_VALIDATE_BOOLEAN) : NULL;
      }
      echo json_encode($out);
    `));
    expect(menu['civicrm/ewayrecurring/createtoken']).toBe(true);
    expect(menu['civicrm/ewayrecurring/savetoken']).toBe(false);
  });

  test('PW-F6-05 a plain GET navigation to createtoken by a permitted user is refused, and a plain post is too', async ({ financePage, stub }) => {
    const contact = newContact('f605get');
    const query = { contact_id: contact.id, pp_id: ewayId };
    const enforced = coreKnowsIsAjax();
    test.info().annotations.push({ type: 'core', description: enforced ? 'this core enforces is_ajax' : 'this core does not know is_ajax: the plain GET is answered like an XHR' });

    const plainGet = await get(financePage, routeUrl('createtoken', query), {});
    expect(plainGet.body).not.toMatch(PHP_OUTPUT);
    expect(plainGet.body).not.toContain('eway-stub.test');

    if (enforced) {
      expect(plainGet.status).toBeGreaterThanOrEqual(400);
      expect(plainGet.body).not.toMatch(/is_error/);
      // A form posted from another site carries no XHR header either: with every billing field present it is still refused.
      stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F605')), { repeat: true });
      const plainPost = await post(financePage, routeUrl('createtoken', query), billingForm(), {});
      expect(plainPost.status).toBeGreaterThanOrEqual(400);
      expect(plainPost.body).not.toMatch(/eway-stub\.test/);
      expect(isJsonText(plainPost.body)).toBe(false);
      expect(stub.requests()).toEqual([]);
    } else {
      // Pinned for a core that does not enforce the flag: the GET reaches the extension, and cannot get past the billing
      // fields (they are read from the posted form only), so eWAY is not asked.
      expect(plainGet.status).toBe(200);
      expect(json(plainGet)).toEqual({ is_error: 1, message: 'Missing field: first_name' });
      expect(stub.requests()).toEqual([]);
    }
  });

  test('PW-F6-05 the same request made as an XHR reaches the extension', async ({ financePage, stub }) => {
    const contact = newContact('f605xhr');
    const result = await get(financePage, routeUrl('createtoken', { contact_id: contact.id, pp_id: ewayId }), XHR);
    expect(result.status).toBe(200);
    expect(json(result)).toEqual({ is_error: 1, message: 'Missing field: first_name' });
    expect(stub.requests()).toEqual([]);
  });
});

/**
 * PW-F6-06: the return address the extension gives eWAY, and eWAY's habit of appending the access code to it. The
 * address always carries the extension's own parameters, so eWAY always appends with "&"; whether the part before them
 * is a clean path (WordPress, Drupal, Standalone) or a query (Joomla, WordPress without pretty permalinks) is the CMS's.
 * Events are the flow that returns through this page; contribution pages return to their own thank-you step.
 */
test.describe('the return address', () => {
  async function toHostedPage(page: Page, stub: EwayStub, label: string) {
    const person = uniqueDonor(label);
    const code = newAccessCode(label.toUpperCase().slice(0, 6));
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(code));
    await page.goto(eventUrl(paidEventId()));
    await fillPaidRegistration(page, person);
    await pressReview(page);
    await page.locator('#_qf_Confirm_next-bottom').waitFor();
    await continueFromEventConfirm(page);
    const asked = stub.requests('postAccessCodeShared')[0].args[0] as Record<string, any>;
    return { person, code, request: asked, redirectUrl: String(asked.RedirectUrl), amount: Number(asked.Payment.TotalAmount) / 100 };
  }

  test('PW-F6-06 the address given to eWAY is the CMS\'s form of the return page with its parameters, and the access code appended to it verifies the payment', async ({ anonPage, stub }) => {
    const { person, code, request, redirectUrl, amount } = await toHostedPage(anonPage, stub, 'f606a');

    // The CMS's own form of the route (path, or option and task parameters), then the extension's parameters.
    const given = new URL(redirectUrl);
    const expected = new URL(routeUrl('verifypayment'), given.origin);
    expect(given.pathname).toBe(expected.pathname);
    for (const [key, value] of expected.searchParams) {
      expect(given.searchParams.get(key), `the CMS's own parameter ${key}`).toBe(value);
    }
    expect(given.searchParams.get('component')).toBe('event');
    expect(given.searchParams.get('paymentProcessorID')).toBe(String(ewayId));
    expect(given.searchParams.get('contributionInvoiceID')).toBe(request.Payment.InvoiceReference);
    expect(given.searchParams.get('qfKey')).toMatch(/^CRMEventController/);
    // There is always a query string already, so eWAY appends with "&" and never "?".
    expect(redirectUrl).toContain('?');
    expect(redirectUrl).not.toContain('AccessCode=');

    stub.respond('getTransaction', transactionApproved(amount));
    const separator = redirectUrl.includes('?') ? '&' : '?';
    expect(appendQuery(redirectUrl, { AccessCode: code })).toBe(`${redirectUrl}${separator}AccessCode=${encodeURIComponent(code)}`);
    await anonPage.goto(appendQuery(redirectUrl, { AccessCode: code }));

    await expect(anonPage.locator('body')).toContainText(/thank you for registering/i);
    expect(new URL(anonPage.url()).searchParams.get('qfKey')).toBe(given.searchParams.get('qfKey'));
    expect(stub.requests('getTransaction').map((r) => r.args[0])).toEqual([code]);
    const [participant] = participantsOf(person.email);
    expect(participant['status_id:name']).toBe('Registered');
    expect(contributionOfParticipant(participant.id)!.status).toBe('Completed');
  });

  test('PW-F6-06 the parameters can come in any order, and an access code with characters that need encoding arrives intact', async ({ anonPage, stub }) => {
    const { person, code, redirectUrl, amount } = await toHostedPage(anonPage, stub, 'f606b');
    const given = new URL(redirectUrl);
    const odd = `${code}+/=`;
    // The same parameters, the access code first and the rest reversed.
    const url = frontUrl('ewayrecurring/verifypayment', {
      AccessCode: odd,
      component: given.searchParams.get('component')!,
      paymentProcessorID: given.searchParams.get('paymentProcessorID')!,
      qfKey: given.searchParams.get('qfKey')!,
      contributionInvoiceID: given.searchParams.get('contributionInvoiceID')!,
    });
    stub.respond('getTransaction', transactionApproved(amount));
    await anonPage.goto(url);

    await expect(anonPage.locator('body')).toContainText(/thank you for registering/i);
    expect(stub.requests('getTransaction').map((r) => r.args[0])).toEqual([odd]);
    const [participant] = participantsOf(person.email);
    expect(participant['status_id:name']).toBe('Registered');
  });

  // eWAY can send the browser back before it has a result (the stub answers as eWAY does then). The visitor is not told
  // the payment succeeded: the thank-you step says it was submitted for processing, and the registration stays pending
  // for the verification job.
  test('PW-F6-06 a return before eWAY has a result says the payment was submitted for processing and leaves the registration pending', async ({ anonPage, stub }) => {
    const { person, code, redirectUrl } = await toHostedPage(anonPage, stub, 'f606c');
    stub.respond('getTransaction', transactionNotProcessedYet(), { repeat: true });
    await anonPage.goto(appendQuery(redirectUrl, { AccessCode: code }));

    await expect(anonPage.locator('body')).toContainText(/submitted to .* for processing/i);
    const [participant] = participantsOf(person.email);
    expect(participant['status_id:name']).toBe('Pending from incomplete transaction');
    const contribution = contributionOfParticipant(participant.id)!;
    expect(contribution.status).toBe('Pending');
  });

  // KNOWN DEFECT (a worse case of DEF-38): the return page marks the verification queue row as done even when eWAY has
  // no result yet, so the verification job, which only looks at rows still waiting, never settles the registration:
  //   CRM/Core/Payment/eWAYRecurring.php, validateContribution() calls CRM_eWAYRecurring_Utils::completeEWayTransaction()
  //   whatever the answer was, including "not processed yet".
  // The registration stays "Pending from incomplete transaction" for good although eWAY later approves the payment.
  // Pinned with test.fail().
  test('PW-F6-06 a registration whose visitor returned before eWAY had a result is still settled by the verification job', async ({ anonPage, stub }) => {
    test.fail();
    const { person, code, redirectUrl, amount } = await toHostedPage(anonPage, stub, 'f606d');
    stub.respond('getTransaction', transactionNotProcessedYet());
    await anonPage.goto(appendQuery(redirectUrl, { AccessCode: code }));
    const [participant] = participantsOf(person.email);
    const contribution = contributionOfParticipant(participant.id)!;
    expect(contribution.status).toBe('Pending');

    // eWAY finishes, and the job runs.
    setOtherQueueRowsAside(contribution.id);
    stub.respond('getTransaction', transactionApproved(amount));
    runVerificationJob();
    expect(contributionOfParticipant(participant.id)!.status).toBe('Completed');
    expect(participantsOf(person.email)[0]['status_id:name']).toBe('Registered');
  });
});

/**
 * PW-F6-07: createtoken and savetoken addressed with CRM.url(path, query, 'front') from a back-office page, which is how
 * js/eway.js reaches them. In the back office the plain address would be inside the CMS's admin (on WordPress,
 * wp-admin/admin.php); 'front' gives the public one, which answers with the bare JSON.
 *
 * Permissions: createtoken needs "access CiviContribute". savetoken needs "make online contributions", which the
 * anonymous visitor holds so that eWAY's redirect can reach it, so unlike createtoken it is NOT refused to anonymous.
 * On WordPress a signed-in user does not inherit the anonymous role's permissions, so the finance persona (who has
 * "access CiviContribute" but not "make online contributions") can create the hosted page and is then refused when the
 * script asks savetoken to read the card back.
 */
test.describe('the back-office script\'s routes', () => {
  /** The script's own calls: jQuery ajax from the page, as CRM.eway.addCreditCard() makes them. */
  async function viaScript(page: Page, request: { url: string; type: 'GET' | 'POST'; data?: Record<string, string | number> }) {
    return page.evaluate((req) => new Promise<{ status: number; body: string }>((resolve) => {
      (window as any).CRM.$.ajax({ url: req.url, type: req.type, data: req.data, dataType: 'text' })
        .always((first: any, _state: string, third: any) => {
          const xhr = typeof first === 'object' && first && 'status' in first ? first : third;
          resolve({ status: xhr.status, body: typeof first === 'string' ? first : (xhr.responseText ?? '') });
        });
    }), request);
  }

  /** A back-office page that loads CiviCRM's script, where the script runs. */
  async function openBackOffice(page: Page, contactId: number): Promise<void> {
    await page.goto(adminUrl('contact/view', { reset: 1, cid: contactId }));
    await page.waitForFunction(() => !!(window as any).CRM?.url && !!(window as any).CRM?.$);
  }

  /** CRM.url(path, query, 'front') evaluated in the page. */
  async function crmFrontUrl(page: Page, route: string, query: Record<string, string | number>): Promise<string> {
    return page.evaluate(([path, params]) => (window as any).CRM.url(path, params, 'front'), [`civicrm/ewayrecurring/${route}`, query] as const);
  }

  test('PW-F6-07 \'front\' gives the public address of each route, not the CMS\'s admin one', async ({ adminPage }) => {
    const contact = newContact('f607url');
    await openBackOffice(adminPage, contact.id);
    for (const route of ['createtoken', 'savetoken'] as const) {
      const query = { contact_id: contact.id, pp_id: ewayId };
      const front = await crmFrontUrl(adminPage, route, query);
      const wanted = new URL(routeUrl(route, query), new URL(adminPage.url()).origin);
      const got = new URL(front, adminPage.url());
      expect(got.pathname, route).toBe(wanted.pathname);
      expect(got.pathname, route).not.toContain('wp-admin');
      for (const [key, value] of wanted.searchParams) {
        expect(got.searchParams.get(key), `${route}: ${key}`).toBe(value);
      }
    }
  });

  test('PW-F6-07 staff add a card through the routes the script uses: hosted page, then the card is read back, and asking again adds no second card', async ({ adminPage, stub }) => {
    const contact = newContact('f607flow');
    const code = newAccessCode('F607');
    const token = newCustomerToken();
    const before = tokenTotal();
    await openBackOffice(adminPage, contact.id);

    // 1. createtoken, with the billing fields as the form serialises them.
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(code));
    const created = await viaScript(adminPage, {
      url: await crmFrontUrl(adminPage, 'createtoken', { contact_id: contact.id, pp_id: ewayId }),
      type: 'POST',
      data: billingForm(),
    });
    expect(created.status).toBe(200);
    const hosted = JSON.parse(created.body) as Json;
    expect(hosted.is_error).toBe(0);
    const hostedUrl = new URL(hosted.url!);
    expect(hostedUrl.pathname).toBe('/pay');
    expect(hostedUrl.searchParams.get('AccessCode')).toBe(code);

    // eWAY was told where to send the browser back: savetoken in its public form, with the contact and processor.
    const asked = stub.requests('postAccessCodeShared')[0].args[0] as Record<string, any>;
    const back = new URL(asked.RedirectUrl);
    expect(back.pathname).toBe(new URL(routeUrl('savetoken'), back.origin).pathname);
    expect(back.searchParams.get('cid')).toBe(String(contact.id));
    expect(back.searchParams.get('pp_id')).toBe(String(ewayId));
    expect(JSON.stringify(asked)).toContain(`civi-${contact.id}`);
    expect(JSON.stringify(asked)).toContain('1 Test Street');

    // 2. savetoken, as the script asks after "Ok": no access code in the address, so the one in the session is used.
    stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }), { repeat: true });
    stub.respond('getCustomer', responses.customerFound(token, `civi-${contact.id}`, { Number: '510510XXXXXX5100', ExpiryMonth: '03', ExpiryYear: '35' }), { repeat: true });
    const saveUrl = await crmFrontUrl(adminPage, 'savetoken', { pp_id: ewayId });
    const saved = await viaScript(adminPage, { url: saveUrl, type: 'GET' });
    expect(saved.status).toBe(200);
    const answer = JSON.parse(saved.body) as Json;
    expect(answer.is_error).toBe(0);
    expect(answer.token_id).toBeTruthy();
    expect(Array.isArray((answer.message as any).values)).toBe(true);

    const cards = api4('PaymentToken', 'get', { select: ['id', 'token', 'masked_account_number', 'payment_processor_id'], where: [['contact_id', '=', contact.id]] });
    expect(cards).toHaveLength(1);
    expect(cards[0]).toMatchObject({ token, masked_account_number: '510510XXXXXX5100', payment_processor_id: ewayId });
    expect(String(answer.token_id)).toBe(String(cards[0].id));

    // 3. Asking again (a double click on Ok) finds the same card and adds none.
    const again = await viaScript(adminPage, { url: saveUrl, type: 'GET' });
    expect(JSON.parse(again.body)).toMatchObject({ is_error: 0 });
    expect(String(JSON.parse(again.body).token_id)).toBe(String(cards[0].id));
    expect(api4('PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', contact.id]] })).toHaveLength(1);
    expect(tokenTotal()).toBe(before + 1);
  });

  test('PW-F6-07 the finance persona can have the hosted page created but is refused when the script asks to read the card back', async ({ financePage, stub }) => {
    const contact = newContact('f607fin');
    const code = newAccessCode('F607F');
    const token = newCustomerToken();
    const before = tokenTotal();
    // The finance persona cannot open a contact, so the script is loaded from the contribution dashboard.
    await financePage.goto(adminUrl('contribute', { reset: 1 }));
    await financePage.waitForFunction(() => !!(window as any).CRM?.url && !!(window as any).CRM?.$);

    stub.respond('postAccessCodeShared', responses.sharedPageCreated(code));
    const created = await viaScript(financePage, {
      url: await crmFrontUrl(financePage, 'createtoken', { contact_id: contact.id, pp_id: ewayId }),
      type: 'POST',
      data: billingForm(),
    });
    expect(created.status).toBe(200);
    expect(JSON.parse(created.body).is_error).toBe(0);

    stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }), { repeat: true });
    stub.respond('getCustomer', responses.customerFound(token, `civi-${contact.id}`), { repeat: true });
    const result = await get(financePage, routeUrl('savetoken', { pp_id: ewayId }), XHR);
    expect(result.status).toBeGreaterThanOrEqual(400);
    expect(isJsonText(result.body)).toBe(false);
    expect(tokenTotal()).toBe(before);
    expect(stub.requests('getTransaction')).toHaveLength(0);
  });

  test('PW-F6-07 an anonymous visitor is refused createtoken but not savetoken: eWAY\'s redirect arrives with no login, and the card goes to the contact in eWAY\'s record', async ({ anonPage, stub }) => {
    const owner = newContact('f607owner');
    const other = newContact('f607other');
    const token = newCustomerToken();

    // createtoken: refused (checked in depth by PW-F6-04).
    const refused = await post(anonPage, routeUrl('createtoken', { contact_id: owner.id, pp_id: ewayId }), billingForm());
    expect(refused.status).toBeGreaterThanOrEqual(400);

    // savetoken, as eWAY's redirect reaches it: a plain navigation with the access code. It saves the card for the contact
    // eWAY's customer record names (its reference), not the one in the address (cid), and answers with the close script.
    stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }));
    stub.respond('getCustomer', responses.customerFound(token, `civi-${owner.id}`, { Number: '555555XXXXXX4444', ExpiryMonth: '09', ExpiryYear: '34' }));
    await anonPage.goto(routeUrl('savetoken', { cid: other.id, pp_id: ewayId, AccessCode: newAccessCode('F607R') }));
    expect(await anonPage.content()).toContain('<script>window.close();</script>');

    const ownerCards = api4('PaymentToken', 'get', { select: ['token', 'masked_account_number'], where: [['contact_id', '=', owner.id]] });
    expect(ownerCards.map(({ token: t, masked_account_number: m }) => ({ token: t, masked_account_number: m }))).toEqual([{ token, masked_account_number: '555555XXXXXX4444' }]);
    expect(api4('PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', other.id]] })).toHaveLength(0);
  });
});
