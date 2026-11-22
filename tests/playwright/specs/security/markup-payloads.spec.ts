import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl, frontUrl } from '../../fixtures/cms';
import { api4, evaluate } from '../../fixtures/civi';
import { responses } from '../../fixtures/eway-stub';
import {
  chooseRecurrence,
  continueToHostedPage,
  fillDonation,
  newAccessCode,
  newTransactionId,
  pageUrl,
  onceOffPageId,
  recurringPageId,
  reviewContribution,
  uniqueDonor,
  type Donor,
} from '../../fixtures/contribution-page';
import { createRecurring, type RecurringRecord } from '../../fixtures/recurring-records';
import { failedListRow, openFailedList, recurringViewUrl, SCREEN_NOISE } from '../../fixtures/recurring-screens';
import { NAVIGATED_AWAY, REFUSED_STATUS, failedListIsCurrent } from '../../fixtures/security';

/**
 * PW-S-01: script and markup in the places a donor, an eWAY answer or a data import can put text, shown as text and never
 * run. Modelled on the elections extension's xss.spec.ts. Test plan: developer/TEST_PLAN.md section 5.3.
 *
 * A payload sets a counter on the window when it runs (`window.__xss`), so a case can tell a script that ran from text
 * that merely looks like one. Every page is then checked for the same four things:
 *
 *   1. the counter was never set and no dialog opened (nothing ran);
 *   2. the page has no element the payload would have made (an <img src=x>, an <svg onload>, a <script> that mentions the
 *      counter, a link to javascript:);
 *   3. where the page shows the text at all, it shows the payload itself, as text;
 *   4. no PHP output.
 *
 * Text goes in three ways. Through the donor's form, where CiviCRM may refuse a payload outright (its own filter against
 * scripting in text fields: an acceptable answer, checked for the same four things). Through the data itself (created with
 * the API, which skips the form's filter, as an import or another extension would), shown on the staff and donor screens
 * that list it. And from eWAY: the message of a declined payment and the masked number of a saved card are eWAY's words,
 * and are shown to donors and staff.
 */

/** Each payload sets the counter, so a run is visible whichever way the browser reads the text. */
const COUNT = '(window.__xss=(window.__xss||0)+1)';
const PAYLOADS = {
  img: `<img src=x onerror="${COUNT}">`,
  script: `"><script>${COUNT}</script>`,
  svg: `<svg onload="${COUNT}">`,
  link: `javascript:${COUNT}`,
};

/** What a payload would have made if the page had treated it as markup. */
async function expectNothingInjected(page: Page, where: string): Promise<void> {
  expect(await page.evaluate(() => (window as any).__xss), `${where}: a payload ran`).toBeUndefined();
  const injected = await page.evaluate((count) => {
    const found: string[] = [];
    document.querySelectorAll('img[src="x"], svg[onload], [onerror*="__xss"], [onload*="__xss"]').forEach((el) => found.push(el.outerHTML.slice(0, 80)));
    // A script made by the payload is the payload's own code. (CiviCRM's page data holds the text of fields inside a JSON string
    // in a script of its own, which is safe and not what this looks for.)
    document.querySelectorAll('script').forEach((el) => {
      if ((el.textContent || '').trim() === count) {
        found.push(`script: ${(el.textContent || '').slice(0, 60)}`);
      }
    });
    document.querySelectorAll('a[href^="javascript:"]').forEach((el) => {
      if ((el.getAttribute('href') || '').includes('__xss')) {
        found.push(`link: ${el.outerHTML.slice(0, 80)}`);
      }
    });
    return found;
  }, COUNT);
  expect(injected, `${where}: elements the payload made`).toEqual([]);
}

/** The text as written, or with its angle brackets as the entities core's profile defaults turn them into. */
function asTextOrEntities(text: string): RegExp {
  const escape = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const encoded = text.replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return new RegExp(`^(${escape(text)}|${escape(encoded)})$`);
}

/** Fail the test if any dialog (alert, confirm, prompt) opens on the page. */
function failOnDialog(page: Page): string[] {
  const dialogs: string[] = [];
  page.on('dialog', async (dialog) => {
    dialogs.push(dialog.message());
    await dialog.dismiss();
  });
  return dialogs;
}

/** The page CiviCRM's intrusion filter (CRM_Core_IDS) shows when it refuses a request. */
const IDS_REFUSAL = /Your activity is a bit suspicious/;

