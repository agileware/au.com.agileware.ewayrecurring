import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl, frontUrl } from '../../fixtures/cms';
import { api4, evaluate } from '../../fixtures/civi';
import { responses } from '../../fixtures/eway-stub';
import { newTransactionId } from '../../fixtures/contribution-page';
import { mailSubjectsFor } from '../../fixtures/queue-records';
import {
  activitiesOf,
  billingAddressOf,
  cardsOf,
  createRecurring,
  recurRow,
  type RecurringRecord,
} from '../../fixtures/recurring-records';
import { NAVIGATED_AWAY, tokenTotal, seededProcessors, withoutCoreNoise } from '../../fixtures/security';
import testData from '../../fixtures/test-data.json';

/**
 * PW-S-05: the card id on the forms that take a saved card is tampered with in the browser, as a user of the page could.
 * Test plan: developer/TEST_PLAN.md section 5.3.
 *
 * Both forms offer a list of the contact's own, unexpired cards for the processor, and trust nothing else on the server
 * (DEF-14: the id used to be looked up by itself, so another donor's card could be put on a recurring record and charged by
 * the cron job; PU-N6 to PU-N8 and PU-B cover the same rules without a browser):
 *
 *   the Update Billing Details form (civicrm/contribute/updatebilling), which staff use from the contact's recurring tab and
 *   a donor can open with a checksum link;
 *   the back-office credit card form, which charges the chosen card.
 *
 * Each case opens the real form, puts a forged option into the Stored Credit Card box (the box is a plain select, so
 * anyone can change what it posts) and submits it. The submit goes straight to the server, without the browser-side
 * checks, as a script or a browser with scripting off would send it. Every case ends with the same questions: did the
 * recurring record, the cards, the billing address, the activity log, the donor's mail and eWAY stay as they were?
 *
 * A refused save is CiviCRM's error page (HTTP 500 on a fresh site, with the message) or, for the form's own checks, the
 * form again with the message; the cases accept either and read the message.
 */

/** Core prints this when an error page is built after the CMS has begun its page; it is core's, not the extension's. */
const HEADERS_SENT = /http_response_code\(\): Cannot set response code/;
/** What the browser reports for a refusal (HTTP 403 or 500) and the CMS's admin script noise on an error page. */
const REFUSED = /status of (403|404|500)|status: 403|HTTP (403|500) from|ViewTransition|Undefined array key "is_required"|values is not iterable/;
const NOISE = new RegExp(`${HEADERS_SENT.source}|${REFUSED.source}|${NAVIGATED_AWAY.source}`);

test.use({ allowedProblems: [NOISE] });

/** The form's address for a recurring record, as the staff link on the contact's tab builds it. */
function billingUrl(record: RecurringRecord): string {
  return adminUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, context: 'contribution' });
}

/** The donor's own address for the same form: the contact id and a checksum. */
function checksumUrl(record: RecurringRecord): string {
  const checksum = evaluate(`echo CRM_Contact_BAO_Contact_Utils::generateChecksum(${Number(record.contactId)});`);
  return frontUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, cs: checksum, context: 'contribution' });
}

/** The text of the page. */
async function pageText(page: Page): Promise<string> {
  const text = await page.locator('body').innerText().catch(() => '');
  return text.replace(/\s+/g, ' ');
}

/** What the recurring record, its cards and its donor looked like, so a case can show that a refused save left them alone. */
function snapshot(record: RecurringRecord) {
  return {
    recur: recurRow(record.recurId),
    cards: cardsOf(record.contactId).map((card) => ({ id: card.id, token: card.token, contact: card.contact_id, expiry: card.expiry_date })),
    address: billingAddressOf(record.contactId)?.street_address,
    activities: activitiesOf(record.contactId, 'Update Recurring Contribution Billing Details').length,
    mail: mailSubjectsFor(record.donor.email),
  };
}

/**
 * Open the form at `url` and wait until it is ready: the saved-card list has been drawn (the script replaces the list when
 * it arrives, which would drop an option added earlier) and the page has settled. Then change the street, so a save that
 * went through half way would show in the address.
 */
