import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4 } from '../../fixtures/civi';
import { statePath } from '../../fixtures/global-setup';
import { SCREEN_NOISE } from '../../fixtures/recurring-screens';
import {
  AMOUNTS,
  createContactWithBilling,
  hasSandboxCredentials,
  prepareSandboxSite,
  removeSandboxCredentials,
  SANDBOX_PROCESSOR,
  SKIP_MESSAGE,
  skipWhenSandboxApproves,
  submitCardOnHostedPage,
  waitForSandboxHostedPage,
  type SandboxSite,
} from '../../fixtures/sandbox';

/**
 * PW-X-03 and PW-X-05: staff add a card for a contact from the back-office credit card form and charge it, on the REAL
 * eWAY sandbox. Add Credit Card asks the sandbox for a customer page (a Responsive Shared Page that only saves a card),
 * opens it in a new tab, staff enter the test card there, and eWAY sends that tab to the extension's savetoken page,
 * which reads the customer token and the card back from the sandbox and saves them. Submitting the form then charges
 * the token with a MOTO transaction on the sandbox. Nothing is stubbed. Test plan: developer/TEST_PLAN.md section 5.4.
 * How the stub is bypassed, the credentials, the test card and the amounts are explained in fixtures/sandbox.ts.
 *
 * The form is opened in test mode (mode=test), which is how CiviCRM uses the processor's test-mode record.
 */

// A declined card ends on a broken address in core's own error handling (DEF-64, core's), and the Add Credit Card
// button's own tab closes itself. Neither is the extension's.
test.use({ allowedProblems: [new RegExp(`${SCREEN_NOISE.source}|status of 404|ViewTransition`)] });

