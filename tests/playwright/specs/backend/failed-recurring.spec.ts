import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4, setSettings } from '../../fixtures/civi';
import { browserApi4, openMenuEntry, resetEwaySettings } from '../../fixtures/backend';
import { expectedNextPayment, newTransactionId } from '../../fixtures/contribution-page';
import { responses, type EwayStub } from '../../fixtures/eway-stub';
import {
  copyRecurring,
  createRecurring,
  deleteRecurring,
  makeRetryDue,
  makeTestRecord,
  moveToProcessor,
  contributionsOf,
  recurRow,
  removeToken,
  restoreStatuses,
  runRecurringJob,
  setFailedAndFailingAside,
  setOtherDueRecurringAside,
  type RecurringRecord,
  type SavedStatuses,
} from '../../fixtures/recurring-records';
import {
  contributionRow,
  contributionRows,
  failedListHeader,
  failedListHeaderLabels,
  failedListIds,
  failedListRow,
  failedListTick,
  openContributionsTab,
  openFailedList,
  pressInReactivateDialog,
  reactivateDialog,
  reactivateRowButton,
  reactivateToolbarButton,
  SCREEN_NOISE,
} from '../../fixtures/recurring-screens';
import testData from '../../fixtures/test-data.json';

/**
 * PW-B-failed: the Failed Recurring Contributions list (a Search Kit page the extension adds at
 * civicrm/contribute/failedrecurring) and its Reactivate action, which puts a stuck record back In Progress with no failures.
 * Test plan: developer/TEST_PLAN.md section 5.2.
 *
 * The list shows every Failed and Failing eWAY record on the site, and earlier cases leave some behind. Each case takes those
 * off the list first (setFailedAndFailingAside marks them Cancelled) and puts them back afterwards, so the rows it sees are
 * its own and its records are on the first page.
 *
 * A record is made Failed in one of two ways: created directly (the cases that are about the list or the dialog) or driven
 * there by the cron job with the stub declining every charge (the cases that are about the whole loop, PW-B-failed-09, 11 and
 * 12). A Failed record is the cron job's own outcome once its retries are used up (DEF-3, decision 11).
 */

/** Core's noise on the recurring record's page, a list cancelled by navigation (see SCREEN_NOISE). */
test.use({ allowedProblems: [SCREEN_NOISE] });

/** A refused user gets HTTP 403 on a development site and 500 on a fresh CI site (core's own permission page). */
const REFUSAL_NOISE = /Cannot set response code|status of (403|500)|HTTP (403|500)/;
const REFUSAL_STATUS = [403, 500];
/** The Reactivate dialog's update call (the list's own data and the dialog's label lookup are different calls). */
const UPDATE_CALL = /ajax(\/|%2F)api4(\/|%2F)ContributionRecur(\/|%2F)update/;
/** test.use() reads a two item list as a value and its options, so more than one pattern is joined into one. */
const either = (...patterns: RegExp[]) => new RegExp(patterns.map((pattern) => pattern.source).join('|'));

let aside: SavedStatuses = {};

test.beforeEach(() => {
  resetEwaySettings();
  aside = setFailedAndFailingAside();
});

test.afterEach(() => {
  resetEwaySettings();
  // Whatever the case left Failed or Failing goes off the list too, then the earlier records come back.
  setFailedAndFailingAside();
  restoreStatuses(aside);
});

/** A Failed record as the cron job leaves one (retries used up), made directly. */
function failedRecord(label: string, extra: { failureCount?: number; retryDate?: string; nextDue?: string } = {}): RecurringRecord {
  return createRecurring({
    label,
    status: 'Failed',
    failureCount: extra.failureCount ?? 3,
    retryDate: extra.retryDate ?? '-1 day',
    nextDue: extra.nextDue,
  });
}

/** A due record with its due date yesterday, a Completed first payment, and nothing else due on the site. */
function dueRecord(label: string): RecurringRecord {
  const record = createRecurring({ label, nextDue: '-1 day' });
  setOtherDueRecurringAside(record.recurId);
  return record;
}

/** Run the job once with eWAY declining the card, and check eWAY was asked exactly once. */
function runDeclined(stub: EwayStub, record: RecurringRecord): void {
  stub.reset();
  stub.respond('postTransaction', responses.paymentDeclined('D4401', newTransactionId()));
  runRecurringJob();
  expect(stub.requests('postTransaction'), 'eWAY was asked once').toHaveLength(1);
  expect((stub.requests('postTransaction')[0].args[0] as any).Customer.TokenCustomerID).toBe(record.token);
}

/** A record the cron job drives to Failed: due, then declined until the limit of failed charges is used up. */
function failedThroughCron(stub: EwayStub, label: string, limit: number): RecurringRecord {
  setSettings({ eway_recurring_contribution_max_retry: limit });
  const record = dueRecord(label);
  for (let run = 1; run <= limit; run++) {
    if (run > 1) {
      makeRetryDue(record.recurId);
    }
    runDeclined(stub, record);
  }
  const state = recurRow(record.recurId);
  expect(state['contribution_status_id:name']).toBe('Failed');
  expect(state.failure_count).toBe(limit);
  return record;
}

/** The recurring record fields these cases compare before and after Reactivate. */
function snapshot(recurId: number): Record<string, any> {
  const row = recurRow(recurId);
  return {
    status: row['contribution_status_id:name'],
    failures: row.failure_count,
    retry: row.failure_retry_date ?? null,
    next: row.next_sched_contribution_date,
    amount: row.amount,
    token: row.payment_token_id,
    processor: row.payment_processor_id,
  };
}

