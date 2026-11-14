import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl, frontUrl } from '../../fixtures/cms';
import { api4, evaluate } from '../../fixtures/civi';
import { chooseOption, newAccessCode, newCustomerToken, newTransactionId } from '../../fixtures/contribution-page';
import { responses, type EwayStub } from '../../fixtures/eway-stub';
import {
  activitiesOf,
  billingAddressOf,
  cardsOf,
  contributionsOf,
  createRecurring,
  makeNextPaymentDue,
  makeRetryDue,
  maxRetries,
  recurRow,
  runRecurringJob,
  setOtherDueRecurringAside,
  type RecurringRecord,
} from '../../fixtures/recurring-records';
import { mailSubjectsFor } from '../../fixtures/queue-records';

/**
 * PW-F4: the Update Billing Details form for a recurring contribution (civicrm/contribute/updatebilling). It is
 * CiviCRM core's form; this extension supplies the saved-card controls (the Stored Credit Card list and the Add Credit
 * Card button, the same as on the back-office payment form) and, on Save, updateSubscriptionBillingInfo(). In practice
 * it is a staff tool, reached from "Change Billing Details" on the contact's Recurring Contributions tab. Test plan:
 * developer/TEST_PLAN.md section 5.1.
 *
 * Staff normally reach the form through CiviCRM's popup (the link on the contact's tab opens it in a dialog), and
 * that is how most cases here open it. KNOWN DEFECT: opened as a full page (the same address typed or linked
 * directly), pressing Save fails with "Missing contribution id and token id.", because updateSubscriptionBillingInfo()
 * reads crid from the request and a full-page POST does not carry it (the popup posts to the address it was loaded
 * from, which does). One test pins that with test.fail().
 */

/** CiviCRM core reads a key the extension's Add Credit Card field metadata does not set (see the pinned test). */
const IS_REQUIRED_WARNING = /Undefined array key "is_required"/;
/** The script error raised each time a card is added (see the pinned test PW-F4-02). */
const CARD_LIST_SCRIPT_ERROR = /values is not iterable/;
/** On a fatal error page inside wp-admin core tries to set the HTTP status after the menu has been printed. */
const HEADERS_SENT_WARNING = /http_response_code\(\): Cannot set response code/;
/**
 * Both of the above. Kept as one pattern in a one-item list: Playwright reads a two-item array whose second item is an
 * object as a [value, options] pair, so a list of two patterns cannot be given to test.use().
 */
const CORE_NOISE = new RegExp(`${IS_REQUIRED_WARNING.source}|${HEADERS_SENT_WARNING.source}|status of 403`);
/** What the browser reports when CiviCRM answers an error (a refused save) with its error page: HTTP 500, and WordPress's admin script. */
const ERROR_PAGE_NOISE = /status of 500|ViewTransition/;

/** The form's address for a recurring record, as the staff link on the contact's tab builds it. */
function billingUrl(record: RecurringRecord, extra: Record<string, string | number> = {}): string {
  return adminUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, context: 'contribution', ...extra });
}

/** The text of a card's entry in the Stored Credit Card list: the last four digits, then month and year of expiry. */
function cardLabel(card: Record<string, any>): string {
  const [year, month] = String(card.expiry_date).split(/[-\s]/);
  return `${String(card.masked_account_number).slice(-4)} - ${month}/${year}`;
}

/** Wait until the Stored Credit Card list (loaded by script after the page) shows exactly these entries, in order. */
async function expectCardList(page: Page, labels: string[]): Promise<void> {
  await expect(page.locator('#contact_payment_token option')).toHaveText(labels);
}

/** Open the form the way staff do: the contact's Recurring Contributions tab, "more", Change Billing Details (a dialog). */
async function openBillingDialog(page: Page, record: RecurringRecord): Promise<void> {
  await page.goto(adminUrl('contact/view', { reset: 1, cid: record.contactId, selectedChild: 'contribute' }));
  await page.getByRole('tab', { name: /Recurring Contributions/ }).click();
  const row = page.locator(`#contribution_recur-${record.recurId}`);
  await expect(row).toBeVisible();
  await row.locator('.btn-slide').click();
  await row.getByRole('link', { name: 'Change Billing Details' }).click();
  await expect(page.locator('.ui-dialog form#UpdateBilling')).toBeVisible();
}

/**
 * Open the same dialog without the link, for a record whose row has none (a Failed one): the contact's page loads the
 * form into a dialog from its address, as clicking the link would.
 */
async function openBillingDialogByAddress(page: Page, record: RecurringRecord): Promise<void> {
  await page.goto(adminUrl('contact/view', { reset: 1, cid: record.contactId, selectedChild: 'contribute' }));
  await expect(page.locator('.crm-contact-page')).toBeVisible();
  const address = billingUrl(record);
  await page.evaluate((url) => (window as any).CRM.loadForm(url, { dialog: { width: 800 } }), address);
  await expect(page.locator('.ui-dialog form#UpdateBilling')).toBeVisible();
}