async function openForm(page: Page, url: string, { cardsExpected }: { cardsExpected: boolean }): Promise<void> {
  await page.goto(url);
  await expect(page.locator('#UpdateBilling')).toBeVisible();
  if (cardsExpected) {
    await page.waitForFunction(() => /\d{4} - \d\d\/\d{4}/.test(document.querySelector('#contact_payment_token option')?.textContent || ''));
  } else {
    await expect(page.locator('#contact_payment_token option')).toHaveText(['No cards found.']);
  }
  await page.waitForLoadState('networkidle');
  await page.locator('#billing_street_address-5').fill('99 Forged Road');
}

/**
 * Put a forged option into the Stored Credit Card box and press Save, in one step so that nothing redraws the list in
 * between (the box is a plain select: whoever uses the page can change what it posts). The form's own submit() does not run
 * the browser-side checks, as a script or a browser with scripting off would send it. The save button's name and value go
 * with it, as they do when it is pressed. Pass null to post no card at all. Resolves when the answer has loaded.
 */
async function forgeAndSubmit(page: Page, forged: string | null): Promise<void> {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.evaluate((value) => {
      const select = document.querySelector('#contact_payment_token') as HTMLSelectElement;
      if (value === null) {
        select.innerHTML = '';
      } else {
        const option = document.createElement('option');
        option.value = value;
        option.text = 'A card I was not offered';
        select.appendChild(option);
        select.value = value;
      }
      const form = document.querySelector('form#UpdateBilling') as HTMLFormElement;
      const field = document.createElement('input');
      field.type = 'hidden';
      field.name = '_qf_UpdateBilling_next';
      field.value = 'Save';
      form.appendChild(field);
      HTMLFormElement.prototype.submit.call(form);
    }, forged),
  ]);
}

test.describe('the Update Billing Details form, as staff', () => {
  test('PW-S-05 another contact\'s card id is refused and the recurring record, both donors and eWAY are left alone', async ({ adminPage, stub }) => {
    const mine = createRecurring({ label: 's05mine' });
    const theirs = createRecurring({ label: 's05theirs' });
    const before = snapshot(mine);
    const theirsBefore = snapshot(theirs);
    const cards = tokenTotal();

    await openForm(adminPage, billingUrl(mine), { cardsExpected: true });
    await forgeAndSubmit(adminPage, String(theirs.tokenId));

    expect(await pageText(adminPage)).toMatch(/The selected card does not belong to this contact\./);
    expect(await pageText(adminPage)).not.toMatch(/have been updated/);
    expect(snapshot(mine)).toEqual(before);
    expect(snapshot(theirs)).toEqual(theirsBefore);
    expect(tokenTotal()).toBe(cards);
    expect(stub.requests(), 'eWAY is not asked').toEqual([]);
  });

  test('PW-S-05 a card saved for another payment processor is refused', async ({ adminPage }) => {
    const { dummy } = seededProcessors();
    const record = createRecurring({ label: 's05proc' });
    // The same donor's own card, but saved for the Dummy processor.
    const [foreign] = api4<{ id: number }>('PaymentToken', 'create', {
      values: { contact_id: record.contactId, payment_processor_id: dummy, token: `foreign-${Date.now()}`, expiry_date: '2040-01-31', masked_account_number: '555555XXXXXX4444' },
    });
    const before = snapshot(record);

    await openForm(adminPage, billingUrl(record), { cardsExpected: true });
    await forgeAndSubmit(adminPage, String(foreign.id));

    expect(await pageText(adminPage)).toMatch(/saved for a different payment processor/);
    expect(snapshot(record)).toEqual(before);
    expect(recurRow(record.recurId).payment_token_id).toBe(record.tokenId);
  });

  test('PW-S-05 an expired card is refused', async ({ adminPage }) => {
    const record = createRecurring({ label: 's05exp' });
    const [expired] = api4<{ id: number }>('PaymentToken', 'create', {
      values: { contact_id: record.contactId, payment_processor_id: record.processorId, token: `expired-${Date.now()}`, expiry_date: '2020-01-31', masked_account_number: '555555XXXXXX4444' },
    });
    const before = snapshot(record);

    await openForm(adminPage, billingUrl(record), { cardsExpected: true });
    await forgeAndSubmit(adminPage, String(expired.id));

    expect(await pageText(adminPage)).toMatch(/The selected card has expired\./);
    expect(snapshot(record)).toEqual(before);
  });

  test('PW-S-05 a card id that does not exist, or is not a number, or is missing, is refused with a message and nothing changes', async ({ adminPage }) => {
    const record = createRecurring({ label: 's05bad' });
    const before = snapshot(record);
    const cases: [string, string | null, RegExp][] = [
      ['an id no card has', '99999999', /The selected card could not be found\./],
      ['letters', 'abc', /Missing contribution id and token id\.|could not be found|not a valid|required field/i],
      ['a number followed by text', `${record.tokenId} OR 1=1`, /Missing contribution id and token id\.|could not be found|not a valid|required field|does not belong/i],
      ['markup', '<script>window.__xss=1</script>', /Missing contribution id and token id\.|could not be found|not a valid|required field/i],
      ['an empty value', '', /Missing contribution id and token id\.|required field/i],
      ['no card in the box at all', null, /Missing contribution id and token id\.|required field/i],
    ];
    for (const [name, value, message] of cases) {
      await openForm(adminPage, billingUrl(record), { cardsExpected: true });
      await forgeAndSubmit(adminPage, value);
      const text = await pageText(adminPage);
      expect(text, `${name}: says why`).toMatch(message);
      expect(text, `${name}: not a success`).not.toMatch(/have been updated/);
      expect(withoutCoreNoise(text), `${name}: no PHP output beyond core's own`).not.toMatch(/Stack trace:|\bon line \d+|Warning:|Notice:|Fatal error/);
      expect(await adminPage.evaluate(() => (window as any).__xss), `${name}: no script ran`).toBeUndefined();
      expect(snapshot(record), `${name}: nothing changed`).toEqual(before);
    }
  });

  test('PW-S-05 the contact\'s own unexpired card still saves, so the refusals above are the checks and not a broken form', async ({ adminPage }) => {
    const record = createRecurring({ label: 's05ok', extraCards: [{}] });
    await openForm(adminPage, billingUrl(record), { cardsExpected: true });
    await forgeAndSubmit(adminPage, String(record.extraTokenIds[0]));
    expect(await pageText(adminPage)).not.toMatch(/does not belong|different payment processor|has expired/);
    expect(recurRow(record.recurId).payment_token_id).toBe(record.extraTokenIds[0]);
    expect(billingAddressOf(record.contactId)!.street_address).toBe('99 Forged Road');
  });
});