/**
 * Choose a day in a date picker by clicking it (the box's own text format depends on the site's date setting, so nothing is
 * typed). Returns the date the picker stored, as yyyy-mm-dd, read back from the field behind the box.
 */
async function pickDay(page: Page, field: ReturnType<Page['locator']>, day = 20): Promise<string> {
  await field.locator('xpath=following-sibling::input[1]').click();
  const picker = page.locator('#ui-datepicker-div');
  await expect(picker).toBeVisible();
  await picker.locator('a.ui-state-default', { hasText: new RegExp(`^${day}$`) }).click();
  await expect(picker).toBeHidden();
  const stored = await field.inputValue();
  expect(stored).toMatch(new RegExp(`^\\d{4}-\\d{2}-${day}`));
  return stored.slice(0, 10);
}

/** Open the Reactivate dialog for one row, and wait for it. */
async function openRowDialog(page: Page, recurId: number) {
  await reactivateRowButton(page, recurId).click();
  const dialog = reactivateDialog(page);
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.alert-info')).toBeVisible();
  return dialog;
}

/** The notification CiviCRM shows after an action. */
function notification(page: Page) {
  return page.locator('#crm-notification-container');
}

test.describe('who can open the list', () => {
  // A user with "edit contributions" (and CiviContribute access) may open the list and use Reactivate, without any access
  // to payment processors. The list finds eWAY records with a calculated field, not a join to the processor, which APIv4
  // drops for anyone who may not read payment processors (DEF-62, feature FR-4).
  test('PW-B-failed-01 the finance user is offered the list under Contributions, sees the Failed and Failing eWAY records only, and can Reactivate one', async ({ financePage }) => {
    test.setTimeout(120_000);
    const failed = failedRecord('failed01finance');
    const failing = createRecurring({ label: 'failed01failing', status: 'Failing', failureCount: 1, retryDate: '+1 day' });
    const otherProcessor = failedRecord('failed01dummy');
    const [dummy] = api4<{ id: number }>('PaymentProcessor', 'get', {
      select: ['id'],
      where: [['name', '=', testData.processors.dummy], ['is_test', '=', false]],
    });
    moveToProcessor(otherProcessor.recurId, dummy.id);
    const before = snapshot(failed.recurId);

    // The entry is offered directly under Contributions, and goes to the list's address.
    await financePage.goto(adminUrl('contribute', { reset: 1 }));
    const entry = await openMenuEntry(financePage, 'Contributions', 'Failed Recurring Contributions');
    const parent = await entry.evaluate((a) => a.closest('ul')?.closest('li')?.querySelector(':scope > a')?.textContent?.trim());
    expect(parent).toBe('Contributions');
    expect(await entry.getAttribute('href')).toContain('civicrm%2Fcontribute%2Ffailedrecurring');
    const rows = financePage.waitForResponse((response) => response.request().method() === 'POST' && /ajax(\/|%2F)api4$/.test(response.url()));
    await entry.click();
    await expect(financePage.getByRole('heading', { name: 'Failed Recurring Contributions' }).first()).toBeVisible();
    expect((await rows).status(), 'the list loads for this user').toBe(200);
    await financePage.waitForLoadState('networkidle');

    // The list has the Failed and the Failing eWAY records, and not the other processor's.
    await expect(financePage.locator('.select2-search-choice')).toHaveText(['Failed', 'Failing']);
    await expect(failedListRow(financePage, failed.recurId)).toHaveCount(1);
    await expect(failedListRow(financePage, failing.recurId)).toHaveCount(1);
    await expect(failedListRow(financePage, otherProcessor.recurId)).toHaveCount(0);
    await expect(failedListRow(financePage, failed.recurId).locator('[data-field-name="contribution_status_id:label"]')).toHaveText('Failed');
    await expect(failedListRow(financePage, failed.recurId).locator('[data-field-name="contact_id.display_name"]')).toContainText(`${failed.donor.firstName} ${failed.donor.lastName}`);

    // And Reactivate works for them: the dialog, the update, the row leaves the list, the record is In Progress.
    await openRowDialog(financePage, failed.recurId);
    await pressInReactivateDialog(financePage, /Reactivate Recurring Contributions/);
    await expect(notification(financePage)).toContainText('Successfully reactivated 1 Recurring Contributions.');
    await expect(reactivateDialog(financePage)).toHaveCount(0);
    expect(snapshot(failed.recurId)).toEqual({ ...before, status: 'In Progress', failures: 0, retry: null });
    await expect(failedListRow(financePage, failed.recurId)).toHaveCount(0);
    // The record they did not touch is still Failing.
    expect(snapshot(failing.recurId).status).toBe('Failing');
  });

  test.describe('refused users', () => {
    test.use({ allowedProblems: [either(SCREEN_NOISE, REFUSAL_NOISE)] });

    test('PW-B-failed-01 the limited user (no "edit contributions") is refused: no list, no rows, and no menu entry', async ({ limitedPage }) => {
      const record = failedRecord('failed01refused');
      const response = await limitedPage.goto(adminUrl('contribute/failedrecurring', { reset: 1 }));
      expect(REFUSAL_STATUS, `HTTP status ${response?.status()}`).toContain(response?.status());
      await expect(limitedPage.getByText('You do not have permission to access this page.')).toBeVisible();
      await expect(limitedPage.locator('crm-search-display-table, table.table')).toHaveCount(0);
      await expect(failedListRow(limitedPage, record.recurId)).toHaveCount(0);

      // The menu does not offer the entry to this user, under Contributions or anywhere else.
      await limitedPage.goto(adminUrl('dashboard', { reset: 1 }));
      await expect(limitedPage.locator('#civicrm-menu')).toBeVisible();
      await expect(limitedPage.locator('#civicrm-menu a', { hasText: 'Failed Recurring Contributions' })).toHaveCount(0);
    });
  });

  test('PW-B-failed-01 the administrator is offered the list under Contributions and sees the Failed records', async ({ adminPage }) => {
    test.setTimeout(90_000);
    const record = failedRecord('failed01admin');
    await adminPage.goto(adminUrl('contribute', { reset: 1 }));
    const entry = await openMenuEntry(adminPage, 'Contributions', 'Failed Recurring Contributions');
    // It is a direct child of Contributions, and it goes to the list's address.
    const parent = await entry.evaluate((a) => a.closest('ul')?.closest('li')?.querySelector(':scope > a')?.textContent?.trim());
    expect(parent).toBe('Contributions');
    expect(await entry.getAttribute('href')).toContain('civicrm%2Fcontribute%2Ffailedrecurring');
    await entry.click();
    await expect(adminPage.getByRole('heading', { name: 'Failed Recurring Contributions' }).first()).toBeVisible();
    await expect(adminPage.locator('.select2-search-choice')).toHaveText(['Failed', 'Failing']);
    await expect(failedListRow(adminPage, record.recurId)).toHaveCount(1);
  });

  test.describe('payment processor data', () => {
    // The refused PaymentProcessor read is a 403 from the ajax route, which the collector would report.
    test.use({ allowedProblems: [either(SCREEN_NOISE, /HTTP 403 from .*ajax(\/|%2F)api4|status of 403/)] });

    test('PW-B-failed-13 the finance user cannot read any payment processor data through the page, its requests or APIv4', async ({ financePage }) => {
      test.setTimeout(120_000);
      const record = failedRecord('failed13');
      // What a processor holds that must not reach this user: its API key and password (their values), and its name and title.
      const [processor] = api4<Record<string, any>>('PaymentProcessor', 'get', {
        select: ['id', 'name', 'title', 'user_name', 'password'],
        where: [['name', '=', testData.processors.live], ['is_test', '=', false]],
      });
      const secrets = [processor.user_name, processor.password, processor.name, processor.title].map(String);
      expect(secrets.every((value) => value.length > 3), 'set-up: the processor has values to look for').toBe(true);

      // Every response the page gets while the list loads (the list's own data request, the page and its scripts) is read.
      const bodies: string[] = [];
      financePage.on('response', async (response) => {
        if (/\.(png|jpe?g|gif|woff2?|ttf|svg|ico)(\?|$)/.test(response.url())) {
          return;
        }
        try {
          bodies.push(await response.text());
        } catch {
          // A response that was cancelled or has no body.
        }
      });
      await openFailedList(financePage);
      await expect(failedListRow(financePage, record.recurId)).toHaveCount(1);
      await financePage.waitForTimeout(500);
      expect(bodies.length, 'responses were read').toBeGreaterThan(3);
      for (const secret of secrets) {
        expect(bodies.some((body) => body.includes(secret)), `no response of the page holds a processor value (${secret.length} characters)`).toBe(false);
      }

      // The same calls the page and its buttons use, and the ones a user could try by hand, from this user's own session.
      const refused = await browserApi4(financePage, 'PaymentProcessor', 'get', { select: ['id', 'name', 'user_name', 'password'] });
      expect(refused).toEqual({ ok: false, status: 403 });
      for (const field of ['id', 'name', 'title', 'user_name', 'password', 'signature', 'url_site', 'class_name', 'payment_processor_type_id:name']) {
        const implicit = await browserApi4(financePage, 'ContributionRecur', 'get', {
          select: ['id', `payment_processor_id.${field}`],
          where: [['id', '=', record.recurId]],
        });
        expect(implicit, `payment_processor_id.${field}`).toEqual({ ok: true, values: [{ id: record.recurId }] });
        const explicit = await browserApi4(financePage, 'ContributionRecur', 'get', {
          select: ['id', `pp.${field}`],
          join: [['PaymentProcessor AS pp', 'LEFT', ['payment_processor_id', '=', 'pp.id']]],
          where: [['id', '=', record.recurId]],
        });
        expect(explicit, `an explicit join's ${field}`).toEqual({ ok: true, values: [{ id: record.recurId }] });
      }
      // The saved search's own request, as the page makes it, has no processor value either.
      const list = await browserApi4(financePage, 'SearchDisplay', 'run', {
        savedSearch: 'Failed_Recurring_Contributions',
        display: 'Failed_Recurring_Contribution_Listing',
        return: 'page:1',
      });
      expect(list.ok).toBe(true);
      expect(list.values!.length).toBeGreaterThan(0);
      for (const secret of secrets) {
        expect(JSON.stringify(list.values), 'the list data').not.toContain(secret);
      }
      // The only thing the calculated field gives is yes or no.
      const flag = await browserApi4(financePage, 'ContributionRecur', 'get', { select: ['id', 'is_eway_processor'], where: [['id', '=', record.recurId]] });
      expect(flag).toEqual({ ok: true, values: [{ id: record.recurId, is_eway_processor: true }] });
    });
  });
});

