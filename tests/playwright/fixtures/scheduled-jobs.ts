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

/** Open the Scheduled Jobs page and wait for its table. */
export async function openJobs(page: Page): Promise<void> {
  await page.goto(jobsUrl());
  await expect(page.locator('tr.crm-entity').first()).toBeVisible();
}

function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** The row of the Scheduled Jobs table for a job, found by its exact name. */
export function jobRow(page: Page, name: string): Locator {
  return page.locator('tr.crm-entity').filter({
    has: page.locator('span[data-field="name"]').filter({ hasText: new RegExp(`^\\s*${escapeRegExp(name)}\\s*$`) }),
  });
}

/** The id CiviCRM gave a job, read from the database. */
export function jobIdByName(name: string): number {
  const rows = api4<{ id: number }>('Job', 'get', { select: ['id'], where: [['name', '=', name]] });
  if (!rows.length) {
    throw new Error(`There is no scheduled job named "${name}".`);
  }
  return rows[0].id;
}

/**
 * Run a job the way an administrator does: press Execute on its row, confirm on the page that asks, and wait for the
 * message CiviCRM shows when it has run. The job runs on the web server, as the signed-in user.
 */
export async function runJobFromScreen(page: Page, name: string): Promise<void> {
  await openJobs(page);
  const row = jobRow(page, name);
  await expect(row).toHaveCount(1);
  await row.getByRole('link', { name: 'Execute' }).click();
  await expect(page.locator('.crm-job-form-block')).toContainText('Are you sure you would like to execute');
  await page.locator('#_qf_Job_submit-bottom').click();
  await expect(page.locator('#crm-notification-container')).toContainText('Scheduled Job has been executed');
}

/** The job log page for one job. */
export function jobLogUrl(name: string): string {
  return adminUrl('admin/joblog', { jid: jobIdByName(name), reset: 1 });
}

/**
 * The summary of the newest "Finished execution" entry in a job's log, for example
 * `Finished execution of X with result: Success (a:1:{s:5:"count";i:0;})`.
 */
export async function latestJobResult(page: Page, name: string): Promise<string> {
  await page.goto(jobLogUrl(name));
  const finished = page.locator('tr.crm-entity .crm-joblog-description', { hasText: 'Finished execution' }).first();
  await expect(finished).toBeVisible();
  return (await finished.innerText()).replace(/^\s*Summary\s*/, '').replace(/\s+/g, ' ').trim();
}

/** How many entries a job's log holds, so a case can tell that running the job added to it. */
export function jobLogCount(name: string): number {
  return api4<{ id: number }>('JobLog', 'get', { select: ['id'], where: [['job_id', '=', jobIdByName(name)]] }).length;
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