test.describe('the Update Billing Details form, as a donor with a checksum link', () => {
  // The donor's card list is empty (the PaymentToken API refuses a donor), so the only card they can post is one they made
  // up: another donor's id, which is a small sequential number.
  test('PW-S-05 a donor who posts another donor\'s card id changes neither record', async ({ anonPage, stub }) => {
    const mine = createRecurring({ label: 's05donor' });
    const theirs = createRecurring({ label: 's05victim' });
    const before = snapshot(mine);
    const theirsBefore = snapshot(theirs);
    const cards = tokenTotal();

    await openForm(anonPage, checksumUrl(mine), { cardsExpected: false });
    await forgeAndSubmit(anonPage, String(theirs.tokenId));

    // CiviCRM's error page, or on a fresh site the login page (the save no longer carries the checksum there).
    expect(await pageText(anonPage)).not.toMatch(/have been updated/);
    expect(snapshot(mine)).toEqual(before);
    expect(snapshot(theirs)).toEqual(theirsBefore);
    expect(tokenTotal()).toBe(cards);
    expect(stub.requests()).toEqual([]);
  });

  test('PW-S-05 a donor cannot use the form to touch another donor\'s recurring record, whatever card they post', async ({ anonPage }) => {
    const mine = createRecurring({ label: 's05own' });
    const theirs = createRecurring({ label: 's05other' });
    const theirsBefore = snapshot(theirs);
    const mineBefore = snapshot(mine);

    // My checksum and my contact id, the other donor's recurring id, with the other donor's own card (so that the card
    // check alone would pass if the record were let through).
    const checksum = evaluate(`echo CRM_Contact_BAO_Contact_Utils::generateChecksum(${Number(mine.contactId)});`);
    const url = frontUrl('contribute/updatebilling', { reset: 1, crid: theirs.recurId, cid: mine.contactId, cs: checksum, context: 'contribution' });
    await anonPage.goto(url);
    await expect(anonPage.locator('#UpdateBilling')).toHaveCount(0);

    // A form post to the other record directly, with no form in between.
    const attempt = await anonPage.request.post(frontUrl('contribute/updatebilling', { reset: 1, crid: theirs.recurId, cid: mine.contactId, cs: checksum }), {
      form: { contact_payment_token: String(theirs.tokenId), crid: String(theirs.recurId), _qf_UpdateBilling_next: '1', 'billing_street_address-5': '99 Forged Road' },
      maxRedirects: 0,
    });
    expect(await attempt.text()).not.toMatch(/have been updated/);
    expect(snapshot(theirs)).toEqual(theirsBefore);
    expect(snapshot(mine)).toEqual(mineBefore);
  });
});