/** The back-office credit card form for a contact, in test mode. */
function cardFormUrl(contactId: number): string {
  return adminUrl('contact/view/contribution', { reset: 1, action: 'add', cid: contactId, context: 'contribution', mode: 'test' });
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

/** Open the card form for a contact with the sandbox processor chosen, and wait for its card list and button. */
async function openCardForm(page: Page, contactId: number): Promise<void> {
  await page.goto(cardFormUrl(contactId));
  await expect(page.locator('#payment_processor_id')).toBeAttached();
  await chooseFrom(page, 'payment_processor_id', SANDBOX_PROCESSOR);
  await expect(page.locator('#contact_payment_token')).toBeVisible();
  await expect(page.locator('#add_credit_card')).toBeVisible();
}

async function cardOptions(page: Page): Promise<string[]> {
  return (await page.locator('#contact_payment_token option').allInnerTexts()).map((text) => text.trim());
}

/**
 * Press Add Credit Card, enter the test card on eWAY's sandbox page in the new tab, and let eWAY send the tab back to the
 * site (which saves the card and closes the tab). Then press Ok to reload the card list.
 */
async function addTestCard(page: Page): Promise<void> {
  const popupOpened = page.context().waitForEvent('page');
  await page.locator('#add_credit_card').click();
  const popup = await popupOpened;
  await waitForSandboxHostedPage(popup);
  const closed = popup.waitForEvent('close', { timeout: 90_000 });
  await submitCardOnHostedPage(popup);
  await closed;
  await page.getByRole('button', { name: 'Ok' }).click();
}

/** Fill in the part of the form a payment needs (the contact is known), up to but not including Save. */
async function fillPayment(page: Page, amount: number): Promise<void> {
  await chooseFrom(page, 'financial_type_id', 'Donation');
  await page.locator('#total_amount').fill(amount.toFixed(2));
}

/** The contributions of a contact, oldest first. */
function contributionsOfContact(contactId: number): Record<string, any>[] {
  return api4('Contribution', 'get', {
    select: ['id', 'total_amount', 'trxn_id', 'contribution_status_id:name', 'contribution_recur_id', 'is_test'],
    where: [['contact_id', '=', contactId], ['is_test', 'IN', [false, true]]],
    orderBy: { id: 'ASC' },
  });
}

/** The saved cards of a contact. */
function savedCards(contactId: number): Record<string, any>[] {
  return api4('PaymentToken', 'get', {
    select: ['id', 'token', 'masked_account_number', 'expiry_date', 'payment_processor_id'],
    where: [['contact_id', '=', contactId]],
    orderBy: { id: 'ASC' },
  });
}

test.describe('adding a card and charging it on the real sandbox', { tag: '@sandbox' }, () => {
  test.skip(!hasSandboxCredentials(), SKIP_MESSAGE);

  let site: SandboxSite;

  test.beforeAll(async ({ browser }) => {
    if (!hasSandboxCredentials()) {
      return;
    }
    site = prepareSandboxSite();
    // Setting up the site changes what CiviCRM's cached script files hold, and the first page loaded after that can ask
    // for one that is not written yet (a 404, then "CRM.url called before initialization" and no card list). Load the
    // form once, unwatched, so the cases that follow find the files in place.
    const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: statePath('admin') });
    const page = await context.newPage();
    const { contactId } = createContactWithBilling('x0warm');
    await page.goto(cardFormUrl(contactId));
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await context.close();
  });

  test.afterAll(() => {
    if (hasSandboxCredentials()) {
      removeSandboxCredentials();
    }
  });

  test('PW-X-03 Add Credit Card saves the sandbox customer and its card, and the card can then be charged', async ({ adminPage }) => {
    test.setTimeout(240_000);
    const { contactId } = createContactWithBilling('x03');
    await openCardForm(adminPage, contactId);
    expect(await cardOptions(adminPage)).toEqual(['No cards found.']);

    await addTestCard(adminPage);

    // The card was read back from the sandbox: eWAY's customer token, the masked number and the expiry it holds.
    const cards = savedCards(contactId);
    expect(cards).toHaveLength(1);
    expect(String(cards[0].token)).toMatch(/^\d{6,}$/);
    expect(cards[0].masked_account_number).toBe('444433XXXXXX1111');
    expect(Number(cards[0].payment_processor_id)).toBe(site.testProcessorId);
    // Locale independent: the expiry is the last day of a month in the future.
    expect(String(cards[0].expiry_date).slice(0, 10) > new Date().toISOString().slice(0, 10)).toBeTruthy();
    // The list on the form shows it (last four digits, then month and year).
    // The list is reloaded from eWAY's slow sandbox. Wait for the card itself: the empty list is also one option
    // ("No cards found."), so waiting for one option can stop too early.
    await expect.poll(async () => (await cardOptions(adminPage))[0] ?? '', { timeout: 90_000 }).toMatch(/^1111 - \d{2}\/\d{4}$/);
    // Adding a card takes no payment.
    expect(contributionsOfContact(contactId)).toHaveLength(0);

    // Charge the card from the form: a MOTO transaction on the sandbox, approved.
    await fillPayment(adminPage, AMOUNTS.approved);
    await Promise.all([adminPage.waitForNavigation({ waitUntil: 'load' }), adminPage.locator('#_qf_Contribution_upload-bottom').click()]);
    const rows = contributionsOfContact(contactId);
    expect(rows).toHaveLength(1);
    expect(rows[0]['contribution_status_id:name']).toBe('Completed');
    expect(Number(rows[0].total_amount)).toBe(AMOUNTS.approved);
    expect(String(rows[0].trxn_id)).toMatch(/^\d{6,}$/);
    expect(rows[0].is_test).toBeTruthy();
    expect(rows[0].contribution_recur_id ?? null).toBeNull();
  });

  test('PW-X-05 adding a card is not checked with the issuer: it only saves the card and makes no payment', async ({ adminPage }) => {
    test.setTimeout(240_000);
    const { contactId } = createContactWithBilling('x05');
    await openCardForm(adminPage, contactId);
    await addTestCard(adminPage);

    // The card is saved and nothing else happened: no contribution, not even a failed one, no authorisation on record.
    expect(savedCards(contactId)).toHaveLength(1);
    expect(contributionsOfContact(contactId)).toHaveLength(0);
    expect(api4('Activity', 'get', { select: ['id'], where: [['source_contact_id', '=', contactId]] })).toHaveLength(0);
  });

  test('PW-X-05 a card eWAY accepted when it was added can still be declined when it is charged', async ({ adminPage }) => {
    test.setTimeout(240_000);
    const { contactId } = createContactWithBilling('x05d');
    await openCardForm(adminPage, contactId);
    await addTestCard(adminPage);
    expect(savedCards(contactId)).toHaveLength(1);
    await expect.poll(async () => (await cardOptions(adminPage))[0] ?? '', { timeout: 90_000 }).toMatch(/^1111 - \d{2}\/\d{4}$/);

    // $10.05 is D4405, Do Not Honour, on a sandbox account that uses the cents of the amount (see fixtures/sandbox.ts).
    await fillPayment(adminPage, AMOUNTS.declined);
    await adminPage.locator('#_qf_Contribution_upload-bottom').click();
    await expect.poll(() => contributionsOfContact(contactId).length, { timeout: 60_000 }).toBe(1);
    const row = contributionsOfContact(contactId)[0];
    skipWhenSandboxApproves(row['contribution_status_id:name'], AMOUNTS.declined);

    // The card was accepted when it was added (so adding a card does not verify it) and is declined now: the attempt is
    // on record as Failed and nothing is completed. CiviCRM keeps eWAY's message for its next screen.
    expect(row['contribution_status_id:name']).toBe('Failed');
    expect(Number(row.total_amount)).toBe(AMOUNTS.declined);
    await adminPage.goto(adminUrl('contact/view', { reset: 1, cid: contactId, selectedChild: 'contribute' }));
    await expect(adminPage.locator('#crm-notification-container')).toContainText('Do Not Honour');
    // The saved card is still there: a decline does not remove it.
    expect(savedCards(contactId)).toHaveLength(1);
  });
});
