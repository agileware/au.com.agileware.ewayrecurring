import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl, frontUrl } from '../../fixtures/cms';
import { api4 } from '../../fixtures/civi';
import { browserApi4 } from '../../fixtures/backend';
import { newAccessCode, newCustomerToken, newTransactionId } from '../../fixtures/contribution-page';
import { responses } from '../../fixtures/eway-stub';
import { createRecurring, recurRow, type RecurringRecord } from '../../fixtures/recurring-records';
import { SCREEN_NOISE } from '../../fixtures/recurring-screens';
import testData from '../../fixtures/test-data.json';

/**
 * PW-B-backoffice: staff taking a payment from a saved card on CiviCRM's back-office credit card contribution form ("New Credit
 * Card Contribution", reached from the contact's Contributions tab). With the eWAY processor chosen, the extension replaces the
 * card number, security code and expiry boxes with a list of the contact's saved cards ("Stored Credit Card") and an "Add
 * Credit Card" button, and charges the chosen card with a MOTO transaction. Test plan: developer/TEST_PLAN.md section 5.2.
 *
 * The form is core's, and its default processor is core's own test processor, so each case picks "Playwright eWAY" first. The
 * script js/eway.js loads the card list from the API when the contact is known (from the address, or from the contact box on
 * the stand-alone form) and decides whether Add Credit Card is allowed (billing details complete, contact chosen).
 *
 * Dates are never compared as text: the expected card labels are worked out from the database (the expiry's month and year),
 * and a date picker is not used.
 */

test.use({ allowedProblems: [SCREEN_NOISE] });

/** A refused user gets HTTP 403 on a development site and 500 on a fresh CI site (core's own permission page). */
const REFUSAL_NOISE = /Cannot set response code|status of (403|500)|HTTP (403|500)/;

const EWAY_TITLE = testData.processors.live;
const BILLING_MESSAGE = 'The Billing Details section must be completed before a Credit Card can be added';

/** The back-office credit card form for a contact (the address the contact's "Submit Credit Card Contribution" button uses). */
function cardFormUrl(contactId: number, extra: Record<string, string | number> = {}): string {
  return adminUrl('contact/view/contribution', { reset: 1, action: 'add', cid: contactId, context: 'contribution', mode: 'live', ...extra });
}

/** The stand-alone credit card form, where the contact is chosen in a box on the form. */
function standaloneFormUrl(): string {
  return adminUrl('contribute/add', { reset: 1, action: 'add', context: 'standalone', mode: 'live' });
}

