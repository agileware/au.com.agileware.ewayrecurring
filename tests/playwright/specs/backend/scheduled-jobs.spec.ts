import { test, expect } from '../../fixtures/base';
import { api4 } from '../../fixtures/civi';
import { responses } from '../../fixtures/eway-stub';
import { newCustomerToken, newTransactionId, runVerificationJob, setOtherQueueRowsAside, transactionApproved, uniqueDonor } from '../../fixtures/contribution-page';
import { contributionState, createPendingPayment, queueRowById } from '../../fixtures/queue-records';
import {
  contributionsOf,
  createRecurring,
  liveProcessorId,
  recurRow,
  setOtherDueRecurringAside,
} from '../../fixtures/recurring-records';
import {
  contributionRow,
  contributionRows,
  openContributionsTab,
  openRecurringTab,
  recurringRow,
  recurringViewUrl,
  SCREEN_NOISE,
} from '../../fixtures/recurring-screens';
import { cardsWithoutDetails, enabledCell, executeAction, JOB, jobRow, latestJobResult, openJobs, openRowActions, runJobFromScreen } from '../../fixtures/scheduled-jobs';

/**
 * PW-B-jobs: the four scheduled jobs the extension adds, as an administrator sees them on CiviCRM's Scheduled Jobs
 * screen, and three of them run from that screen with the eWAY stub answering (the recurring payments job, the
 * transaction verifications job and the job that fills in missing card details). The cron job is also driven straight
 * through the API in PW-B-cron; these cases are about the screen. Test plan: developer/TEST_PLAN.md section 5.2.
 *
 * Each job's answer is the stub's: it replies by method and in order, not by customer, so every case that runs a job
 * that asks eWAY first puts the other waiting work aside (a due recurring record, a queued verification) and gives the
 * stub exactly what its own record needs.
 */

/** The state of a scheduled job as it is stored, and how the screen words it. */
interface ExpectedJob {
  name: string;
  /** The frequency as stored. */
  frequency: string;
  /** How the screen words the frequency: older versions of CiviCRM say "Always", newer ones "Every time cron job is run". */
  shown: RegExp;
  entity: string;
  action: string;
  parameters: string;
  enabled: 'Yes' | 'No';
}

const EXPECTED: ExpectedJob[] = [
  { name: JOB.recurring, frequency: 'Always', shown: /Always|Every time cron job is run/, entity: 'Job', action: 'run_payment_cron', parameters: 'processor_name=eWay_Recurring', enabled: 'Yes' },
  { name: JOB.verifications, frequency: 'Always', shown: /Always|Every time cron job is run/, entity: 'EwayContributionTransactions', action: 'validate', parameters: '', enabled: 'Yes' },
  { name: JOB.tokens, frequency: 'Hourly', shown: /Hourly/, entity: 'EwayRecurring', action: 'fillTokensMeta', parameters: '', enabled: 'Yes' },
  { name: JOB.settlement, frequency: 'Daily', shown: /Daily/, entity: 'EwaySettlement', action: 'Sync', parameters: '', enabled: 'No' },
];

