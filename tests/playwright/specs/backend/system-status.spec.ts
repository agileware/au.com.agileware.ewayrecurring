import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4, setSettings } from '../../fixtures/civi';
import { resetEwaySettings } from '../../fixtures/backend';
import { newTransactionId } from '../../fixtures/contribution-page';
import { responses, type EwayStub } from '../../fixtures/eway-stub';
import {
  createRecurring,
  makeRetryDue,
  makeTestRecord,
  recurRow,
  restoreRetryDates,
  runRecurringJob,
  setFailedRecordsAside,
  setOtherDueRecurringAside,
  setRetryDate,
  type RecurringRecord,
  type SavedRetryDates,
} from '../../fixtures/recurring-records';
import { failedListRow } from '../../fixtures/recurring-screens';

/**
 * PW-B-status: the extension's check on CiviCRM's System Status page, "eWAYRecurring - failed recurring contributions".
 * It counts recurring contributions with status Failed, live ones only, whose retry date is more than four hours old
 * (a record whose retries are used up and that nobody has dealt with), and links to the Failed Recurring Contributions
 * list. Test plan: developer/TEST_PLAN.md section 5.2.
 *
 * Earlier cases leave Failed records behind, and the check counts every one on the site. Each case therefore makes the
 * existing Failed records look freshly failed first (setFailedRecordsAside) so the count is exactly its own, and puts
 * their retry dates back afterwards.
 *
 * The System Status page is a CiviCRM Angular screen that asks the server for every check when it opens; the cases wait
 * for that answer before they look.
 */

const TITLE = 'eWAYRecurring - failed recurring contributions';
const CHECK_NAME = 'ewayrecurring_failed';

/** The System Status page's address: an Angular route on CiviCRM's own app page. */
function statusUrl(): string {
  return `${adminUrl('a/')}#/status`;
}

/** Open the System Status page and wait until the server has answered and the messages are drawn. */
async function openSystemStatus(page: Page): Promise<void> {
  const answered = page.waitForResponse((response) => /ajax(\/|%2F)rest/.test(response.url()) && (response.request().postData() ?? '').includes('entity=System'));
  // Leave the page first: opening the same address again would only change the hash and not ask the server.
  await page.goto('about:blank');
  await page.goto(statusUrl());
  await answered;
  await expect(page.getByRole('heading', { name: 'CiviCRM System Status' })).toBeVisible();
  await expect(page.locator('.crm-status-item').first()).toBeVisible();
}

/** The eWAY message on the System Status page, if there is one. */
function ewayMessage(page: Page) {
  return page.locator('.crm-status-item').filter({ hasText: TITLE });
}

/** A Failed recurring contribution as the cron job leaves one whose retries are used up, made directly. */
function stuckRecord(label: string): RecurringRecord {
  return createRecurring({ label, status: 'Failed', failureCount: 3, retryDate: '-2 days' });
}

/** Remove any preference rows for the check, so a case that disables it never leaks. */
function removeCheckPreference(): void {
  api4('StatusPreference', 'delete', { where: [['name', '=', CHECK_NAME]] });
}

/**
 * Other messages on the page can contain the word "Warning:" in their own text (a site with debug on shows "Warning: Debug is
 * enabled in system settings"), which the problem collector reads as PHP output when the page is the last one a case looks at.
 */
test.use({ allowedProblems: [/Warning: Debug is enabled in system settings/] });

let aside: SavedRetryDates = {};

test.beforeEach(() => {
  resetEwaySettings();
  removeCheckPreference();
  aside = setFailedRecordsAside();
});

test.afterEach(() => {
  removeCheckPreference();
  resetEwaySettings();
  restoreRetryDates(aside);
});

/** Run the job once with every charge declined. */
function runDeclined(stub: EwayStub): void {
  stub.reset();
  stub.respond('postTransaction', responses.paymentDeclined('D4401', newTransactionId()));
  runRecurringJob();
  expect(stub.requests('postTransaction')).toHaveLength(1);
}

