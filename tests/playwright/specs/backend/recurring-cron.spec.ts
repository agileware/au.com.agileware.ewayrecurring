import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { api4, setSettings } from '../../fixtures/civi';
import { resetEwaySettings } from '../../fixtures/backend';
import { newTransactionId, expectedNextPayment, serverToday } from '../../fixtures/contribution-page';
import { responses, type EwayStub } from '../../fixtures/eway-stub';
import { mailSubjectsFor } from '../../fixtures/queue-records';
import {
  activitiesOf,
  contributionsOf,
  createRecurring,
  makeNextPaymentDue,
  makeRetryDue,
  recurRow,
  runRecurringJob,
  setOtherDueRecurringAside,
  type RecurringRecord,
} from '../../fixtures/recurring-records';
import {
  civiDay,
  contributionRow,
  contributionRows,
  failedListRow,
  openActivitiesTab,
  openContributionsTab,
  openFailedList,
  openRecurringTab,
  recurringRow,
  recurringViewUrl,
  SCREEN_NOISE,
} from '../../fixtures/recurring-screens';
import { cardsWithoutDetails, JOB, latestJobResult, runJobFromScreen } from '../../fixtures/scheduled-jobs';

/**
 * PW-B-cron: what the recurring payments job (the call the scheduled job "eWay Recurring Payments" makes) does to a
 * recurring contribution, and what staff then see on CiviCRM's own screens. The job is started with
 * `Job.run_payment_cron` straight through cv, as the plan says (running it from the Scheduled Jobs screen is
 * PW-B-jobs-05), and the screens are then opened as the administrator. Test plan: developer/TEST_PLAN.md section 5.2.
 *
 * The job asks eWAY for every record that is due and the stub answers by method and order, so each case puts every
 * other due record aside first and queues exactly the answers its own record needs. Time is moved on with the helpers in
 * recurring-records.ts: a retry is only due days after the failure in real life.
 *
 * What is on the screens, learned from them: the Recurring Contributions tab lists a record by status, amount, next
 * date and installments (no failure count and no retry date); the record's own page shows "Failure Count" but not the
 * retry date; the retry date is shown only on the Failed Recurring Contributions list, which lists Failed and Failing
 * records. A recurring record with status Failed is under "Inactive Recurring Contributions" with a View link only.
 */

/** Core prints a warning on every recurring contribution's own page, and a list still loading can be cancelled (see SCREEN_NOISE). */
test.use({ allowedProblems: [SCREEN_NOISE] });

test.beforeEach(() => resetEwaySettings());
test.afterEach(() => resetEwaySettings());

/** A date plus a number of days, as 'YYYY-MM-DD 00:00:00' (the form the extension writes retry dates in). */
function plusDays(day: string, days: number): string {
  const [year, month, date] = day.split('-').map(Number);
  return `${new Date(Date.UTC(year, month - 1, date + days)).toISOString().slice(0, 10)} 00:00:00`;
}

/** A due record with its due date yesterday, a Completed first payment, and nothing else due on the site. */
function dueRecord(label: string, extra: { amount?: number } = {}): RecurringRecord {
  const record = createRecurring({ label, nextDue: '-1 day', ...extra });
  setOtherDueRecurringAside(record.recurId);
  return record;
}

/** Run the job once with eWAY declining the card, and check eWAY was asked exactly once. */
function runDeclined(stub: EwayStub, record: RecurringRecord, message = 'D4401'): void {
  stub.reset();
  stub.respond('postTransaction', responses.paymentDeclined(message, newTransactionId()));
  runRecurringJob();
  expect(stub.requests('postTransaction'), 'eWAY was asked once').toHaveLength(1);
  expect((stub.requests('postTransaction')[0].args[0] as any).Customer.TokenCustomerID).toBe(record.token);
}

/** Run the job once with eWAY approving the card. Returns the transaction id eWAY gave. */
function runApproved(stub: EwayStub, record: RecurringRecord): number {
  stub.reset();
  const transactionId = newTransactionId();
  stub.respond('postTransaction', responses.paymentApproved(transactionId));
  runRecurringJob();
  expect(stub.requests('postTransaction'), 'eWAY was asked once').toHaveLength(1);
  expect((stub.requests('postTransaction')[0].args[0] as any).Customer.TokenCustomerID).toBe(record.token);
  return transactionId;
}