/**
 * Wait for an error message from the form. CiviCRM normally shows it at once. On this site the dialog is left on its
 * "working" spinner after a refused save and the message, which CiviCRM has queued, shows on the next page it loads
 * (the PHP warning the form prints, see the pinned test, is in the response and stops the dialog reading it). So if it
 * has not appeared, load the contact's page and look there.
 */
async function expectErrorMessage(page: Page, message: RegExp, record: RecurringRecord): Promise<void> {
  const container = page.locator('#crm-notification-container');
  const shown = await expect(container).toContainText(message, { timeout: 5000 }).then(() => true, () => false);
  if (!shown) {
    await page.goto(adminUrl('contact/view', { reset: 1, cid: record.contactId, selectedChild: 'contribute' }));
    await expect(container).toContainText(message);
  }
}

/** Press Save in the dialog and wait for it to close. */
async function saveInDialog(page: Page): Promise<void> {
  await page.locator('.ui-dialog-buttonpane').getByRole('button', { name: 'Save' }).click();
}

/** A card eWAY will report when the donor adds it on the hosted page. */
interface NewCard {
  token: string;
  masked: string;
  month: string;
  /** Two digits, as eWAY reports it. */
  year: string;
  /** The entry in the Stored Credit Card list once the card is saved. */
  label: string;
}

function newCard(): NewCard {
  return { token: newCustomerToken(), masked: '510510XXXXXX5100', month: '03', year: '35', label: '5100 - 03/2035' };
}

/** Script eWAY for adding `card` to a contact: the hosted page is set up, then answers for the saved card. */
function scriptAddCard(stub: EwayStub, record: RecurringRecord, card: NewCard, code = newAccessCode('F4')): void {
  stub.respond('postAccessCodeShared', responses.sharedPageCreated(code));
  // The extension looks the card up twice: when eWAY sends the browser back, and when staff confirm the card list.
  stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: card.token }), { repeat: true });
  stub.respond('getCustomer', responses.customerFound(card.token, `civi-${record.contactId}`, { Number: card.masked, ExpiryMonth: card.month, ExpiryYear: card.year }), { repeat: true });
}

/** Press Add Credit Card and return the hosted page window it opens. */
async function openHostedWindow(page: Page): Promise<Page> {
  const opened = page.waitForEvent('popup');
  await page.locator('#add_credit_card').click();
  const popup = await opened;
  await popup.waitForLoadState();
  return popup;
}

/** Answer "Click Ok to update the card list." */
async function confirmCardList(page: Page): Promise<void> {
  await page.locator('.ui-dialog button', { hasText: /^\s*Ok\s*$/ }).click();
}

/** Add a card the way staff do: open eWAY's window, pay there, and confirm the card list. */
async function addCardOnHostedPage(page: Page, card: NewCard): Promise<void> {
  const popup = await openHostedWindow(page);
  await popup.locator('#pay').click();
  // eWAY sends the window to the extension, which saves the card and closes the window.
  await expect.poll(() => popup.isClosed(), { message: 'the hosted window closes itself after the card is saved' }).toBe(true);
  await confirmCardList(page);
  await expect(page.locator('#contact_payment_token option', { hasText: card.label })).toHaveCount(1);
}