test.describe('through the donor\'s form', () => {
  test.use({ allowedProblems: [new RegExp(`${REFUSED_STATUS.source}|${NAVIGATED_AWAY.source}`)] });

  for (const [name, payload] of Object.entries(PAYLOADS)) {
    test(`PW-S-01 a ${name} payload in the billing name and address is refused or shown as text on the confirm and thank-you steps`, async ({ anonPage, stub }) => {
      test.setTimeout(120_000);
      const dialogs = failOnDialog(anonPage);
      const donor: Donor = uniqueDonor(`s01${name}`, { firstName: `A${payload}`, lastName: `B${payload}`, street: `1 ${payload}`, city: `C${payload}` });
      const accessCode = newAccessCode('S01');
      stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));
      stub.respond('getTransaction', responses.transactionFound({ TransactionID: newTransactionId(), TransactionStatus: true, TotalAmount: 2500, TokenCustomerID: '' }));

      await anonPage.goto(pageUrl(onceOffPageId()));
      await fillDonation(anonPage, 25, donor);
      await anonPage.locator('#_qf_Main_upload-bottom').click();
      await anonPage.waitForLoadState('load');

      const confirm = anonPage.locator('#_qf_Confirm_next-bottom');
      if (await confirm.count() === 0 && await anonPage.getByText(IDS_REFUSAL).count() > 0) {
        // CiviCRM's intrusion filter (PHPIDS) stopped the request before the form saw it. Stock CiviCRM does this for
        // payloads like these; the Agileware pipeline image has the filter switched off, so the form handles them.
        await expectNothingInjected(anonPage, `${name}: the filter's refusal page`);
        test.info().annotations.push({ type: 'core', description: `${name}: refused by core's intrusion filter` });
      } else if (await confirm.count() === 0) {
        // CiviCRM's own filter refused the payload: the form is shown again, with the message and the payload as the value.
        await expectNothingInjected(anonPage, `${name}: the form after a refusal`);
        await expect(anonPage.locator('#billing_first_name')).toBeVisible();
        test.info().annotations.push({ type: 'core', description: `${name}: refused by core's filter` });
      } else {
        await expectNothingInjected(anonPage, `${name}: confirm step`);
        // The confirm step shows the name and address as text.
        await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText(payload);
        await continueToHostedPage(anonPage);
        await anonPage.locator('#pay').click();
        await expect(anonPage.locator('body')).toContainText('processed successfully');
        await expectNothingInjected(anonPage, `${name}: thank-you step`);
        await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText(payload);
        test.info().annotations.push({ type: 'core', description: `${name}: accepted and shown as text` });
      }
      expect(dialogs, `${name}: dialogs`).toEqual([]);
    });
  }

  test('PW-S-01 a payload in the recurring page\'s interval and installments is refused or ignored', async ({ anonPage, stub }) => {
    const dialogs = failOnDialog(anonPage);
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('S01R')));
    await anonPage.goto(pageUrl(recurringPageId()));
    await fillDonation(anonPage, 25, uniqueDonor('s01recur'));
    await chooseRecurrence(anonPage, { interval: 1, unit: 'month' });
    // Numeric boxes take whatever a script puts in them.
    await anonPage.locator('#frequency_interval').evaluate((el, value) => { (el as HTMLInputElement).type = 'text'; (el as HTMLInputElement).value = value; }, PAYLOADS.img);
    await anonPage.locator('#installments').evaluate((el, value) => { (el as HTMLInputElement).type = 'text'; (el as HTMLInputElement).value = value; }, PAYLOADS.svg);
    await anonPage.locator('#_qf_Main_upload-bottom').click();
    await anonPage.waitForLoadState('load');
    await expectNothingInjected(anonPage, 'recurring page after a bad interval');
    // Either the form says what is wrong, or (it cannot) the confirm step; never the payload as markup.
    await expect(anonPage.locator('body')).toContainText(/must be|valid|integer|number|required|frequency|installments/i);
    expect(dialogs).toEqual([]);
  });
});

/**
 * A donor, their address, a Completed contribution and a recurring record, all made with the API so the form's filter is
 * skipped. The text is in the contact's name, the billing address, the contribution's source and the recurring record's
 * cancel reason, which are what the staff and donor screens print.
 */