/**
 * Decline until the record has `failures` failures, moving time on between runs so each retry is due. Leaves the
 * record as the last decline left it.
 */
function declineUntil(stub: EwayStub, record: RecurringRecord, failures: number): void {
  for (let run = 1; run <= failures; run++) {
    if (run > 1) {
      makeRetryDue(record.recurId);
    }
    runDeclined(stub, record);
    expect(recurRow(record.recurId).failure_count, `failures after run ${run}`).toBe(run);
  }
}

/** The status text shown for the record on the contact's Recurring Contributions tab. */
async function statusOnTab(page: Page, record: RecurringRecord): Promise<string> {
  await openRecurringTab(page, record.contactId);
  const row = recurringRow(page, record);
  await expect(row).toBeVisible();
  return (await row.locator('td').nth(6).innerText()).trim();
}

/** The notes on a contribution, as stored. */
function notesOn(contributionId: number): Record<string, any>[] {
  return api4('Note', 'get', {
    select: ['subject', 'note'],
    where: [['entity_table', '=', 'civicrm_contribution'], ['entity_id', '=', contributionId]],
  });
}

test.describe('recurring payments job: what staff see', () => {
  test('PW-B-cron-01 a due recurring contribution is charged: the contact shows a new Completed payment and the next date moves on a month', async ({ adminPage, stub }) => {
    test.setTimeout(90_000);
    const record = dueRecord('cron01');
    const due = String(recurRow(record.recurId).next_sched_contribution_date);
    expect(contributionsOf(record.recurId)).toHaveLength(1);

    const transactionId = runApproved(stub, record);

    const contributions = contributionsOf(record.recurId);
    expect(contributions).toHaveLength(2);
    const charged = contributions[1];
    expect(charged['contribution_status_id:name']).toBe('Completed');
    expect(String(charged.trxn_id)).toBe(String(transactionId));
    const after = recurRow(record.recurId);
    expect(after['contribution_status_id:name']).toBe('In Progress');
    expect(after.failure_count).toBe(0);
    expect(after.failure_retry_date).toBeFalsy();
    // Yesterday's date plus a month, at midnight.
    const expectedNext = expectedNextPayment(due.slice(0, 10), { interval: 1, unit: 'month' });
    expect(after.next_sched_contribution_date).toBe(expectedNext);

    // The contact's Contributions tab lists two payments, both Completed, the new one a recurring $10.00.
    await openContributionsTab(adminPage, record.contactId);
    await expect(contributionRows(adminPage)).toHaveCount(2);
    const row = contributionRow(adminPage, charged.id);
    await expect(row.locator('.crm-contribution-amount')).toContainText('$10.00');
    await expect(row.locator('.crm-contribution-amount')).toContainText('(Recurring)');
    await expect(row.locator('.crm-contribution-status')).toHaveText('Completed');
    await expect(contributionRow(adminPage, record.contributionId!).locator('.crm-contribution-status')).toHaveText('Completed');

    // The recurring row is still active (In Progress) and shows the new next date.
    await openRecurringTab(adminPage, record.contactId);
    const recurring = recurringRow(adminPage, record);
    await expect(recurring).toContainText('In Progress');
    await expect(recurring).toContainText(civiDay(expectedNext));
    await expect(recurring.getByRole('link', { name: 'Edit' })).toBeVisible();

    // A successful charge records no failure.
    expect(activitiesOf(record.contactId, 'eWay_Transaction_Failed')).toHaveLength(0);
  });

  test('PW-B-cron-02 a declined charge leaves a Failed contribution with a Transaction Error note, a failure activity, and the record In Progress with a retry date', async ({ adminPage, stub }) => {
    test.setTimeout(90_000);
    const record = dueRecord('cron02');
    const today = serverToday();

    runDeclined(stub, record, 'D4401');

    // The data: a Failed contribution carrying the eWAY message in a "Transaction Error" note.
    const contributions = contributionsOf(record.recurId);
    expect(contributions).toHaveLength(2);
    const failed = contributions[1];
    expect(failed['contribution_status_id:name']).toBe('Failed');
    const notes = notesOn(failed.id);
    expect(notes).toHaveLength(1);
    expect(notes[0].subject).toBe('Transaction Error');
    expect(notes[0].note).toBe('Refer to Issuer');
    // The record keeps running: one failure, and a retry date the configured delay (4 days) from today, at midnight.
    const state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('In Progress');
    expect(state.failure_count).toBe(1);
    expect(state.failure_retry_date).toBe(plusDays(today, 4));
    expect(activitiesOf(record.contactId, 'eWay_Transaction_Failed')).toHaveLength(1);

    // The contributions tab lists the Failed payment, and its View page shows the eWAY message as the note.
    await openContributionsTab(adminPage, record.contactId);
    await expect(contributionRows(adminPage)).toHaveCount(2);
    const row = contributionRow(adminPage, failed.id);
    await expect(row.locator('.crm-contribution-status')).toHaveText('Failed');
    await row.getByRole('link', { name: 'View' }).click();
    const dialog = adminPage.locator('.ui-dialog');
    await expect(dialog).toContainText('View Contribution');
    await expect(dialog).toContainText('Contribution Status Failed');
    await expect(dialog.locator('tr', { hasText: /^\s*Note\b/ })).toContainText('Refer to Issuer');
    await dialog.getByRole('button', { name: 'Close' }).first().click();

    // The contact has an "eWay Transaction Failed" activity.
    await openActivitiesTab(adminPage, record.contactId);
    await expect(adminPage.locator('table', { hasText: 'Added by' })).toContainText('eWay Transaction Failed');

    // The Recurring Contributions tab still lists it as an active record. It shows neither the failure count nor the
    // retry date; the record's own page shows the failure count.
    expect(await statusOnTab(adminPage, record)).toBe('In Progress');
    await adminPage.goto(recurringViewUrl(record));
    await expect(adminPage.locator('tr', { hasText: /^\s*Failure Count/ })).toContainText('1');
    await expect(adminPage.locator('tr', { hasText: /^\s*Status/ }).first()).toContainText('In Progress');
  });

  test('PW-B-cron-03 a retry that is charged after the delay brings the record back to normal and records a success activity', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    const record = dueRecord('cron03');
    declineUntil(stub, record, 1);
    expect(recurRow(record.recurId).failure_retry_date).toBeTruthy();

    // Before the delay has passed the job does not charge it again.
    stub.reset();
    runRecurringJob();
    expect(stub.requests('postTransaction')).toHaveLength(0);
    expect(recurRow(record.recurId).failure_count).toBe(1);

    // The delay passes and eWAY accepts the card.
    makeRetryDue(record.recurId);
    const transactionId = runApproved(stub, record);

    const state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('In Progress');
    expect(state.failure_count).toBe(0);
    expect(state.failure_retry_date).toBeFalsy();
    const contributions = contributionsOf(record.recurId);
    expect(contributions.map((c) => c['contribution_status_id:name'])).toEqual(['Completed', 'Failed', 'Completed']);
    expect(String(contributions[2].trxn_id)).toBe(String(transactionId));
    const succeeded = activitiesOf(record.contactId, 'eWay_Transaction_Succeeded');
    expect(succeeded).toHaveLength(1);
    expect(succeeded[0].details).toBe('Transaction Succeeded after 1 retries');

    // On the screens: both activities are listed, the success reads as the plan says, and the record is In Progress.
    await openActivitiesTab(adminPage, record.contactId);
    const activities = adminPage.locator('table', { hasText: 'Added by' });
    await expect(activities).toContainText('eWay Transaction Failed');
    await expect(activities).toContainText('eWay Transaction Succeeded');
    await activities.locator('tr', { hasText: 'eWay Transaction Succeeded' }).getByRole('link', { name: 'View' }).click();
    await expect(adminPage.locator('.ui-dialog')).toContainText('Transaction Succeeded after 1 retries');
    expect(await statusOnTab(adminPage, record)).toBe('In Progress');
    await openContributionsTab(adminPage, record.contactId);
    await expect(contributionRows(adminPage)).toHaveCount(3);
    await expect(contributionRow(adminPage, contributions[2].id).locator('.crm-contribution-status')).toHaveText('Completed');
  });

  test('PW-B-cron-04 when the retries run out the record is Failed on the Failed Recurring list, every failed payment is on the contact, and nothing more is charged', async ({ adminPage, stub }) => {
    test.setTimeout(150_000);
    // The default limit of three failed charges.
    const limit = 3;
    setSettings({ eway_recurring_contribution_max_retry: limit });
    const record = dueRecord('cron04');
    declineUntil(stub, record, limit);

    const failed = recurRow(record.recurId);
    expect(failed['contribution_status_id:name']).toBe('Failed');
    expect(failed.failure_count).toBe(limit);
    expect(failed.failure_retry_date).toBeTruthy();
    const contributions = contributionsOf(record.recurId);
    expect(contributions.map((c) => c['contribution_status_id:name'])).toEqual(['Completed', 'Failed', 'Failed', 'Failed']);

    // Nothing more is charged, even when both the next payment and a retry would be due.
    makeRetryDue(record.recurId);
    makeNextPaymentDue(record.recurId);
    stub.reset();
    runRecurringJob();
    expect(stub.requests()).toHaveLength(0);
    expect(contributionsOf(record.recurId)).toHaveLength(4);
    expect(recurRow(record.recurId).failure_count).toBe(limit);

    // The three failed payments are on the contact's Contributions tab, and the record is under Inactive.
    await openContributionsTab(adminPage, record.contactId);
    await expect(contributionRows(adminPage)).toHaveCount(4);
    for (const contribution of contributions.slice(1)) {
      await expect(contributionRow(adminPage, contribution.id).locator('.crm-contribution-status')).toHaveText('Failed');
    }
    // The tab lists it as an inactive record with View only.
    await openRecurringTab(adminPage, record.contactId);
    const row = recurringRow(adminPage, record);
    await expect(row).toContainText('Failed');
    await expect(row.getByRole('link')).toHaveCount(1);
    await expect(row.getByRole('link', { name: 'View' })).toBeVisible();

    // And it is on the Failed Recurring Contributions list, with its failure count and retry date.
    await openFailedList(adminPage);
    const listed = failedListRow(adminPage, record.recurId);
    await expect(listed).toHaveCount(1);
    await expect(listed.locator('[data-field-name="contact_id.display_name"]')).toContainText(record.donor.firstName);
    await expect(listed.locator('[data-field-name="failure_count"]')).toHaveText(String(limit));
    await expect(listed.locator('[data-field-name="failure_retry_date"]')).toContainText(civiDay(String(recurRow(record.recurId).failure_retry_date)));
    await expect(listed.locator('[data-field-name="contribution_status_id:label"], [data-field-name="contribution_status_id"]').first()).toContainText('Failed');
    await expect(listed.getByTitle('Reactivate')).toBeVisible();
  });

  test('PW-B-cron-05 the donor gets a receipt for a recurring payment only when "Send receipt for all recurring contributions" is on', async ({ stub }) => {
    test.setTimeout(90_000);
    // Off (the default): the payment completes and no mail is made.
    const off = dueRecord('cron05off');
    runApproved(stub, off);
    expect(contributionsOf(off.recurId).pop()!['contribution_status_id:name']).toBe('Completed');
    expect(mailSubjectsFor(off.donor.email)).toEqual([]);

    // On: the next one sends exactly one receipt to the donor.
    setSettings({ eway_recurring_keep_sending_receipts: true });
    const on = dueRecord('cron05on');
    runApproved(stub, on);
    expect(contributionsOf(on.recurId).pop()!['contribution_status_id:name']).toBe('Completed');
    const subjects = mailSubjectsFor(on.donor.email);
    expect(subjects, `mail for ${on.donor.email}`).toHaveLength(1);
    expect(subjects[0]).not.toBe('');
  });

  test('PW-B-cron-06 with a limit of two failed charges the second marks the record Failed, and a further run charges nothing', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    setSettings({ eway_recurring_contribution_max_retry: 2 });
    const record = dueRecord('cron06');

    // First failure: still In Progress, failure_count 1, a retry date.
    runDeclined(stub, record);
    let state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('In Progress');
    expect(state.failure_count).toBe(1);
    expect(state.failure_retry_date).toBeTruthy();
    expect(await statusOnTab(adminPage, record)).toBe('In Progress');

    // Second failure, once the retry is due: Failed, with the retry date kept and two failed payments.
    makeRetryDue(record.recurId);
    runDeclined(stub, record);
    state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('Failed');
    expect(state.failure_count).toBe(2);
    expect(state.failure_retry_date).toBeTruthy();
    expect(await statusOnTab(adminPage, record)).toBe('Failed');
    await expect(adminPage.locator('h3', { hasText: 'Inactive Recurring Contributions' })).toBeVisible();

    // A further run, with both the retry and the next payment due, charges nothing and makes no contribution.
    const count = contributionsOf(record.recurId).length;
    expect(count).toBe(3);
    makeRetryDue(record.recurId);
    makeNextPaymentDue(record.recurId);
    stub.reset();
    runRecurringJob();
    expect(stub.requests()).toHaveLength(0);
    expect(contributionsOf(record.recurId)).toHaveLength(count);
    state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('Failed');
    expect(state.failure_count).toBe(2);
  });

  test('PW-B-cron-07 a charge that succeeds on a retry: the record stays In Progress throughout, the failures and retry date clear, and the success activity counts the retries', async ({ adminPage, stub }) => {
    test.setTimeout(150_000);
    const record = dueRecord('cron07');

    // Two failures. The record is In Progress, never Failed, after each (the default limit is three).
    runDeclined(stub, record);
    expect(await statusOnTab(adminPage, record)).toBe('In Progress');
    makeRetryDue(record.recurId);
    runDeclined(stub, record);
    expect(await statusOnTab(adminPage, record)).toBe('In Progress');
    let state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('In Progress');
    expect(state.failure_count).toBe(2);
    expect(state.failure_retry_date).toBeTruthy();

    // The third attempt is accepted.
    makeRetryDue(record.recurId);
    const transactionId = runApproved(stub, record);
    state = recurRow(record.recurId);
    expect(state['contribution_status_id:name']).toBe('In Progress');
    expect(state.failure_count).toBe(0);
    expect(state.failure_retry_date).toBeFalsy();
    expect(String(state.next_sched_contribution_date) > '').toBe(true);
    const contributions = contributionsOf(record.recurId);
    expect(contributions.map((c) => c['contribution_status_id:name'])).toEqual(['Completed', 'Failed', 'Failed', 'Completed']);
    expect(String(contributions[3].trxn_id)).toBe(String(transactionId));

    // The contact has the success activity reading "after 2 retries", and the failure ones.
    const succeeded = activitiesOf(record.contactId, 'eWay_Transaction_Succeeded');
    expect(succeeded).toHaveLength(1);
    expect(succeeded[0].details).toBe('Transaction Succeeded after 2 retries');
    expect(activitiesOf(record.contactId, 'eWay_Transaction_Failed')).toHaveLength(2);
    expect(await statusOnTab(adminPage, record)).toBe('In Progress');
    await openActivitiesTab(adminPage, record.contactId);
    await expect(adminPage.locator('table', { hasText: 'Added by' })).toContainText('eWay Transaction Succeeded');
    // It is not on the Failed Recurring Contributions list.
    await openFailedList(adminPage);
    await expect(failedListRow(adminPage, record.recurId)).toHaveCount(0);
  });

  // The schedule after a charge that failed and then succeeded on a retry. The first failure already moves the next date one
  // interval on (DEF-46), and the success moves it on from there, so a monthly record ends two months on and one
  // payment is skipped. A record that is charged once, with no failure, moves one month on (PW-B-cron-01).
  test('PW-B-cron-07 the next date after a failure and a successful retry is one interval after the date the payment was due (DEF-58)', async ({ stub }) => {
    test.fail(true, 'DEF-58: the failure and the success each move the schedule on, so it ends two intervals ahead');
    test.setTimeout(120_000);
    const record = dueRecord('cron07date');
    const due = String(recurRow(record.recurId).next_sched_contribution_date);
    runDeclined(stub, record);
    makeRetryDue(record.recurId);
    runApproved(stub, record);
    expect(recurRow(record.recurId).next_sched_contribution_date).toBe(expectedNextPayment(due.slice(0, 10), { interval: 1, unit: 'month' }));
  });

  test('PW-B-cron-08 a card replaced in place through the API is charged on its new token value, and the fill-in job then restores its masked number and expiry', async ({ adminPage, stub }) => {
    test.setTimeout(120_000);
    const record = dueRecord('cron08');
    const oldCard = api4<Record<string, any>>('PaymentToken', 'get', {
      select: ['id', 'token', 'masked_account_number', 'expiry_date'],
      where: [['id', '=', record.tokenId]],
    })[0];
    expect(oldCard.token).toBe(record.token);
    expect(oldCard.masked_account_number).toBeTruthy();

    // A website's update-card page changes the saved card in place: a new token, and no expiry or masked number yet.
    const newToken = `9${String(newTransactionId()).padStart(11, '0')}`.slice(0, 12);
    expect(newToken).not.toBe(record.token);
    api4('PaymentToken', 'update', {
      where: [['id', '=', record.tokenId]],
      values: { token: newToken, expiry_date: null, masked_account_number: null },
    });
    const replaced = api4<Record<string, any>>('PaymentToken', 'get', {
      select: ['id', 'token', 'masked_account_number', 'expiry_date'],
      where: [['id', '=', record.tokenId]],
    })[0];
    expect(replaced.token).toBe(newToken);
    expect(replaced.masked_account_number ?? null).toBeNull();
    expect(replaced.expiry_date ?? null).toBeNull();

    // The next run charges the new token value; the recurring record still points at the same card row.
    stub.reset();
    const transactionId = newTransactionId();
    stub.respond('postTransaction', responses.paymentApproved(transactionId));
    runRecurringJob();
    const charges = stub.requests('postTransaction');
    expect(charges).toHaveLength(1);
    expect((charges[0].args[0] as any).Customer.TokenCustomerID).toBe(newToken);
    expect(recurRow(record.recurId).payment_token_id).toBe(record.tokenId);
    const charged = contributionsOf(record.recurId).pop()!;
    expect(charged['contribution_status_id:name']).toBe('Completed');
    expect(String(charged.trxn_id)).toBe(String(transactionId));
    await openContributionsTab(adminPage, record.contactId);
    await expect(contributionRows(adminPage)).toHaveCount(2);
    await expect(contributionRow(adminPage, charged.id).locator('.crm-contribution-status')).toHaveText('Completed');

    // The fill-in job, run from the Scheduled Jobs screen, asks eWAY about the card and restores both details.
    const waiting = cardsWithoutDetails();
    expect(waiting).toBeGreaterThanOrEqual(1);
    stub.reset();
    stub.respond('getCustomer', responses.customerFound(newToken, 'civi-1', { Number: '444433XXXXXX1111', ExpiryMonth: '12', ExpiryYear: '31' }), { repeat: true });
    await runJobFromScreen(adminPage, JOB.tokens);
    expect(await latestJobResult(adminPage, JOB.tokens)).toContain(`s:5:"count";i:${waiting};`);
    expect(stub.requests('getCustomer').map((request) => request.args[0])).toContain(newToken);
    const restored = api4<Record<string, any>>('PaymentToken', 'get', {
      select: ['id', 'token', 'masked_account_number', 'expiry_date'],
      where: [['id', '=', record.tokenId]],
    })[0];
    expect(restored.token).toBe(newToken);
    expect(restored.masked_account_number).toBe('444433XXXXXX1111');
    expect(String(restored.expiry_date)).toMatch(/^2031-12-31/);
  });
});