test.describe('update billing details: the staff form', () => {
  test.use({ allowedProblems: [new RegExp(`${IS_REQUIRED_WARNING.source}|${CARD_LIST_SCRIPT_ERROR.source}|${ERROR_PAGE_NOISE.source}`)] });

  test('PW-F4-01 staff open Change Billing Details from the recurring tab and see the saved cards, Add Credit Card and the current billing address', async ({ adminPage }) => {
    const record = createRecurring({
      label: 'f401',
      extraCards: [
        { masked: '510510XXXXXX5100', expiry: '+3 years' },
        { masked: '378282XXXXX0005', expiry: '-1 month' },
      ],
    });
    // Another donor's card on the same processor, which must not be offered.
    const stranger = createRecurring({ label: 'f401other' });

    // The way staff get there: the contact's Contributions tab, Recurring Contributions, "more", Change Billing Details.
    // CiviCRM opens the form in a dialog.
    await openBillingDialog(adminPage, record);
    await expect(adminPage.locator('.ui-dialog-title')).toHaveText('Update Billing Details');
    await expect(adminPage.locator('.ui-dialog .help')).toContainText('Recurring Contribution Details: $10.00 every 1 month');

    // The Stored Credit Card list: the donor's own cards that have not expired, longest lasting first. The expired
    // card and the other donor's card are not offered. The card the record charges now is the one selected.
    const cards = cardsOf(record.contactId);
    const current = cards.find((card) => card.id === record.tokenId)!;
    const longer = cards.find((card) => card.id === record.extraTokenIds[0])!;
    await expectCardList(adminPage, [cardLabel(longer), cardLabel(current)]);
    await expect(adminPage.locator('#contact_payment_token')).toHaveValue(String(record.tokenId));
    await expect(adminPage.locator('label[for="contact_payment_token"]')).toContainText('Stored Credit Card');
    const offered = await adminPage.locator('#contact_payment_token option').evaluateAll((options) => options.map((o) => (o as HTMLOptionElement).value));
    expect(offered).not.toContain(String(record.extraTokenIds[1]));
    expect(offered).not.toContain(String(stranger.tokenId));

    // Add Credit Card is there and ready.
    await expect(adminPage.locator('#add_credit_card')).toBeVisible();
    await expect(adminPage.locator('#add_credit_card')).toBeEnabled();
    await expect(adminPage.locator('#add_credit_card')).toHaveValue('Add Credit Card');

    // The current billing address, as saved on the contact.
    const address = billingAddressOf(record.contactId)!;
    await expect(adminPage.locator('#billing_first_name')).toHaveValue(record.donor.firstName);
    await expect(adminPage.locator('#billing_last_name')).toHaveValue(record.donor.lastName);
    await expect(adminPage.locator('#billing_street_address-5')).toHaveValue(address.street_address);
    await expect(adminPage.locator('#billing_city-5')).toHaveValue(address.city);
    await expect(adminPage.locator('#billing_postal_code-5')).toHaveValue(address.postal_code);
    await expect(adminPage.locator('#billing_country_id-5 option:checked')).toHaveText(address['country_id:label']);
    await expect(adminPage.locator('#billing_state_province_id-5 option:checked')).toHaveText(address['state_province_id:label']);

    await expect(adminPage.locator('.ui-dialog-buttonpane').getByRole('button', { name: 'Save' })).toBeVisible();
    await expect(adminPage.locator('.ui-dialog-buttonpane').getByRole('button', { name: 'Cancel' })).toBeVisible();
  });

  test('PW-F4-02 staff add a card on eWAY\'s hosted window, choose it and save: the record uses the new card and the address is updated', async ({ adminPage, stub }) => {
    const record = createRecurring({ label: 'f402' });
    const before = recurRow(record.recurId);
    const card = newCard();
    scriptAddCard(stub, record, card);

    await openBillingDialog(adminPage, record);
    const old = cardsOf(record.contactId)[0];
    await expectCardList(adminPage, [cardLabel(old)]);

    await adminPage.locator('#billing_street_address-5').fill('77 New Road');
    await adminPage.locator('#billing_city-5').fill('Sydney');
    await adminPage.locator('#billing_postal_code-5').fill('2000');
    await chooseOption(adminPage, '#billing_state_province_id-5', 'New South Wales');

    // Add Credit Card: eWAY's hosted window opens for this contact, and the card is saved when staff pay there.
    const popup = await openHostedWindow(adminPage);
    await expect(popup.locator('#access-code')).not.toHaveText('');
    await popup.locator('#pay').click();
    await expect.poll(() => popup.isClosed()).toBe(true);
    await confirmCardList(adminPage);
    // The new card is listed (it lasts longest, so it comes first) and the old one is still offered. The old card
    // stays selected: staff have to choose the new one.
    await expectCardList(adminPage, [card.label, cardLabel(old)]);
    await expect(adminPage.locator('#contact_payment_token')).toHaveValue(String(record.tokenId));
    const saved = cardsOf(record.contactId);
    expect(saved).toHaveLength(2);
    const added = saved.find((c) => c.id !== record.tokenId)!;
    expect(added.token).toBe(card.token);
    expect(added.masked_account_number).toBe(card.masked);
    expect(added.expiry_date).toMatch(/^2035-03-31/);
    expect(added.payment_processor_id).toBe(record.processorId);
    // Nothing has changed on the recurring record yet.
    expect(recurRow(record.recurId).payment_token_id).toBe(record.tokenId);

    await adminPage.locator('#contact_payment_token').selectOption(String(added.id));
    await saveInDialog(adminPage);

    // The dialog closes and the contact's tab shows the success message.
    await expect(adminPage.locator('.ui-dialog form#UpdateBilling')).toHaveCount(0);
    await expect(adminPage.locator('#crm-notification-container')).toContainText(/Billing details for the recurring contribution of .* every .*have been updated/s);

    const after = recurRow(record.recurId);
    expect(after.payment_token_id).toBe(added.id);
    expect(after.processor_id).toBe(card.token);
    expect(after.payment_processor_id).toBe(record.processorId);
    expect(after['contribution_status_id:name']).toBe('In Progress');
    expect(after.failure_count).toBe(before.failure_count);
    expect(after.next_sched_contribution_date).toBe(before.next_sched_contribution_date);

    // The old card is kept, untouched, and nothing else was added.
    const kept = cardsOf(record.contactId);
    expect(kept.map((c) => c.id)).toEqual([record.tokenId, added.id]);
    expect(kept[0].token).toBe(record.token);
    expect(kept[0].masked_account_number).toBe(old.masked_account_number);

    // The billing address was updated in place (one billing address, not a second one).
    const address = billingAddressOf(record.contactId)!;
    expect(address.street_address).toBe('77 New Road');
    expect(address.city).toBe('Sydney');
    expect(address.postal_code).toBe('2000');
    expect(address['state_province_id:label']).toBe('New South Wales');
    expect(api4('Address', 'get', { select: ['id'], where: [['contact_id', '=', record.contactId], ['is_billing', '=', true]] })).toHaveLength(1);

    // CiviCRM logged the change as an activity and told the donor.
    expect(activitiesOf(record.contactId, 'Update Recurring Contribution Billing Details')).toHaveLength(1);
    expect(mailSubjectsFor(record.donor.email)).toHaveLength(1);

    // eWAY was asked to set up a hosted window and to confirm the card, and nothing was charged or changed there.
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(stub.requests('postTransaction')).toHaveLength(0);
    expect(stub.requests().map((r) => r.method).filter((m) => !['postAccessCodeShared', 'getTransaction', 'getCustomer'].includes(m))).toEqual([]);
    expect(contributionsOf(record.recurId)).toHaveLength(1);
  });

  // KNOWN DEFECT: the Save button, pressed on the form as CiviCRM renders it, does not work. The form posts back to its
  // own address without crid, and updateSubscriptionBillingInfo() reads crid from the request:
  //   CRM/Core/Payment/eWAYRecurring.php, updateSubscriptionBillingInfo(), CRM_Utils_Request::retrieve('crid', ...)
  // so every Save ends on an error page reading "Missing contribution id and token id." and changes nothing. Core
  // already hands the id to the method as $params['contributionRecurID'] (CRM_Contribute_Form_UpdateBilling::postProcess).
  // PHPUnit never sees it because those tests set $_REQUEST['crid']. test.fail() keeps this passing while the defect
  // exists and makes the run fail on the day it is fixed, so the annotation is removed then.
  test.describe('as CiviCRM renders the form', () => {
    test.use({ allowedProblems: [CORE_NOISE] });

    test('PW-F4-02 saving the form as CiviCRM renders it updates the recurring record', async ({ adminPage }) => {
      test.fail(true, 'Save fails with "Missing contribution id and token id.": updateSubscriptionBillingInfo() reads crid from a request that does not carry it.');
      const record = createRecurring({ label: 'f402real', extraCards: [{}] });
      await adminPage.goto(billingUrl(record));
      await expect(adminPage.locator('#contact_payment_token option')).toHaveCount(2);
      await adminPage.locator('#contact_payment_token').selectOption(String(record.extraTokenIds[0]));
      await adminPage.locator('#_qf_UpdateBilling_next-bottom').click();
      await adminPage.waitForLoadState('load');

      await expect(adminPage.locator('body')).not.toContainText('Missing contribution id and token id.');
      expect(recurRow(record.recurId).payment_token_id).toBe(record.extraTokenIds[0]);
    });
  });

  test('PW-F4-03 a card that is not added on eWAY\'s window changes nothing, and staff can try again', async ({ adminPage, stub }) => {
    const record = createRecurring({ label: 'f403' });
    const before = recurRow(record.recurId);
    const old = cardsOf(record.contactId)[0];
    const card = newCard();
    const failedSave = 'The credit card was not saved. Please try again.';

    // eWAY answers "no card yet" the first two times it is asked (the window was closed, then cancelled), and then
    // the card once it has really been added.
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F403A')));
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F403B')));
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F403C')));
    const nothingYet = responses.transactionFound({ TransactionID: '', TransactionStatus: false, ResponseCode: '', ResponseMessage: '', TokenCustomerID: '' });
    stub.respond('getTransaction', nothingYet);
    stub.respond('getTransaction', nothingYet);
    stub.respond('getTransaction', responses.transactionFound({ TokenCustomerID: card.token }), { repeat: true });
    stub.respond('getCustomer', responses.customerFound(card.token, `civi-${record.contactId}`, { Number: card.masked, ExpiryMonth: card.month, ExpiryYear: card.year }), { repeat: true });

    await openBillingDialog(adminPage, record);
    await expectCardList(adminPage, [cardLabel(old)]);

    const nothingChanged = () => {
      expect(cardsOf(record.contactId).map((c) => c.id), 'no new card').toEqual([record.tokenId]);
      const now = recurRow(record.recurId);
      expect(now.payment_token_id).toBe(before.payment_token_id);
      expect(now.processor_id).toBe(before.processor_id);
      expect(now['contribution_status_id:name']).toBe(before['contribution_status_id:name']);
    };
    const formStillWorks = async () => {
      await expectCardList(adminPage, [cardLabel(old)]);
      await expect(adminPage.locator('#contact_payment_token')).toBeEnabled();
      await expect(adminPage.locator('#add_credit_card')).toBeEnabled();
      await expect(adminPage.locator('.ui-dialog-buttonpane').getByRole('button', { name: 'Save' })).toBeEnabled();
    };

    // 1. Staff close eWAY's window without doing anything.
    const closed = await openHostedWindow(adminPage);
    await closed.close();
    await confirmCardList(adminPage);
    await expect(adminPage.locator('#crm-notification-container')).toContainText(failedSave);
    nothingChanged();
    await formStillWorks();

    // 2. Staff press Cancel on eWAY's window, which sends it back to the site's home page.
    const cancelled = await openHostedWindow(adminPage);
    await cancelled.locator('#cancel').click();
    await cancelled.waitForLoadState('load');
    await cancelled.close();
    await confirmCardList(adminPage);
    await expect(adminPage.locator('#crm-notification-container')).toContainText(failedSave);
    nothingChanged();
    await formStillWorks();

    // 3. The third go works: the form was never left in a bad state.
    await addCardOnHostedPage(adminPage, card);
    await expectCardList(adminPage, [card.label, cardLabel(old)]);
    expect(cardsOf(record.contactId)).toHaveLength(2);
    expect(stub.requests('postAccessCodeShared')).toHaveLength(3);
  });

  test('PW-F4-03 when eWAY refuses to set up its window, staff see the reason and nothing is added', async ({ adminPage, stub }) => {
    const record = createRecurring({ label: 'f403refused' });
    const before = recurRow(record.recurId);
    const old = cardsOf(record.contactId)[0];
    stub.respond('postAccessCodeShared', responses.errors('V6111'));

    await openBillingDialog(adminPage, record);
    await expectCardList(adminPage, [cardLabel(old)]);
    await adminPage.locator('#add_credit_card').click();

    // eWAY's own message is shown, no window opens, and the form is still usable.
    await expect(adminPage.locator('#crm-notification-container')).toContainText('Unauthorised API Access, Account Not PCI Certified');
    expect(adminPage.context().pages()).toHaveLength(1);
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(cardsOf(record.contactId).map((c) => c.id)).toEqual([record.tokenId]);
    expect(recurRow(record.recurId).payment_token_id).toBe(before.payment_token_id);
    await expectCardList(adminPage, [cardLabel(old)]);
    await expect(adminPage.locator('#add_credit_card')).toBeEnabled();
    await expect(adminPage.locator('.ui-dialog-buttonpane').getByRole('button', { name: 'Save' })).toBeEnabled();
  });

  test('PW-F4-03 Add Credit Card stays off until the billing details are complete, and eWAY is not asked', async ({ adminPage, stub }) => {
    const record = createRecurring({ label: 'f403blank' });
    await openBillingDialog(adminPage, record);
    await expectCardList(adminPage, [cardLabel(cardsOf(record.contactId)[0])]);
    await expect(adminPage.locator('#add_credit_card')).toBeEnabled();

    await adminPage.locator('#billing_street_address-5').fill('');
    await adminPage.locator('#billing_street_address-5').blur();
    await expect(adminPage.locator('#add_credit_card')).toBeDisabled();
    await expect(adminPage.locator('#add_credit_card_notification')).toHaveText('The Billing Details section must be completed before a Credit Card can be added');

    await adminPage.locator('#billing_street_address-5').fill('5 Another Street');
    await adminPage.locator('#billing_street_address-5').blur();
    await expect(adminPage.locator('#add_credit_card')).toBeEnabled();
    await expect(adminPage.locator('#add_credit_card_notification')).toHaveText('');
    expect(stub.requests()).toHaveLength(0);
  });

  // The record has reached Completed or Cancelled: its schedule is over, so billing details are refused. The contact's
  // tab only offers Change Billing Details for an active record, so these cases open the form on an In Progress record
  // and let the record end while the form is open (a scheduled job or another member of staff can do that), then press
  // Save.
  test.describe('records whose schedule has ended', () => {
    for (const status of ['Completed', 'Cancelled'] as const) {
      test(`PW-F4-04 a recurring contribution that has become ${status} cannot have its billing details updated`, async ({ adminPage }) => {
        const record = createRecurring({ label: `f404${status.toLowerCase()}`, extraCards: [{}] });
        const addressBefore = billingAddressOf(record.contactId)!;

        await openBillingDialog(adminPage, record);
        await expect(adminPage.locator('#contact_payment_token option')).toHaveCount(2);
        await adminPage.locator('#contact_payment_token').selectOption(String(record.extraTokenIds[0]));
        await adminPage.locator('#billing_street_address-5').fill('99 Refused Road');

        // The schedule ends while the form is open.
        api4('ContributionRecur', 'update', { values: { 'contribution_status_id:name': status }, where: [['id', '=', record.recurId]] });
        const before = recurRow(record.recurId);
        expect(before['contribution_status_id:name']).toBe(status);

        await saveInDialog(adminPage);

        // The refusal is shown as an error message, and the success message is not.
        await expectErrorMessage(adminPage, new RegExp(`Attempted to update billing details for a\\s+${status.toLowerCase()} contribution\\.`), record);
        await expect(adminPage.locator('#crm-notification-container')).not.toContainText('have been updated');

        // Nothing changed: not the card, the status, nor the billing address, and the donor was told nothing.
        const after = recurRow(record.recurId);
        expect(after.payment_token_id).toBe(before.payment_token_id);
        expect(after.processor_id).toBe(before.processor_id);
        expect(after['contribution_status_id:name']).toBe(status);
        expect(billingAddressOf(record.contactId)!.street_address).toBe(addressBefore.street_address);
        expect(activitiesOf(record.contactId, 'Update Recurring Contribution Billing Details')).toHaveLength(0);
        expect(mailSubjectsFor(record.donor.email)).toEqual([]);
      });
    }

    test('PW-F4-04 the contact\'s tab offers Change Billing Details only for a recurring contribution that is still running', async ({ adminPage }) => {
      const offered = async (record: RecurringRecord): Promise<number> => {
        await adminPage.goto(adminUrl('contact/view', { reset: 1, cid: record.contactId, selectedChild: 'contribute' }));
        await adminPage.getByRole('tab', { name: /Recurring Contributions/ }).click();
        await expect(adminPage.locator(`#contribution_recur-${record.recurId}`)).toBeVisible();
        return adminPage.locator('a[title="Change Billing Details"]').count();
      };
      expect(await offered(createRecurring({ label: 'f404tab' }))).toBe(1);
      expect(await offered(createRecurring({ label: 'f404tabcancelled', status: 'Cancelled' }))).toBe(0);
      expect(await offered(createRecurring({ label: 'f404tabcompleted', status: 'Completed' }))).toBe(0);
      // A Failed record is listed with the inactive ones, so the link is not offered for it either (PW-F4-06).
      expect(await offered(createRecurring({ label: 'f404tabfailed', status: 'Failed', failureCount: 3, retryDate: '-1 day' }))).toBe(0);
    });
  });

  test('PW-F4-06 staff update the card on a Failed record: it is In Progress again with no failures, and the next payment charges the new card', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    // A record that is due now, with an earlier Completed payment (the cron job needs one) and a second saved card.
    const record = createRecurring({ label: 'f406', nextDue: '-1 day', extraCards: [{}] });
    const newToken = cardsOf(record.contactId).find((c) => c.id === record.extraTokenIds[0])!;
    const retries = maxRetries();
    expect(retries).toBeGreaterThan(0);
    const initial = recurRow(record.recurId);
    setOtherDueRecurringAside(record.recurId);

    // Every charge is declined, until the record has used up its retries and is marked Failed. (Each run needs the
    // retry to be due, which takes days in real life, so time is moved on between runs.)
    for (let run = 1; run <= retries; run++) {
      if (run > 1) {
        makeRetryDue(record.recurId);
      }
      stub.reset();
      stub.respond('postTransaction', responses.paymentDeclined('D4401', newTransactionId()));
      runRecurringJob();
      expect(stub.requests('postTransaction'), `run ${run} asked eWAY once`).toHaveLength(1);
      const state = recurRow(record.recurId);
      expect(state.failure_count, `failures after run ${run}`).toBe(run);
      expect(state['contribution_status_id:name'], `status after run ${run}`).toBe(run < retries ? 'In Progress' : 'Failed');
      expect(state.failure_retry_date, `retry date after run ${run}`).toBeTruthy();
    }
    const failed = recurRow(record.recurId);
    expect(failed['contribution_status_id:name']).toBe('Failed');
    expect(failed.failure_count).toBe(retries);
    // The first run moved the schedule on one month; a failed charge does not bring it back.
    const scheduled = failed.next_sched_contribution_date;
    expect(String(scheduled) > String(initial.next_sched_contribution_date)).toBe(true);
    // Still charging the first card.
    expect(failed.payment_token_id).toBe(record.tokenId);

    // Staff change the card.
    // The contact's tab does not offer Change Billing Details for a Failed record (it is listed with the inactive
    // ones), so the dialog is opened with the address the link would have used.
    await openBillingDialogByAddress(adminPage, record);
    await expect(adminPage.locator('#contact_payment_token option')).toHaveCount(2);
    await adminPage.locator('#contact_payment_token').selectOption(String(newToken.id));
    await saveInDialog(adminPage);
    await expect(adminPage.locator('.ui-dialog form#UpdateBilling')).toHaveCount(0);
    await expect(adminPage.locator('#crm-notification-container')).toContainText('have been updated');

    const recovered = recurRow(record.recurId);
    expect(recovered['contribution_status_id:name']).toBe('In Progress');
    expect(recovered.failure_count).toBe(0);
    expect(recovered.failure_retry_date).toBeFalsy();
    expect(recovered.payment_token_id).toBe(newToken.id);
    expect(recovered.processor_id).toBe(newToken.token);
    // The schedule date was left alone.
    expect(recovered.next_sched_contribution_date).toBe(scheduled);

    // The record is not due until its date arrives, so the job does nothing today.
    const contributionsBefore = contributionsOf(record.recurId).length;
    stub.reset();
    runRecurringJob();
    expect(stub.requests('postTransaction')).toHaveLength(0);
    expect(contributionsOf(record.recurId)).toHaveLength(contributionsBefore);

    // When the date arrives, the next run charges the new card, once, and the payment completes.
    makeNextPaymentDue(record.recurId);
    stub.reset();
    const transactionId = newTransactionId();
    stub.respond('postTransaction', responses.paymentApproved(transactionId));
    runRecurringJob();
    const charges = stub.requests('postTransaction');
    expect(charges).toHaveLength(1);
    expect((charges[0].args[0] as any).Customer.TokenCustomerID).toBe(newToken.token);
    const latest = contributionsOf(record.recurId).pop()!;
    expect(latest['contribution_status_id:name']).toBe('Completed');
    expect(String(latest.trxn_id)).toBe(String(transactionId));
    const charged = recurRow(record.recurId);
    expect(charged['contribution_status_id:name']).toBe('In Progress');
    expect(charged.failure_count).toBe(0);
  });
});

