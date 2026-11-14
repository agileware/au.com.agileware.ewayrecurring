import { test, expect } from '../../fixtures/base';
import { responses } from '../../fixtures/eway-stub';
import {
  newTransactionId,
  runVerificationJob,
  setOtherQueueRowsAside,
  transactionApproved,
  transactionNotProcessedYet,
} from '../../fixtures/contribution-page';
import {
  contributionState,
  createPendingPayment,
  mailSubjectsFor,
  queueRowById,
} from '../../fixtures/queue-records';

/**
 * PW-F5: the verification queue. A donor who reaches eWAY's hosted page and never comes back leaves a Pending
 * contribution and a queue row; the scheduled job "eWay Transaction Verifications" asks eWAY what became of each
 * waiting row and settles the contribution. PW-F1-08 follows a real donor to the hosted page and checks the first
 * case through the browser; these cases start from the abandoned state (a Pending contribution and its queue row,
 * made in the database) and check each way the job can settle it. Test plan: developer/TEST_PLAN.md section 5.1.
 *
 * The job asks eWAY about every row that is still waiting, and the stub answers by method and order, not by access
 * code, so each test puts the rows other tests left behind aside first (setOtherQueueRowsAside) and gives the job
 * exactly the answers its own row needs.
 *
 * Queue row status: 0 waiting, 1 success, 2 failed.
 */
