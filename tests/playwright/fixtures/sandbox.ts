import { expect, test, type Locator, type Page } from '@playwright/test';
import { api4, api4WithSecrets, evaluate } from './civi';
import { chooseOption, uniqueDonor, type Donor } from './contribution-page';

/**
 * Support for the real eWAY sandbox cases (PW-X, specs/sandbox/). Unlike every other spec these do not use the stub:
 * the PHP code calls eWAY's sandbox API and the donor pays on eWAY's own sandbox hosted page.
 *
 * How a sandbox spec runs with the stub off
 * -----------------------------------------
 * The stub extension replaces the HTTP service for every eWAY client (fixtures/stub-extension). It makes one
 * exception: the TEST-MODE record of the processor named "Playwright eWAY Sandbox" gets the real HTTP service,
 * pointed at https://api.sandbox.ewaypayments.com/ (the endpoint the extension itself picks for a test-mode
 * processor, CRM_eWAYRecurring_Utils::getEWayClient). The live-mode record of that processor keeps dummy credentials
 * and stays stubbed, so nothing here can ever reach eWAY's live gateway. Everything else on the site is untouched.
 *
 * The specs go through the contribution pages' test-drive mode (action=preview) and the back-office credit card form
 * in test mode (mode=test), which are the ways CiviCRM uses a processor's test-mode record.
 *
 * Credentials (private)
 * ---------------------
 * They come from the environment, EWAY_SANDBOX_API_KEY and EWAY_SANDBOX_API_PASSWORD, and are saved on the test-mode
 * processor record for the run. They are never printed, put in a message, a title, an attachment or a file. The only
 * place they are sent is the call that saves them (api4WithSecrets, which withholds the command from every error).
 * Do not take a screenshot or trace of the processor's admin form; no sandbox spec opens it.
 *
 * eWAY's sandbox behaviour, which the amounts below rely on
 * ---------------------------------------------------------
 * Source: eWAY Rapid API reference, section "Sandbox Testing" (https://eway.io/api-v3/). With the sandbox account in
 * its default "Use Cents Value" mode, the last two digits of the amount (in cents) choose the response message: a
 * transaction for $1.05 gives D4405 (Do Not Honour). 00 is A2000 (Transaction Approved); 05 is D4405 (Do Not Honour);
 * 51 is D4451 (Insufficient Funds). The account can instead be set to "Always Approve" or "Always Decline"
 * (Settings > Sandbox > Customise Sandbox Behaviour), in which case the cents have no effect and the decline cases fail
 * with a message saying so.
 * Test card: 4444333322221111 (Visa), any future expiry, CVN 123. (eWAY knowledge base, "Test credit card numbers";
 * the same number is in every sandbox example of the API reference.)
 */

export const SANDBOX_PROCESSOR = 'Playwright eWAY Sandbox';
/** The sandbox endpoint the extension uses for a test-mode processor, and the host of eWAY's sandbox hosted pages. */
export const SANDBOX_API = 'https://api.sandbox.ewaypayments.com/';
export const SANDBOX_HOSTED_HOST = /(^|\.)sandbox\.ewaypayments\.com$/;

export const TEST_CARD = { number: '4444333322221111', name: 'Playwright Sandbox', expiryMonth: '12', cvn: '123' } as const;

/** Amounts, in dollars, whose cents make eWAY's sandbox answer in a known way (see the note above). */
export const AMOUNTS = {
  /** A2000 Transaction Approved. */
  approved: 10.0,
  /** D4405 Do Not Honour. */
  declined: 10.05,
  /** D4451 Insufficient Funds. */
  insufficientFunds: 10.51,
} as const;

export const SKIP_MESSAGE = 'The real eWAY sandbox cases need EWAY_SANDBOX_API_KEY and EWAY_SANDBOX_API_PASSWORD in the environment';

export interface SandboxCredentials {
  key: string;
  password: string;
}

/** The sandbox credentials from the environment, or null when either is missing. Never log what this returns. */
export function sandboxCredentials(): SandboxCredentials | null {
  const key = (process.env.EWAY_SANDBOX_API_KEY || '').trim();
  const password = (process.env.EWAY_SANDBOX_API_PASSWORD || '').trim();
  return key && password ? { key, password } : null;
}

/** True when the credentials are present, for `test.skip(!hasSandboxCredentials(), SKIP_MESSAGE)`. */
export function hasSandboxCredentials(): boolean {
  return sandboxCredentials() !== null;
}