function recordWithPayloads(label: string, status: 'Failed' | 'In Progress' = 'In Progress'): RecurringRecord & { payload: string } {
  const payload = PAYLOADS.img;
  const record = createRecurring({ label, status, failureCount: status === 'Failed' ? 3 : 0, retryDate: status === 'Failed' ? '-1 day' : undefined, extraCards: [{ masked: '5105<b>x' }] });
  api4('Contact', 'update', { where: [['id', '=', record.contactId]], values: { first_name: `A${PAYLOADS.svg}`, last_name: `B${payload}` } });
  api4('Address', 'update', {
    where: [['contact_id', '=', record.contactId], ['is_billing', '=', true]],
    values: { street_address: `1 ${PAYLOADS.script}`, city: `C${payload}` },
  });
  api4('Contribution', 'update', { where: [['id', '=', record.contributionId]], values: { source: `S${payload}` } });
  api4('ContributionRecur', 'update', { where: [['id', '=', record.recurId]], values: { cancel_reason: `R${PAYLOADS.script}` } });
  return { ...record, payload };
}

test.describe('through the data, on the staff screens', () => {
  // The donor's card list is refused to a donor (HTTP 403), which the browser logs.
  test.use({ allowedProblems: [new RegExp(`${SCREEN_NOISE.source}|${NAVIGATED_AWAY.source}|status of 403|status: 403|HTTP 403 from`)] });

  test('PW-S-01 the Failed list, the contact, the recurring and the contribution screens show the payloads as text and run nothing', async ({ financePage, adminPage }) => {
    test.setTimeout(120_000);
    const dialogs = failOnDialog(financePage);
    const adminDialogs = failOnDialog(adminPage);
    const record = recordWithPayloads('s01staff', 'Failed');

    // The list is the same for whoever may open it. Finance staff can on a current site; a development site that has not been
    // upgraded since FR-4 still has the older saved search, which only an administrator can load.
    const listPage = failedListIsCurrent() ? financePage : adminPage;
    await openFailedList(listPage);
    const row = failedListRow(listPage, record.recurId);
    await expect(row).toBeVisible();
    await expectNothingInjected(listPage, 'Failed list');
    // The donor's name is in the row, as text.
    await expect(row).toContainText(`B${PAYLOADS.img}`);

    await financePage.goto(adminUrl('contact/view', { reset: 1, cid: record.contactId, selectedChild: 'contribute' }));
    await expect(financePage.locator('.crm-contact-page')).toBeVisible();
    await financePage.waitForLoadState('networkidle');
    await expectNothingInjected(financePage, 'contact page');

    await financePage.getByRole('tab', { name: /Recurring Contributions/ }).click();
    await financePage.waitForLoadState('networkidle');
    await expectNothingInjected(financePage, 'contact page, Recurring Contributions tab');

    await financePage.goto(adminUrl('contact/view/contribution', { reset: 1, action: 'view', id: record.contributionId!, cid: record.contactId, context: 'contribution' }));
    await financePage.waitForLoadState('networkidle');
    await expectNothingInjected(financePage, 'contribution page');
    expect(dialogs).toEqual([]);
    expect(adminDialogs).toEqual([]);
  });

  // The payload's image is a broken address, so the page also logs a 404 for it (and on CiviCRM 6.19.5, where the page still runs
  // the script, the browser reports that as a console error).
  test.describe('a page that runs the payload', () => {
    test.use({ allowedProblems: [new RegExp(`${SCREEN_NOISE.source}|${NAVIGATED_AWAY.source}|status of 404`)] });

    // KNOWN DEFECT IN CORE (upstream-issues/14): core's Recurring Contribution page prints the record's cancel reason without
    // escaping (templates/CRM/Contribute/Page/ContributionRecur.tpl) and runs a script in a contribution's source. This case
    // asserts the right behaviour and is expected to fail until core is fixed.
    test('PW-S-01 core\'s Recurring Contribution page shows a cancel reason and a contribution source with markup in them as text', async ({ adminPage }) => {
      test.fail(true, 'core: the recurring contribution page does not escape the cancel reason or a contribution\'s source (upstream-issues/14)');
      const dialogs = failOnDialog(adminPage);
      const record = recordWithPayloads('s01recurview');
      await adminPage.goto(recurringViewUrl(record));
      await adminPage.waitForLoadState('networkidle');
      await expectNothingInjected(adminPage, 'recurring contribution page');
      expect(dialogs).toEqual([]);
    });
  });

  test('PW-S-01 the Update Billing form fills the payloads into its boxes as values, for staff and for a donor with a checksum link', async ({ adminPage, anonPage }) => {
    const dialogs = failOnDialog(adminPage);
    const donorDialogs = failOnDialog(anonPage);
    const record = recordWithPayloads('s01billing');
    const checksum = evaluate(`echo CRM_Contact_BAO_Contact_Utils::generateChecksum(${Number(record.contactId)});`);

    await adminPage.goto(adminUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, context: 'contribution' }));
    await expect(adminPage.locator('#UpdateBilling')).toBeVisible();
    // The boxes hold the text, either as it was written or (core encodes what it loads into a profile field, so a "<" can come
    // back as "&lt;") with its angle brackets as entities. Never as markup.
    await expect(adminPage.locator('#billing_city-5')).toHaveValue(asTextOrEntities(`C${record.payload}`));
    await expect(adminPage.locator('#billing_street_address-5')).toHaveValue(asTextOrEntities(`1 ${PAYLOADS.script}`));
    await expect(adminPage.locator('#billing_last_name')).toHaveValue(asTextOrEntities(`B${record.payload}`));
    await expectNothingInjected(adminPage, 'Update Billing form, staff');

    await anonPage.goto(frontUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, cs: checksum, context: 'contribution' }));
    await expect(anonPage.locator('#UpdateBilling')).toBeVisible();
    await expect(anonPage.locator('#billing_city-5')).toHaveValue(asTextOrEntities(`C${record.payload}`));
    await expectNothingInjected(anonPage, 'Update Billing form, donor');
    expect(dialogs).toEqual([]);
    expect(donorDialogs).toEqual([]);
  });

  test('PW-S-01 a card whose masked number holds markup is listed as text in the Stored Credit Card box', async ({ adminPage }) => {
    const record = recordWithPayloads('s01card');
    await adminPage.goto(adminUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, context: 'contribution' }));
    await adminPage.waitForFunction(() => document.querySelectorAll('#contact_payment_token option').length >= 2);
    const options = (await adminPage.locator('#contact_payment_token option').allInnerTexts()).map((text) => text.trim());
    // The box shows the last four characters of the masked number: the card's "<b>x", then its month and year.
    expect(options.some((text) => text.startsWith('<b>x - ')), `the box lists the markup as text: ${JSON.stringify(options)}`).toBe(true);
    expect(await adminPage.locator('#contact_payment_token b').count(), 'no element made by the markup').toBe(0);
  });
});

