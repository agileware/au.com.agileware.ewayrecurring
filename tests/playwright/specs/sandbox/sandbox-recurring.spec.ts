import { test, expect } from '../../fixtures/base';
import {
  chooseRecurrence,
  expectedNextPayment,
  latestContribution,
  latestRecurring,
  pageUrl,
  queueRow,
  reviewContribution,
  tokensOf,
} from '../../fixtures/contribution-page';
import { contributionsOf, makeNextPaymentDue, recurRow, runRecurringJob, setOtherDueRecurringAside } from '../../fixtures/recurring-records';
import {
  AMOUNTS,
  fillSandboxDonation,
  hasSandboxCredentials,
  prepareSandboxSite,
  removeSandboxCredentials,
  SKIP_MESSAGE,
  submitCardOnHostedPage,
  uniqueDonor,
  waitForSandboxHostedPage,
  type SandboxSite,
} from '../../fixtures/sandbox';

/**
 * PW-X-02: recurring set-up through the REAL eWAY sandbox, then one run of the recurring payments job against the saved
 * token. The first payment is made on eWAY's sandbox hosted page, which also saves the card under a customer token;
 * the extension reads that token and the card back from the sandbox. The job then charges the token with a MOTO
 * transaction on the sandbox, no donor involved. Nothing is stubbed. Test plan: developer/TEST_PLAN.md section 5.4.
 * How the stub is bypassed, the credentials and the test card are explained in fixtures/sandbox.ts.
 */
test.describe('recurring set-up and the cron job on the real sandbox', { tag: '@sandbox' }, () => {
  test.skip(!hasSandboxCredentials(), SKIP_MESSAGE);

  let site: SandboxSite;

  test.beforeAll(() => {
    if (hasSandboxCredentials()) {
      site = prepareSandboxSite();
    }
  });

  test.afterAll(() => {
    if (hasSandboxCredentials()) {
      removeSandboxCredentials();
    }
  });

  test('PW-X-02 a recurring set-up saves eWAY\'s customer token, and the job then charges that token on the sandbox', async ({ anonPage }) => {
    test.setTimeout(240_000);
    const donor = uniqueDonor('x02');

    // Set up: $10.00 every month, paid on eWAY's sandbox page.
    await anonPage.goto(pageUrl(site.recurringPageId, { action: 'preview' }));
    await fillSandboxDonation(anonPage, AMOUNTS.approved, donor);
    await chooseRecurrence(anonPage, { interval: 1, unit: 'month' });
    await reviewContribution(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').click();
    await waitForSandboxHostedPage(anonPage);
    await submitCardOnHostedPage(anonPage);
    await expect(anonPage.locator('body')).toContainText('Thank you for your donation', { timeout: 60_000 });
    await expect(anonPage.locator('body')).toContainText('processed successfully');

    // The first payment is Completed with eWAY's own transaction id, and belongs to a recurring record.
    const first = latestContribution(donor.email, true)!;
    expect(first['contribution_status_id:name']).toBe('Completed');
    expect(String(first.trxn_id)).toMatch(/^\d{6,}$/);
    expect(first.contribution_recur_id).toBeTruthy();
    expect(Number(queueRow(first.id)!.payment_processor_id)).toBe(site.testProcessorId);

    // The record holds the token eWAY issued, and the card as eWAY reports it (a masked number and an expiry).
    const recur = latestRecurring(donor.email)!;
    expect(recur.id).toBe(first.contribution_recur_id);
    expect(recur['contribution_status_id:name']).toBe('In Progress');
    expect(Number(recur.amount)).toBe(AMOUNTS.approved);
    expect(recur.frequency_unit).toBe('month');
    expect(Number(recur.payment_processor_id)).toBe(site.testProcessorId);
    expect(String(recur.processor_id)).toMatch(/^\d{6,}$/);
    expect(recur.payment_token_id).toBeTruthy();
    const cards = tokensOf(first.contact_id);
    expect(cards).toHaveLength(1);
    expect(cards[0].id).toBe(recur.payment_token_id);
    expect(String(cards[0].token)).toBe(String(recur.processor_id));
    expect(cards[0].masked_account_number).toBe('444433XXXXXX1111');
    expect(Number(cards[0].payment_processor_id)).toBe(site.testProcessorId);
    // Locale independent: the expiry is the last day of a month in the future.
    expect(String(cards[0].expiry_date).slice(0, 10) > new Date().toISOString().slice(0, 10)).toBeTruthy();

    // The job: make the next payment due, with nothing else due on the site, and run it. The charge goes to the sandbox.
    makeNextPaymentDue(recur.id);
    setOtherDueRecurringAside(recur.id);
    const due = String(recurRow(recur.id).next_sched_contribution_date);
    runRecurringJob();

    const contributions = contributionsOf(recur.id);
    expect(contributions).toHaveLength(2);
    const charged = contributions[1];
    expect(charged['contribution_status_id:name']).toBe('Completed');
    expect(String(charged.trxn_id)).toMatch(/^\d{6,}$/);
    // eWAY gave the second charge a transaction of its own.
    expect(String(charged.trxn_id)).not.toBe(String(first.trxn_id));
    expect(Number(charged.total_amount)).toBe(AMOUNTS.approved);

    const after = recurRow(recur.id);
    expect(after['contribution_status_id:name']).toBe('In Progress');
    expect(after.failure_count).toBe(0);
    expect(after.failure_retry_date).toBeFalsy();
    // Yesterday's date plus a month, at midnight.
    expect(after.next_sched_contribution_date).toBe(expectedNextPayment(due.slice(0, 10), { interval: 1, unit: 'month' }));
  });
});