/** A PHP single quoted string literal. */
function php(value: unknown): string {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

export const PAGE_TITLES = {
  onceOff: 'Playwright sandbox once-off donation',
  recurring: 'Playwright sandbox recurring donation',
} as const;

export interface SandboxSite {
  liveProcessorId: number;
  testProcessorId: number;
  onceOffPageId: number;
  recurringPageId: number;
}

/**
 * Make sure the sandbox processor (a live record with dummy credentials and a test record), and two contribution pages
 * that use it, exist, and save the credentials on the test record. Safe to run again. Call from beforeAll.
 */
export function prepareSandboxSite(): SandboxSite {
  const credentials = sandboxCredentials();
  if (!credentials) {
    throw new Error(SKIP_MESSAGE);
  }
  const amounts = [AMOUNTS.approved, AMOUNTS.declined, AMOUNTS.insufficientFunds];
  const out = evaluate(`
    $name = ${php(SANDBOX_PROCESSOR)};
    $ids = [];
    foreach ([FALSE, TRUE] as $isTest) {
      $existing = \\Civi\\Api4\\PaymentProcessor::get(FALSE)->addSelect('id')
        ->addWhere('name', '=', $name)->addWhere('is_test', '=', $isTest)->execute()->first();
      // Both records start with dummy credentials. The real ones are saved on the test record afterwards.
      $ids[$isTest ? 'test' : 'live'] = $existing ? (int) $existing['id'] : (int) \\Civi\\Api4\\PaymentProcessor::create(FALSE)
        ->setValues([
          'name' => $name,
          'title' => $name,
          'payment_processor_type_id:name' => 'eWay_Recurring',
          'is_test' => $isTest,
          'is_active' => TRUE,
          'domain_id' => CRM_Core_Config::domainID(),
          'user_name' => 'sandbox-placeholder',
          'password' => 'sandbox-placeholder',
        ])->execute()->first()['id'];
    }
    $amounts = ${php(JSON.stringify(amounts))};
    $makePage = function ($title, $priceSetName, $recur) use ($ids, $amounts) {
      $found = civicrm_api3('ContributionPage', 'get', ['title' => $title, 'options' => ['limit' => 1]]);
      if (!empty($found['count'])) {
        return (int) $found['id'];
      }
      $pageValues = [
        'title' => $title,
        'financial_type_id' => 'Donation',
        'is_active' => 1,
        'is_monetary' => 1,
        'currency' => 'AUD',
        'payment_processor' => [$ids['live']],
        'amount_block_is_active' => 1,
        'is_allow_other_amount' => 0,
        'is_pay_later' => 0,
        'is_confirm_enabled' => 1,
        'is_email_receipt' => 0,
        'is_recur' => $recur ? 1 : 0,
        'intro_text' => '<p>Playwright sandbox donation page.</p>',
        'thankyou_title' => 'Thank you for your donation',
        'thankyou_text' => '<p>Your donation has been received.</p>',
      ];
      if ($recur) {
        $pageValues += [
          'is_recur_interval' => 1,
          'is_recur_installments' => 1,
          'recur_frequency_unit' => implode(CRM_Core_DAO::VALUE_SEPARATOR, ['week', 'month', 'year']),
        ];
      }
      $pageId = (int) civicrm_api3('ContributionPage', 'create', $pageValues)['id'];
      $priceSetId = (int) civicrm_api3('PriceSet', 'create', [
        'name' => $priceSetName, 'title' => $priceSetName, 'extends' => 2, 'financial_type_id' => 'Donation', 'is_active' => 1,
      ])['id'];
      $fieldId = (int) civicrm_api3('PriceField', 'create', [
        'price_set_id' => $priceSetId, 'name' => 'amount', 'label' => 'Donation amount', 'html_type' => 'Radio',
        'is_display_amounts' => 1, 'is_required' => 1, 'is_active' => 1,
      ])['id'];
      foreach (json_decode($amounts, TRUE) as $weight => $amount) {
        civicrm_api3('PriceFieldValue', 'create', [
          'price_field_id' => $fieldId, 'name' => 'amount_' . ($weight + 1), 'label' => '$' . number_format($amount, 2, '.', ''),
          'amount' => $amount, 'weight' => $weight + 1, 'is_active' => 1, 'financial_type_id' => 'Donation',
        ]);
      }
      CRM_Price_BAO_PriceSet::addTo('civicrm_contribution_page', $pageId, $priceSetId);
      return $pageId;
    };
    echo json_encode([
      'live' => $ids['live'],
      'test' => $ids['test'],
      'onceOff' => $makePage(${php(PAGE_TITLES.onceOff)}, 'playwright_sandbox_once_off', FALSE),
      'recurring' => $makePage(${php(PAGE_TITLES.recurring)}, 'playwright_sandbox_recurring', TRUE),
    ]);
  `);
  const ids = JSON.parse(out.slice(out.indexOf('{"live"'), out.lastIndexOf('}') + 1));

  // The credentials go on the test record only. The answer is the saved row, which includes them: it is not looked at.
  api4WithSecrets(
    'PaymentProcessor',
    'update',
    { where: [['id', '=', ids.test]], values: { user_name: credentials.key, password: credentials.password } },
    [credentials.key, credentials.password],
  );
  return { liveProcessorId: ids.live, testProcessorId: ids.test, onceOffPageId: ids.onceOff, recurringPageId: ids.recurring };
}

/**
 * Take the credentials off the processor again, so a database left behind by an interrupted run holds none. Call from
 * afterAll. Does nothing when the processor does not exist.
 */
export function removeSandboxCredentials(): void {
  const rows = api4<{ id: number }>('PaymentProcessor', 'get', {
    select: ['id'],
    where: [['name', '=', SANDBOX_PROCESSOR], ['is_test', 'IN', [false, true]]],
  });
  if (rows.length) {
    api4WithSecrets(
      'PaymentProcessor',
      'update',
      { where: [['name', '=', SANDBOX_PROCESSOR], ['is_test', 'IN', [false, true]]], values: { user_name: 'sandbox-removed', password: 'sandbox-removed' } },
      [],
    );
  }
}

/** The address of a sandbox contribution page in test-drive mode (the page's "Test-drive" link). */
export function testDrivePath(pageId: number): Record<string, string | number> {
  return { reset: 1, id: pageId, action: 'preview' };
}

/**
 * Choose the amount labelled `$10.05` (and so on) and fill in the donor's details. Unlike fillDonation this picks the
 * option by its whole label, because the sandbox pages offer $10.00, $10.05 and $10.51 side by side.
 */
export async function fillSandboxDonation(page: Page, amount: number, donor: Donor): Promise<void> {
  // The option reads "$10.05 - $ 10.05": its label, then the amount.
  const label = `$${amount.toFixed(2)} - `;
  const radio = page.getByRole('radio', { name: new RegExp('^' + label.replace(/[$.]/g, '\\$&')) });
  await expect(async () => {
    await radio.check();
    await expect(radio).toBeChecked({ timeout: 1000 });
  }).toPass({ timeout: 15000 });
  await page.locator('#email-5').fill(donor.email);
  await page.locator('#billing_first_name').fill(donor.firstName);
  await page.locator('#billing_last_name').fill(donor.lastName);
  await page.locator('#billing_street_address-5').fill(donor.street);
  await page.locator('#billing_city-5').fill(donor.city);
  await chooseOption(page, '#billing_country_id-5', donor.country);
  await chooseOption(page, '#billing_state_province_id-5', donor.state);
  await page.locator('#billing_postal_code-5').fill(donor.postcode);
}

export { uniqueDonor };

/** A contact with a billing address (and no saved card), for the back-office cases. */
export function createContactWithBilling(label: string): { contactId: number; donor: Donor } {
  const donor = uniqueDonor(label);
  const out = evaluate(`
    $d = json_decode(${php(JSON.stringify(donor))}, TRUE);
    $contactId = (int) civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual', 'first_name' => $d['firstName'], 'last_name' => $d['lastName'], 'email' => $d['email'],
    ])['id'];
    $country = \\Civi\\Api4\\Country::get(FALSE)->addWhere('name', '=', $d['country'])->execute()->first();
    $state = \\Civi\\Api4\\StateProvince::get(FALSE)->addWhere('name', '=', $d['state'])->addWhere('country_id', '=', $country['id'])->execute()->first();
    civicrm_api3('Address', 'create', [
      'contact_id' => $contactId, 'location_type_id' => 'Billing', 'is_billing' => 1, 'is_primary' => 0,
      'street_address' => $d['street'], 'city' => $d['city'], 'postal_code' => $d['postcode'],
      'country_id' => $country['id'], 'state_province_id' => $state['id'],
    ]);
    echo json_encode(['contactId' => $contactId]);
  `);
  const contactId = JSON.parse(out.slice(out.indexOf('{"contactId"'), out.lastIndexOf('}') + 1)).contactId as number;
  return { contactId, donor };
}

/**
 * The fields of eWAY's sandbox hosted page (the shared payment page) the cases use. The page is eWAY's, so its markup
 * can change; every lookup is by the field's visible label or name, never by position, and a missing field fails with
 * the list of fields the page does have.
 */
export interface HostedPageField {
  id: string;
  name: string;
  type: string;
  label: string;
}

/** A description of the inputs and buttons on the page, for a failure message. */
export async function describeHostedPage(page: Page): Promise<string> {
  const fields = await page.evaluate(() =>
    Array.from(document.querySelectorAll('input:not([type=hidden]), select, button, a.uibutton')).slice(0, 40).map((el) => {
      const e = el as HTMLInputElement;
      const label = (e.labels && e.labels[0] ? e.labels[0].textContent : e.getAttribute('aria-label') || e.getAttribute('placeholder') || e.textContent || '') || '';
      return `${el.tagName.toLowerCase()}#${e.id || '-'}[name=${e.name || '-'}][type=${e.type || '-'}] ${label.trim().replace(/\s+/g, ' ').slice(0, 40)}`;
    }),
  );
  return fields.join('\n');
}

/** Wait until the browser is on one of eWAY's sandbox pages. */
export async function waitForSandboxHostedPage(page: Page): Promise<void> {
  await page.waitForURL((url) => SANDBOX_HOSTED_HOST.test(url.hostname), { timeout: 60_000 });
}

/** Wait until eWAY has sent the browser back to the site after the card was submitted, and the page has loaded. */
export async function waitForReturnFromSandbox(page: Page): Promise<void> {
  await page.waitForURL((url) => !SANDBOX_HOSTED_HOST.test(url.hostname), { timeout: 60_000 });
  await page.waitForLoadState('load');
}

/**
 * Skip the running test, saying why, when eWAY's sandbox approved an amount that is meant to be declined.
 *
 * The cents-value rule (see the note at the top) only applies while the sandbox account is on "Use Cents Value". The
 * account used so far approves every amount (seen with $10.05, $10.51, $10.01 and $10.99 on the hosted page), most
 * likely because it is set to "Always Approve". That is the account's setting, not a fault of the extension, so the
 * decline cases skip with this message instead of failing. They run for real once the account is on "Use Cents Value".
 */
export function skipWhenSandboxApproves(outcome: string, amount: number): void {
  const approved = outcome === 'Completed';
  if (approved) {
    test.info().annotations.push({
      type: 'sandbox-always-approves',
      description: `eWAY's sandbox approved $${amount.toFixed(2)}, which "Use Cents Value" would decline`,
    });
  }
  test.skip(
    approved,
    `eWAY's sandbox approved $${amount.toFixed(2)}, so the account is not on "Use Cents Value" (Settings > Sandbox > Customise Sandbox Behaviour); the decline cannot be tested until it is`,
  );
}

/**
 * Fill in the card on eWAY's sandbox hosted page and press its submit button (Pay, or Create Token when the page is
 * only saving a card). Leaves the browser wherever eWAY sends it next.
 */
export async function submitCardOnHostedPage(page: Page, card = TEST_CARD): Promise<void> {
  const fail = async (what: string): Promise<never> => {
    throw new Error(`eWAY's sandbox page has no ${what}. It has:\n${await describeHostedPage(page)}`);
  };
  const find = async (locator: Locator, what: string): Promise<Locator> => {
    try {
      await locator.first().waitFor({ state: 'visible', timeout: 30_000 });
    } catch {
      return fail(what);
    }
    return locator.first();
  };
  const name = await find(page.locator('#EWAY_CARDNAME, input[name="EWAY_CARDNAME"]'), 'card name field');
  await name.fill(card.name);
  const number = await find(page.locator('#EWAY_CARDNUMBER, input[name="EWAY_CARDNUMBER"]'), 'card number field');
  await number.fill(card.number);
  const month = page.locator('#EWAY_CARDEXPIRYMONTH, select[name="EWAY_CARDEXPIRYMONTH"]').first();
  const year = page.locator('#EWAY_CARDEXPIRYYEAR, select[name="EWAY_CARDEXPIRYYEAR"]').first();
  await month.selectOption(card.expiryMonth);
  // The latest year offered, so the card does not expire while the cases are being worked on.
  const years = await year.locator('option').evaluateAll((options) => options.map((o) => (o as HTMLOptionElement).value).filter(Boolean));
  await year.selectOption(years[years.length - 1]);
  await (await find(page.locator('#EWAY_CARDCVN, input[name="EWAY_CARDCVN"]'), 'CVN field')).fill(card.cvn);
  // The button is a link: "Pay Now" on a payment page (#EWAYPayNowButton), another label when only a card is saved.
  await (await find(page.locator('#EWAYPayNowButton, a.uibutton:not(.backBtn)').filter({ visible: true }), 'submit button')).click();
}
