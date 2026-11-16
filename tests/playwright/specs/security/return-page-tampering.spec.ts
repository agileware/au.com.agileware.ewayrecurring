import { test, expect } from '../../fixtures/base';
import { api4, evaluate } from '../../fixtures/civi';
import { createPendingPayment, contributionState, queueRowById } from '../../fixtures/queue-records';
import { newAccessCode, newCustomerToken } from '../../fixtures/contribution-page';
import { responses } from '../../fixtures/eway-stub';
import {
  PHP_OUTPUT,
  approvedTransaction,
  billingForm,
  contributionTotal,
  declinedTransaction,
  get,
  invoiceOf,
  isJsonText,
  json,
  NAVIGATED_AWAY,
  REFUSED_STATUS,
  newContact,
  post,
  routeUrl,
  seededProcessors,
  tokenTotal,
} from '../../fixtures/security';

/**
 * PW-S-02: an attacker's view of the return page, civicrm/ewayrecurring/verifypayment, and of the two token pages,
 * createtoken and savetoken. Test plan: developer/TEST_PLAN.md section 5.3.
 *
 * The return page is public (eWAY's redirect arrives with no login) and takes everything from the address: the invoice
 * id of the contribution, the processor id, the component and the access code. Whoever holds a Pending contribution's
 * invoice id can send any access code with it, so the page must never trust the pair. Since DEF-47, DEF-48 and DEF-51 it
 * checks that eWAY's transaction names that invoice and that amount, that the processor is an eWAY one and that the id is
 * digits only. PW-F6-01 already covers missing and malformed parameters one by one; these cases are the attacks that
 * combine valid pieces (an approved code of the attacker's own, another payment's invoice id, a completed payment's code,
 * a processor that exists) and show that nothing changes and nothing is printed.
 *
 * Every request here is a plain HTTP request without following redirects, so the status and the Location header are seen
 * as they are. The fake eWAY answers each method in the order the answers were queued, so a test queues exactly what
 * eWAY would say for the attack, and a refusal is shown by the contribution staying as it was.
 */

/** A return address as eWAY builds it for a contribution page payment, with the pieces an attacker can swap. */
function returnUrl(invoice: string, processor: number | string, accessCode: string, extra: Record<string, string | number> = {}): string {
  return routeUrl('verifypayment', {
    contributionInvoiceID: invoice,
    paymentProcessorID: processor,
    component: 'contribute',
    qfKey: 'no-such-key',
    AccessCode: accessCode,
    ...extra,
  });
}

/** The money movements recorded against a contribution: a second completion would add one. */
function paymentRecords(contributionId: number): number {
  return Number(evaluate(
    `echo (int) CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_entity_financial_trxn WHERE entity_table = 'civicrm_contribution' AND entity_id = ${Number(contributionId)}");`,
  ));
}