test.describe('words from eWAY', () => {
  test.use({ allowedProblems: [new RegExp(`${REFUSED_STATUS.source}|${NAVIGATED_AWAY.source}`)] });

  test('PW-S-01 a decline message with markup in it is shown to the donor as text', async ({ anonPage, stub }) => {
    test.setTimeout(120_000);
    const dialogs = failOnDialog(anonPage);
    const message = PAYLOADS.img;
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('S01D')));
    stub.respond('getTransaction', responses.transactionFound({ TransactionID: newTransactionId(), TransactionStatus: false, ResponseCode: '01', ResponseMessage: message, TokenCustomerID: '' }));

    await anonPage.goto(pageUrl(onceOffPageId()));
    await fillDonation(anonPage, 10, uniqueDonor('s01decl'));
    await reviewContribution(anonPage);
    await continueToHostedPage(anonPage);
    await anonPage.locator('#pay').click();

    await expect(anonPage.locator('body')).toContainText(/declined/i);
    await expectNothingInjected(anonPage, 'declined payment');
    await expect(anonPage.locator('body')).toContainText(message);
    expect(dialogs).toEqual([]);
  });

  test('PW-S-01 eWAY error text with markup in it is shown to staff as text when the hosted page cannot be set up', async ({ adminPage, stub }) => {
    const dialogs = failOnDialog(adminPage);
    const record = createRecurring({ label: 's01err' });
    // eWAY refuses with an error whose text is the payload.
    stub.respond('postAccessCodeShared', { AccessCode: '', SharedPaymentUrl: '', Errors: PAYLOADS.img }, { repeat: true });

    await adminPage.goto(adminUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, context: 'contribution' }));
    await expect(adminPage.locator('#UpdateBilling')).toBeVisible();
    await adminPage.waitForFunction(() => /\d{4} - \d\d\/\d{4}/.test(document.querySelector('#contact_payment_token option')?.textContent || ''));
    await adminPage.locator('#add_credit_card').click();
    await expect(adminPage.locator('#crm-notification-container, .crm-error').first()).toBeVisible();
    await expectNothingInjected(adminPage, 'hosted page refused');
    expect(dialogs).toEqual([]);
  });
});