/** Choose an entry of a select2 box by the box's id (without the s2id_ prefix) and the entry's text. */
async function chooseFrom(page: Page, selectId: string, label: string | RegExp): Promise<void> {
  const entry = page.locator('.select2-drop-active .select2-result-label', { hasText: label }).first();
  // A list that is filled in by the server after another choice (the states after the country) may not have its entries
  // yet when the box is first opened, so close it and open it again until the entry is there.
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

/** Pick the eWAY processor on the form, and wait for its payment block (the saved card list and the button). */
async function useEway(page: Page): Promise<void> {
  await expect(page.locator('#payment_processor_id')).toBeAttached();
  await chooseFrom(page, 'payment_processor_id', EWAY_TITLE);
  await expect(page.locator('#contact_payment_token')).toBeVisible();
  await expect(page.locator('#add_credit_card')).toBeVisible();
}

/** Open the card form for a contact, with the eWAY processor chosen. */
async function openCardForm(page: Page, contactId: number, extra: Record<string, string | number> = {}): Promise<void> {
  await page.goto(cardFormUrl(contactId, extra));
  await useEway(page);
}

/** The saved card list's entries as text. */
async function cardOptions(page: Page): Promise<string[]> {
  return (await page.locator('#contact_payment_token option').allInnerTexts()).map((text) => text.trim());
}

/** The label the form gives a saved card, worked out from its row: last four digits, then month/year of the expiry. */
function cardLabel(card: { masked_account_number?: string | null; expiry_date: string }): string {
  const [year, month] = card.expiry_date.slice(0, 10).split('-');
  const when = `${month}/${year}`;
  return card.masked_account_number ? `${card.masked_account_number.slice(-4)} - ${when}` : when;
}

/** A contact's saved cards that are not expired, newest expiry first, as the form should list them. */
function expectedCardLabels(contactId: number, processorId: number): string[] {
  const cards = api4<{ masked_account_number: string | null; expiry_date: string }>('PaymentToken', 'get', {
    select: ['masked_account_number', 'expiry_date'],
    where: [['contact_id', '=', contactId], ['payment_processor_id', '=', processorId], ['expiry_date', '>', 'now']],
    orderBy: { expiry_date: 'DESC' },
  });
  return cards.map(cardLabel);
}

/**
 * Make the eWAY processor the form's default for the length of a case (core's own test processor is the default otherwise,
 * so the form would start on that), and put it back as it was.
 */
async function withEwayAsDefault(run: () => Promise<void>): Promise<void> {
  const { eway } = processors();
  const was = Boolean(api4<{ is_default: boolean }>('PaymentProcessor', 'get', { select: ['is_default'], where: [['id', '=', eway]] })[0].is_default);
  api4('PaymentProcessor', 'update', { where: [['id', '=', eway]], values: { is_default: true } });
  try {
    await run();
  } finally {
    api4('PaymentProcessor', 'update', { where: [['id', '=', eway]], values: { is_default: was } });
  }
}

/** The id of the seeded live eWAY processor and of the seeded non-eWAY (Dummy) one. */
function processors(): { eway: number; dummy: number } {
  const find = (name: string) => api4<{ id: number }>('PaymentProcessor', 'get', { select: ['id'], where: [['name', '=', name], ['is_test', '=', false]] })[0].id;
  return { eway: find(testData.processors.live), dummy: find(testData.processors.dummy) };
}

/** Fill in the part of the form a payment needs (the contact is known), up to but not including Save. */
async function fillPayment(page: Page, amount: string): Promise<void> {
  await chooseFrom(page, 'financial_type_id', 'Donation');
  await page.locator('#total_amount').fill(amount);
}

/** Press Save on the credit card form and wait for the answer, which is the contact's page or the form again with a message. */
async function save(page: Page): Promise<void> {
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator('#_qf_Contribution_upload-bottom').click()]);
}

/** The contributions of a contact, oldest first. */
function contributionsOfContact(contactId: number): Record<string, any>[] {
  return api4('Contribution', 'get', {
    select: ['id', 'total_amount', 'trxn_id', 'contribution_status_id:name', 'contribution_recur_id', 'payment_instrument_id:name'],
    where: [['contact_id', '=', contactId], ['is_test', 'IN', [false, true]]],
    orderBy: { id: 'ASC' },
  });
}

/** A contact with a billing address and saved cards: the first on a record is the card given, the rest are extra. */
function donorWithCards(label: string, extraCards: { masked?: string; expiry?: string }[] = []): RecurringRecord {
  return createRecurring({ label, extraCards });
}