test.describe('the return page: an approved code from somewhere else', () => {
  test('PW-S-02 an approved code that belongs to another payment of the same amount, or a completed one, does not complete this contribution', async ({ anonPage, stub }) => {
    const { live } = seededProcessors();
    const victim = createPendingPayment({ label: 's02victim', amount: 25 });
    const attacker = createPendingPayment({ label: 's02own', amount: 25 });
    const victimInvoice = invoiceOf(victim.contributionId);
    const attackerInvoice = invoiceOf(attacker.contributionId);
    const contributions = contributionTotal();

    // 1. The attacker pays for their own contribution (a real, approved transaction for their invoice) ...
    stub.respond('getTransaction', approvedTransaction(attackerInvoice, 25));
    const own = await get(anonPage, returnUrl(attackerInvoice, live, attacker.accessCode));
    expect(own.status, 'their own return completes their own payment').toBe(302);
    expect(contributionState(attacker.contributionId).status).toBe('Completed');

    // 2. ... and then presents that completed payment's code with the victim's invoice id. The amount matches, so only
    // the invoice reference on eWAY's transaction can tell the two payments apart.
    const records = paymentRecords(victim.contributionId);
    stub.reset();
    stub.respond('getTransaction', approvedTransaction(attackerInvoice, 25), { repeat: true });
    const swapped = await get(anonPage, returnUrl(victimInvoice, live, attacker.accessCode));
    expect(swapped.status).toBe(400);
    expect(swapped.body.trim()).toBe('');
    expect(contributionState(victim.contributionId).status).toBe('Pending');
    expect(contributionState(victim.contributionId).trxnId).toBeNull();
    expect(paymentRecords(victim.contributionId)).toBe(records);
    expect(Number(queueRowById(victim.queueId).status), 'the victim\'s queue row is still waiting').toBe(0);

    // 3. A declined payment of the attacker's own must not fail the victim's contribution either.
    stub.reset();
    stub.respond('getTransaction', declinedTransaction(attackerInvoice, 25), { repeat: true });
    const declined = await get(anonPage, returnUrl(victimInvoice, live, attacker.accessCode));
    expect(declined.status).toBe(400);
    expect(contributionState(victim.contributionId).status).toBe('Pending');

    // 4. The attacker's payment is still completed once, and nothing was added anywhere.
    expect(contributionState(attacker.contributionId).status).toBe('Completed');
    expect(contributionTotal()).toBe(contributions);
  });

  test('PW-S-02 a small completed payment cannot be used to complete a larger contribution, and the right invoice with the wrong amount is refused', async ({ anonPage, stub }) => {
    const { live } = seededProcessors();
    const small = createPendingPayment({ label: 's02small', amount: 1 });
    const large = createPendingPayment({ label: 's02large', amount: 50 });
    const smallInvoice = invoiceOf(small.contributionId);
    const largeInvoice = invoiceOf(large.contributionId);

    stub.respond('getTransaction', approvedTransaction(smallInvoice, 1));
    expect((await get(anonPage, returnUrl(smallInvoice, live, small.accessCode))).status).toBe(302);
    expect(contributionState(small.contributionId).status).toBe('Completed');

    // The small payment's code again, with the large contribution's invoice id.
    stub.reset();
    stub.respond('getTransaction', approvedTransaction(smallInvoice, 1), { repeat: true });
    expect((await get(anonPage, returnUrl(largeInvoice, live, small.accessCode))).status).toBe(400);
    expect(contributionState(large.contributionId).status).toBe('Pending');

    // The large contribution's own invoice id on a transaction that carries it, but for one dollar.
    stub.reset();
    stub.respond('getTransaction', approvedTransaction(largeInvoice, 1), { repeat: true });
    const wrongAmount = await get(anonPage, returnUrl(largeInvoice, live, newAccessCode('S02W')));
    expect(wrongAmount.status).toBe(400);
    expect(wrongAmount.body.trim()).toBe('');
    expect(contributionState(large.contributionId)).toMatchObject({ status: 'Pending', trxnId: null });

    // And for the right amount it does complete, so the refusals above are the checks and not a broken page.
    stub.reset();
    stub.respond('getTransaction', approvedTransaction(largeInvoice, 50));
    expect((await get(anonPage, returnUrl(largeInvoice, live, large.accessCode))).status).toBe(302);
    expect(contributionState(large.contributionId).status).toBe('Completed');
  });
});

