import { expect, type Locator, type Page } from '@playwright/test';
import { api4 } from './civi';
import { adminUrl } from './cms';

/**
 * Helpers for CiviCRM's Scheduled Jobs screen (Administer, System Settings, Scheduled Jobs) and the job log, used by the
 * specs that run this extension's jobs from the screen the way an administrator does (PW-B-jobs).
 */

/** The names of the four jobs the extension adds. */
export const JOB = {
  recurring: 'eWay Recurring Payments',
  verifications: 'eWay Transaction Verifications',
  tokens: 'eWay Recurring: fill missing tokens metadata',
  settlement: 'eWay Settlement Sync',
} as const;

export type JobName = (typeof JOB)[keyof typeof JOB];

/** The Scheduled Jobs page's address. */
export function jobsUrl(): string {
  return adminUrl('admin/job', { reset: 1 });
}

/**
 * Open the Scheduled Jobs page and wait for its list. CiviCRM draws the list differently from one version to the next (a
 * classic table of `tr.crm-entity` rows with Execute links, or a Search Kit table whose row actions are behind a "Row
 * Actions" button), so rows are found by their role and text, and the helpers below cope with both.
 */
export async function openJobs(page: Page): Promise<void> {
  await page.goto(jobsUrl());
  await expect(page.getByRole('row').filter({ hasText: JOB.recurring }).first()).toBeVisible();
}

/** The row of the Scheduled Jobs table for a job, found by its name. */
export function jobRow(page: Page, name: string): Locator {
  return page.getByRole('row').filter({ hasText: name });
}

/** The cell that says whether the job is enabled (Yes or No). */
export function enabledCell(row: Locator): Locator {
  return row.getByRole('cell', { name: /^\s*(Yes|No)\s*$/ });
}

/** Open the row's menu of actions, where the version of CiviCRM keeps one. */
export async function openRowActions(row: Locator): Promise<void> {
  const menu = row.getByRole('button', { name: 'Row Actions' });
  if (await menu.count()) {
    await menu.click();
  } else {
    await row.locator('.btn-slide').click();
  }
}

/** The row's Execute action ("Execute" on older versions, "Execute now" on newer ones), once it is visible. */
export function executeAction(row: Locator): Locator {
  return row.locator('a', { hasText: /^\s*Execute( now)?\s*$/ });
}

/** The id CiviCRM gave a job, read from the database. */
export function jobIdByName(name: string): number {
  const rows = api4<{ id: number }>('Job', 'get', { select: ['id'], where: [['name', '=', name]] });
  if (!rows.length) {
    throw new Error(`There is no scheduled job named "${name}".`);
  }
  return rows[0].id;
}

/** The newest "Finished execution" entry in a job's log, as the log stores it, or undefined. */
function newestFinishedEntry(name: string): { id: number; description: string } | undefined {
  return api4<{ id: number; description: string }>('JobLog', 'get', {
    select: ['id', 'description'],
    where: [['job_id', '=', jobIdByName(name)], ['description', 'LIKE', 'Finished execution%']],
    orderBy: { id: 'DESC' },
    limit: 1,
  })[0];
}

/**
 * Run a job the way an administrator does: press Execute on its row, confirm on the page that asks, and wait until the
 * job's log has a new "Finished execution" entry. The job runs on the web server, as the signed-in user. The wait is on
 * the log, not on the message CiviCRM shows afterwards, which is worded differently from one version to the next.
 */
export async function runJobFromScreen(page: Page, name: string): Promise<void> {
  const before = newestFinishedEntry(name)?.id ?? 0;
  await openJobs(page);
  const row = jobRow(page, name);
  await expect(row).toHaveCount(1);
  const execute = executeAction(row);
  if (!(await execute.isVisible())) {
    await openRowActions(row);
  }
  await execute.click();
  await expect(page.locator('#_qf_Job_submit-bottom')).toBeVisible();
  await page.locator('#_qf_Job_submit-bottom').click();
  await expect.poll(() => newestFinishedEntry(name)?.id ?? 0, { timeout: 30_000 }).toBeGreaterThan(before);
}

/**
 * The summary of the newest "Finished execution" entry in a job's log, for example
 * `Finished execution of X with result: Success (a:1:{s:5:"count";i:0;})`. Read from the log table: the log screen is a
 * different page on different versions of CiviCRM.
 */
export function latestJobResult(name: string): string {
  const entry = newestFinishedEntry(name);
  if (!entry) {
    throw new Error(`The job "${name}" has no "Finished execution" entry in its log.`);
  }
  return entry.description.replace(/\s+/g, ' ').trim();
}

/** How many saved eWAY cards are missing their expiry date or masked number, which is what the fill-in job looks for. */
export function cardsWithoutDetails(): number {
  return api4<{ id: number }>('PaymentToken', 'get', {
    select: ['id'],
    where: [
      ['payment_processor_id.payment_processor_type_id.name', '=', 'eWay_Recurring'],
      ['OR', [['expiry_date', 'IS NULL'], ['masked_account_number', 'IS NULL']]],
    ],
  }).length;
}
