import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl, frontUrl } from '../../fixtures/cms';
import { api4, evaluate } from '../../fixtures/civi';
import { browserApi4 } from '../../fixtures/backend';
import { responses } from '../../fixtures/eway-stub';
import {
  continueToHostedPage,
  fillDonation,
  newAccessCode,
  onceOffPageId,
  pageUrl,
  recurringPageId,
  reviewContribution,
  transactionApproved,
  uniqueDonor,
  chooseRecurrence,
} from '../../fixtures/contribution-page';
import { eventUrl, fillPaidRegistration, paidEventId, pressReview } from '../../fixtures/event-registration';
import { createRecurring } from '../../fixtures/recurring-records';
import { failedListRow, openFailedList, recurringViewUrl, SCREEN_NOISE } from '../../fixtures/recurring-screens';
import { NAVIGATED_AWAY, REFUSED_STATUS, XHR, billingForm, failedListIsCurrent, get, newContact, post, routeUrl, seededProcessors, visibleText } from '../../fixtures/security';
import testData from '../../fixtures/test-data.json';

/**
 * PW-S-04: the processor's API key and password never leave the server for anyone who may not read them. Test plan:
 * developer/TEST_PLAN.md section 5.3.
 *
 * The seeded processors hold made-up credentials, so for these cases the live processor and its test-mode twin are given
 * credentials that are unique to the run, and put back afterwards. Then every page and every response the donor and the
 * staff personas are sent while they use the extension is searched for those two strings: the contribution pages (once-off
 * and recurring), the event registration, the return and token routes, the saved-card forms, the Failed list, the contact
 * and recurring screens. The administrator is left out of the page scans because the processor's own admin form shows the
 * password to administrators by design (core's form: see the last case, which pins what the list and the form show).
 *
 * The server's CiviCRM log is searched too: nothing the extension logs may carry the credentials.
 */

const EWAY = testData.processors.live;

/** Made up for the run, so a match can only be this run's. */
const SECRET_KEY = `pw-key-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
const SECRET_PASSWORD = `pw-secret-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
const SECRETS = [SECRET_KEY, SECRET_PASSWORD];

/** What the processor held before the run, to put back. */
let original: { id: number; user_name: string | null; password: string | null; signature: string | null }[] = [];

test.beforeAll(() => {
  original = api4('PaymentProcessor', 'get', {
    select: ['id', 'user_name', 'password', 'signature'],
    where: [['name', '=', EWAY], ['is_test', 'IN', [true, false]]],
  });
  expect(original.length, 'the seeded live and test-mode processor rows').toBeGreaterThanOrEqual(2);
  for (const row of original) {
    api4('PaymentProcessor', 'update', { where: [['id', '=', row.id]], values: { user_name: SECRET_KEY, password: SECRET_PASSWORD } });
  }
});

test.afterAll(() => {
  for (const row of original) {
    api4('PaymentProcessor', 'update', { where: [['id', '=', row.id]], values: { user_name: row.user_name, password: row.password, signature: row.signature } });
  }
});

/** Everything a page was sent: each response from the site, and the page as the browser ended up with it. */
class Capture {
  readonly seen: { where: string; text: string }[] = [];
  private readonly pending: Promise<void>[] = [];

  /** Start recording what a page receives. */
  watch(page: Page): void {
    page.on('response', (response) => {
      const type = response.headers()['content-type'] || '';
      if (!/text|json|javascript|xml|html/i.test(type)) {
        return;
      }
      this.pending.push(response.text().then((text) => { this.seen.push({ where: response.url(), text }); }, () => undefined));
    });
  }

  /** Add the page as it is now (what scripts built included). */
  async snapshot(page: Page, name: string): Promise<void> {
    this.seen.push({ where: `${name} (the page as shown)`, text: await page.content() });
    this.seen.push({ where: `${name} (the visible text)`, text: await page.locator('body').innerText().catch(() => '') });
  }

  /** Add an answer to a plain request. */
  add(where: string, text: string): void {
    this.seen.push({ where, text });
  }

  /** Fail naming every place a secret turned up. */
  async expectNoSecrets(label: string): Promise<void> {
    await Promise.all(this.pending);
    const leaks: string[] = [];
    for (const { where, text } of this.seen) {
      for (const secret of SECRETS) {
        if (text.includes(secret) || text.includes(encodeURIComponent(secret)) || text.includes(Buffer.from(secret).toString('base64'))) {
          leaks.push(`${where}: carries ${secret === SECRET_KEY ? 'the API key' : 'the API password'}`);
        }
      }
    }
    expect(leaks, `${label}: ${this.seen.length} responses and pages searched`).toEqual([]);
    expect(this.seen.length, `${label}: something was searched`).toBeGreaterThan(0);
  }
}