test.describe('the return page: the processor and the invoice id', () => {
  test('PW-S-02 a processor that is not the live eWAY one is refused even when the invoice and the code are right, and eWAY is not asked', async ({ anonPage, stub }) => {
    const processors = seededProcessors();
    const payment = createPendingPayment({ label: 's02proc', amount: 25 });
    const invoice = invoiceOf(payment.contributionId);
    stub.respond('getTransaction', approvedTransaction(invoice, 25), { repeat: true });

    // Every processor on this site that is not the live eWAY one: the test-mode eWAY record (mode mismatch), the Dummy
    // processor, and whatever else the site has that is not eWAY's (core's own test processor on a development site; the
    // CI site has only the eWAY records and the Dummy one, so the list is read from the site and not assumed). Then ids that
    // are no processor, and processor ids written so that they are numbers only to a sloppy reader.
    const others = api4<{ id: number; title: string; is_test: boolean; 'payment_processor_type_id:name': string }>('PaymentProcessor', 'get', {
      select: ['id', 'title', 'is_test', 'payment_processor_type_id:name'],
      where: [['is_test', 'IN', [true, false]]],
    }).filter((row) => row.id !== processors.live && (row['payment_processor_type_id:name'] !== 'eWay_Recurring' || row.is_test));
    expect(others.map((row) => row.id), 'the seeded test-mode and Dummy processors are among them').toEqual(expect.arrayContaining([processors.test, processors.dummy]));
    const attempts: [string, number | string][] = [
      ...others.map((row): [string, number] => [`${row.is_test ? 'test-mode ' : ''}processor ${row.id} (${row.title})`, row.id]),
      ['no such processor', 99999999],
      ['zero', 0],
      ['a processor id with a plus sign', `+${processors.live}`],
      ['a processor id in hexadecimal', `0x${processors.live.toString(16)}`],
      ['a processor id with a decimal point', `${processors.live}.0`],
      ['a processor id with trailing text', `${processors.live} OR 1=1`],
    ];
    for (const [name, processor] of attempts) {
      const result = await get(anonPage, returnUrl(invoice, processor, payment.accessCode));
      expect(result.status, `${name}: status`).toBe(400);
      expect(result.body.trim(), `${name}: body`).toBe('');
    }
    expect(contributionState(payment.contributionId)).toMatchObject({ status: 'Pending', trxnId: null });
    expect(stub.requests(), 'a refusal never reaches eWAY').toEqual([]);
  });

  test('PW-S-02 SQL meta-characters and wildcards in the invoice id match nothing, and the tables are intact', async ({ anonPage, stub }) => {
    const { live } = seededProcessors();
    const payment = createPendingPayment({ label: 's02sql', amount: 25 });
    const invoice = invoiceOf(payment.contributionId);
    const before = contributionTotal();
    // If any of these matched the contribution, this answer would complete it.
    stub.respond('getTransaction', approvedTransaction(invoice, 25), { repeat: true });

    const hostile = [
      "' OR '1'='1",
      `${invoice}' OR '1'='1`,
      `${invoice}' OR ''='`,
      `${invoice}%`,
      `%${invoice.slice(4)}`,
      '%',
      '_'.repeat(32),
      `${invoice.slice(0, 12)}%`,
      "'; DROP TABLE civicrm_contribution; --",
      `${invoice}\\`,
      `${invoice}\u0000`,
      '0',
      '1',
      '-1',
      '1e0',
      'NULL',
    ];
    for (const value of hostile) {
      const result = await get(anonPage, returnUrl(value, live, payment.accessCode));
      const label = JSON.stringify(value).slice(0, 50);
      expect(result.status, `${label}: status`).toBe(400);
      expect(result.body, `${label}: nothing printed`).toBe('');
    }
    expect(contributionState(payment.contributionId)).toMatchObject({ status: 'Pending', trxnId: null });
    expect(contributionTotal(), 'no contribution was added or lost').toBe(before);
    // The table is still there and still answers.
    expect(api4('Contribution', 'get', { select: ['id'], where: [['id', '=', payment.contributionId]] })).toHaveLength(1);
  });
});

test.describe('the return page: naming a payment without knowing its invoice id', () => {
  // FIXED DEFECT (DEF-70, security): the invoice id was looked up through APIv3, which reads a "%" in a plain string as a
  // wildcard, so "4cf11d9f%" named the first contribution whose invoice id starts that way. With an access code eWAY answers
  // with an error for (any made-up one), the return page fails the contribution it found, so a visitor could fail other
  // people's Pending payments (a donor on eWAY's page, a registration being paid) by trying prefixes, knowing nothing
  // about them. A donor who then paid on eWAY was charged for a contribution that stays Failed.
  //   CRM/eWAYRecurring/Utils.php, validateEwayContribution(): 'invoice_id' => $invoiceID.
  // The id is now matched exactly.
  test('PW-S-02 a wildcard in the invoice id cannot name someone else\'s payment, so a made-up access code fails nothing', async ({ anonPage, stub }) => {
    const { live } = seededProcessors();
    const victim = createPendingPayment({ label: 's02wild', amount: 25 });
    const other = createPendingPayment({ label: 's02wild2', amount: 25 });
    const invoice = invoiceOf(victim.contributionId);
    // eWAY has no transaction for a made-up access code and answers with an error.
    stub.respond('getTransaction', { Transactions: [], Errors: 'V6110' }, { repeat: true });

    const patterns = [
      `${invoice.slice(0, 8)}%`,
      `${invoice.slice(0, 3)}%`,
      `${invoice.slice(0, 1)}%`,
      `%${invoice.slice(-6)}`,
      `${invoice.slice(0, 4)}%${invoice.slice(-4)}`,
      '%',
      '%%',
    ];
    for (const pattern of patterns) {
      const result = await get(anonPage, returnUrl(pattern, live, 'MADE-UP-ACCESS-CODE'));
      expect(result.status, `${pattern}: refused`).toBe(400);
      expect(result.body.trim(), `${pattern}: nothing shown`).toBe('');
    }
    for (const payment of [victim, other]) {
      expect(contributionState(payment.contributionId), 'still waiting').toMatchObject({ status: 'Pending', trxnId: null });
      expect(Number(queueRowById(payment.queueId).status), 'its queue row is still waiting').toBe(0);
    }
    expect(stub.requests(), 'a refusal never reaches eWAY').toEqual([]);

    // Control: the whole invoice id, held by the donor, is what names a payment, and eWAY's error then fails it. This is
    // what the attack above used to do to a stranger's payment.
    const own = createPendingPayment({ label: 's02own2', amount: 25 });
    const result = await get(anonPage, returnUrl(invoiceOf(own.contributionId), live, 'MADE-UP-ACCESS-CODE'));
    expect(result.status).toBe(302);
    expect(contributionState(own.contributionId).status).toBe('Failed');
    expect(contributionState(victim.contributionId).status).toBe('Pending');
  });
});

