import { expect, type Locator, type Page } from '@playwright/test';
import { adminUrl } from './cms';
import type { RecurringRecord } from './recurring-records';

/**
 * Helpers for the screens a recurring contribution shows up on, for the back-office specs that start the cron job and
 * then look at what staff see: the contact's Contributions, Recurring Contributions and Activities tabs, and the
 * recurring contribution's own page. Test plan: developer/TEST_PLAN.md section 5.2.
 */

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** The English ordinal of a day of the month: 1st, 2nd, 3rd, 4th, 11th, 21st. */
function ordinal(day: number): string {
  if (day % 100 >= 11 && day % 100 <= 13) {
    return `${day}th`;
  }
  return `${day}${['th', 'st', 'nd', 'rd'][day % 10] ?? 'th'}`;
}

/**
 * A date as these screens print it ("December 14th, 2026"), from the database's 'YYYY-MM-DD HH:MM:SS'. The time is left
 * out: CiviCRM prints it in the site's own style and a case that only cares about the day does not want to depend on that.
 */
export function civiDay(databaseDate: string): string {
  const [year, month, day] = databaseDate.slice(0, 10).split('-').map(Number);
  return `${MONTHS[month - 1]} ${ordinal(day)}, ${year}`;
}

/** Open a contact's Contributions tab and wait for its list of contributions. */
export async function openContributionsTab(page: Page, contactId: number): Promise<void> {
  await page.goto(adminUrl('contact/view', { reset: 1, cid: contactId, selectedChild: 'contribute' }));
  await expect(page.locator('#contributionSearch, table.selector').first()).toBeVisible();
}

/** The contributions listed on the Contributions tab (open it first), newest first as CiviCRM lists them. */
export function contributionRows(page: Page): Locator {
  return page.locator('tr[id^="rowid"]');
}

/** One contribution's row on the Contributions tab. */
export function contributionRow(page: Page, contributionId: number): Locator {
  return page.locator(`tr#rowid${contributionId}`);
}

/** Open the Recurring Contributions sub-tab of the contact's Contributions tab. */
export async function openRecurringTab(page: Page, contactId: number): Promise<void> {
  await openContributionsTab(page, contactId);
  await page.getByRole('tab', { name: /Recurring Contributions/ }).click();
}

/** A recurring contribution's row on the Recurring Contributions sub-tab (open it first). */
export function recurringRow(page: Page, record: Pick<RecurringRecord, 'recurId'>): Locator {
  return page.locator(`#contribution_recur-${record.recurId}`);
}

/** Open the contact's Activities tab and wait for its list. */
export async function openActivitiesTab(page: Page, contactId: number): Promise<void> {
  await page.goto(adminUrl('contact/view', { reset: 1, cid: contactId, selectedChild: 'activity' }));
  await expect(page.locator('table.selector, table.crm-ajax-table').first()).toBeVisible();
}

/** The address of the recurring contribution's own page. */
export function recurringViewUrl(record: Pick<RecurringRecord, 'recurId' | 'contactId'>): string {
  return adminUrl('contact/view/contributionrecur', { reset: 1, id: record.recurId, cid: record.contactId, context: 'contribution' });
}

/** The address of the Update Recurring Contribution form (the one behind the Edit link). */
export function updateRecurringUrl(record: Pick<RecurringRecord, 'recurId' | 'contactId'>): string {
  return adminUrl('contribute/updaterecur', { reset: 1, action: 'update', crid: record.recurId, cid: record.contactId, context: 'contribution' });
}

/** The address of the Cancel Recurring Contribution form (the one behind the Cancel link). */
export function cancelRecurringUrl(record: Pick<RecurringRecord, 'recurId' | 'contactId'>): string {
  return adminUrl('contribute/unsubscribe', { reset: 1, crid: record.recurId, cid: record.contactId, context: 'contribution' });
}

/**
 * Open the Failed Recurring Contributions list (a Search Kit page) and wait until it has asked the server for its rows and
 * drawn them, so a case can also check that a record is not on it.
 */
export async function openFailedList(page: Page): Promise<void> {
  const rows = page.waitForResponse((response) => response.request().method() === 'POST' && /ajax(\/|%2F)api4$/.test(response.url()));
  await page.goto(adminUrl('contribute/failedrecurring', { reset: 1 }));
  await expect(page.getByRole('heading', { name: 'Failed Recurring Contributions' }).first()).toBeVisible();
  await rows;
  await page.waitForLoadState('networkidle');
}

/** A recurring contribution's row on the Failed Recurring Contributions list, found by the id in its ID column. */
export function failedListRow(page: Page, recurId: number): Locator {
  return page.locator('tbody tr').filter({
    has: page.locator('[data-field-name="id"]').filter({ hasText: new RegExp(`^\\s*${recurId}\\s*$`) }),
  });
}

/**
 * Core's own "Recurring Contribution" page prints "Undefined array key invoice_id" twice for every recurring record,
 * whatever the processor (a template reads a key the page never sets). That is CiviCRM's, not this extension's, so the
 * cases that open the page allow it.
 */
export const RECURRING_VIEW_CORE_NOISE = /Undefined array key "invoice_id"/;

/**
 * The contact's tabs load their lists through Search Kit, and a list still loading when the next page is opened has its
 * request cancelled by the browser. That is the test moving on, not a failure of the page.
 */
export const SEARCH_ABORTED = /SearchDisplay%2Frun \(net::ERR_ABORTED\)/;

/** Both of the above, as one pattern (test.use() reads a list whose second item is an object as a pair). */
export const SCREEN_NOISE = new RegExp(`${RECURRING_VIEW_CORE_NOISE.source}|${SEARCH_ABORTED.source}`);