test.describe('system status: failed recurring contributions', () => {
  test('PW-B-status-01 with stuck failures the message gives the right count and its link opens the Failed Recurring Contributions list', async ({ adminPage }) => {
    test.setTimeout(90_000);
    const first = stuckRecord('status01a');
    const second = stuckRecord('status01b');

    await openSystemStatus(adminPage);
    const message = ewayMessage(adminPage);
    await expect(message).toHaveCount(1);
    await expect(message).toContainText('Found 2 failed recurring contributions that may be stuck');
    // It is an active message (the Hidden tab is for ones staff have hidden), with a Hide choice like any other.
    await expect(message.getByRole('button', { name: 'Hide' })).toBeVisible();

    // A third stuck record makes it three.
    const third = stuckRecord('status01c');
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toContainText('Found 3 failed recurring contributions');

    // The link goes to the Failed Recurring Contributions list, which shows all three.
    const link = ewayMessage(adminPage).getByRole('link', { name: 'Failed Recurring Contributions' });
    await expect(link).toHaveAttribute('href', /civicrm(\/|%2F)contribute(\/|%2F)failedrecurring/);
    await link.click();
    await expect(adminPage.getByRole('heading', { name: 'Failed Recurring Contributions' }).first()).toBeVisible();
    for (const record of [first, second, third]) {
      await expect(failedListRow(adminPage, record.recurId), `recurring ${record.recurId} is listed`).toHaveCount(1);
    }
  });

  test('PW-B-status-02 with none there is no message, and with the check disabled in the status preferences there is none either', async ({ adminPage }) => {
    test.setTimeout(90_000);
    // None stuck: the page loads and the eWAY message is not on it.
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toHaveCount(0);

    // One stuck record: the message appears, so the first look was not vacuous.
    const record = stuckRecord('status02');
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toContainText('Found 1 failed recurring contributions');

    // Disable the check the way CiviCRM stores it (a status preference with is_active off). The System Status page
    // has no control for this: its Hide choice only hides one message for a time or for good.
    api4('StatusPreference', 'create', { values: { name: CHECK_NAME, domain_id: 'current_domain', is_active: false } });
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toHaveCount(0);
    expect(recurRow(record.recurId)['contribution_status_id:name']).toBe('Failed');

    // Switch it back on and the message is back.
    removeCheckPreference();
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toContainText('Found 1 failed recurring contributions');
  });

  test('PW-B-status-03 a record that used up its retries through the cron is counted once its retry date is more than four hours old, and records still being retried or in test mode are not', async ({ adminPage, stub }) => {
    test.setTimeout(240_000);
    setSettings({ eway_recurring_contribution_max_retry: 2 });

    // A record that really runs out of retries: due, declined, retry due, declined again.
    const record = createRecurring({ label: 'status03', nextDue: '-1 day' });
    setOtherDueRecurringAside(record.recurId);
    runDeclined(stub);
    makeRetryDue(record.recurId);
    runDeclined(stub);
    const failed = recurRow(record.recurId);
    expect(failed['contribution_status_id:name']).toBe('Failed');
    expect(failed.failure_count).toBe(2);

    // A record that has failed once and is still being retried, with its retry date well in the past, and a test-mode
    // record that is Failed and stuck. Neither is counted.
    const retrying = createRecurring({ label: 'status03retry', nextDue: '-1 day' });
    setOtherDueRecurringAside(retrying.recurId);
    runDeclined(stub);
    expect(recurRow(retrying.recurId)['contribution_status_id:name']).toBe('In Progress');
    expect(recurRow(retrying.recurId).failure_count).toBe(1);
    setRetryDate(retrying.recurId, '-3 days');
    const testMode = stuckRecord('status03test');
    makeTestRecord(testMode.recurId);

    // The cron set the retry date days ahead, so the record is not stuck yet: nothing is counted.
    expect(String(failed.failure_retry_date) > new Date().toISOString().slice(0, 10)).toBe(true);
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toHaveCount(0);

    // A retry date three hours old is still inside the four hours.
    setRetryDate(record.recurId, '-3 hours');
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toHaveCount(0);

    // Five hours old: counted, and only that one.
    setRetryDate(record.recurId, '-5 hours');
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toContainText('Found 1 failed recurring contributions');

    // Cancelling the stuck record takes it out of the count.
    api4('ContributionRecur', 'update', { where: [['id', '=', record.recurId]], values: { 'contribution_status_id:name': 'Cancelled' } });
    await openSystemStatus(adminPage);
    await expect(ewayMessage(adminPage)).toHaveCount(0);
  });
});