test.describe('the form for a saved card', () => {
  test('PW-B-backoffice-01 the credit card form for the eWAY processor offers Stored Credit Card and Add Credit Card instead of card fields', async ({ adminPage }) => {
    test.setTimeout(90_000);
    const donor = donorWithCards('bo01');

    // From the contact's Contributions tab, as staff do.
    await adminPage.goto(adminUrl('contact/view', { reset: 1, cid: donor.contactId, selectedChild: 'contribute' }));
    await adminPage.getByRole('link', { name: /Submit Credit Card Contribution/ }).first().click();
    await expect(adminPage.locator('.ui-dialog form#Contribution')).toBeVisible();
    const dialog = adminPage.locator('.ui-dialog');

    // With core's own processor (the form's default) the card boxes are core's.
    await expect(dialog.locator('#payment_processor_id')).toBeAttached();
    await expect(dialog.locator('#credit_card_number')).toHaveCount(1);
    await expect(dialog.locator('#cvv2')).toHaveCount(1);
    await expect(dialog.locator('#contact_payment_token')).toHaveCount(0);

    // With eWAY chosen: the saved card list and the button replace them.
    await chooseFrom(adminPage, 'payment_processor_id', EWAY_TITLE);
    await expect(dialog.locator('#contact_payment_token')).toBeVisible();
    await expect(dialog.getByText('Stored Credit Card')).toBeVisible();
    await expect(dialog.locator('#add_credit_card')).toBeVisible();
    await expect(dialog.locator('#add_credit_card')).toHaveValue('Add Credit Card');
    await expect(dialog.locator('#credit_card_number')).toHaveCount(0);
    await expect(dialog.locator('#cvv2')).toHaveCount(0);
    await expect(dialog.locator('[name^="credit_card_exp_date"]')).toHaveCount(0);
    // The stored card is required, the notice about PCI is there, and the billing name and address are still asked for.
    await expect(dialog.locator('.contact_payment_token-section .crm-marker')).toBeVisible();
    await expect(dialog.getByText('Credit card details are entered directly into eWAY and not stored in CiviCRM.')).toBeVisible();
    await expect(dialog.locator('#billing_first_name')).toBeVisible();
    await expect(dialog.locator('input[id^="billing_street_address"]')).toBeVisible();
    // The contact's cards are in the list (two here), and the contact is known to the script.
    await expect.poll(() => cardOptions(adminPage)).toEqual(expectedCardLabels(donor.contactId, donor.processorId));
  });

  test('PW-B-backoffice-02 Add Credit Card is disabled with the billing message until every billing field is complete, and enabled after', async ({ adminPage }) => {
    test.setTimeout(90_000);
    // No billing address: the name comes from the contact, the address is blank.
    const donor = createRecurring({ label: 'bo02', billingAddress: false });
    await openCardForm(adminPage, donor.contactId);

    const button = adminPage.locator('#add_credit_card');
    const notice = adminPage.locator('#add_credit_card_notification');
    const street = adminPage.locator('input[id^="billing_street_address"]');
    const city = adminPage.locator('input[id^="billing_city"]');
    const postcode = adminPage.locator('input[id^="billing_postal_code"]');
    await expect(adminPage.locator('#billing_first_name')).toHaveValue(donor.donor.firstName);
    await expect(adminPage.locator('#billing_last_name')).toHaveValue(donor.donor.lastName);
    await expect(button).toBeDisabled();
    await expect(notice).toHaveText(BILLING_MESSAGE);
    await expect(notice).toHaveClass(/crm-error/);

    // Each field filled in turn: still disabled until the last one.
    await street.fill(donor.donor.street);
    await city.fill(donor.donor.city);
    await postcode.fill(donor.donor.postcode);
    await city.blur();
    await expect(button).toBeDisabled();
    await expect(notice).toHaveText(BILLING_MESSAGE);
    await chooseFrom(adminPage, (await adminPage.locator('[id^="billing_country_id"]').first().getAttribute('id'))!, donor.donor.country);
    await expect(button).toBeDisabled();
    await chooseFrom(adminPage, (await adminPage.locator('[id^="billing_state_province_id"]').first().getAttribute('id'))!, donor.donor.state);
    await expect(button).toBeEnabled();
    await expect(notice).toHaveText('');
    await expect(notice).not.toHaveClass(/crm-error/);

    // Taking one away disables it again.
    await postcode.fill('');
    await postcode.blur();
    await expect(button).toBeDisabled();
    await expect(notice).toHaveText(BILLING_MESSAGE);
    await postcode.fill(donor.donor.postcode);
    await postcode.blur();
    await expect(button).toBeEnabled();
  });

  test('PW-B-backoffice-03 choosing a contact loads their unexpired cards, newest expiry first, "No cards found." when none, and reloads when the contact changes', async ({ adminPage }) => {
    test.setTimeout(120_000);
    const withCards = donorWithCards('bo03cards', [
      { masked: '510510XXXXXX5100', expiry: '+4 years' },
      { masked: '555555XXXXXX4444', expiry: '-2 years' },
    ]);
    const without = createRecurring({ label: 'bo03none' });
    // The second contact has no usable card at all: take the one the record made away.
    api4('ContributionRecur', 'update', { where: [['id', '=', without.recurId]], values: { payment_token_id: null } });
    api4('PaymentToken', 'delete', { where: [['contact_id', '=', without.contactId]] });
    const { eway } = processors();
    const listed = expectedCardLabels(withCards.contactId, eway);
    expect(listed).toHaveLength(2);
    // The expired card is in the database but not on the list.
    expect(api4('PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', withCards.contactId]] })).toHaveLength(3);

    await adminPage.goto(standaloneFormUrl());
    await useEway(adminPage);
    // No contact yet: nothing to list, and the card list is switched off. (The notice under the button is the billing one,
    // because the billing check runs after the contact check and replaces its message: DEF-63.)
    expect(await cardOptions(adminPage)).toEqual(['No cards found.']);
    await expect(adminPage.locator('#contact_payment_token')).toBeDisabled();
    await expect(adminPage.locator('#add_credit_card_notification')).toHaveText(BILLING_MESSAGE);

    const chooseContact = async (donor: RecurringRecord) => {
      await adminPage.locator('#s2id_contact_id').click();
      await adminPage.locator('.select2-drop-active input.select2-input:visible').fill(donor.donor.email);
      await adminPage.locator('.select2-drop-active .select2-result-label', { hasText: donor.donor.lastName }).first().click();
    };

    await chooseContact(withCards);
    await expect.poll(() => cardOptions(adminPage)).toEqual(listed);
    // Newest expiry first: the card that expires last leads.
    expect(listed[0]).toContain('5100');
    await expect(adminPage.locator('#contact_payment_token')).toBeEnabled();

    // Another contact: their list replaces it, and "No cards found." when they have none.
    await chooseContact(without);
    await expect.poll(() => cardOptions(adminPage)).toEqual(['No cards found.']);
    // Back again: the first contact's cards come back.
    await chooseContact(withCards);
    await expect.poll(() => cardOptions(adminPage)).toEqual(listed);
  });

  test('PW-B-backoffice-03 with billing details complete but no contact chosen the notice says "No contact selected" and the button is off (DEF-63)', async ({ adminPage }) => {
    test.fail(true, 'DEF-63: the billing check replaces the no-contact message, so the button is on and says nothing while no contact is chosen');
    test.setTimeout(90_000);
    await adminPage.goto(standaloneFormUrl());
    await useEway(adminPage);
    await adminPage.locator('#billing_first_name').fill('Pat');
    await adminPage.locator('#billing_last_name').fill('Tester');
    await adminPage.locator('input[id^="billing_street_address"]').fill('1 Test Street');
    await adminPage.locator('input[id^="billing_city"]').fill('Canberra');
    await adminPage.locator('input[id^="billing_postal_code"]').fill('2600');
    await chooseFrom(adminPage, (await adminPage.locator('[id^="billing_country_id"]').first().getAttribute('id'))!, 'Australia');
    await chooseFrom(adminPage, (await adminPage.locator('[id^="billing_state_province_id"]').first().getAttribute('id'))!, 'Australian Capital Territory');
    await expect(adminPage.locator('#add_credit_card_notification')).toHaveText('No contact selected');
    await expect(adminPage.locator('#add_credit_card')).toBeDisabled();
  });

  test('PW-B-backoffice-04 a card saved before masked numbers existed is listed with its month and year only', async ({ adminPage }) => {
    test.setTimeout(90_000);
    const donor = donorWithCards('bo04');
    const { eway } = processors();
    api4('PaymentToken', 'create', {
      values: {
        contact_id: donor.contactId,
        payment_processor_id: eway,
        token: newCustomerToken(),
        expiry_date: '2033-06-30',
        masked_account_number: null,
        created_date: 'now',
      },
    });
    await openCardForm(adminPage, donor.contactId);
    await expect.poll(async () => (await cardOptions(adminPage)).length).toBe(2);
    const options = await cardOptions(adminPage);
    // Last expiry first: the legacy card (2033) leads, with no digits in front of its date.
    expect(options[0]).toBe('06/2033');
    expect(options[1]).toMatch(/^1111 - \d{2}\/\d{4}$/);
  });

  test('PW-B-backoffice-09 a card saved for another payment processor is not offered', async ({ adminPage }) => {
    test.setTimeout(90_000);
    const donor = donorWithCards('bo09');
    const { dummy, eway } = processors();
    api4('PaymentToken', 'create', {
      values: {
        contact_id: donor.contactId,
        payment_processor_id: dummy,
        token: newCustomerToken(),
        expiry_date: '2035-01-31',
        masked_account_number: '411111XXXXXX9999',
        created_date: 'now',
      },
    });
    expect(api4('PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', donor.contactId]] })).toHaveLength(2);
    await openCardForm(adminPage, donor.contactId);
    await expect.poll(() => cardOptions(adminPage)).toEqual(expectedCardLabels(donor.contactId, eway));
    expect(await cardOptions(adminPage)).toHaveLength(1);
    expect((await cardOptions(adminPage)).join('|')).not.toContain('9999');
  });
});