test.describe('the return page: replaying a completed return', () => {
  test('PW-S-02 replaying a completed return with the same code, another code, a decline or an error changes nothing and pays nothing twice', async ({ anonPage, stub }) => {
    const { live } = seededProcessors();
    const payment = createPendingPayment({ label: 's02replay', amount: 25 });
    const invoice = invoiceOf(payment.contributionId);
    const address = returnUrl(invoice, live, payment.accessCode);

    // The first return is eWAY's real one: it completes the contribution and sends the browser on.
    stub.respond('getTransaction', approvedTransaction(invoice, 25));
    const first = await get(anonPage, address);
    expect(first.status).toBe(302);
    const done = contributionState(payment.contributionId);
    expect(done.status).toBe('Completed');
    const records = paymentRecords(payment.contributionId);
    const contributions = contributionTotal();

    /** Return again, with eWAY saying `answerFromEway`, and show that the contribution and its payment records did not move. */
    async function replay(name: string, url: string, answerFromEway: Record<string, unknown>) {
      stub.reset();
      stub.respond('getTransaction', answerFromEway, { repeat: true });
      const result = await get(anonPage, url);
      expect(result.status, `${name}: the visitor gets a redirect or a plain refusal`).toBeLessThan(500);
      expect(result.body, `${name}: nothing printed`).not.toMatch(PHP_OUTPUT);
      expect(contributionState(payment.contributionId), `${name}: contribution`).toEqual(done);
      expect(paymentRecords(payment.contributionId), `${name}: payment records`).toBe(records);
      expect(contributionTotal(), `${name}: contributions`).toBe(contributions);
      return result;
    }

    await replay('the same address again', address, approvedTransaction(invoice, 25));
    await replay('another access code, approved', returnUrl(invoice, live, newAccessCode('S02R')), approvedTransaction(invoice, 25));
    // A decline or an error must not turn a paid contribution into a Failed one (CiviCRM refuses that move).
    await replay('a declined transaction', returnUrl(invoice, live, newAccessCode('S02D')), declinedTransaction(invoice, 25));
    await replay('eWAY answers with an error', returnUrl(invoice, live, newAccessCode('S02E')), { Transactions: [], Errors: 'V6110' });
  });

  test.describe('a visitor who follows the redirect', () => {
    test.use({ allowedProblems: [new RegExp(`${REFUSED_STATUS.source}|${NAVIGATED_AWAY.source}`)] });

    test('PW-S-02 the replayed address shows a visitor with no session nothing of the donor', async ({ anonPage, stub }) => {
      const { live } = seededProcessors();
      const payment = createPendingPayment({ label: 's02view', amount: 25 });
      const invoice = invoiceOf(payment.contributionId);
      stub.respond('getTransaction', approvedTransaction(invoice, 25), { repeat: true });
      expect((await get(anonPage, returnUrl(invoice, live, payment.accessCode))).status).toBe(302);

      // A browser, redirects and all: the redirect target is the contact's page, which a visitor may not see.
      await anonPage.goto(returnUrl(invoice, live, payment.accessCode), { waitUntil: 'load' }).catch(() => undefined);
      const text = (await anonPage.locator('body').innerText()).toLowerCase();
      expect(text).not.toContain(payment.donor.email.toLowerCase());
      expect(text).not.toContain(payment.donor.lastName.toLowerCase());
      expect(text).not.toContain('contact id');
      expect(contributionState(payment.contributionId).status).toBe('Completed');
    });
  });
});