test.describe('the back-office credit card form', () => {
  /** The back-office credit card form for a contact. */
  function cardFormUrl(contactId: number): string {
    return adminUrl('contact/view/contribution', { reset: 1, action: 'add', cid: contactId, context: 'contribution', mode: 'live' });
  }

  /** Choose an entry of a select2 box by the box's id (without the s2id_ prefix) and the entry's text. */
  async function chooseFrom(page: Page, selectId: string, label: string | RegExp): Promise<void> {
    const entry = page.locator('.select2-drop-active .select2-result-label', { hasText: label }).first();
    await expect(async () => {
      await page.locator(`#s2id_${selectId}`).click();
      try {
        await expect(entry).toBeVisible({ timeout: 3000 });
      } catch (error) {
        await page.keyboard.press('Escape');
        throw error;
      }
    }).toPass({ timeout: 25_000 });
    await entry.click();
  }

  test('PW-S-05 another contact\'s card posted on the credit card form is not charged, and nothing is completed for either contact', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    const payer = createRecurring({ label: 's05payer' });
    const victim = createRecurring({ label: 's05cardowner' });
    const completed = (contactId: number) => api4('Contribution', 'get', {
      select: ['id'],
      where: [['contact_id', '=', contactId], ['contribution_status_id:name', '=', 'Completed'], ['is_test', 'IN', [false, true]]],
    }).length;
    const before = { payer: completed(payer.contactId), victim: completed(victim.contactId) };
    const victimCards = cardsOf(victim.contactId);

    await adminPage.goto(cardFormUrl(payer.contactId));
    await expect(adminPage.locator('#payment_processor_id')).toBeAttached();
    await chooseFrom(adminPage, 'payment_processor_id', testData.processors.live);
    await expect(adminPage.locator('#contact_payment_token')).toBeVisible();
    await adminPage.waitForFunction(() => /\d{4} - \d\d\/\d{4}/.test(document.querySelector('#contact_payment_token option')?.textContent || ''));
    await adminPage.locator('#contact_payment_token').evaluate((el, forged) => {
      const select = el as HTMLSelectElement;
      const option = document.createElement('option');
      option.value = forged;
      option.text = 'A card I was not offered';
      select.appendChild(option);
      select.value = forged;
    }, String(victim.tokenId));
    await chooseFrom(adminPage, 'financial_type_id', 'Donation');
    await adminPage.locator('#total_amount').fill('25.00');

    // eWAY would approve a charge if one reached it.
    stub.respond('postTransaction', responses.paymentApproved(newTransactionId()), { repeat: true });
    await adminPage.locator('#_qf_Contribution_upload-bottom').click();
    await adminPage.waitForLoadState('load');
    // CiviCRM shows the refusal on whichever page it lands on first, and keeps it for the next if that page is a broken
    // address (DEF-64), so read what this page says now and what the contact's page says next.
    const shownNow = (await adminPage.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ');

    expect(stub.requests('postTransaction'), 'the other donor\'s card was never sent to eWAY').toHaveLength(0);
    expect(completed(payer.contactId)).toBe(before.payer);
    expect(completed(victim.contactId)).toBe(before.victim);
    expect(cardsOf(victim.contactId)).toEqual(victimCards);
    // The refusal is shown to staff, on the form or on the next screen CiviCRM loads.
    await adminPage.goto(adminUrl('contact/view', { reset: 1, cid: payer.contactId, selectedChild: 'contribute' }));
    await adminPage.waitForLoadState('networkidle');
    const shownNext = (await adminPage.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ');
    expect(`${shownNow} ${shownNext}`).toMatch(/does not belong to this contact/);
  });
});
