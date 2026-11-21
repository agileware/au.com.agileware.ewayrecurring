import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4, evaluate } from '../../fixtures/civi';
import { openMenuEntry } from '../../fixtures/backend';
import { createRecurring, recurRow, type RecurringRecord } from '../../fixtures/recurring-records';
import { reactivateDialog, SCREEN_NOISE } from '../../fixtures/recurring-screens';

/**
 * PW-B-blocked: the Blocked Recurring Contributions list (a Search Kit page the extension adds at
 * civicrm/contribute/blockedrecurring), its Reactivate and Cancel actions, and the System Status check that counts it.
 * Test plan: developer/TEST_PLAN.md section 5.2. Feature: developer/FEATURES.md, FR-6.
 *
 * A record is blocked when it is due to be charged but a Cancelled contribution dated on or after the date it is waiting on
 * stops the scheduled job charging it. Cancelling a contribution through CiviCRM makes the extension skip that installment
 * (PU-F42), so a blocked record is made here by putting the Cancelled status on straight in the table, as a record left
 * that way before the upgrade would be.
 *
 * Records are due 70 days ago and their Cancelled contribution is dated 60 days ago, so that a date picked in the current
 * month is always after it. Every record a case makes is cancelled afterwards so none stays blocked for other cases.
 */

/** test.use() reads a two item list as a value and its options, so more than one pattern is joined into one. */
const either = (...patterns: RegExp[]) => new RegExp(patterns.map((pattern) => pattern.source).join('|'));

test.use({ allowedProblems: [either(SCREEN_NOISE, /Warning: Debug is enabled in system settings/)] });

/** A refused user gets HTTP 403 on a development site and 500 on a fresh CI site (core's own permission page). */
const REFUSAL_STATUS = [403, 500];

const made: number[] = [];

test.afterEach(() => {
  // Nothing a case made stays blocked (or due) for the cases after it.
  if (made.length) {
    api4('ContributionRecur', 'update', { where: [['id', 'IN', made.splice(0)]], values: { 'contribution_status_id:name': 'Cancelled' } });
  }
});

/** A due, In Progress eWAY record that a Cancelled contribution blocks. */
function blockedRecord(label: string): RecurringRecord {
  const record = createRecurring({ label, nextDue: '-70 days' });
  made.push(record.recurId);
  evaluate(`
    // Recording the seeded Completed contribution made core move the date on, so put the due date back.
    \\Civi\\Api4\\ContributionRecur::update(FALSE)
      ->addWhere('id', '=', ${record.recurId})
      ->addValue('next_sched_contribution_date', date('Y-m-d 00:00:00', strtotime('-70 days')))
      ->execute();
    $created = civicrm_api3('Contribution', 'create', [
      'contact_id' => ${record.contactId},
      'financial_type_id' => 1,
      'total_amount' => 10,
      'contribution_status_id' => 'Pending',
      'contribution_recur_id' => ${record.recurId},
      'receive_date' => date('Y-m-d H:i:s', strtotime('-60 days')),
      'invoice_id' => md5(uniqid('', TRUE)),
      'is_email_receipt' => 0,
    ]);
    $id = (int) reset($created['values'])['id'];
    CRM_Core_DAO::executeQuery('UPDATE civicrm_contribution SET contribution_status_id = %1 WHERE id = %2', [
      1 => [(int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Cancelled'), 'Integer'],
      2 => [$id, 'Integer'],
    ]);
    echo $id;
  `);
  return record;
}

/** A due record that nothing blocks (its only contribution is the Completed one). */
function dueRecord(label: string): RecurringRecord {
  const record = createRecurring({ label, nextDue: '-70 days' });
  made.push(record.recurId);
  return record;
}

/** How many live In Progress eWAY records the list should show right now, counted straight from the site. */
function blockedCount(): number {
  return api4('ContributionRecur', 'get', {
    select: ['id'],
    where: [
      ['is_eway_processor', '=', true],
      ['is_blocked_by_cancelled_contribution', '=', true],
      ['is_test', '=', false],
      ['contribution_status_id:name', '=', 'In Progress'],
    ],
  }).length;
}

/** Open the list and wait until it has asked the server for its rows and drawn them. */
async function openBlockedList(page: Page): Promise<void> {
  const rows = page.waitForResponse((response) => response.request().method() === 'POST' && /ajax(\/|%2F)api4$/.test(response.url()));
  await page.goto(adminUrl('contribute/blockedrecurring', { reset: 1 }));
  await expect(page.getByRole('heading', { name: 'Blocked Recurring Contributions' }).first()).toBeVisible();
  await rows;
  await page.waitForLoadState('networkidle');
}