test.describe('the return page: what the visitor controls and sees', () => {
  test('PW-S-02 hostile qfKey and component values on a good return are not reflected, and cannot add a header', async ({ anonPage, stub }) => {
    const { live } = seededProcessors();
    const cases: [string, string][] = [
      ['a line break and a header', 'x\r\nSet-Cookie: pwned=1'],
      ['markup', '"><script>window.__xss=1</script>'],
      ['a redirect to another site', 'https://evil.example/steal'],
      ['a very long value', 'A'.repeat(3000)],
    ];
    for (const [name, qfKey] of cases) {
      const payment = createPendingPayment({ label: 's02hdr', amount: 25 });
      const invoice = invoiceOf(payment.contributionId);
      stub.reset();
      stub.respond('getTransaction', approvedTransaction(invoice, 25));
      // An event return carries the qfKey into the thank-you address.
      const result = await get(anonPage, returnUrl(invoice, live, payment.accessCode, { component: 'event', qfKey }));
      expect(result.status, `${name}: status`).toBe(302);
      expect(result.headers['set-cookie'] ?? '', `${name}: no cookie of the attacker's`).not.toMatch(/pwned/);
      expect(Object.keys(result.headers).filter((header) => /pwned|^x-injected/i.test(header)), `${name}: no injected header`).toEqual([]);
      const location = result.location ?? '';
      const site = String(test.info().project.use.baseURL);
      expect(new URL(location, site).origin, `${name}: stays on this site`).toBe(new URL(site).origin);
      expect(location.split('?')[0], `${name}: the path is the extension's choice`).toMatch(/civicrm\/event\/register|q=civicrm%2Fevent%2Fregister/);
      expect(location, `${name}: the value is encoded`).not.toMatch(/[<>"\r\n ]/);
      expect(result.body, `${name}: nothing printed`).not.toMatch(PHP_OUTPUT);
      expect(result.body, `${name}: nothing reflected`).not.toContain('__xss');
      expect(contributionState(payment.contributionId).status, `${name}: the genuine payment still completes`).toBe('Completed');
    }
  });
});

test.describe('the token pages: hostile parameters', () => {
  test('PW-S-02 savetoken with hostile pp_id, cid and access code values answers a JSON refusal or the close script, and saves no card', async ({ anonPage, stub }) => {
    const { live } = seededProcessors();
    const before = tokenTotal();
    // For an access code eWAY knows nothing of.
    stub.respond('getTransaction', { Transactions: [], Errors: null }, { repeat: true });

    const hostile = ["' OR '1'='1", '<script>window.__xss=1</script>', '../../etc/passwd', `${live} OR 1=1`, 'A'.repeat(4000), '%', '0x1'];
    for (const value of hostile) {
      const label = JSON.stringify(value).slice(0, 40);
      const badProcessor = await get(anonPage, routeUrl('savetoken', { pp_id: value, AccessCode: 'x' }));
      expect(badProcessor.status, `pp_id ${label}`).toBe(200);
      expect(badProcessor.body, `pp_id ${label}: nothing printed`).not.toMatch(PHP_OUTPUT);
      expect(badProcessor.body, `pp_id ${label}: nothing reflected`).not.toContain('__xss');
      expect(json(badProcessor).is_error, `pp_id ${label}`).toBe(1);

      const badCode = await get(anonPage, routeUrl('savetoken', { pp_id: live, AccessCode: value, cid: value }));
      expect(badCode.status, `access code ${label}`).toBe(200);
      expect(badCode.body, `access code ${label}: nothing printed`).not.toMatch(PHP_OUTPUT);
      expect(badCode.body, `access code ${label}: nothing reflected`).not.toContain('__xss');
      expect(json(badCode), `access code ${label}`).toEqual({ is_error: 1, message: 'no transaction.' });
    }

    // Lists where a value is expected. A list is not a processor id (DEF-72: it printed a PHP warning with the server's file
    // path) and not an access code: the answer is the window-close script, or the JSON refusal.
    for (const query of ['pp_id%5B%5D=1&AccessCode=x', `pp_id%5Bx%5D=${live}&AccessCode=x`, `pp_id=${live}&AccessCode%5B%5D=x`, `pp_id=${live}&AccessCode%5Bx%5D=y`, 'pp_id%5B%5D=1&AccessCode%5B%5D=x']) {
      const base = routeUrl('savetoken');
      const result = await get(anonPage, `${base}${base.includes('?') ? '&' : '?'}${query}`);
      expect(result.body, query).not.toMatch(PHP_OUTPUT);
      expect(result.status, query).toBe(200);
      expect(result.body.trim() === '<script>window.close();</script>' || json(result).is_error === 1, query).toBe(true);
    }
    expect(tokenTotal()).toBe(before);
  });

  test('PW-S-02 the card goes to the contact in eWAY\'s record, never to the one an attacker names in the address', async ({ anonPage, stub }) => {
    const { live } = seededProcessors();
    const attacker = newContact('s02att');
    const victim = newContact('s02vic');
    const token = newCustomerToken();
    const victimCards = () => api4('PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', victim.id]] }).length;

    // The attacker's own, legitimate access code, with the victim's id in every place the address has for one.
    stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }));
    stub.respond('getCustomer', responses.customerFound(token, `civi-${attacker.id}`, { Number: '555555XXXXXX4444', ExpiryMonth: '09', ExpiryYear: '34' }));
    const result = await get(anonPage, routeUrl('savetoken', { pp_id: live, cid: victim.id, contact_id: victim.id, contactID: victim.id, AccessCode: newAccessCode('S02C') }));
    expect(result.body.trim()).toBe('<script>window.close();</script>');
    expect(victimCards()).toBe(0);
    expect(api4('PaymentToken', 'get', { select: ['token'], where: [['contact_id', '=', attacker.id]] }).map((row) => row.token)).toEqual([token]);

    // A customer reference that is not exactly "civi-<digits>" names nobody: several lines, a prefix, trailing text.
    for (const reference of [`junk\ncivi-${victim.id}`, `xcivi-${victim.id}`, `civi-${victim.id} civi-${attacker.id}`, `civi-${victim.id}x`, ` civi-${victim.id}`]) {
      stub.reset();
      stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: newCustomerToken() }));
      stub.respond('getCustomer', responses.customerFound(newCustomerToken(), reference, { Number: '555555XXXXXX4444', ExpiryMonth: '09', ExpiryYear: '34' }));
      const refused = await get(anonPage, routeUrl('savetoken', { pp_id: live, cid: victim.id, AccessCode: newAccessCode('S02D') }));
      const label = JSON.stringify(reference);
      expect(refused.body, `${label}: nothing printed`).not.toMatch(PHP_OUTPUT);
      if (isJsonText(refused.body)) {
        expect(json(refused), label).toEqual({ is_error: 1, message: 'no contact.' });
      }
      expect(victimCards(), `${label}: the victim got no card`).toBe(0);
    }
  });

  test('PW-S-02 createtoken with hostile contact_id and pp_id values is a JSON refusal and eWAY is not asked', async ({ financePage, stub }) => {
    const { live } = seededProcessors();
    const contact = newContact('s02ct');
    const before = tokenTotal();
    const hostile = ["' OR '1'='1", `${contact.id} OR 1=1`, `${contact.id}'`, '<script>window.__xss=1</script>', '-1', '0x1', `${contact.id}.5`];
    for (const value of hostile) {
      const label = JSON.stringify(value);
      const badContact = await post(financePage, routeUrl('createtoken', { contact_id: value, pp_id: live }), billingForm());
      expect(badContact.body, `contact ${label}: nothing printed`).not.toMatch(PHP_OUTPUT);
      expect(badContact.body, `contact ${label}: nothing reflected`).not.toContain('__xss');
      expect(json(badContact), `contact ${label}`).toEqual({ is_error: 1, message: 'no contact found.' });

      const badProcessor = await post(financePage, routeUrl('createtoken', { contact_id: contact.id, pp_id: value }), billingForm());
      expect(badProcessor.body, `processor ${label}: nothing printed`).not.toMatch(PHP_OUTPUT);
      expect(json(badProcessor), `processor ${label}`).toEqual({ is_error: 1, message: 'no payment processor found.' });
    }
    expect(stub.requests()).toEqual([]);
    expect(tokenTotal()).toBe(before);
  });
});