test.describe('what the list shows', () => {
  test('PW-B-failed-02 the default filter is Failed and Failing, the columns are as designed, the rows are sorted by status then retry date, and the contact link works', async ({ adminPage }) => {
    test.setTimeout(120_000);
    // Failed with the later retry date, Failed with the earlier one, Failing; and three records the list must not show.
    const later = failedRecord('failed02later', { failureCount: 3, retryDate: '+5 days' });
    const earlier = failedRecord('failed02earlier', { failureCount: 2, retryDate: '+2 days' });
    const failing = createRecurring({ label: 'failed02failing', status: 'Failing', failureCount: 1, retryDate: '+1 day' });
    createRecurring({ label: 'failed02progress' });
    createRecurring({ label: 'failed02cancelled', status: 'Cancelled' });
    createRecurring({ label: 'failed02completed', status: 'Completed' });

    await openFailedList(adminPage);

    // The status filter starts on Failed and Failing.
    const chips = adminPage.locator('.select2-search-choice');
    await expect(chips).toHaveText(['Failed', 'Failing']);

    // Only those records are listed, sorted by status (Failed before Failing), then retry date, oldest first.
    expect(await failedListIds(adminPage)).toEqual([earlier.recurId, later.recurId, failing.recurId]);

    // The columns, left to right. The last column holds the buttons and has no label.
    expect(await failedListHeaderLabels(adminPage)).toEqual([
      'Contact', 'ID', 'Start Date', 'Next Scheduled Date', 'Status', 'Amount', 'Frequency', 'Number of Failures', 'Retry Failed Attempt On',
    ]);
    // Every column sorts except Frequency.
    for (const label of ['Contact', 'ID', 'Start Date', 'Next Scheduled Date', 'Status', 'Amount', 'Number of Failures', 'Retry Failed Attempt On']) {
      await expect(failedListHeader(adminPage, label).locator('.crm-search-table-column-sort-icon'), label).toHaveCount(1);
    }
    await expect(failedListHeader(adminPage, 'Frequency').locator('.crm-search-table-column-sort-icon')).toHaveCount(0);

    // A row's cells.
    const row = failedListRow(adminPage, later.recurId);
    await expect(row.locator('[data-field-name="contact_id.display_name"]')).toContainText(`${later.donor.firstName} ${later.donor.lastName}`);
    await expect(row.locator('[data-field-name="contribution_status_id:label"]')).toHaveText('Failed');
    await expect(row.locator('[data-field-name="amount"]')).toHaveText(/^\s*[A-Z]{0,3}\$10\.00\s*$/); // CI shows the currency prefix, for example A$10.00
    await expect(row.locator('[data-field-name="frequency_unit:label"]')).toHaveText('1 month');
    await expect(row.locator('[data-field-name="failure_count"]')).toHaveText('3');
    await expect(row.locator('[data-field-name="start_date"]')).not.toHaveText('');
    // The dates are printed in the site's own date format, so they are checked as present, not as text.
    await expect(row.locator('[data-field-name="next_sched_contribution_date"]')).toContainText(/\d{4}/);
    await expect(row.locator('[data-field-name="failure_retry_date"]')).toContainText(/\d{4}/);
    await expect(failedListRow(adminPage, failing.recurId).locator('[data-field-name="contribution_status_id:label"]')).toHaveText('Failing');

    // Clicking a header sorts by it: ID ascending, then descending; failures ascending.
    await failedListHeader(adminPage, 'ID').click();
    await expect.poll(() => failedListIds(adminPage)).toEqual([later.recurId, earlier.recurId, failing.recurId]);
    await failedListHeader(adminPage, 'ID').click();
    await expect.poll(() => failedListIds(adminPage)).toEqual([failing.recurId, earlier.recurId, later.recurId]);
    await failedListHeader(adminPage, 'Number of Failures').click();
    await expect.poll(() => failedListIds(adminPage)).toEqual([failing.recurId, earlier.recurId, later.recurId]);
    await failedListHeader(adminPage, 'Number of Failures').click();
    await expect.poll(() => failedListIds(adminPage)).toEqual([later.recurId, earlier.recurId, failing.recurId]);

    // The contact's name links to the contact.
    const link = failedListRow(adminPage, later.recurId).locator('[data-field-name="contact_id.display_name"] a');
    expect(await link.getAttribute('href')).toContain(`cid=${later.contactId}`);
    await link.click();
    await expect(adminPage.getByText('View Contact').first()).toBeVisible();
    await expect(adminPage.getByText(later.donor.email).first()).toBeVisible();
    expect(adminPage.url()).toContain(`cid=${later.contactId}`);
  });

  test('PW-B-failed-02 the list shows 50 rows to a page, and the rest on the next page', async ({ adminPage }) => {
    test.setTimeout(90_000);
    const first = failedRecord('failed02pages');
    const others = copyRecurring(first, 51);
    try {
      const all = [first.recurId, ...others];
      await openFailedList(adminPage);
      // Sort by ID so the pages are the same each time (ties on the default sort have no fixed order).
      await failedListHeader(adminPage, 'ID').click();
      await expect.poll(async () => (await failedListIds(adminPage)).length).toBe(50);
      expect(await failedListIds(adminPage)).toEqual(all.slice(0, 50));
      const pager = adminPage.locator('ul.pagination');
      await expect(pager.locator('li.pagination-page')).toHaveText(['1', '2']);
      await expect(pager.locator('li.pagination-page.active')).toHaveText('1');

      await pager.locator('li.pagination-page a', { hasText: /^2$/ }).click();
      await expect.poll(() => failedListIds(adminPage)).toEqual(all.slice(50));
      await expect(pager.locator('li.pagination-page.active')).toHaveText('2');
      // Back to the first.
      await pager.locator('li.pagination-page a', { hasText: /^1$/ }).click();
      await expect.poll(async () => (await failedListIds(adminPage)).length).toBe(50);
    } finally {
      deleteRecurring(others);
    }
  });

  test('PW-B-failed-03 records on another processor, test records and records with no saved card are not listed', async ({ adminPage }) => {
    test.setTimeout(120_000);
    const listed = failedRecord('failed03listed');
    const otherProcessor = failedRecord('failed03dummy');
    const [dummy] = api4<{ id: number }>('PaymentProcessor', 'get', {
      select: ['id'],
      where: [['name', '=', testData.processors.dummy], ['is_test', '=', false]],
    });
    // The card stays on the record, so the processor is the only thing that differs.
    moveToProcessor(otherProcessor.recurId, dummy.id);
    const testMode = failedRecord('failed03test');
    makeTestRecord(testMode.recurId);
    const noCard = failedRecord('failed03nocard');
    removeToken(noCard.recurId);
    // A Failing one of each kind too.
    const failingOther = createRecurring({ label: 'failed03failingdummy', status: 'Failing', failureCount: 1, retryDate: '+1 day' });
    moveToProcessor(failingOther.recurId, dummy.id);
    const failingNoCard = createRecurring({ label: 'failed03failingnocard', status: 'Failing', failureCount: 1, retryDate: '+1 day' });
    removeToken(failingNoCard.recurId);

    await openFailedList(adminPage);
    expect(await failedListIds(adminPage)).toEqual([listed.recurId]);
    for (const record of [otherProcessor, testMode, noCard, failingOther, failingNoCard]) {
      await expect(failedListRow(adminPage, record.recurId), `recurring ${record.recurId}`).toHaveCount(0);
    }
  });

  test('PW-B-failed-04 the Reactivate button is on Failed and Failing rows only', async ({ adminPage }) => {
    test.setTimeout(120_000);
    const failed = failedRecord('failed04failed');
    const failing = createRecurring({ label: 'failed04failing', status: 'Failing', failureCount: 1, retryDate: '+1 day' });
    const inProgress = createRecurring({ label: 'failed04progress' });
    const cancelled = createRecurring({ label: 'failed04cancelled', status: 'Cancelled' });
    const completed = createRecurring({ label: 'failed04completed', status: 'Completed' });

    await openFailedList(adminPage);
    // Both listed statuses have the button on their rows.
    for (const record of [failed, failing]) {
      await expect(reactivateRowButton(adminPage, record.recurId), `recurring ${record.recurId}`).toHaveCount(1);
    }

    // Widen the status filter to the other statuses; those rows come in without a button. (Newest first, so the new
    // records are on the first page whatever the site already holds.)
    const filter = adminPage.locator('.select2-container-multi').first();
    for (const status of ['In Progress', 'Cancelled', 'Completed']) {
      await filter.locator('.select2-input').click();
      await adminPage.locator('.select2-drop-active .select2-result-label', { hasText: new RegExp(`^${status}$`) }).click();
    }
    await expect(filter.locator('.select2-search-choice')).toHaveText(['Failed', 'Failing', 'In Progress', 'Cancelled', 'Completed']);
    await failedListHeader(adminPage, 'ID').click();
    await failedListHeader(adminPage, 'ID').click();
    await expect.poll(async () => (await failedListIds(adminPage))[0]).toBe(completed.recurId);

    for (const record of [inProgress, cancelled, completed]) {
      await expect(failedListRow(adminPage, record.recurId), `recurring ${record.recurId}`).toHaveCount(1);
      await expect(reactivateRowButton(adminPage, record.recurId), `recurring ${record.recurId}`).toHaveCount(0);
    }
    for (const record of [failed, failing]) {
      await expect(reactivateRowButton(adminPage, record.recurId), `recurring ${record.recurId}`).toHaveCount(1);
    }
    // Across every row on the page: a button exactly when the status is Failed or Failing.
    const rows = adminPage.locator('tbody tr[data-entity-id]');
    const count = await rows.count();
    expect(count).toBeGreaterThanOrEqual(5);
    for (let index = 0; index < count; index++) {
      const row = rows.nth(index);
      const status = (await row.locator('[data-field-name="contribution_status_id:label"]').innerText()).trim();
      await expect(row.getByTitle('Reactivate'), `row ${index} (${status})`).toHaveCount(['Failed', 'Failing'].includes(status) ? 1 : 0);
    }
  });
});