/**
 * PW-F4-05 and PW-F4-07: who can use the form. CiviCRM's page permission is "make online contributions", and the form
 * itself lets a user in only for their own record (or with staff rights, "edit contributions"): the owner is known
 * from the signed-in user or from a checksum (cs) for the contact. Anyone else is sent away.
 */
test.describe('update billing details: who can open the form', () => {
  const NO_ACCESS = /You do not have permission to access this page/;
  test.use({ allowedProblems: [CORE_NOISE] });

  /** The donor's street, which must not reach anyone who may not see the record. */
  async function expectNotShown(page: Page, record: RecurringRecord): Promise<void> {
    await expect(page.locator('#UpdateBilling')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText(record.donor.street);
    await expect(page.locator('body')).not.toContainText(record.donor.email);
  }

  function checksumFor(contactId: number): string {
    return evaluate(`echo CRM_Contact_BAO_Contact_Utils::generateChecksum(${Number(contactId)});`);
  }

  function frontBilling(record: RecurringRecord, extra: Record<string, string | number> = {}): string {
    return frontUrl('contribute/updatebilling', { reset: 1, crid: record.recurId, cid: record.contactId, context: 'contribution', ...extra });
  }

  test('PW-F4-05 a visitor with no checksum is sent away and sees nothing of the record', async ({ anonPage }) => {
    const record = createRecurring({ label: 'f405anon' });
    await anonPage.goto(frontBilling(record));
    await anonPage.waitForURL((url) => !url.href.includes('updatebilling'));
    await expectNotShown(anonPage, record);
    expect(recurRow(record.recurId).payment_token_id).toBe(record.tokenId);
  });

  test('PW-F4-05 a bad checksum is sent away and sees nothing of the record', async ({ anonPage }) => {
    const record = createRecurring({ label: 'f405bad' });
    await anonPage.goto(frontBilling(record, { cs: 'deadbeef_1_1' }));
    await anonPage.waitForURL((url) => !url.href.includes('updatebilling'));
    await expectNotShown(anonPage, record);
  });

  test('PW-F4-05 another contact\'s checksum does not open this contact\'s recurring record', async ({ anonPage }) => {
    const record = createRecurring({ label: 'f405mine' });
    const stranger = createRecurring({ label: 'f405theirs' });

    // The stranger's own checksum, used with the stranger's contact id on my recurring record...
    await anonPage.goto(frontBilling(record, { cid: stranger.contactId, cs: checksumFor(stranger.contactId) }));
    await anonPage.waitForURL((url) => !url.href.includes('updatebilling'));
    await expectNotShown(anonPage, record);

    // ...and with my contact id, which the checksum does not belong to.
    await anonPage.goto(frontBilling(record, { cs: checksumFor(stranger.contactId) }));
    await anonPage.waitForURL((url) => !url.href.includes('updatebilling'));
    await expectNotShown(anonPage, record);

    expect(recurRow(record.recurId).payment_token_id).toBe(record.tokenId);
    expect(recurRow(stranger.recurId).payment_token_id).toBe(stranger.tokenId);
  });

  test('PW-F4-05 signed-in users without "make online contributions" cannot open the form, whatever else they may do', async ({ financePage, tokenviewerPage, limitedPage }) => {
    // None of these personas holds "make online contributions", the permission of the page, so CiviCRM refuses the
    // page before the form is built. That includes the finance persona, who can edit contributions.
    const record = createRecurring({ label: 'f405staff' });
    for (const page of [financePage, tokenviewerPage, limitedPage]) {
      await page.goto(billingUrl(record));
      await expect(page.locator('body')).toContainText(NO_ACCESS);
      await expectNotShown(page, record);
    }
    expect(recurRow(record.recurId).payment_token_id).toBe(record.tokenId);
  });

  test('PW-F4-07 a user who arrives with only a checksum gets the form without any stored cards, and cannot finish it', async ({ adminPage, browser }) => {
    // The stored-card list is loaded with the PaymentToken API, which needs staff permission, so for a donor the
    // request is refused (HTTP 403) and the list stays at "No cards found.". The form is effectively staff only.
    // Add Credit Card is staff only as well (civicrm/ewayrecurring/createtoken needs "access CiviContribute").
    const record = createRecurring({ label: 'f407' });
    const cs = checksumFor(record.contactId);

    // Control: staff see the cards on the same record.
    await adminPage.goto(billingUrl(record));
    await expectCardList(adminPage, [cardLabel(cardsOf(record.contactId)[0])]);

    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const donorPage = await context.newPage();
    const refusals: string[] = [];
    donorPage.on('response', (response) => {
      if (response.status() === 403 && /PaymentToken/.test(response.url())) {
        refusals.push(response.url());
      }
    });
    try {
      await donorPage.goto(frontBilling(record, { cs }));
      // The donor is let in to their own record: the form, with their name and address filled in.
      await expect(donorPage.locator('#UpdateBilling')).toBeVisible();
      await expect(donorPage.locator('#billing_street_address-5')).toHaveValue(record.donor.street);
      // But the card list is empty: the API refuses the donor.
      await expect.poll(() => refusals.length, { message: 'the PaymentToken request is refused' }).toBeGreaterThan(0);
      await expect(donorPage.locator('#contact_payment_token option')).toHaveText(['No cards found.']);

      // Save needs a card, so the donor cannot complete it. Nothing is changed.
      await donorPage.getByRole('button', { name: 'Save' }).click();
      await expect(donorPage.locator('body')).toContainText('Stored Credit Card is a required field.');
      expect(recurRow(record.recurId).payment_token_id).toBe(record.tokenId);
      expect(billingAddressOf(record.contactId)!.street_address).toBe(record.donor.street);
    } finally {
      await context.close();
    }
  });
});

/**
 * The form prints a PHP warning on every page that shows it. Core's payment form reads is_required from each field the
 * extension lists (CRM/Core/Payment/Form.php, addCommonFields()), and the Add Credit Card field has none:
 *   CRM/Core/Payment/eWAYRecurring.php, getPaymentFormFieldsMetadata(), the 'add_credit_card' entry.
 * It is the same on the back-office credit card form. test.fail() keeps this passing while the defect exists.
 */
test.describe('update billing details: known defects in the page and its script', () => {
  test.use({ allowedProblems: [new RegExp(`${IS_REQUIRED_WARNING.source}|${CARD_LIST_SCRIPT_ERROR.source}`)] });

  test('PW-F4-01 the form prints no PHP warning', async ({ adminPage }) => {
    test.fail(true, 'Undefined array key "is_required": the Add Credit Card field metadata has no is_required (core 6.16.5 reads it unguarded).');
    const record = createRecurring({ label: 'f401warn' });
    await adminPage.goto(billingUrl(record));
    await expect(adminPage.locator('#add_credit_card')).toBeVisible();
    await expect(adminPage.locator('body')).not.toContainText(IS_REQUIRED_WARNING);
  });

  // KNOWN DEFECT: each time a card is added on the form opened as a full page (not in the dialog), js/eway.js raises
  // "values is not iterable". After the hosted window,
  // CRM.eway.addCreditCard() calls savetoken without an access code, which answers with the API result object in
  // data.message, and passes it to CRM.eway.updateOptions(), which loops over it as if it were the list of cards. The
  // list is still refreshed by the setPaymentTokenOptions() call that follows, so staff do not see a fault. test.fail()
  // keeps this passing while the defect exists.
  test('PW-F4-02 adding a card raises no script error', async ({ adminPage, stub }) => {
    test.fail(true, 'js/eway.js: updateOptions(data.message) is given the API result object, not its values ("values is not iterable").');
    const record = createRecurring({ label: 'f402script' });
    const card = newCard();
    scriptAddCard(stub, record, card);
    const errors: string[] = [];
    adminPage.on('pageerror', (error) => errors.push(error.message));
    await adminPage.goto(billingUrl(record));
    await expectCardList(adminPage, [cardLabel(cardsOf(record.contactId)[0])]);
    await addCardOnHostedPage(adminPage, card);
    // The list is refreshed by a separate request that can finish before the one that raises the error.
    await adminPage.waitForTimeout(2000);
    expect(errors).toEqual([]);
  });
});