test.describe('verification queue', () => {
  test('PW-F5-01 an abandoned payment that eWAY approved is completed by the job, and the donor\'s receipt is queued', async ({ stub }) => {
    const payment = createPendingPayment({ label: 'f501', receipt: true });
    expect(contributionState(payment.contributionId).status).toBe('Pending');
    const waiting = queueRowById(payment.queueId);
    expect(Number(waiting.status)).toBe(0);
    expect(Number(waiting.tries)).toBe(0);
    expect(waiting.access_code).toBe(payment.accessCode);
    expect(mailSubjectsFor(payment.donor.email)).toEqual([]);

    setOtherQueueRowsAside(payment.contributionId);
    const approved = transactionApproved(payment.amount);
    stub.respond('getTransaction', approved);

    const counts = runVerificationJob();
    expect(counts).toMatchObject({ success: 1, failed: 0, not_processed: 0, deleted: 0 });
    expect(stub.requests('getTransaction').map((r) => r.args[0])).toEqual([payment.accessCode]);

    const after = contributionState(payment.contributionId);
    expect(after.status).toBe('Completed');
    expect(after.trxnId).toBe(String((approved.Transactions as any[])[0].TransactionID));
    expect(after.total).toBe(payment.amount);
    const done = queueRowById(payment.queueId);
    expect(Number(done.status)).toBe(1);
    expect(Number(done.tries)).toBe(1);

    // The queue row said the donor wants a receipt, so one was sent. Outbound mail goes to the database on this site.
    const subjects = mailSubjectsFor(payment.donor.email);
    expect(subjects, `mail for ${payment.donor.email}`).toHaveLength(1);
    expect(after.receiptDate, 'the receipt date is set').toBeTruthy();

    // A second run has nothing to do and does not ask eWAY again.
    expect(runVerificationJob()).toMatchObject({ success: 0, failed: 0, not_processed: 0 });
    expect(stub.requests('getTransaction')).toHaveLength(1);
    expect(mailSubjectsFor(payment.donor.email)).toHaveLength(1);
  });

  test('PW-F5-01 a donor who asked for no receipt gets none when the job completes the payment', async ({ stub }) => {
    const payment = createPendingPayment({ label: 'f501n', receipt: false });
    setOtherQueueRowsAside(payment.contributionId);
    stub.respond('getTransaction', transactionApproved(payment.amount));

    expect(runVerificationJob()).toMatchObject({ success: 1, failed: 0 });

    const after = contributionState(payment.contributionId);
    expect(after.status).toBe('Completed');
    expect(after.receiptDate).toBeFalsy();
    expect(mailSubjectsFor(payment.donor.email)).toEqual([]);
  });

  test('PW-F5-02 an abandoned payment that eWAY declined becomes Failed, with the reason kept on the queue row', async ({ stub }) => {
    const payment = createPendingPayment({ label: 'f502', receipt: true });
    setOtherQueueRowsAside(payment.contributionId);
    const transactionId = newTransactionId();
    stub.respond('getTransaction', responses.transactionFound({
      TransactionID: transactionId,
      TransactionStatus: false,
      ResponseCode: '01',
      ResponseMessage: 'D4401',
      TokenCustomerID: '',
    }));

    const counts = runVerificationJob();
    expect(counts).toMatchObject({ success: 0, failed: 1, not_processed: 0, deleted: 0 });

    const after = contributionState(payment.contributionId);
    expect(after.status).toBe('Failed');
    expect(after.trxnId).toBe(String(transactionId));
    const row = queueRowById(payment.queueId);
    expect(Number(row.status)).toBe(2);
    expect(Number(row.tries)).toBe(1);
    expect(row.failed_message).toMatch(/declined/i);
    // A declined payment gets no receipt, whatever the donor asked for.
    expect(mailSubjectsFor(payment.donor.email)).toEqual([]);

    // The row is finished: the next run does not ask eWAY about it.
    expect(runVerificationJob()).toMatchObject({ success: 0, failed: 0, not_processed: 0 });
    expect(stub.requests('getTransaction')).toHaveLength(1);
  });

  test('PW-F5-03 a payment eWAY is still processing stays Pending and is asked about again on the next run', async ({ stub }) => {
    const payment = createPendingPayment({ label: 'f503' });
    setOtherQueueRowsAside(payment.contributionId);
    // eWAY has no result yet, however many times it is asked.
    stub.respond('getTransaction', transactionNotProcessedYet(), { repeat: true });

    const first = runVerificationJob();
    expect(first).toMatchObject({ success: 0, failed: 0, not_processed: 1, deleted: 0 });
    expect(contributionState(payment.contributionId).status).toBe('Pending');
    let row = queueRowById(payment.queueId);
    expect(Number(row.status)).toBe(0);
    expect(Number(row.tries)).toBe(1);

    const second = runVerificationJob();
    expect(second).toMatchObject({ success: 0, failed: 0, not_processed: 1 });
    expect(contributionState(payment.contributionId).status).toBe('Pending');
    row = queueRowById(payment.queueId);
    expect(Number(row.status)).toBe(0);
    expect(Number(row.tries)).toBe(2);
    expect(stub.requests('getTransaction').map((r) => r.args[0])).toEqual([payment.accessCode, payment.accessCode]);

    // eWAY finishes the payment: the third run settles it.
    stub.reset();
    stub.respond('getTransaction', transactionApproved(payment.amount));
    expect(runVerificationJob()).toMatchObject({ success: 1, not_processed: 0 });
    expect(contributionState(payment.contributionId).status).toBe('Completed');
    row = queueRowById(payment.queueId);
    expect(Number(row.status)).toBe(1);
    // Every run that asked eWAY counts as a try, including the one that settled it.
    expect(Number(row.tries)).toBe(3);
  });

  test('PW-F5-04 after seven tries with no answer the contribution is marked Failed and the row is closed', async ({ stub }) => {
    const payment = createPendingPayment({ label: 'f504' });
    setOtherQueueRowsAside(payment.contributionId);
    stub.respond('getTransaction', transactionNotProcessedYet(), { repeat: true });

    // Six runs: still waiting, one more try left.
    for (let run = 1; run <= 6; run++) {
      expect(runVerificationJob(), `run ${run}`).toMatchObject({ not_processed: 1, failed: 0 });
      expect(contributionState(payment.contributionId).status, `after run ${run}`).toBe('Pending');
      expect(Number(queueRowById(payment.queueId).tries), `tries after run ${run}`).toBe(run);
    }
    expect(Number(queueRowById(payment.queueId).status)).toBe(0);

    // The seventh run asks once more, gets nothing, and gives up.
    expect(runVerificationJob()).toMatchObject({ not_processed: 1, success: 0 });
    expect(contributionState(payment.contributionId).status).toBe('Failed');
    const row = queueRowById(payment.queueId);
    expect(Number(row.status)).toBe(2);
    expect(Number(row.tries)).toBe(7);
    expect(stub.requests('getTransaction')).toHaveLength(7);

    // Nothing more happens: the closed row is not asked about again.
    expect(runVerificationJob()).toMatchObject({ success: 0, failed: 0, not_processed: 0 });
    expect(stub.requests('getTransaction')).toHaveLength(7);
    expect(contributionState(payment.contributionId).status).toBe('Failed');
  });
});