test.describe('Reactivate', () => {
  test('PW-B-failed-05 Reactivate on one row: singular wording, no date, then the record is In Progress with no failures and the same schedule', async ({ adminPage, stub }) => {
    test.setTimeout(90_000);
    const record = failedRecord('failed05', { failureCount: 3, retryDate: '-1 day', nextDue: '+10 days' });
    const before = snapshot(record.recurId);
    expect(before).toMatchObject({ status: 'Failed', failures: 3 });
    expect(before.retry).toBeTruthy();

    await openFailedList(adminPage);
    const dialog = await openRowDialog(adminPage, record.recurId);
    // One record: the singular wording, with the status the record will get.
    await expect(dialog.locator('.alert-info')).toHaveText(/Clear the failures on 1 selected recurring contribution and reset its status to "In Progress"/);
    // The date is optional and starts blank.
    await expect(dialog.getByText('Update Schedule Date')).toBeVisible();
    await expect(dialog.locator('#crm-search-task-next-date')).toHaveValue('');
    await expect(dialog.getByText('Leave blank for no change.')).toBeVisible();
    await expect(dialog.locator('.ui-dialog-buttonpane').getByRole('button')).toHaveText(['Cancel', 'Reactivate Recurring Contributions']);

    await pressInReactivateDialog(adminPage, /Reactivate Recurring Contributions/);
    await expect(notification(adminPage)).toContainText('Successfully reactivated 1 Recurring Contributions.');
    await expect(notification(adminPage)).toContainText('Reactivation Complete');
    await expect(reactivateDialog(adminPage)).toHaveCount(0);

    // In Progress, no failures, no retry date; the schedule, amount and card are as they were.
    const after = snapshot(record.recurId);
    expect(after).toEqual({ ...before, status: 'In Progress', failures: 0, retry: null });
    // Nothing was sent to eWAY.
    expect(stub.requests()).toHaveLength(0);
    // The list no longer has it.
    await expect(failedListRow(adminPage, record.recurId)).toHaveCount(0);
  });

  test('PW-B-failed-06 Reactivate with a chosen date sets the schedule to exactly that date', async ({ adminPage }) => {
    test.setTimeout(90_000);
    const record = failedRecord('failed06', { failureCount: 3, retryDate: '-1 day', nextDue: '+10 days' });
    const before = snapshot(record.recurId);

    await openFailedList(adminPage);
    const dialog = await openRowDialog(adminPage, record.recurId);
    const chosen = await pickDay(adminPage, dialog.locator('#crm-search-task-next-date'));
    await pressInReactivateDialog(adminPage, /Reactivate Recurring Contributions/);
    await expect(notification(adminPage)).toContainText('Successfully reactivated 1 Recurring Contributions.');

    const after = snapshot(record.recurId);
    expect(after).toEqual({ ...before, status: 'In Progress', failures: 0, retry: null, next: `${chosen} 00:00:00` });
  });

  test('PW-B-failed-07 ticking several rows and pressing Reactivate: plural wording, progress shown, all of them updated and the others left alone', async ({ adminPage }) => {
    test.setTimeout(120_000);
    const one = failedRecord('failed07a', { failureCount: 3, retryDate: '-1 day' });
    const two = createRecurring({ label: 'failed07b', status: 'Failing', failureCount: 1, retryDate: '+2 days' });
    const three = failedRecord('failed07c', { failureCount: 2, retryDate: '-3 days' });
    const left = failedRecord('failed07left', { failureCount: 3, retryDate: '-1 day' });
    const ticked = [one, two, three];
    const leftBefore = snapshot(left.recurId);

    // Hold the update back for a moment so the progress panel can be seen; the call itself is not changed.
    await adminPage.route(UPDATE_CALL, async (route) => {
      await new Promise((done) => setTimeout(done, 2000));
      await route.continue();
    });

    await openFailedList(adminPage);
    await expect(reactivateToolbarButton(adminPage)).toBeDisabled();
    for (const record of ticked) {
      await failedListTick(adminPage, record.recurId).check();
    }
    await expect(failedListTick(adminPage, left.recurId)).not.toBeChecked();
    await expect(reactivateToolbarButton(adminPage)).toBeEnabled();
    await reactivateToolbarButton(adminPage).click();
    const dialog = reactivateDialog(adminPage);
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.alert-info')).toHaveText(/Clear the failures on 3 selected recurring contributions and reset their statuses to "In Progress"/);

    await pressInReactivateDialog(adminPage, /Reactivate Recurring Contributions/);
    // Progress: the heading, the bar and the range of records being processed, and the buttons are off.
    await expect(dialog.getByText('Reactivating Recurring Contributions...')).toBeVisible();
    await expect(dialog.locator('.progress-bar')).toBeVisible();
    await expect(dialog.getByText('Processing records 1 - 3')).toBeVisible();
    for (const button of await dialog.locator('.ui-dialog-buttonpane').getByRole('button').all()) {
      await expect(button).toBeDisabled();
    }
    await expect(notification(adminPage)).toContainText('Successfully reactivated 3 Recurring Contributions.');
    await expect(reactivateDialog(adminPage)).toHaveCount(0);

    for (const record of ticked) {
      const state = snapshot(record.recurId);
      expect(state, `recurring ${record.recurId}`).toMatchObject({ status: 'In Progress', failures: 0, retry: null });
    }
    // The one that was not ticked is untouched, and is the only row left on the list.
    expect(snapshot(left.recurId)).toEqual(leftBefore);
    await expect.poll(() => failedListIds(adminPage)).toEqual([left.recurId]);
  });

  test('PW-B-failed-08 Cancel in the dialog changes nothing, even with a date typed in', async ({ adminPage, stub }) => {
    test.setTimeout(90_000);
    const record = failedRecord('failed08', { failureCount: 3, retryDate: '-1 day', nextDue: '+10 days' });
    const before = snapshot(record.recurId);

    await openFailedList(adminPage);
    const dialog = await openRowDialog(adminPage, record.recurId);
    await pickDay(adminPage, dialog.locator('#crm-search-task-next-date'));
    await pressInReactivateDialog(adminPage, 'Cancel');
    await expect(reactivateDialog(adminPage)).toHaveCount(0);
    await expect(notification(adminPage).locator('.notify-content, .crm-status-box-outer')).toHaveCount(0);

    expect(snapshot(record.recurId)).toEqual(before);
    expect(stub.requests()).toHaveLength(0);
    // The row is still on the list, and the dialog opens again with a blank date.
    await expect(failedListRow(adminPage, record.recurId)).toHaveCount(1);
    const again = await openRowDialog(adminPage, record.recurId);
    await expect(again.locator('#crm-search-task-next-date')).toHaveValue('');
    await pressInReactivateDialog(adminPage, 'Cancel');
    expect(snapshot(record.recurId)).toEqual(before);
  });

  test.describe('an error from the API', () => {
    // The refused update is a 403 from the ajax route, which the collector would report.
    test.use({ allowedProblems: [either(SCREEN_NOISE, /HTTP 403 from .*ajax(\/|%2F)api4|status of 403/)] });

    test('PW-B-failed-10 when the update is refused the error alert shows, the dialog closes and the record is untouched', async ({ adminPage }) => {
      test.setTimeout(90_000);
      const record = failedRecord('failed10', { failureCount: 3, retryDate: '-1 day' });
      const before = snapshot(record.recurId);

      // No user can open the list and then be refused the update (the page, the list and the update all need "edit
      // contributions" and "access CiviContribute" together), so the refusal is made the way the server makes it, for the
      // update call only.
      let refused = 0;
      await adminPage.route(UPDATE_CALL, async (route) => {
        refused++;
        await route.fulfill({
          status: 403,
          contentType: 'application/json',
          body: JSON.stringify({ error_code: 'unauthorized', error_message: 'API permission check failed for ContributionRecur::update call; insufficient permission: require access CiviCRM and access CiviContribute and edit contributions' }),
        });
      });

      await openFailedList(adminPage);
      await openRowDialog(adminPage, record.recurId);
      await pressInReactivateDialog(adminPage, /Reactivate Recurring Contributions/);

      await expect(notification(adminPage)).toContainText('Reactivation Error');
      await expect(notification(adminPage)).toContainText('An error occurring while attempting to reactivate 1 Recurring Contributions.');
      await expect(notification(adminPage)).not.toContainText('Successfully reactivated');
      await expect(reactivateDialog(adminPage)).toHaveCount(0);
      expect(refused).toBe(1);
      expect(snapshot(record.recurId)).toEqual(before);
      // The list is still usable: the row is there and the dialog opens again.
      await expect(failedListRow(adminPage, record.recurId)).toHaveCount(1);
      await openRowDialog(adminPage, record.recurId);
      await pressInReactivateDialog(adminPage, 'Cancel');
      await expect(reactivateDialog(adminPage)).toHaveCount(0);
    });
  });
});