test.describe('what donors and staff are sent', () => {
  // The donor's card list is refused to a donor (HTTP 403), which the browser logs.
  // Refusals: the donor's card list is refused to a donor, and the processor screens are refused to staff without administrator
  // rights (HTTP 403 here, 500 on a fresh CI site), plus core's own warning about setting the status after the page began
  // (upstream-issues/07) and requests cancelled when the test moves to the next page.
  test.use({ allowedProblems: [new RegExp(`${SCREEN_NOISE.source}|${NAVIGATED_AWAY.source}|${REFUSED_STATUS.source}|Cannot set response code`)] });

  test('PW-S-04 a donor\'s journeys carry neither the API key nor the password: the contribution pages, the confirm step, the thank-you step and the event registration', async ({ anonPage, stub }) => {
    test.setTimeout(150_000);
    const capture = new Capture();
    capture.watch(anonPage);

    // A once-off payment from the first step to the thank-you step.
    const donor = uniqueDonor('s04once');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('S04')));
    stub.respond('getTransaction', transactionApproved(25));
    await anonPage.goto(pageUrl(onceOffPageId()));
    await capture.snapshot(anonPage, 'once-off page');
    await fillDonation(anonPage, 25, donor);
    await reviewContribution(anonPage);
    await capture.snapshot(anonPage, 'confirm step');
    await continueToHostedPage(anonPage);
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText('processed successfully');
    await capture.snapshot(anonPage, 'thank-you step');

    // The recurring page, as far as its confirm step.
    const recurring = uniqueDonor('s04recur');
    await anonPage.goto(pageUrl(recurringPageId()));
    await capture.snapshot(anonPage, 'recurring page');
    await fillDonation(anonPage, 25, recurring);
    await chooseRecurrence(anonPage, { interval: 1, unit: 'month' });
    await reviewContribution(anonPage);
    await capture.snapshot(anonPage, 'recurring confirm step');

    // The paid event's registration, as far as its confirm step.
    await anonPage.goto(eventUrl(paidEventId()));
    await capture.snapshot(anonPage, 'event registration');
    await fillPaidRegistration(anonPage, uniqueDonor('s04event'));
    await pressReview(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').waitFor();
    await capture.snapshot(anonPage, 'event confirm step');

    await capture.expectNoSecrets('the donor\'s journeys');
  });

  test('PW-S-04 the extension\'s public routes carry no credentials, in an answer or in a refusal', async ({ anonPage, stub }) => {
    const { live, test: testMode, dummy } = seededProcessors();
    const capture = new Capture();
    const contact = newContact('s04route');
    // Answers that make the extension talk to eWAY, so its error paths run too.
    stub.respond('getTransaction', { Transactions: [], Errors: 'V6110' }, { repeat: true });
    stub.respond('getCustomer', { Customers: [], Errors: null }, { repeat: true });

    const requests: [string, () => Promise<{ status: number; body: string }>][] = [
      ['verifypayment, nothing', () => get(anonPage, routeUrl('verifypayment'))],
      ['verifypayment, bogus', () => get(anonPage, routeUrl('verifypayment', { contributionInvoiceID: 'x', paymentProcessorID: live, component: 'event', AccessCode: 'x' }))],
      ['verifypayment, test processor', () => get(anonPage, routeUrl('verifypayment', { contributionInvoiceID: 'x', paymentProcessorID: testMode, component: 'event', AccessCode: 'x' }))],
      ['verifypayment, Dummy processor', () => get(anonPage, routeUrl('verifypayment', { contributionInvoiceID: 'x', paymentProcessorID: dummy, component: 'event', AccessCode: 'x' }))],
      ['savetoken, no processor', () => get(anonPage, routeUrl('savetoken'))],
      ['savetoken, no transaction', () => get(anonPage, routeUrl('savetoken', { pp_id: live, AccessCode: 'x' }))],
      ['savetoken, test processor', () => get(anonPage, routeUrl('savetoken', { pp_id: testMode, AccessCode: 'x' }))],
      ['createtoken, refused', () => post(anonPage, routeUrl('createtoken', { contact_id: contact.id, pp_id: live }), billingForm())],
      ['settings, refused', () => get(anonPage, routeUrl('settings'))],
    ];
    for (const [name, request] of requests) {
      const result = await request();
      capture.add(`${name} (status ${result.status})`, result.body);
    }
    await capture.expectNoSecrets('the public routes');
  });

  test('PW-S-04 staff without administrator rights are sent no credentials: the saved-card forms, the Failed list, the contact and recurring screens and the token routes', async ({ financePage, tokenviewerPage, limitedPage, stub }) => {
    test.setTimeout(150_000);
    const { live } = seededProcessors();
    const record = createRecurring({ label: 's04staff', status: 'Failed', failureCount: 3, retryDate: '-1 day', extraCards: [{}] });
    const capture = new Capture();
    for (const page of [financePage, tokenviewerPage, limitedPage]) {
      capture.watch(page);
    }

    // Finance: the Failed list, the saved-card form of the contact (it needs "view all contacts") and the recurring screens.
    if (failedListIsCurrent()) {
      await openFailedList(financePage);
      await expect(failedListRow(financePage, record.recurId)).toBeVisible();
      await capture.snapshot(financePage, 'Failed list');
    } else {
      test.info().annotations.push({ type: 'site', description: 'the Failed list is skipped: this site still has the saved search from before FR-4 (no upgrade or reconcile since), which only an administrator can load' });
    }
    await financePage.goto(adminUrl('contact/view', { reset: 1, cid: record.contactId, selectedChild: 'contribute' }));
    await expect(financePage.locator('.crm-contact-page')).toBeVisible();
    await capture.snapshot(financePage, 'contact, Contributions tab');
    await financePage.goto(recurringViewUrl(record));
    await capture.snapshot(financePage, 'recurring contribution');
    await financePage.goto(adminUrl('contact/view/contribution', { reset: 1, action: 'add', cid: record.contactId, context: 'contribution', mode: 'live' }));
    await capture.snapshot(financePage, 'credit card form');

    // The token routes as staff use them.
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('S04F')), { repeat: true });
    const created = await post(financePage, routeUrl('createtoken', { contact_id: record.contactId, pp_id: live }), billingForm());
    capture.add('createtoken as finance', created.body);
    capture.add('createtoken with an unknown processor', (await post(financePage, routeUrl('createtoken', { contact_id: record.contactId, pp_id: 99999999 }), billingForm())).body);
    capture.add('savetoken as finance', (await get(financePage, routeUrl('savetoken', { pp_id: live }), XHR)).body);

    // The viewer and the limited user: the same screens they may open, and the card API answers.
    for (const [name, page] of [['tokenviewer', tokenviewerPage], ['limited', limitedPage]] as const) {
      await page.goto(adminUrl('dashboard', { reset: 1 }));
      await capture.snapshot(page, `${name}: dashboard`);
      await page.goto(adminUrl('admin/paymentProcessor', { reset: 1 }));
      await capture.snapshot(page, `${name}: processor list`);
    }
    await tokenviewerPage.goto(adminUrl('dashboard', { reset: 1 }));
    await tokenviewerPage.waitForFunction(() => typeof (window as any).CRM?.api4 === 'function');
    const cards = await browserApi4(tokenviewerPage, 'PaymentToken', 'get', { where: [['contact_id', '=', record.contactId]] });
    capture.add('PaymentToken.get as tokenviewer', JSON.stringify(cards));
    expect(cards.ok, 'the viewer reads cards').toBe(true);

    await capture.expectNoSecrets('staff without administrator rights');
  });

  test('PW-S-04 a donor with a checksum link is sent the Update Billing form without credentials', async ({ anonPage }) => {
    const record = createRecurring({ label: 's04donor' });
    const checksum = evaluate(`echo CRM_Contact_BAO_Contact_Utils::generateChecksum(${Number(record.contactId)});`);
    const capture = new Capture();
    capture.watch(anonPage);
    await anonPage.goto(frontUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, cs: checksum, context: 'contribution' }));
    await expect(anonPage.locator('#UpdateBilling')).toBeVisible();
    await capture.snapshot(anonPage, 'update billing form');
    await capture.expectNoSecrets('the donor\'s billing form');
  });
});