/** A record's row on the list, found by the id in its Recurring ID column. */
function blockedRow(page: Page, recurId: number): Locator {
  return page.locator('tbody tr').filter({
    has: page.locator('[data-field-name="id"]').filter({ hasText: new RegExp(`^\\s*${recurId}\\s*$`) }),
  });
}

/** The notification CiviCRM shows after an action. */
function notification(page: Page) {
  return page.locator('#crm-notification-container');
}

/** The Cancel dialog (a CiviCRM dialog opened from a row button). */
function cancelDialog(page: Page): Locator {
  return page.locator('.ui-dialog').filter({ has: page.locator('form[name="crmSearchTaskCancelRecur"]') });
}

/** Choose a day in a date picker by clicking it, and read back the date it stored (yyyy-mm-dd). */
async function pickDay(page: Page, field: Locator, day = 20): Promise<string> {
  await field.locator('xpath=following-sibling::input[1]').click();
  const picker = page.locator('#ui-datepicker-div');
  await expect(picker).toBeVisible();
  await picker.locator('a.ui-state-default', { hasText: new RegExp(`^${day}$`) }).click();
  await expect(picker).toBeHidden();
  const stored = await field.inputValue();
  expect(stored).toMatch(new RegExp(`^\\d{4}-\\d{2}-${day}`));
  return stored.slice(0, 10);
}

test('PW-B-blocked-01 the finance user is offered the list under Contributions with its instructions, and sees the blocked records only', async ({ financePage }) => {
  test.setTimeout(120_000);
  const blocked = blockedRecord('blocked01blocked');
  const notBlocked = dueRecord('blocked01free');

  await financePage.goto(adminUrl('contribute', { reset: 1 }));
  const entry = await openMenuEntry(financePage, 'Contributions', 'Blocked Recurring Contributions');
  const parent = await entry.evaluate((a) => a.closest('ul')?.closest('li')?.querySelector(':scope > a')?.textContent?.trim());
  expect(parent).toBe('Contributions');
  expect(await entry.getAttribute('href')).toContain('civicrm%2Fcontribute%2Fblockedrecurring');
  const rows = financePage.waitForResponse((response) => response.request().method() === 'POST' && /ajax(\/|%2F)api4$/.test(response.url()));
  await entry.click();
  await expect(financePage.getByRole('heading', { name: 'Blocked Recurring Contributions' }).first()).toBeVisible();
  expect((await rows).status(), 'the list loads for this user').toBe(200);
  await financePage.waitForLoadState('networkidle');

  // The instructions are on the page and name the three things a person can do.
  const instructions = financePage.locator('.alert-info').filter({ hasText: 'What this list shows' });
  await expect(instructions).toBeVisible();
  await expect(instructions).toContainText('Reactivate');
  await expect(instructions).toContainText('Cancel');
  await expect(instructions).toContainText('Failed');

  // The blocked record is listed, with its details; the one nothing blocks is not.
  await expect(blockedRow(financePage, blocked.recurId)).toHaveCount(1);
  await expect(blockedRow(financePage, blocked.recurId).locator('[data-field-name="contact_id.display_name"]')).toContainText(`${blocked.donor.firstName} ${blocked.donor.lastName}`);
  await expect(blockedRow(financePage, blocked.recurId).getByTitle('Reactivate')).toBeVisible();
  await expect(blockedRow(financePage, blocked.recurId).getByTitle('Cancel')).toBeVisible();
  await expect(blockedRow(financePage, notBlocked.recurId)).toHaveCount(0);
});

test.describe('refused users', () => {
  test.use({ allowedProblems: [either(SCREEN_NOISE, /Cannot set response code|status of (403|500)|HTTP (403|500)/)] });

  test('PW-B-blocked-01 the limited user (no "edit contributions") is refused: no list, and no menu entry', async ({ limitedPage }) => {
    const record = blockedRecord('blocked01refused');
    const response = await limitedPage.goto(adminUrl('contribute/blockedrecurring', { reset: 1 }));
    expect(REFUSAL_STATUS, `HTTP status ${response?.status()}`).toContain(response?.status());
    await expect(limitedPage.getByText('You do not have permission to access this page.')).toBeVisible();
    await expect(limitedPage.locator('crm-search-display-table, table.table')).toHaveCount(0);
    await expect(blockedRow(limitedPage, record.recurId)).toHaveCount(0);

    await limitedPage.goto(adminUrl('dashboard', { reset: 1 }));
    await expect(limitedPage.locator('#civicrm-menu')).toBeVisible();
    await expect(limitedPage.locator('#civicrm-menu a', { hasText: 'Blocked Recurring Contributions' })).toHaveCount(0);
  });
});