test.describe('scheduled jobs: the listing', () => {
  test('PW-B-jobs-01 the Scheduled Jobs page lists the four eWAY jobs once each, with their frequencies, commands and active states', async ({ adminPage }) => {
    await openJobs(adminPage);

    const stored = api4<Record<string, any>>('Job', 'get', {
      select: ['name', 'run_frequency', 'api_entity', 'api_action', 'parameters', 'is_active'],
      where: [['name', 'LIKE', 'eWay%']],
    });
    // The list shows exactly these four from the extension and no stray extra ones: anything else with "eWay" in its name
    // would be a leftover of an older version of the extension.
    expect(stored.map((job) => job.name).sort()).toEqual(EXPECTED.map((job) => job.name).sort());

    for (const job of EXPECTED) {
      // As stored.
      const record = stored.find((row) => row.name === job.name)!;
      expect(record.run_frequency, `${job.name}: frequency`).toBe(job.frequency);
      expect(record.api_entity, `${job.name}: API entity`).toBe(job.entity);
      expect(record.api_action, `${job.name}: API action`).toBe(job.action);
      expect(String(record.parameters ?? '').trim(), `${job.name}: parameters`).toBe(job.parameters);
      expect(record.is_active, `${job.name}: active`).toBe(job.enabled === 'Yes');

      // As the screen shows it. Older versions of CiviCRM print the entity and the action on separate lines, newer ones
      // as Entity.action.
      const row = jobRow(adminPage, job.name);
      await expect(row, `${job.name} is listed once`).toHaveCount(1);
      await expect(row, `${job.name}: frequency`).toContainText(job.shown);
      await expect(row, `${job.name}: command`).toContainText(new RegExp(`${job.entity}\\.${job.action}|API Entity: ${job.entity}[\\s\\S]*API Action: ${job.action}`));
      await expect(enabledCell(row), `${job.name}: enabled`).toHaveText(job.enabled);
      // Every job can be run from its row.
      if (!(await executeAction(row).isVisible())) {
        await openRowActions(row);
      }
      await expect(executeAction(row), `${job.name}: Execute action`).toBeVisible();
    }
  });

  test('PW-B-jobs-02 the eWay Settlement Sync job is listed and switched off, with an Enable choice on its row', async ({ adminPage }) => {
    await openJobs(adminPage);
    const row = jobRow(adminPage, JOB.settlement);
    await expect(row).toHaveCount(1);
    await expect(enabledCell(row)).toHaveText('No');
    await expect(row).toContainText(/Daily/);
    await expect(row).toContainText('reconciles fee_amount and net_amount');

    // Switched off means the row offers Enable.
    await openRowActions(row);
    await expect(row.getByText(/^\s*Enable\s*$/).first()).toBeVisible();

    // And the database agrees, and shows the other three on.
    const jobs = api4<{ name: string; is_active: boolean }>('Job', 'get', { select: ['name', 'is_active'], where: [['name', 'LIKE', 'eWay%']] });
    expect(Object.fromEntries(jobs.map((job) => [job.name, job.is_active]))).toEqual({
      [JOB.recurring]: true,
      [JOB.verifications]: true,
      [JOB.tokens]: true,
      [JOB.settlement]: false,
    });
  });
});

