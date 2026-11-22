import { expect, type Page } from '@playwright/test';
import { api4, evaluate, evaluateJson } from './civi';
import { frontUrl } from './cms';
import { responses, STUB_HOST } from './eway-stub';
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

/** The id of a seeded contribution page, found by its title. */
export function pageIdByTitle(title: string): number {
  const rows = api4<{ id: number }>('ContributionPage', 'get', {
    select: ['id'],
    where: [['title', '=', title]],
  });
  if (!rows.length) {
    throw new Error(`The seeded contribution page "${title}" does not exist. Run the setup script.`);
  }
  return rows[0].id;
}

/** The id of the seeded once-off contribution page. */
export function onceOffPageId(): number {
  return pageIdByTitle(testData.pages.onceOff.title);
}

/** The id of the seeded once-off page whose title has accented characters (it goes to eWAY in the description). */
export function nonAsciiPageId(): number {
  return pageIdByTitle(testData.pages.nonAscii.title);
}

/** The id of the seeded page whose only price option is free. */
export function freePageId(): number {
  return pageIdByTitle(testData.pages.free.title);
}

/** The id of the seeded recurring-enabled page (tick box, interval, unit and installments). */
export function recurringPageId(): number {
  return pageIdByTitle(testData.pages.recurring.title);
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

/**
 * A donor with an email address no earlier run has used, so a test that counts records, or that needs a new contact,
 * is not disturbed by what other tests left behind. The label shows in the address, which helps when reading the site.
 */
export function uniqueDonor(label: string, overrides: Partial<Donor> = {}): Donor {
  const unique = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
  return { ...DEFAULT_DONOR, email: `playwright.${label}.${unique}@example.test`, ...overrides };
}

/** A fresh access code, so tests do not share one. */
export function newAccessCode(label: string): string {
  return `AC-${label}-${Math.random().toString(36).slice(2, 8).toUpperCase()}`;
}

/**
 * Choose an option of a CiviCRM select box by its label. The visible control is a select2 widget over a hidden
 * select, so the select is set directly and the change event is raised the way CiviCRM listens for it.
 */
export async function chooseOption(page: Page, selector: string, label: string): Promise<void> {
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
  // The price set's script can redraw the radio buttons just after the page loads (seen on a slower CI site, at
  // phone width), which drops the first click. Try again until the choice sticks.
  const radio = page.getByRole('radio', { name: new RegExp(`\\$${amount}\\b`) });
  await expect(async () => {
    await radio.check();
    await expect(radio).toBeChecked({ timeout: 1000 });
  }).toPass({ timeout: 15000 });
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

/**
 * The contribution a donor's confirm step created, found by email address (the newest one). Pass `isTest` to look
 * only at live (false) or test-mode (true) contributions.
 */
export function latestContribution(email: string, isTest?: boolean): Record<string, any> | undefined {
  const where: any[] = [['contact_id.email_primary.email', '=', email]];
  if (isTest !== undefined) {
    where.push(['is_test', '=', isTest]);
  }
  const rows = api4('Contribution', 'get', {
    select: ['id', 'contribution_status_id:name', 'total_amount', 'trxn_id', 'invoice_id', 'is_test', 'contribution_page_id', 'contact_id', 'contribution_recur_id'],
    where,
    orderBy: { id: 'DESC' },
    limit: 1,
  });
  return rows[0];
}

/** How many contributions (live and test) a donor's email address has. */
export function contributionCount(email: string): number {
  return api4('Contribution', 'get', {
    select: ['id'],
    where: [['contact_id.email_primary.email', '=', email], ['is_test', 'IN', [false, true]]],
  }).length;
}

/** The verification queue row for a contribution (the extension offers it through APIv3 only). */
export function queueRow(contributionId: number): Record<string, any> | undefined {
  const values = evaluateJson<Record<string, any>>(
    `echo json_encode(civicrm_api3('EwayContributionTransactions', 'get', ['contribution_id' => ${Number(contributionId)}])['values']);`,
  );
  return Object.values(values)[0];
}

/**
 * Mark every verification queue row still waiting, except the one for `keepContributionId`, as failed.
 *
 * The verification job asks eWAY about every row that is still waiting, and the stub answers by method, in the order
 * answers were queued, not by access code. Earlier tests leave their rows waiting (a donor who goes to the hosted
 * page and never pays), so a test that runs the job must put those aside or the job asks about them too and takes
 * the answers meant for its own row. Only the queue rows change; the contributions are left as they are.
 */
export function setOtherQueueRowsAside(keepContributionId: number): void {
  evaluate(`
    $rows = civicrm_api3('EwayContributionTransactions', 'get', ['status' => 0, 'options' => ['limit' => 0]])['values'];
    foreach ($rows as $row) {
      if ((int) $row['contribution_id'] !== ${Number(keepContributionId)}) {
        civicrm_api3('EwayContributionTransactions', 'create', ['id' => $row['id'], 'status' => 2]);
      }
    }
  `);
}

/** Run the verification job (the APIv3 call the scheduled job makes) and return its counts. */
export function runVerificationJob(): Record<string, number> {
  return evaluateJson<Record<string, number>>(
    `echo json_encode(civicrm_api3('EwayContributionTransactions', 'validate', [])['values']);`,
  );
}

/** True when the page is wider than its window, so the donor would have to scroll sideways. */
export async function hasHorizontalOverflow(page: Page): Promise<boolean> {
  return page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
}

/**
 * An empty string when the page fits its window, otherwise the widest few elements that stick out past the right
 * edge (tag, id, classes, right edge in pixels), so a failure says what to fix.
 */
export async function horizontalOverflowReport(page: Page): Promise<string> {
  return page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth <= width + 1) {
      return '';
    }
    const found: { text: string; right: number }[] = [];
    for (const el of Array.from(document.body.querySelectorAll('*'))) {
      const box = el.getBoundingClientRect();
      if (box.width > 0 && box.right > width + 1) {
        const id = el.id ? `#${el.id}` : '';
        const classes = typeof el.className === 'string' && el.className ? `.${el.className.trim().split(/\s+/).slice(0, 3).join('.')}` : '';
        found.push({ text: `${el.tagName.toLowerCase()}${id}${classes}`, right: Math.round(box.right) });
      }
    }
    // Keep the innermost ones: a container is only wide because something inside it is.
    const stickingOut = new Set(Array.from(document.body.querySelectorAll('*')).filter((el) => {
      const box = el.getBoundingClientRect();
      return box.width > 0 && box.right > width + 1;
    }));
    const leaves = Array.from(stickingOut).filter((el) => !Array.from(el.children).some((child) => stickingOut.has(child)));
    found.length = 0;
    for (const el of leaves) {
      const box = el.getBoundingClientRect();
      const id = el.id ? `#${el.id}` : '';
      const classes = typeof el.className === 'string' && el.className ? `.${el.className.trim().split(/\s+/).slice(0, 3).join('.')}` : '';
      const style = getComputedStyle(el);
      found.push({ text: `${el.tagName.toLowerCase()}${id}${classes} (width ${Math.round(box.width)}px, ${style.display}, ${style.position})`, right: Math.round(box.right) });
    }
    found.sort((a, b) => b.right - a.right);
    return `page is ${document.documentElement.scrollWidth}px wide in a ${width}px window; sticking out: ` +
      found.slice(0, 8).map((item) => `${item.text} (right edge ${item.right}px)`).join('; ');
  });
}