test.describe('adding and charging a card', () => {
  /** The hosted page the Add Credit Card button opens, in a new tab, and the stub's scripted answers for it. */
  async function addCardThroughHostedPage(page: Page, stub: import('../../fixtures/eway-stub').EwayStub, donor: RecurringRecord, card: { masked: string; month: string; year: string }) {
    const accessCode = newAccessCode('BO05');
    const token = newCustomerToken();
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));
    stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: token }), { repeat: true });
    stub.respond('getCustomer', responses.customerFound(token, `civi-${donor.contactId}`, { Number: card.masked, ExpiryMonth: card.month, ExpiryYear: card.year }), { repeat: true });

    const popupOpened = page.context().waitForEvent('page');
    await page.locator('#add_credit_card').click();
    const popup = await popupOpened;
    // eWAY's hosted page (the stub's) opens in its own tab, for this access code.
    await expect(popup.locator('#access-code')).toHaveText(accessCode);
    await expect(page.locator('.crm-confirm-dialog', { hasText: 'Click Ok to update the card list.' })).toBeVisible();

    // Pay on the hosted page: eWAY sends the browser to the extension's savetoken page, which saves the card and closes the tab.
    const closed = popup.waitForEvent('close');
    await popup.locator('#pay').click();
    await closed;
    return { token, accessCode };
  }

  test('PW-B-backoffice-05 Add Credit Card opens eWAY\'s page in a new tab, asks to update the list, and after Ok the new card is in the list with an alert', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    const donor = donorWithCards('bo05');
    await openCardForm(adminPage, donor.contactId);
    await expect.poll(() => cardOptions(adminPage)).toHaveLength(1);

    const { token } = await addCardThroughHostedPage(adminPage, stub, donor, { masked: '510510XXXXXX5100', month: '03', year: '36' });
    // The card was saved when eWAY's page sent the browser back, before Ok.
    const saved = api4<Record<string, any>>('PaymentToken', 'get', { select: ['id', 'token', 'masked_account_number', 'expiry_date', 'payment_processor_id'], where: [['contact_id', '=', donor.contactId], ['token', '=', token]] });
    expect(saved).toHaveLength(1);
    expect(saved[0]).toMatchObject({ masked_account_number: '510510XXXXXX5100', payment_processor_id: donor.processorId });

    // Ok: the list is asked for again and the new card, which expires last, leads it.
    await adminPage.getByRole('button', { name: 'Ok' }).click();
    await expect.poll(() => cardOptions(adminPage)).toHaveLength(2);
    expect((await cardOptions(adminPage))[0]).toBe(cardLabel(saved[0] as any));
    expect(await cardOptions(adminPage)).toEqual(expectedCardLabels(donor.contactId, donor.processorId));
    await expect(adminPage.locator('#crm-notification-container')).toContainText('The credit card list has been updated.');
    // No duplicate: asking again after Ok found the same card.
    expect(api4('PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', donor.contactId], ['token', '=', token]] })).toHaveLength(1);
    // eWAY was asked for a hosted page once, with the contact's reference.
    const asked = stub.requests('postAccessCodeShared');
    expect(asked).toHaveLength(1);
    expect(JSON.stringify(asked[0].args[0])).toContain(`civi-${donor.contactId}`);
  });

  test('PW-B-backoffice-05 the first card added for a contact appears in the list without the "list has been updated" alert', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    const donor = donorWithCards('bo05first');
    // A contact with no card at all.
    api4('ContributionRecur', 'update', { where: [['id', '=', donor.recurId]], values: { payment_token_id: null } });
    api4('PaymentToken', 'delete', { where: [['contact_id', '=', donor.contactId]] });
    await openCardForm(adminPage, donor.contactId);
    expect(await cardOptions(adminPage)).toEqual(['No cards found.']);

    await addCardThroughHostedPage(adminPage, stub, donor, { masked: '555555XXXXXX4444', month: '09', year: '37' });
    await adminPage.getByRole('button', { name: 'Ok' }).click();
    await expect.poll(async () => (await cardOptions(adminPage))[0]).toMatch(/^4444 - 09\/2037$/);
    expect(await cardOptions(adminPage)).toHaveLength(1);
    // The alert is only shown when the list changes from one that already had cards.
    await expect(adminPage.locator('#crm-notification-container')).not.toContainText('The credit card list has been updated.');
  });

  test('PW-B-backoffice-06 submitting with a saved card completes the contribution with a MOTO charge on that card and stores the transaction id', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    const donor = donorWithCards('bo06');
    await openCardForm(adminPage, donor.contactId);
    await expect.poll(() => cardOptions(adminPage)).toHaveLength(1);
    await fillPayment(adminPage, '25.00');
    const transactionId = newTransactionId();
    stub.respond('postTransaction', responses.paymentApproved(transactionId));
    await save(adminPage);

    // eWAY was asked once: this card's token, the amount in cents, a MOTO transaction.
    const charges = stub.requests('postTransaction');
    expect(charges).toHaveLength(1);
    const sent = charges[0].args[0] as any;
    expect(sent.Customer.TokenCustomerID).toBe(donor.token.slice(0, 16));
    expect(String(sent.Payment.TotalAmount)).toBe('2500');
    expect(sent.TransactionType).toBe('MOTO');

    // The contribution is Completed with eWAY's transaction id, and the page moved on to the contact.
    const rows = contributionsOfContact(donor.contactId).filter((row) => row.id !== donor.contributionId);
    expect(rows).toHaveLength(1);
    expect(rows[0]['contribution_status_id:name']).toBe('Completed');
    expect(Number(rows[0].total_amount)).toBe(25);
    expect(String(rows[0].trxn_id)).toBe(String(transactionId));
    expect(adminPage.url()).toContain('contact');
    // It is a one-off: it belongs to no recurring record, and the donor's recurring record is untouched.
    expect(rows[0].contribution_recur_id ?? null).toBeNull();
    expect(recurRow(donor.recurId).payment_token_id).toBe(donor.tokenId);
  });

  test.describe('a declined card', () => {
    // After a failed card payment core's own error handling sends the browser to a broken address (a 404 page in the CMS):
    // it hands the form's query string to its redirect as if it were a whole address (DEF-64).
    test.use({ allowedProblems: [new RegExp(`${SCREEN_NOISE.source}|status of 404|ViewTransition`)] });

    test('PW-B-backoffice-07 a declined card records a Failed contribution, shows the eWAY message on the next screen, and completes nothing', async ({ adminPage, stub }) => {
      test.setTimeout(120_000);
      const donor = donorWithCards('bo07');
      await openCardForm(adminPage, donor.contactId);
      await expect.poll(() => cardOptions(adminPage)).toHaveLength(1);
      await fillPayment(adminPage, '30.00');
      stub.respond('postTransaction', responses.paymentDeclined('D4401', newTransactionId()));
      await adminPage.locator('#_qf_Contribution_upload-bottom').click();
      await expect.poll(() => stub.requests('postTransaction').length).toBe(1);

      // The attempt is on record as Failed, and nothing else was added.
      await expect.poll(() => contributionsOfContact(donor.contactId).length).toBe(2);
      const added = contributionsOfContact(donor.contactId).filter((row) => row.id !== donor.contributionId);
      expect(added).toHaveLength(1);
      expect(added[0]['contribution_status_id:name']).toBe('Failed');
      expect(Number(added[0].total_amount)).toBe(30);
      expect(contributionsOfContact(donor.contactId).filter((row) => row['contribution_status_id:name'] === 'Completed')).toHaveLength(1);

      // CiviCRM keeps the error to show on the next of its screens: eWAY's message, from the decline code.
      await adminPage.goto(adminUrl('contact/view', { reset: 1, cid: donor.contactId, selectedChild: 'contribute' }));
      await expect(adminPage.locator('#crm-notification-container')).toContainText('Payment Processor Error');
      await expect(adminPage.locator('#crm-notification-container')).toContainText('Refer to Issuer');
    });
  });

  test('PW-B-backoffice-08 opened for an existing recurring record (crid) the form preselects that record\'s card, and the record keeps its card', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    // The record's card is the older of two, so the preselection is not just the first entry in the list.
    const donor = createRecurring({ label: 'bo08', extraCards: [{ masked: '510510XXXXXX5100', expiry: '+4 years' }] });
    const before = recurRow(donor.recurId);
    await withEwayAsDefault(async () => {
      // eWAY is the form's processor from the start, as on a site where it is the only one.
      await adminPage.goto(cardFormUrl(donor.contactId, { crid: donor.recurId }));
      await expect(adminPage.locator('#contact_payment_token')).toBeVisible();
      await expect.poll(() => cardOptions(adminPage)).toHaveLength(2);
      expect((await cardOptions(adminPage))[0]).toContain('5100');
      await expect(adminPage.locator('#contact_payment_token')).toHaveValue(String(donor.tokenId));
      expect(await adminPage.evaluate(() => (window as any).CRM.eway.contribution_recur_id)).toBe(donor.recurId);

      // Charging the preselected card takes a one-off payment on it. The form has no way to attach the payment to the
      // recurring record, and the record keeps its card.
      await fillPayment(adminPage, '12.00');
      stub.respond('postTransaction', responses.paymentApproved(newTransactionId()));
      await save(adminPage);
    });
    expect(((stub.requests('postTransaction')[0].args[0]) as any).Customer.TokenCustomerID).toBe(donor.token.slice(0, 16));
    const after = recurRow(donor.recurId);
    expect(after.payment_token_id).toBe(before.payment_token_id);
    expect(after.processor_id).toBe(before.processor_id);
    expect(after['contribution_status_id:name']).toBe(before['contribution_status_id:name']);
    const rows = contributionsOfContact(donor.contactId).filter((row) => row.id !== donor.contributionId);
    expect(rows).toHaveLength(1);
    expect(rows[0]['contribution_status_id:name']).toBe('Completed');
    expect(rows[0].contribution_recur_id ?? null).toBeNull();
  });

  test('PW-B-backoffice-08 choosing eWAY from the processor list on a form opened with crid still preselects the record\'s card (DEF-65)', async ({ adminPage }) => {
    test.fail(true, 'DEF-65: the payment block that loads when a processor is chosen is asked for without crid, so the newest card is selected instead of the record\'s');
    test.setTimeout(90_000);
    const donor = createRecurring({ label: 'bo08switch', extraCards: [{ masked: '510510XXXXXX5100', expiry: '+4 years' }] });
    await openCardForm(adminPage, donor.contactId, { crid: donor.recurId });
    await expect.poll(() => cardOptions(adminPage)).toHaveLength(2);
    await expect(adminPage.locator('#contact_payment_token')).toHaveValue(String(donor.tokenId));
  });
});