test.describe('scheduled jobs: run from the screen', () => {
  test('PW-B-jobs-03 "fill missing tokens metadata" fills in a card that has no expiry or masked number, and says so in its log', async ({ adminPage, stub }) => {
    // A card eWAY holds that this site has only a token for: no expiry date and no masked number.
    const donor = uniqueDonor('jobs03');
    const token = newCustomerToken();
    const contactId = api4<{ id: number }>('Contact', 'create', {
      values: { contact_type: 'Individual', first_name: donor.firstName, last_name: donor.lastName },
    })[0].id;
    const tokenId = api4<{ id: number }>('PaymentToken', 'create', {
      values: { contact_id: contactId, payment_processor_id: liveProcessorId(), token },
    })[0].id;
    const before = api4<Record<string, any>>('PaymentToken', 'get', {
      select: ['id', 'masked_account_number', 'expiry_date'],
      where: [['id', '=', tokenId]],
    })[0];
    expect(before.masked_account_number ?? null).toBeNull();
    expect(before.expiry_date ?? null).toBeNull();

    // How many cards the job has to fill in on this site: ours, and any other one an earlier case left without details.
    const waiting = cardsWithoutDetails();
    expect(waiting).toBeGreaterThanOrEqual(1);

    // eWAY knows the card. A card expires at the end of its month, and eWAY gives the year as two digits.
    stub.respond('getCustomer', responses.customerFound(token, 'civi-1', { Number: '512345XXXXXX2346', ExpiryMonth: '09', ExpiryYear: '32' }), { repeat: true });
    await runJobFromScreen(adminPage, JOB.tokens);

    const result = latestJobResult(JOB.tokens);
    expect(result).toContain('result: Success');
    expect(result, 'the log gives the number of cards filled in').toContain(`s:5:"count";i:${waiting};`);
    expect(stub.requests('getCustomer').map((request) => request.args[0])).toContain(token);

    const after = api4<Record<string, any>>('PaymentToken', 'get', {
      select: ['id', 'token', 'masked_account_number', 'expiry_date'],
      where: [['id', '=', tokenId]],
    })[0];
    expect(after.masked_account_number).toBe('512345XXXXXX2346');
    expect(String(after.expiry_date)).toMatch(/^2032-09-30/);
    expect(after.token).toBe(token);

    // Nothing is left to fill, so a second run asks eWAY about nothing and says so.
    stub.reset();
    await runJobFromScreen(adminPage, JOB.tokens);
    expect(latestJobResult(JOB.tokens)).toContain('s:5:"count";i:0;');
    expect(stub.requests('getCustomer')).toHaveLength(0);
  });

  test('PW-B-jobs-04 "eWay Transaction Verifications" run from the screen settles a waiting payment', async ({ adminPage, stub }) => {
    const payment = createPendingPayment({ label: 'jobs04', receipt: false });
    expect(contributionState(payment.contributionId).status).toBe('Pending');
    setOtherQueueRowsAside(payment.contributionId);
    const approved = transactionApproved(payment.amount);
    stub.respond('getTransaction', approved);

    await runJobFromScreen(adminPage, JOB.verifications);

    const result = latestJobResult(JOB.verifications);
    expect(result).toContain('result: Success');
    const after = contributionState(payment.contributionId);
    expect(after.status).toBe('Completed');
    expect(after.trxnId).toBe(String((approved.Transactions as any[])[0].TransactionID));
    expect(Number(queueRowById(payment.queueId).status)).toBe(1);
    expect(stub.requests('getTransaction').map((request) => request.args[0])).toEqual([payment.accessCode]);

    // The contribution shows as Completed on the donor's page.
    await openContributionsTab(adminPage, payment.contactId);
    await expect(contributionRow(adminPage, payment.contributionId).locator('.crm-contribution-status')).toContainText('Completed');

    // A second run has nothing waiting and does not ask eWAY again.
    await runJobFromScreen(adminPage, JOB.verifications);
    expect(stub.requests('getTransaction')).toHaveLength(1);
  });

  test.describe('recurring payments', () => {
    test.use({ allowedProblems: [SCREEN_NOISE] });

    test('PW-B-jobs-05 "eWay Recurring Payments" run from the screen charges a due recurring contribution: the new payment is on the contact and the schedule moves on', async ({ adminPage, stub }) => {
      const record = createRecurring({ label: 'jobs05', nextDue: '-1 day', amount: 15 });
      setOtherDueRecurringAside(record.recurId);
      const before = recurRow(record.recurId);
      expect(before['contribution_status_id:name']).toBe('In Progress');
      expect(contributionsOf(record.recurId)).toHaveLength(1);
      const transactionId = newTransactionId();
      stub.respond('postTransaction', responses.paymentApproved(transactionId));

      await runJobFromScreen(adminPage, JOB.recurring);

      expect(latestJobResult(JOB.recurring)).toContain('result: Success');
      const charges = stub.requests('postTransaction');
      expect(charges).toHaveLength(1);
      const sent = charges[0].args[0] as any;
      expect(sent.Customer.TokenCustomerID).toBe(record.token);
      expect(Number(sent.Payment.TotalAmount)).toBe(1500);

      const contributions = contributionsOf(record.recurId);
      expect(contributions).toHaveLength(2);
      const charged = contributions[1];
      expect(charged['contribution_status_id:name']).toBe('Completed');
      expect(String(charged.trxn_id)).toBe(String(transactionId));
      expect(charged.total_amount).toBe(15);

      // The contact's Contributions tab lists the new payment as a Completed recurring one.
      await openContributionsTab(adminPage, record.contactId);
      await expect(contributionRows(adminPage)).toHaveCount(2);
      const row = contributionRow(adminPage, charged.id);
      await expect(row.locator('.crm-contribution-amount')).toContainText('$15.00');
      await expect(row.locator('.crm-contribution-amount')).toContainText('(Recurring)');
      await expect(row.locator('.crm-contribution-status')).toContainText('Completed');

      // The Recurring Contributions tab and the record's own page show the next payment a month on.
      const after = recurRow(record.recurId);
      expect(after['contribution_status_id:name']).toBe('In Progress');
      expect(String(after.next_sched_contribution_date) > String(before.next_sched_contribution_date)).toBe(true);
      await openRecurringTab(adminPage, record.contactId);
      // The date's own text depends on the site's date format, so only that a date is shown is checked; its value is
      // checked above, in the database.
      await expect(recurringRow(adminPage, record).locator('td').nth(3)).not.toHaveText('');
      await adminPage.goto(recurringViewUrl(record));
      await expect(adminPage.locator('tr', { hasText: /^\s*Next Contribution/ }).locator('td').last()).not.toHaveText('');
    });
  });
});
