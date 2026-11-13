<?php

use Civi\Api4\Activity;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;
use CRM_eWAYRecurring_Test_FakeEwayResponses as Responses;

/**
 * The scheduled payment job: which recurring contributions are due, and what
 * happens when each is charged (CRM_eWAYRecurring_ProcessTrait).
 *
 * The gateway is faked (seam S1). Each charge needs its own TransactionID:
 * process_payment() refuses two consecutive responses with the same id.
 *
 * Test plan suite PU-F: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_RecurringProcessingTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var \CRM_eWAYRecurring_Test_FakeEwayHttp
   */
  private $http;

  /**
   * @var int
   */
  private static $transactionId = 880000000;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->http = (new FakeHttp())->install();
  }

  public function tearDown(): void {
    FakeHttp::uninstall();
    foreach (['eway_recurring_contribution_max_retry', 'eway_recurring_contribution_retry_delay', 'eway_recurring_keep_sending_receipts'] as $setting) {
      \Civi::settings()->revert($setting);
    }
    parent::tearDown();
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private static function nextTransactionId(): int {
    return ++self::$transactionId;
  }

  /**
   * Queue the next charge as approved, with a new TransactionID.
   */
  private function approveNextCharge(): int {
    $id = self::nextTransactionId();
    $this->http->respond('postTransaction', Responses::paymentApproved($id));
    return $id;
  }

  /**
   * A recurring contribution that is due, with the Completed contribution the
   * cron requires.
   *
   * @return array [processor, recur]
   */
  private function dueRecurring(array $values = [], ?array $processor = NULL): array {
    $processor = $processor ?? Fixture::processor();
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $processor, $values);
    Fixture::completedContributionFor($recur);
    return [$processor, $recur];
  }

  private function runCron(array $processor): void {
    $row = $processor;
    try {
      (new CRM_Core_Payment_eWAYRecurring('live', $row))->handlePaymentCron();
    }
    finally {
      $this->releaseJobLock();
    }
  }

  /**
   * The job takes a database lock and releases it when it finishes. While a
   * database transaction is open (as in every one of these tests) core defers
   * that release until the transaction ends, so a second run in the same test
   * would find the lock still held and exit. Release it by hand so a test can
   * run the job more than once. This only affects tests: production runs the
   * job outside a transaction.
   */
  private function releaseJobLock(): void {
    $lock = \Civi\Core\Container::singleton()->get('lockManager')->create('worker.ewayrecurring');
    $property = new ReflectionProperty(CRM_Core_Lock::class, '_id');
    $property->setAccessible(TRUE);
    CRM_Core_DAO::singleValueQuery('SELECT RELEASE_LOCK(%1)', [1 => [$property->getValue($lock), 'String']]);
  }

  /**
   * Contributions for a recurring contribution after the one seeded by dueRecurring().
   */
  private function charges(array $recur): array {
    $all = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $recur['id'],
      'options' => ['sort' => 'id ASC', 'limit' => 0],
      'sequential' => 1,
    ])['values'];
    return array_slice($all, 1);
  }

  private function contributionStatus(array $contribution): string {
    return CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $contribution['contribution_status_id']);
  }

  private function recurStatus(array $recur): string {
    return CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur['contribution_status_id']);
  }

  /**
   * The ids the normal list would charge.
   */
  private function dueIds(array $processor): array {
    $row = $processor;
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $row);
    $method = new ReflectionMethod($payment, 'get_scheduled_contributions');
    $method->setAccessible(TRUE);
    return array_map(function ($recur) {
      return (int) $recur->id;
    }, $method->invoke($payment));
  }

  /**
   * The ids the retry list would charge.
   */
  private function retryIds(array $processor): array {
    $row = $processor;
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $row);
    return array_map(function ($recur) {
      return (int) $recur->id;
    }, $payment->get_scheduled_failed_contributions());
  }

  // ---------------------------------------------------------------------------
  // Which recurring contributions are due
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-F1
   */
  public function testDueInProgressAndPendingRecurringContributionsAreSelected(): void {
    $processor = Fixture::processor();
    [, $inProgress] = $this->dueRecurring(['contribution_status_id:name' => 'In Progress'], $processor);
    [, $pending] = $this->dueRecurring(['contribution_status_id:name' => 'Pending'], $processor);
    [, $future] = $this->dueRecurring(['next_sched_contribution_date' => '+2 days'], $processor);

    $due = $this->dueIds($processor);

    $this->assertContains((int) $inProgress['id'], $due);
    $this->assertContains((int) $pending['id'], $due);
    $this->assertNotContains((int) $future['id'], $due);
  }

  /**
   * @testPlan PU-F2
   */
  public function testCancelledCompletedAndFailedRecurringContributionsAreNotSelected(): void {
    $processor = Fixture::processor();
    $ids = [];
    foreach (['Cancelled', 'Completed', 'Failed'] as $status) {
      [, $recur] = $this->dueRecurring(['contribution_status_id:name' => $status], $processor);
      $ids[$status] = (int) $recur['id'];
    }

    $this->assertSame([], array_intersect($ids, $this->dueIds($processor)));
    $this->assertSame([], array_intersect($ids, $this->retryIds($processor)));
  }

  /**
   * @testPlan PU-F3
   */
  public function testRecurringContributionInRetryStateMovesToTheRetryList(): void {
    $processor = Fixture::processor();
    [, $retrying] = $this->dueRecurring(['failure_count' => 1, 'failure_retry_date' => '-1 hour'], $processor);
    [, $notYetDue] = $this->dueRecurring(['failure_count' => 1, 'failure_retry_date' => '+2 days'], $processor);

    $this->assertNotContains((int) $retrying['id'], $this->dueIds($processor), 'A record in retry state is not on the normal list.');
    $this->assertContains((int) $retrying['id'], $this->retryIds($processor));
    $this->assertNotContains((int) $notYetDue['id'], $this->retryIds($processor), 'It waits until the retry date.');
  }

  /**
   * @testPlan PU-F4
   */
  public function testRetriesStopAtTheConfiguredMaximum(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 3);
    $processor = Fixture::processor();
    $ids = [];
    foreach ([0, 1, 2, 3] as $failures) {
      [, $recur] = $this->dueRecurring(['failure_count' => $failures, 'failure_retry_date' => '-1 hour'], $processor);
      $ids[$failures] = (int) $recur['id'];
    }

    $retry = $this->retryIds($processor);

    $this->assertNotContains($ids[0], $retry, 'No failures: nothing to retry.');
    $this->assertContains($ids[1], $retry);
    $this->assertContains($ids[2], $retry);
    $this->assertNotContains($ids[3], $retry, 'Three failures is the maximum.');
  }

  /**
   * @testPlan PU-F5
   */
  public function testRecurringContributionThatNeverCompletedIsNotCharged(): void {
    $processor = Fixture::processor();
    $recur = Fixture::recurring(Fixture::contact(), $processor);
    $retrying = Fixture::recurring(Fixture::contact(), $processor, ['failure_count' => 1, 'failure_retry_date' => '-1 hour']);

    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
    $this->assertNotContains((int) $retrying['id'], $this->retryIds($processor));
  }

  /**
   * @testPlan PU-F6
   */
  public function testAlreadyProcessedSchedulesAreNotChargedTwice(): void {
    $processor = Fixture::processor();
    [, $recur] = $this->dueRecurring([], $processor);
    [, $retrying] = $this->dueRecurring(['failure_count' => 1, 'failure_retry_date' => '-1 hour'], $processor);
    // A contribution received after the schedule date and after the retry date.
    Fixture::completedContributionFor($recur, 'now');
    Fixture::completedContributionFor($retrying, 'now');

    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
    $this->assertNotContains((int) $retrying['id'], $this->retryIds($processor));
  }

  /**
   * @testPlan PU-F7
   */
  public function testOnlyTheCurrentProcessorsRecurringContributionsAreSelected(): void {
    $mine = Fixture::processor();
    $other = Fixture::processor();
    [, $theirs] = $this->dueRecurring([], $other);
    [, $ours] = $this->dueRecurring([], $mine);

    $this->assertSame([(int) $ours['id']], array_values(array_intersect([(int) $ours['id'], (int) $theirs['id']], $this->dueIds($mine))));
  }

  // ---------------------------------------------------------------------------
  // Charging
  // ---------------------------------------------------------------------------

  public function amountProvider(): array {
    return [
      'whole dollars' => ['10.00', '1000'],
      'fifty cents' => ['10.50', '1050'],
      'under a dollar' => ['0.99', '99'],
      'thousands' => ['1234.56', '123456'],
    ];
  }

  /**
   * The database stores amounts with exactly two decimals, so the regex that
   * strips the decimal point (DEF-6) is only ever given two-decimal values.
   *
   * @dataProvider amountProvider
   * @testPlan PU-F8
   */
  public function testAmountIsSentToEwayInCents(string $amount, string $expectedCents): void {
    [$processor] = $this->dueRecurring(['amount' => $amount]);
    $this->approveNextCharge();

    $this->runCron($processor);

    $sent = $this->http->lastArgs('postTransaction')[0];
    // Compared as numbers: amounts under a dollar are sent with a leading zero ("099"), which eWAY reads as 99.
    $this->assertSame((int) $expectedCents, (int) $sent['Payment']['TotalAmount']);
    $this->assertSame('MOTO', $sent['TransactionType']);
  }

  public function scheduleProvider(): array {
    return [
      'daily' => ['day', 1],
      'every 3 days' => ['day', 3],
      'weekly' => ['week', 1],
      'fortnightly' => ['week', 2],
      'monthly' => ['month', 1],
      'quarterly' => ['month', 3],
      'yearly' => ['year', 1],
    ];
  }

  /**
   * The extension moves the schedule on from the scheduled date before it
   * charges. Then, for a Completed or Failed contribution, CiviCRM core
   * replaces that date with the payment date plus one interval if the date is
   * empty or less than 48 hours ahead (ContributionRecur::updateOnNewPayment).
   * So the extension's own date survives for weekly and longer schedules, and
   * a daily schedule always ends up one day after the payment.
   *
   * @dataProvider scheduleProvider
   * @testPlan PU-F9
   */
  public function testNextScheduleAdvancesFromTheScheduledDate(string $unit, int $interval): void {
    $scheduled = date('Y-m-d 00:00:00', strtotime('-5 days'));
    [$processor, $recur] = $this->dueRecurring([
      'frequency_unit' => $unit,
      'frequency_interval' => $interval,
      'next_sched_contribution_date' => $scheduled,
    ]);
    $this->approveNextCharge();

    $this->runCron($processor);

    $byExtension = strtotime($scheduled . " +{$interval} {$unit}s");
    $expected = $byExtension <= strtotime('+48 hours')
      ? date('Y-m-d 00:00:00', strtotime("+{$interval} {$unit}s"))
      : date('Y-m-d 00:00:00', $byExtension);
    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertSame($expected, date('Y-m-d H:i:s', strtotime($reloaded['next_sched_contribution_date'])));
  }

  /**
   * @testPlan PU-F10
   */
  public function testInstallmentsEndTheScheduleOnTheLastOne(): void {
    // One contribution already exists (the seeded one), so the second installment is the last.
    [$processor, $last] = $this->dueRecurring(['installments' => 2]);
    [, $more] = $this->dueRecurring(['installments' => 3], $processor);
    [, $unlimited] = $this->dueRecurring(['installments' => 0], $processor);
    $this->http->respond('postTransaction', Responses::paymentApproved(self::nextTransactionId()), TRUE);
    $this->http->respond('postTransaction', Responses::paymentApproved(self::nextTransactionId()));

    $this->runCron($processor);

    $lastRow = Fixture::reloadRecurring($last['id']);
    $this->assertSame('Completed', $this->recurStatus($lastRow));
    $this->assertNotEmpty($lastRow['end_date']);
    $this->assertSame('In Progress', $this->recurStatus(Fixture::reloadRecurring($more['id'])));
    $this->assertSame('In Progress', $this->recurStatus(Fixture::reloadRecurring($unlimited['id'])));
  }

  /**
   * @testPlan PU-F11
   */
  public function testSuccessfulChargeCreatesACompletedContribution(): void {
    [$processor, $recur] = $this->dueRecurring(['failure_count' => 0]);
    $seeded = civicrm_api3('Contribution', 'getsingle', ['contribution_recur_id' => $recur['id']]);
    civicrm_api3('Contribution', 'create', ['id' => $seeded['id'], 'source' => 'Spring appeal']);
    $transactionId = $this->approveNextCharge();

    $this->runCron($processor);

    $charges = $this->charges($recur);
    $this->assertCount(1, $charges);
    $charge = Fixture::reloadContribution($charges[0]['id']);
    $this->assertSame('Completed', $this->contributionStatus($charge));
    $this->assertSame((string) $transactionId, (string) $charge['trxn_id']);
    $this->assertEquals(10, $charge['total_amount']);
    // A successful charge keeps the source copied from the first contribution; the "eWay Recurring" prefix is only added when the charge fails (see PU-F13).
    $this->assertSame('Spring appeal', $charge['contribution_source']);
    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(0, $reloaded['failure_count']);
    $this->assertEmpty($reloaded['failure_retry_date']);
  }

  /**
   * @testPlan PU-F12
   */
  public function testReceiptIsOnlySentWhenTheSettingIsOn(): void {
    $sent = [];
    $listener = function ($event) use (&$sent) {
      if ($event->getApiRequestSig() === '3.contribution.completetransaction') {
        $sent[] = (int) $event->getApiRequest()['params']['is_email_receipt'];
      }
    };
    \Civi::dispatcher()->addListener('civi.api.prepare', $listener);

    try {
      foreach ([0, 1] as $setting) {
        \Civi::settings()->set('eway_recurring_keep_sending_receipts', $setting);
        [$processor] = $this->dueRecurring();
        $this->approveNextCharge();
        $this->runCron($processor);
      }
    }
    finally {
      \Civi::dispatcher()->removeListener('civi.api.prepare', $listener);
    }

    $this->assertSame([0, 1], $sent);
  }

  /**
   * The failure is recorded on the recurring contribution and the contribution
   * gets a note. The "eWay Transaction Failed" activity is not created (DEF-10):
   * the job asks for it by its label, but CiviCRM only accepts the option's
   * name (eWay_Transaction_Failed), so Activity.create is rejected and the
   * extension logs and carries on. If that is fixed, expect one activity.
   *
   * @testPlan PU-F13
   */
  public function testDeclinedChargeRecordsTheFailureAndSchedulesARetry(): void {
    \Civi::settings()->set('eway_recurring_contribution_retry_delay', 4);
    [$processor, $recur] = $this->dueRecurring();
    $seeded = civicrm_api3('Contribution', 'getsingle', ['contribution_recur_id' => $recur['id']]);
    civicrm_api3('Contribution', 'create', ['id' => $seeded['id'], 'source' => 'Spring appeal']);
    $transactionId = self::nextTransactionId();
    $this->http->respond('postTransaction', Responses::paymentDeclined('D4401', $transactionId));

    $this->runCron($processor);

    $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
    $this->assertSame('Failed', $this->contributionStatus($charge));
    $this->assertSame("eWay Recurring Donation:\nSpring appeal", $charge['contribution_source']);
    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(1, $reloaded['failure_count']);
    $expectedRetry = (new DateTime('+4 days'))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $this->assertSame($expectedRetry, date('Y-m-d H:i:s', strtotime($reloaded['failure_retry_date'])));

    $note = civicrm_api3('Note', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $charge['id']]);
    $this->assertSame('Transaction Error', $note['subject']);
    $this->assertStringContainsString(\Eway\Rapid::getMessage('D4401'), $note['note']);

    $activities = Activity::get(FALSE)
      ->addWhere('activity_type_id:label', '=', 'eWay Transaction Failed')
      ->addWhere('source_contact_id', '=', $recur['contact_id'])
      ->execute();
    $this->assertCount(0, $activities, 'DEF-10: the activity is never created.');
  }

  /**
   * Characterisation (DEF-3): once retries run out nothing moves the recurring
   * contribution to Failed or marks it for attention. It stays In Progress,
   * is not selected again, and is never charged. If a status change is added,
   * update this test.
   *
   * @testPlan PU-F14
   */
  public function testRecurringContributionStaysInProgressOnceRetriesAreExhausted(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 3);
    [$processor, $recur] = $this->dueRecurring(['failure_count' => 3, 'failure_retry_date' => '-1 hour']);

    $this->runCron($processor);

    $this->assertSame([], $this->http->requests, 'It is not charged again.');
    $this->assertSame([], $this->charges($recur));
    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->recurStatus($reloaded));
    $this->assertEquals(3, $reloaded['failure_count']);
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
  }

  /**
   * Characterisation (DEF-3, DEF-10): the code that restores a recurring
   * contribution and records a "Transaction Succeeded after N retries" activity
   * only runs for a recurring contribution whose status is Failed, but the
   * retry list only selects ones that are In Progress. So after a successful
   * retry the failure count is cleared, but no success activity is recorded
   * (and the activity could not be created anyway, see PU-F13).
   *
   * @testPlan PU-F15
   */
  public function testSuccessfulRetryClearsTheFailuresButRecordsNoSuccessActivity(): void {
    [$processor, $recur] = $this->dueRecurring(['failure_count' => 2, 'failure_retry_date' => '-1 hour']);
    $this->approveNextCharge();

    $this->runCron($processor);

    $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
    $this->assertSame('Completed', $this->contributionStatus($charge));
    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(0, $reloaded['failure_count']);
    $this->assertEmpty($reloaded['failure_retry_date']);
    $this->assertSame('In Progress', $this->recurStatus($reloaded));
    $this->assertCount(0, Activity::get(FALSE)
      ->addWhere('activity_type_id:label', '=', 'eWay Transaction Succeeded')
      ->addWhere('source_contact_id', '=', $recur['contact_id'])
      ->execute());
  }

  /**
   * @testPlan PU-F16
   */
  public function testFailureAfterEwayRepliedLeavesThePaymentPendingAndQueued(): void {
    [$processor, $recur] = $this->dueRecurring();
    $transactionId = $this->approveNextCharge();
    // Something goes wrong in CiviCRM after eWAY has taken the payment.
    $listener = function ($event) {
      if ($event->getApiRequestSig() === '3.contribution.completetransaction') {
        throw new CRM_Core_Exception('Simulated failure after the gateway replied');
      }
    };
    \Civi::dispatcher()->addListener('civi.api.prepare', $listener);

    try {
      $this->runCron($processor);
    }
    finally {
      \Civi::dispatcher()->removeListener('civi.api.prepare', $listener);
    }

    $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
    $this->assertSame('Pending', $this->contributionStatus($charge));
    $queued = civicrm_api3('EwayContributionTransactions', 'getsingle', ['contribution_id' => $charge['id']]);
    $this->assertSame((string) $transactionId, (string) $queued['access_code']);
    $this->assertEquals($processor['id'], $queued['payment_processor_id']);
    $note = civicrm_api3('Note', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $charge['id']]);
    $this->assertSame('Contribution Error', $note['subject']);
    $this->assertSame(0, Fixture::reloadRecurring($recur['id'])['failure_count'] ?? 0, 'The schedule is not penalised while the payment is unconfirmed.');
  }

  /**
   * @testPlan PU-F17
   * @testPlan PU-F18
   */
  public function testMissingTokenFailsTheChargeBeforeEwayIsCalled(): void {
    [$processor, $recur] = $this->dueRecurring(['token' => FALSE, 'processor_id' => '']);

    $this->runCron($processor);

    $this->assertSame([], $this->http->requests);
    $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
    $this->assertSame('Failed', $this->contributionStatus($charge));
    $this->assertEquals(1, Fixture::reloadRecurring($recur['id'])['failure_count']);
    $note = civicrm_api3('Note', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $charge['id']]);
    $this->assertSame('Contribution Error', $note['subject']);
    $this->assertStringContainsString('No eWAY token found for Recurring Contribution ' . $recur['id'], $note['note']);
  }

  /**
   * @testPlan PU-F18
   */
  public function testThePaymentTokenIsUsedAndTheLegacyProcessorIdIsTheFallback(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'CARDTOKEN-0001']);
    $withToken = Fixture::recurring($contactId, $processor, ['token' => $token, 'processor_id' => 'LEGACY-IGNORED']);
    Fixture::completedContributionFor($withToken);
    $this->approveNextCharge();
    $this->runCron($processor);
    $this->assertSame('CARDTOKEN-0001', $this->http->lastArgs('postTransaction')[0]['Customer']['TokenCustomerID']);

    [$processor2] = $this->dueRecurring(['token' => FALSE, 'processor_id' => 'LEGACY-0001']);
    $this->approveNextCharge();
    $this->runCron($processor2);
    $this->assertSame('LEGACY-0001', $this->http->lastArgs('postTransaction')[0]['Customer']['TokenCustomerID']);
  }

  /**
   * @testPlan PU-F19
   */
  public function testSecondRunExitsWhenAnotherRunIsInProgress(): void {
    [$processor] = $this->dueRecurring();
    $lock = \Civi\Core\Container::singleton()->get('lockManager')->create('worker.ewayrecurring');
    $this->assertTrue($lock->acquire());

    try {
      $row = $processor;
      $result = (new CRM_Core_Payment_eWAYRecurring('live', $row))->process_recurring_payments();
    }
    finally {
      $this->releaseJobLock();
    }

    $this->assertFalse($result);
    $this->assertSame([], $this->http->requests);
  }

  /**
   * Characterisation (DEF-11): process_payment() is meant to reject two
   * consecutive responses with the same TransactionID, but it remembers the
   * previous response with `static $prev_response; $prev_response = &$local;`.
   * PHP does not keep a reference assigned to a static variable between calls,
   * so the guard never sees a previous response and never fires. The second
   * charge is stopped only later, when CiviCRM refuses a second contribution
   * with the same transaction id, so it ends up Pending with a queue row.
   *
   * @testPlan PU-F20
   */
  public function testTheSameTransactionIdTwiceInOneRunIsNotCaughtByTheDuplicateGuard(): void {
    $processor = Fixture::processor();
    [, $first] = $this->dueRecurring([], $processor);
    [, $second] = $this->dueRecurring([], $processor);
    $duplicated = self::nextTransactionId();
    $this->http->respond('postTransaction', Responses::paymentApproved($duplicated));
    $this->http->respond('postTransaction', Responses::paymentApproved($duplicated));

    $this->runCron($processor);

    $statuses = [];
    $notes = [];
    foreach ([$first, $second] as $recur) {
      $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
      $statuses[] = $this->contributionStatus($charge);
      $notes = array_merge($notes, array_column(civicrm_api3('Note', 'get', ['entity_table' => 'civicrm_contribution', 'entity_id' => $charge['id'], 'sequential' => 1])['values'], 'note'));
    }
    sort($statuses);
    $this->assertSame(['Completed', 'Pending'], $statuses);
    $this->assertCount(1, $notes);
    $this->assertStringNotContainsString('duplicate transaction number', $notes[0]);
    $this->assertStringContainsString('Duplicate error', $notes[0], 'It is CiviCRM, not the extension, that refuses the duplicate.');
  }

  /**
   * @testPlan PU-F21
   */
  public function testNonAsciiTextIsSanitisedBeforeItIsSentToEway(): void {
    [$processor, $recur] = $this->dueRecurring();
    $seeded = civicrm_api3('Contribution', 'getsingle', ['contribution_recur_id' => $recur['id']]);
    civicrm_api3('Contribution', 'create', ['id' => $seeded['id'], 'source' => "Caf\u{e9} \u{2013} \u{1F600} appeal"]);
    $this->approveNextCharge();

    $this->runCron($processor);

    $sent = $this->http->lastArgs('postTransaction')[0]['Payment'];
    $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $sent['InvoiceDescription']);
    $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $sent['InvoiceReference']);
    $this->assertStringContainsString('Cafe', $sent['InvoiceDescription']);
  }

  /**
   * Characterisation (DEF-10): when the contact has no address at all the job
   * reads $billing_address['id'] on null. PHP raises "Trying to access array
   * offset on null" and carries on, so the payment is still taken. The test
   * records the warning instead of letting PHPUnit turn it into an exception,
   * which is what production does.
   *
   * @testPlan PU-F24
   */
  public function testContactWithoutAnAddressIsStillChargedButRaisesAWarning(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact('No', 'Address', FALSE);
    $recur = Fixture::recurring($contactId, $processor);
    Fixture::completedContributionFor($recur);
    $transactionId = $this->approveNextCharge();

    $warnings = [];
    set_error_handler(function ($errno, $message) use (&$warnings) {
      $warnings[] = $message;
      return TRUE;
    }, E_WARNING);
    try {
      $this->runCron($processor);
    }
    finally {
      restore_error_handler();
    }

    $this->assertContains('Trying to access array offset on null', $warnings);
    $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
    $this->assertSame('Completed', $this->contributionStatus($charge));
    $this->assertSame((string) $transactionId, (string) $charge['trxn_id']);
  }

  /**
   * @testPlan PU-F22
   */
  public function testTheScheduledJobApiRunsTheProcessor(): void {
    // The core job runs the live and then the test instances of the processor type, and fails if either is missing.
    Fixture::processor(TRUE);
    [, $recur] = $this->dueRecurring();
    $this->approveNextCharge();

    civicrm_api3('Job', 'run_payment_cron', ['processor_name' => 'eWay_Recurring']);

    $charges = $this->charges($recur);
    $this->assertCount(1, $charges);
    $this->assertSame('Completed', $this->contributionStatus(Fixture::reloadContribution($charges[0]['id'])));
  }

  /**
   * @testPlan PU-F23
   */
  public function testMarkingFailedStillUpdatesTheScheduleWhenTheActivityCannotBeCreated(): void {
    [$processor, $recur] = $this->dueRecurring(['failure_count' => 1]);
    $listener = function ($event) {
      if ($event->getApiRequestSig() === '3.activity.create') {
        throw new CRM_Core_Exception('Simulated activity failure');
      }
    };
    \Civi::dispatcher()->addListener('civi.api.prepare', $listener);

    try {
      $bao = new CRM_Contribute_BAO_ContributionRecur();
      $bao->id = $recur['id'];
      $bao->find(TRUE);
      $row = $processor;
      (new CRM_Core_Payment_eWAYRecurring('live', $row))->mark_recurring_contribution_failed($bao);
    }
    finally {
      \Civi::dispatcher()->removeListener('civi.api.prepare', $listener);
    }

    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(2, $reloaded['failure_count']);
    $this->assertNotEmpty($reloaded['failure_retry_date']);
  }

}