/**
 * Submit a QuickForm step the way a browser would if its script had not run: without the browser-side checks.
 * A donor with scripting off, or a script that posts the form directly, gets this path, so the server has to refuse
 * bad input itself. `buttonName` is the name of the step's submit button, for example `_qf_Main_upload`. Resolves
 * once the next page has loaded.
 */
export async function submitWithoutBrowserValidation(page: Page, buttonName: string): Promise<void> {
  // The new document will not have this mark, which is how the page change is noticed.
  await page.evaluate(() => {
    (window as any).__beforeSubmit = true;
  });
  await page.evaluate((name) => {
    const button = document.querySelector(`[name="${name}"]`) as HTMLElement | null;
    const form = (button ? button.closest('form') : document.querySelector('form.CRM_Contribute_Form_Contribution_Main')) as HTMLFormElement;
    const field = document.createElement('input');
    field.type = 'hidden';
    field.name = name;
    field.value = '1';
    form.appendChild(field);
    // The form's own submit() does not run the submit handlers, so the browser-side checks are skipped.
    HTMLFormElement.prototype.submit.call(form);
  }, buttonName);
  await page.waitForFunction(() => !(window as any).__beforeSubmit);
  await page.waitForLoadState('load');
}

/** On the confirm step, press Continue and wait for eWAY's hosted page (the fake one). */
export async function continueToHostedPage(page: Page): Promise<void> {
  await page.locator('#_qf_Confirm_next-bottom').click();
  await page.waitForURL((url) => url.hostname === STUB_HOST);
}