test('PW-B-blocked-02 Reactivate with a date after the cancelled contribution unblocks the record, and it leaves the list', async ({ adminPage }) => {
  test.setTimeout(120_000);
  const record = blockedRecord('blocked02reactivate');
  const other = blockedRecord('blocked02untouched');
  await openBlockedList(adminPage);
  await expect(blockedRow(adminPage, record.recurId)).toHaveCount(1);

  await blockedRow(adminPage, record.recurId).getByTitle('Reactivate').click();
  const dialog = reactivateDialog(adminPage);
  await expect(dialog).toBeVisible();
  const chosen = await pickDay(adminPage, dialog.locator('#crm-search-task-next-date'));
  await dialog.locator('.ui-dialog-buttonpane').getByRole('button', { name: /Reactivate Recurring Contributions/ }).click();
  await expect(notification(adminPage)).toContainText('Successfully reactivated 1 Recurring Contributions.');
  await expect(reactivateDialog(adminPage)).toHaveCount(0);

  const row = recurRow(record.recurId);
  expect(String(row.next_sched_contribution_date).slice(0, 10)).toBe(chosen);
  expect(row['contribution_status_id:name']).toBe('In Progress');
  await expect(blockedRow(adminPage, record.recurId)).toHaveCount(0);
  // The record they did not touch is still blocked, and still listed.
  await expect(blockedRow(adminPage, other.recurId)).toHaveCount(1);
});

test('PW-B-blocked-03 Cancel stops the record for good with the reason, and it leaves the list', async ({ adminPage }) => {
  test.setTimeout(120_000);
  const record = blockedRecord('blocked03cancel');
  await openBlockedList(adminPage);

  await blockedRow(adminPage, record.recurId).getByTitle('Cancel').click();
  const dialog = cancelDialog(adminPage);
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.alert-warning')).toContainText('never be charged again');
  await dialog.locator('#crm-search-task-cancel-reason').fill('Donor asked to stop');
  await dialog.locator('.ui-dialog-buttonpane').getByRole('button', { name: /Cancel Recurring Contributions/ }).click();
  await expect(notification(adminPage)).toContainText('Successfully cancelled 1 Recurring Contributions.');
  await expect(cancelDialog(adminPage)).toHaveCount(0);

  const [row] = api4('ContributionRecur', 'get', {
    select: ['contribution_status_id:name', 'cancel_reason', 'cancel_date'],
    where: [['id', '=', record.recurId]],
  });
  expect(row['contribution_status_id:name']).toBe('Cancelled');
  expect(row.cancel_reason).toBe('Donor asked to stop');
  expect(row.cancel_date).toBeTruthy();
  await expect(blockedRow(adminPage, record.recurId)).toHaveCount(0);
});

test('PW-B-blocked-04 the System Status page counts the blocked records and links to the list', async ({ adminPage }) => {
  test.setTimeout(120_000);
  blockedRecord('blocked04a');
  blockedRecord('blocked04b');
  const expected = blockedCount();
  expect(expected).toBeGreaterThanOrEqual(2);

  const answered = adminPage.waitForResponse((response) => /ajax(\/|%2F)rest/.test(response.url()) && (response.request().postData() ?? '').includes('entity=System'));
  await adminPage.goto('about:blank');
  await adminPage.goto(`${adminUrl('a/')}#/status`);
  await answered;
  await expect(adminPage.getByRole('heading', { name: 'CiviCRM System Status' })).toBeVisible();
  await expect(adminPage.locator('.crm-status-item').first()).toBeVisible();

  const message = adminPage.locator('.crm-status-item').filter({ hasText: 'eWAYRecurring - blocked recurring contributions' });
  await expect(message).toHaveCount(1);
  await expect(message).toContainText(`Found ${expected} recurring contributions`);
  expect(await message.locator('a', { hasText: 'Blocked Recurring Contributions' }).getAttribute('href')).toContain('contribute/blockedrecurring');
});