test.describe('who can use it', () => {
  test.describe('other users', () => {
    // Any refused request (the Add Credit Card route, the token API) answers HTTP 403, and a user with no right to see the
    // contact gets core's own message.
    test.use({ allowedProblems: [new RegExp(`${SCREEN_NOISE.source}|${REFUSAL_NOISE.source}`)] });

    test('PW-B-backoffice-10 the token viewer and the limited user get no form and cannot add a card; the viewer can read cards, the limited user cannot', async ({ financePage, tokenviewerPage, limitedPage, stub }) => {
      test.setTimeout(120_000);
      const donor = donorWithCards('bo10');
      const { eway } = processors();

      // The finance user (edit contributions) opens the form and sees the contact's cards.
      await openCardForm(financePage, donor.contactId);
      await expect.poll(() => cardOptions(financePage)).toEqual(expectedCardLabels(donor.contactId, eway));

      // The other two are not given the form: it needs "edit contributions". The viewer lands on the contact's summary
      // (which has no Contributions tab for them) and the limited user cannot even see the contact.
      for (const [name, page] of [['tokenviewer', tokenviewerPage], ['limited', limitedPage]] as const) {
        await page.goto(cardFormUrl(donor.contactId));
        await expect(page.locator('form#Contribution'), name).toHaveCount(0);
        await expect(page.locator('#contact_payment_token'), name).toHaveCount(0);
        await expect(page.locator('#add_credit_card'), name).toHaveCount(0);

        // Add Credit Card's own route is refused too, and eWAY is not asked for a page.
        const created = await page.request.post(frontUrl('ewayrecurring/createtoken', { contact_id: donor.contactId, pp_id: eway }), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          form: { billing_first_name: 'Pat', billing_last_name: 'Tester' },
          maxRedirects: 0,
        });
        expect(created.status(), `${name}: createtoken status`).toBeGreaterThanOrEqual(400);
      }
      await expect(limitedPage.getByText('You do not have the necessary permission to view this contact')).toBeVisible();
      expect(stub.requests('postAccessCodeShared')).toHaveLength(0);

      // From a CiviCRM page the token viewer reads the cards and cannot create one; the limited user can do neither.
      await tokenviewerPage.goto(adminUrl('dashboard', { reset: 1 }));
      await tokenviewerPage.waitForFunction(() => !!(window as any).CRM?.api4);
      const viewerRead = await browserApi4(tokenviewerPage, 'PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', donor.contactId]] });
      expect(viewerRead.ok).toBe(true);
      expect(viewerRead.values).toHaveLength(1);
      const viewerCreate = await browserApi4(tokenviewerPage, 'PaymentToken', 'create', { values: { contact_id: donor.contactId, payment_processor_id: eway, token: newCustomerToken() } });
      expect(viewerCreate.ok).toBe(false);
      expect(viewerCreate.status).toBe(403);
      await limitedPage.goto(adminUrl('dashboard', { reset: 1 }));
      await limitedPage.waitForFunction(() => !!(window as any).CRM?.api4);
      const limitedRead = await browserApi4(limitedPage, 'PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', donor.contactId]] });
      expect(limitedRead.ok).toBe(false);
      expect(limitedRead.status).toBe(403);
      expect(api4('PaymentToken', 'get', { select: ['id'], where: [['contact_id', '=', donor.contactId]] })).toHaveLength(1);
    });
  });
});