/** eWAY's answer when it has no result for the access code yet: the donor has not paid, or eWAY is still working. */
export function transactionNotProcessedYet(): Record<string, unknown> {
  return responses.transactionFound({ TransactionID: '', TransactionStatus: false, ResponseCode: '', ResponseMessage: '', TokenCustomerID: '' });
}

/** eWAY's answer for an approved once-off payment of `amount` dollars, with a transaction id no earlier run used. */
export function transactionApproved(amount: number): Record<string, unknown> {
  return responses.transactionFound({ TransactionID: newTransactionId(), TransactionStatus: true, TotalAmount: Math.round(amount * 100), TokenCustomerID: '' });
}

/** How a recurring contribution repeats: every `interval` `unit`s, optionally for a number of installments. */
export interface Recurrence {
  interval: number;
  unit: 'week' | 'month' | 'year';
  installments?: number;
}

/**
 * Tick "I want to contribute this amount every ..." on the recurring page and fill in the interval, the unit and,
 * when given, the number of installments. The three controls stay disabled until the box is ticked.
 */
export async function chooseRecurrence(page: Page, recurrence: Recurrence): Promise<void> {
  await page.locator('#is_recur').check();
  await page.locator('#frequency_interval').fill(String(recurrence.interval));
  await chooseOption(page, '#frequency_unit', `${recurrence.unit}(s)`);
  if (recurrence.installments !== undefined) {
    await page.locator('#installments').fill(String(recurrence.installments));
  }
}

/** The recurring contribution a donor's payment set up, found by email address (the newest one). */
export function latestRecurring(email: string): Record<string, any> | undefined {
  const rows = api4('ContributionRecur', 'get', {
    select: [
      'id', 'contact_id', 'amount', 'currency', 'frequency_unit', 'frequency_interval', 'installments',
      'contribution_status_id:name', 'payment_processor_id', 'processor_id', 'payment_token_id', 'trxn_id',
      'next_sched_contribution_date', 'failure_count', 'is_test',
    ],
    where: [['contact_id.email_primary.email', '=', email], ['is_test', 'IN', [false, true]]],
    orderBy: { id: 'DESC' },
    limit: 1,
  });
  return rows[0];
}

/** How many recurring contributions (live and test) a donor's email address has. */
export function recurringCount(email: string): number {
  return api4('ContributionRecur', 'get', {
    select: ['id'],
    where: [['contact_id.email_primary.email', '=', email], ['is_test', 'IN', [false, true]]],
  }).length;
}

/** The saved cards (payment token rows) a contact has, oldest first. */
export function tokensOf(contactId: number): Record<string, any>[] {
  return api4('PaymentToken', 'get', {
    select: ['id', 'token', 'masked_account_number', 'expiry_date', 'payment_processor_id', 'contact_id'],
    where: [['contact_id', '=', contactId]],
    orderBy: { id: 'ASC' },
  });
}

/** A customer token no earlier run used, shaped like the ones eWAY issues (digits only). */
export function newCustomerToken(): string {
  return `9${String(newTransactionId()).padStart(11, '0')}`.slice(0, 12);
}

/** eWAY's answer for an approved payment of `amount` dollars that also saved the card under `customerToken`. */
export function transactionApprovedWithCard(amount: number, customerToken: string): Record<string, unknown> {
  return responses.transactionFound({
    TransactionID: newTransactionId(),
    TransactionStatus: true,
    TotalAmount: Math.round(amount * 100),
    TokenCustomerID: customerToken,
  });
}

/** The site's own date today, as the PHP server sees it (it can differ from this machine's by a day). */
export function serverToday(): string {
  return evaluate("echo date('Y-m-d');");
}

/**
 * The date the extension schedules the next payment for: today plus `interval` units, at midnight, written
 * `YYYY-MM-DD 00:00:00`. Worked out here, not by asking the server, so the test does not just repeat the code.
 * Months and years overflow the way PHP's strtotime does (31 January plus a month is the 3rd of March).
 */
export function expectedNextPayment(today: string, recurrence: Pick<Recurrence, 'interval' | 'unit'>): string {
  const [year, month, day] = today.split('-').map(Number);
  const next = {
    week: () => Date.UTC(year, month - 1, day + 7 * recurrence.interval),
    month: () => Date.UTC(year, month - 1 + recurrence.interval, day),
    year: () => Date.UTC(year + recurrence.interval, month - 1, day),
  }[recurrence.unit]();
  return `${new Date(next).toISOString().slice(0, 10)} 00:00:00`;
}
