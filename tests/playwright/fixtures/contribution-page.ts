import type { Page } from '@playwright/test';
import { api4, evaluateJson } from './civi';
import { frontUrl } from './cms';
import testData from './test-data.json';

/**
 * Helpers for the seeded once-off contribution page (see seed-data.php and test-data.json): find it, fill in the
 * donor's details, and move through its steps. The page has fixed amounts, an email address, billing name and
 * address fields, and no card fields because the card is entered on eWAY's hosted page.
 */

export interface Donor {
  email: string;
  firstName: string;
  lastName: string;
  street: string;
  city: string;
  postcode: string;
  country: string;
  state: string;
}

export const DEFAULT_DONOR: Donor = {
  email: 'playwright.donor@example.test',
  firstName: 'Playwright',
  lastName: 'Donor',
  street: '1 Test Street',
  city: 'Canberra',
  postcode: '2600',
  country: 'Australia',
  state: 'Australian Capital Territory',
};

/** The id of the seeded once-off contribution page. */
export function onceOffPageId(): number {
  const rows = api4<{ id: number }>('ContributionPage', 'get', {
    select: ['id'],
    where: [['title', '=', testData.pages.onceOff.title]],
  });
  if (!rows.length) {
    throw new Error(`The seeded contribution page "${testData.pages.onceOff.title}" does not exist. Run the setup script.`);
  }
  return rows[0].id;
}

/** The address of a contribution page on the front end. */
export function pageUrl(pageId: number, query: Record<string, string | number> = {}): string {
  return frontUrl('contribute/transact', { reset: 1, id: pageId, ...query });
}

/**
 * A fresh eWAY transaction id. CiviCRM refuses a second contribution with the same transaction id ("Duplicate
 * error"), so a fixed id would break the second run against the same site.
 */
export function newTransactionId(): number {
  return Number(String(Date.now()).slice(-9)) * 10 + Math.floor(Math.random() * 10);
}

/** A fresh access code, so tests do not share one. */
export function newAccessCode(label: string): string {
  return `AC-${label}-${Math.random().toString(36).slice(2, 8).toUpperCase()}`;
}

/**
 * Choose an option of a CiviCRM select box by its label. The visible control is a select2 widget over a hidden
 * select, so the select is set directly and the change event is raised the way CiviCRM listens for it.
 */
async function chooseOption(page: Page, selector: string, label: string): Promise<void> {
  const select = page.locator(selector);
  await select.locator('option', { hasText: label }).first().waitFor({ state: 'attached' });
  await select.evaluate((el, wanted) => {
    const select = el as HTMLSelectElement;
    const option = Array.from(select.options).find((o) => o.text.trim() === wanted);
    if (!option) {
      throw new Error(`No option "${wanted}" in ${select.id}`);
    }
    select.value = option.value;
    const jq = (window as any).CRM?.$ ?? (window as any).jQuery;
    if (jq) {
      jq(select).trigger('change');
    } else {
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, label);
}

/** Choose a fixed amount and fill in the donor's details. Leaves the page ready to submit. */
export async function fillDonation(page: Page, amount: number, donor: Donor = DEFAULT_DONOR): Promise<void> {
  await page.getByRole('radio', { name: new RegExp(`\\$${amount}\\b`) }).check();
  await page.locator('#email-5').fill(donor.email);
  await page.locator('#billing_first_name').fill(donor.firstName);
  await page.locator('#billing_last_name').fill(donor.lastName);
  await page.locator('#billing_street_address-5').fill(donor.street);
  await page.locator('#billing_city-5').fill(donor.city);
  // The country is chosen explicitly: a site may have no default, and the state list depends on it.
  await chooseOption(page, '#billing_country_id-5', donor.country);
  await chooseOption(page, '#billing_state_province_id-5', donor.state);
  await page.locator('#billing_postal_code-5').fill(donor.postcode);
}

/** Submit the first step ("Review your contribution") and wait for the confirm step. */
export async function reviewContribution(page: Page): Promise<void> {
  await page.locator('#_qf_Main_upload-bottom').click();
  await page.locator('#_qf_Confirm_next-bottom').waitFor();
}

/** The contribution a donor's confirm step created, found by email address (the newest one). */
export function latestContribution(email: string): Record<string, any> | undefined {
  const rows = api4('Contribution', 'get', {
    select: ['id', 'contribution_status_id:name', 'total_amount', 'trxn_id', 'invoice_id', 'is_test', 'contribution_page_id', 'contact_id', 'contribution_recur_id'],
    where: [['contact_id.email_primary.email', '=', email]],
    orderBy: { id: 'DESC' },
    limit: 1,
  });
  return rows[0];
}

/** The verification queue row for a contribution (the extension offers it through APIv3 only). */
export function queueRow(contributionId: number): Record<string, any> | undefined {
  const values = evaluateJson<Record<string, any>>(
    `echo json_encode(civicrm_api3('EwayContributionTransactions', 'get', ['contribution_id' => ${Number(contributionId)}])['values']);`,
  );
  return Object.values(values)[0];
}