test.describe('the recovery loop', () => {
  test('PW-B-failed-09 a reactivated record is charged by the next cron run once it is due, and not while it is Failed', async ({ adminPage, stub }) => {
    test.setTimeout(150_000);
    // Failed, with its payment already due, as a record the cron job once gave up on.
    const record = createRecurring({ label: 'failed09', status: 'Failed', failureCount: 3, retryDate: '-1 day', nextDue: '-1 day' });
    setOtherDueRecurringAside(record.recurId);
    const due = String(recurRow(record.recurId).next_sched_contribution_date);

    // Failed: the job leaves it alone.
    stub.reset();
    stub.respond('postTransaction', responses.paymentApproved(newTransactionId()));
    runRecurringJob();
    expect(stub.requests('postTransaction')).toHaveLength(0);
    expect(contributionsOf(record.recurId)).toHaveLength(1);

    await openFailedList(adminPage);
    await openRowDialog(adminPage, record.recurId);
    await pressInReactivateDialog(adminPage, /Reactivate Recurring Contributions/);
    await expect(notification(adminPage)).toContainText('Successfully reactivated 1 Recurring Contributions.');
    expect(snapshot(record.recurId)).toMatchObject({ status: 'In Progress', failures: 0, retry: null, next: due });

    // In Progress and due: the next run charges it once, with its own card.
    stub.reset();
    const transactionId = newTransactionId();
    stub.respond('postTransaction', responses.paymentApproved(transactionId));
    runRecurringJob();
    const charges = stub.requests('postTransaction');
    expect(charges).toHaveLength(1);
    expect((charges[0].args[0] as any).Customer.TokenCustomerID).toBe(record.token);
    const contributions = contributionsOf(record.recurId);
    expect(contributions).toHaveLength(2);
    expect(contributions[1]['contribution_status_id:name']).toBe('Completed');
    expect(String(contributions[1].trxn_id)).toBe(String(transactionId));
    expect(snapshot(record.recurId)).toMatchObject({ status: 'In Progress', failures: 0, retry: null });
    expect(String(snapshot(record.recurId).next) > due).toBe(true);

    // The contact shows the new Completed payment.
    await openContributionsTab(adminPage, record.contactId);
    await expect(contributionRows(adminPage)).toHaveCount(2);
    await expect(contributionRow(adminPage, contributions[1].id).locator('.crm-contribution-status')).toHaveText('Completed');
  });

  // A failure does not move the schedule date (DEF-46), so after Reactivate the very next run charges the record, once, and only
  // then does the date move on, one interval from the day the payment was due.
  test('PW-B-failed-11 the whole loop: the cron job drives a record to Failed, the list shows it, Reactivate returns it to In Progress, and the very next run charges it once', async ({ adminPage, stub }) => {
    test.setTimeout(240_000);
    setSettings({ eway_recurring_contribution_max_retry: 2 });
    const record = dueRecord('failed11');
    const due = String(recurRow(record.recurId).next_sched_contribution_date);

    // Declined until the limit is used up; time is moved on between runs so the retry is due.
    runDeclined(stub, record);
    makeRetryDue(record.recurId);
    runDeclined(stub, record);
    const failed = recurRow(record.recurId);
    expect(failed['contribution_status_id:name']).toBe('Failed');
    expect(failed.failure_count).toBe(2);
    expect(contributionsOf(record.recurId).map((c) => c['contribution_status_id:name'])).toEqual(['Completed', 'Failed', 'Failed']);

    // The list shows it as Failed, with the failure count and the retry date.
    await openFailedList(adminPage);
    const row = failedListRow(adminPage, record.recurId);
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-field-name="contribution_status_id:label"]')).toHaveText('Failed');
    await expect(row.locator('[data-field-name="failure_count"]')).toHaveText('2');
    await expect(row.locator('[data-field-name="failure_retry_date"]')).toContainText(/\d{4}/);

    // Reactivate returns it to In Progress, with no failures and no retry date. The failures did not move the schedule, so it is
    // still the day the payment was due.
    await openRowDialog(adminPage, record.recurId);
    await pressInReactivateDialog(adminPage, /Reactivate Recurring Contributions/);
    await expect(notification(adminPage)).toContainText('Successfully reactivated 1 Recurring Contributions.');
    const reactivated = recurRow(record.recurId);
    expect(reactivated['contribution_status_id:name']).toBe('In Progress');
    expect(reactivated.failure_count).toBe(0);
    expect(reactivated.failure_retry_date).toBeFalsy();
    expect(reactivated.next_sched_contribution_date).toBe(due);
    expect(failed.next_sched_contribution_date).toBe(due);
    await expect(failedListRow(adminPage, record.recurId)).toHaveCount(0);

    // The very next run charges it, once, and the contact shows the new Completed payment.
    stub.reset();
    const transactionId = newTransactionId();
    stub.respond('postTransaction', responses.paymentApproved(transactionId));
    runRecurringJob();
    expect(stub.requests('postTransaction')).toHaveLength(1);
    const contributions = contributionsOf(record.recurId);
    expect(contributions.map((c) => c['contribution_status_id:name'])).toEqual(['Completed', 'Failed', 'Failed', 'Completed']);
    expect(String(contributions[3].trxn_id)).toBe(String(transactionId));
    const charged = recurRow(record.recurId);
    expect(charged['contribution_status_id:name']).toBe('In Progress');
    expect(charged.failure_count).toBe(0);
    expect(charged.failure_retry_date).toBeFalsy();
    // One month on from the day the payment was due, not from the failures or the charge.
    expect(charged.next_sched_contribution_date).toBe(expectedNextPayment(due.slice(0, 10), { interval: 1, unit: 'month' }));

    // A second run the same day charges nothing more.
    stub.reset();
    stub.respond('postTransaction', responses.paymentApproved(newTransactionId()));
    runRecurringJob();
    expect(stub.requests('postTransaction')).toHaveLength(0);

    await openContributionsTab(adminPage, record.contactId);
    await expect(contributionRows(adminPage)).toHaveCount(4);
    await expect(contributionRow(adminPage, contributions[3].id).locator('.crm-contribution-status')).toHaveText('Completed');
    await expect(contributionRow(adminPage, contributions[3].id).locator('.crm-contribution-amount')).toContainText('(Recurring)');
  });

  // The staff expectation behind the loop: reactivate, and the next cron run charges (DEF-46). It did not, while a failure moved
  // the schedule date on.
  test('PW-B-failed-11 the next cron run after Reactivate charges the record (DEF-46)', async ({ stub }) => {
    test.setTimeout(180_000);
    const record = failedThroughCron(stub, 'failed11pin', 2);
    api4('ContributionRecur', 'update', {
      where: [['id', '=', record.recurId]],
      values: { 'contribution_status_id:name': 'In Progress', failure_count: 0, failure_retry_date: '' },
    });
    stub.reset();
    stub.respond('postTransaction', responses.paymentApproved(newTransactionId()));
    runRecurringJob();
    expect(stub.requests('postTransaction'), 'the next run after Reactivate charges the record').toHaveLength(1);
  });

  test('PW-B-failed-12 a record that has failed but has retries left is not on the list until its last permitted retry fails', async ({ adminPage, stub }) => {
    test.setTimeout(240_000);
    // The default limit of three failed charges.
    const record = dueRecord('failed12');

    runDeclined(stub, record);
    let state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('In Progress');
    expect(state.failure_count).toBe(1);
    await openFailedList(adminPage);
    await expect(failedListRow(adminPage, record.recurId)).toHaveCount(0);
    expect(await failedListIds(adminPage)).toEqual([]);

    makeRetryDue(record.recurId);
    runDeclined(stub, record);
    state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('In Progress');
    expect(state.failure_count).toBe(2);
    await openFailedList(adminPage);
    await expect(failedListRow(adminPage, record.recurId)).toHaveCount(0);

    // The third failed charge is the last permitted one: now it is Failed, and listed.
    makeRetryDue(record.recurId);
    runDeclined(stub, record);
    state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('Failed');
    expect(state.failure_count).toBe(3);
    await openFailedList(adminPage);
    const row = failedListRow(adminPage, record.recurId);
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-field-name="contribution_status_id:label"]')).toHaveText('Failed');
    await expect(row.locator('[data-field-name="failure_count"]')).toHaveText('3');
  });
});