test.describe('who can read the processor', () => {
  // Refusals are on purpose here; the administrator's test also moves between pages with the API still being asked.
  test.use({ allowedProblems: [new RegExp(`${REFUSED_STATUS.source}|${NAVIGATED_AWAY.source}`)] });

  test('PW-S-04 the processor and its credentials are refused to a visitor and to every persona but the administrator, through every API and screen', async ({ anonPage, financePage, tokenviewerPage, limitedPage, adminPage }) => {
    const { live } = seededProcessors();
    const capture = new Capture();

    // A visitor.
    for (const [entity, action, params] of [
      ['PaymentProcessor', 'get', { select: ['id', 'name', 'user_name', 'password'] }],
      ['PaymentProcessor', 'get', { where: [['id', '=', live]] }],
    ] as const) {
      const result = await post(anonPage, frontUrl(`ajax/api4/${entity}/${action}`), { params: JSON.stringify(params) }, XHR);
      expect(result.status).toBeGreaterThanOrEqual(400);
      capture.add(`visitor: APIv4 ${entity}.${action}`, result.body);
    }
    const visitorV3 = await post(anonPage, frontUrl('ajax/rest'), { entity: 'PaymentProcessor', action: 'get', json: JSON.stringify({ id: live }) }, XHR);
    expect(visitorV3.status).toBeGreaterThanOrEqual(400);
    capture.add('visitor: APIv3 PaymentProcessor.get', visitorV3.body);

    for (const [name, page] of [['finance', financePage], ['tokenviewer', tokenviewerPage], ['limited', limitedPage]] as const) {
      await page.goto(adminUrl('dashboard', { reset: 1 }));
      await page.waitForFunction(() => typeof (window as any).CRM?.api4 === 'function');
      for (const params of [
        { select: ['id', 'name', 'user_name', 'password', 'signature'], where: [['id', '=', live]] },
        { select: ['id', 'name'] },
        {},
      ]) {
        const result = await browserApi4(page, 'PaymentProcessor', 'get', params);
        expect(result, `${name}: APIv4 PaymentProcessor.get ${JSON.stringify(params)}`).toEqual({ ok: false, status: 403 });
      }
      // APIv3 answers a refusal with a JSON error and status 200.
      const v3 = await get(page, frontUrl('ajax/rest', { entity: 'PaymentProcessor', action: 'get', json: JSON.stringify({ id: live }) }), XHR);
      expect(JSON.parse(v3.body), `${name}: APIv3`).toMatchObject({ is_error: 1, error_code: 'unauthorized' });
      capture.add(`${name}: APIv3 PaymentProcessor.get`, v3.body);

      // The processor screens.
      for (const path of ['admin/paymentProcessor', `admin/paymentProcessor/edit`]) {
        const screen = await get(page, adminUrl(path, { reset: 1, action: 'update', id: live }));
        expect(screen.status, `${name}: ${path}`).toBeGreaterThanOrEqual(400);
        expect(visibleText(screen.body), `${name}: ${path}: no processor named`).not.toContain(EWAY);
        capture.add(`${name}: ${path}`, screen.body);
      }
    }
    await capture.expectNoSecrets('the refusals');
    expect(adminPage, 'the administrator persona exists').toBeTruthy();
  });

  test('PW-S-04 the administrator sees the credentials only on the processor\'s own edit form, not on the list, the settings page or the contact screens', async ({ adminPage }) => {
    const { live } = seededProcessors();
    const capture = new Capture();
    capture.watch(adminPage);

    // The list of processors, the extension's settings page, and a donor's contact and recurring screens.
    const record = createRecurring({ label: 's04admin' });
    for (const [name, url] of [
      ['processor list', adminUrl('admin/paymentProcessor', { reset: 1 })],
      ['extension settings', adminUrl('ewayrecurring/settings', { reset: 1 })],
      ['contact', adminUrl('contact/view', { reset: 1, cid: record.contactId, selectedChild: 'contribute' })],
      ['recurring contribution', recurringViewUrl(record)],
      ['update billing form', adminUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, context: 'contribution' })],
    ] as const) {
      await adminPage.goto(url);
      await adminPage.waitForLoadState('networkidle');
      await capture.snapshot(adminPage, name);
    }
    await capture.expectNoSecrets('the administrator\'s screens other than the processor form');

    // The edit form is where an administrator keeps the credentials, so it shows them: in an input, as a password box if core
    // makes one. Recorded rather than required: it is core's form.
    await adminPage.goto(adminUrl('admin/paymentProcessor/edit', { reset: 1, action: 'update', id: live }));
    const field = adminPage.locator('input[name="password"]').first();
    await expect(field).toBeVisible();
    test.info().annotations.push({ type: 'core', description: `the processor form's password box is type "${await field.getAttribute('type')}"` });
  });
});

test.describe('the server\'s logs', () => {
  test('PW-S-04 the CiviCRM log written while the extension ran carries neither credential', async () => {
    // The log directory of the site, searched for the two strings. Everything above exercised the extension's error paths.
    const found = evaluate(`
      $dir = CRM_Core_Config::singleton()->configAndLogDir;
      $hits = [];
      foreach (glob($dir . '*.log*') as $file) {
        $text = @file_get_contents($file);
        foreach (${JSON.stringify(SECRETS).replace(/"/g, "'")} as $secret) {
          if ($text !== FALSE && strpos($text, $secret) !== FALSE) {
            $hits[] = basename($file) . ' holds ' . substr($secret, 0, 8) . '...';
          }
        }
      }
      echo json_encode($hits);
    `);
    expect(JSON.parse(found.slice(found.indexOf('['), found.lastIndexOf(']') + 1))).toEqual([]);
  });
});
