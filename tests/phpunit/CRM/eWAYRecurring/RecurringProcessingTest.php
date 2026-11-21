<?php

use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
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

  use CRM_eWAYRecurring_Test_LogCapture;

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
    $this->releaseLog();
    unset($_REQUEST['crid'], $_REQUEST['contact_payment_token']);
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
    // The seeded contribution is two months old, or older than a due date further back than that: a contribution
    // received on or after the due date counts as the payment having been made.
    $receiveDate = '-2 months';
    if (!empty($values['next_sched_contribution_date'])) {
      $receiveDate = date('Y-m-d H:i:s', min(strtotime('-2 months'), strtotime($values['next_sched_contribution_date'] . ' -1 month')));
    }
    Fixture::completedContributionFor($recur, $receiveDate);
    if (!empty($values['next_sched_contribution_date'])) {
      // Recording the Completed contribution makes core move a date that is under 48 hours after the contribution
      // (two months ago), so an old due date has to be put back to be the one the test asked for.
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recur['id'])
        ->addValue('next_sched_contribution_date', date('Y-m-d H:i:s', strtotime($values['next_sched_contribution_date'])))
        ->execute();
    }
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

  /**
   * The schedule date as stored, Y-m-d H:i:s.
   */
  private function scheduleOf(array $recur): string {
    return date('Y-m-d H:i:s', strtotime(Fixture::reloadRecurring($recur['id'])['next_sched_contribution_date']));
  }

  /**
   * What the schedule should be after a successful charge, worked out from the rule rather than from the code under
   * test: the first of due + 1 interval, due + 2 intervals and so on that is after today (midnight).
   */
  private function firstDateAfterToday(string $due, int $interval, string $unit): string {
    $today = strtotime('today');
    for ($steps = 1; $steps < 10000; $steps++) {
      $candidate = strtotime(date('Y-m-d', strtotime($due)) . ' +' . ($steps * $interval) . " {$unit}s");
      if ($candidate > $today) {
        return date('Y-m-d 00:00:00', $candidate);
      }
    }
    $this->fail('No date found');
  }

  private function declineNextCharge(): void {
    $this->http->respond('postTransaction', Responses::paymentDeclined('D4401', self::nextTransactionId()));
  }

  /**
   * Make the retry date of a record that failed come due.
   */
  private function makeRetryDue(array $recur): void {
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addValue('failure_retry_date', date('Y-m-d H:i:s', strtotime('-1 hour')))
      ->execute();
  }

  /**
   * Use up the retries of a record, as the cron does: a decline, then a decline of each retry, until it is Failed.
   */
  private function runUntilFailed(array $processor, array $recur, int $maxRetries): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', $maxRetries);
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
      $this->declineNextCharge();
      $this->runCron($processor);
      if ($attempt < $maxRetries) {
        $this->makeRetryDue($recur);
      }
    }
  }

  /**
   * Replace the card the way the Update Billing Details form does (DEF-13): it recovers a Failed record.
   */
  private function updateTheCard(array $processor, array $recur, string $tokenValue): void {
    $token = Fixture::token($recur['contact_id'], $processor['id'], ['token' => $tokenValue]);
    $_REQUEST['crid'] = $recur['id'];
    $_REQUEST['contact_payment_token'] = $token['id'];
    $params = [
      'first_name' => 'Test',
      'last_name' => 'Donor',
      'street_address' => '99 New Street',
      'city' => 'Sydney',
      'postal_code' => '2000',
      'country_id' => 1013,
      'country' => 'AU',
    ];
    try {
      $row = $processor;
      $message = '';
      $this->assertTrue((new CRM_Core_Payment_eWAYRecurring('live', $row))->updateSubscriptionBillingInfo($message, $params));
    }
    finally {
      unset($_REQUEST['crid'], $_REQUEST['contact_payment_token']);
    }
  }

  /**
   * What the Reactivate action of the Failed Recurring Contributions list sends for each selected record.
   */
  private function reactivate(array $recur): void {
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->setValues(['contribution_status_id:name' => 'In Progress', 'failure_count' => 0, 'failure_retry_date' => ''])
      ->execute();
  }

  /**
   * The contributions of a record other than the seeded one, as [status name => count].
   */
  private function chargeStatuses(array $recur): array {
    $counts = [];
    foreach ($this->charges($recur) as $charge) {
      $name = $this->contributionStatus($charge);
      $counts[$name] = ($counts[$name] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
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
   * A recurring contribution that is cancelled while it is being retried must
   * not be retried (CIVIEWAY-125: a failed transaction kept retrying after the
   * contribution had been cancelled). It is the realistic case: it has
   * failures and a retry date that is due. A record still being retried is
   * charged in the same run, which shows the cancelled one was skipped on
   * purpose rather than because nothing was due.
   *
   * @testPlan PU-F27
   */
  public function testARecurringContributionCancelledWhileBeingRetriedIsNotRetried(): void {
    $processor = Fixture::processor();
    [, $cancelled] = $this->dueRecurring(['contribution_status_id:name' => 'Cancelled', 'failure_count' => 2, 'failure_retry_date' => '-1 hour'], $processor);
    [, $stillRetrying] = $this->dueRecurring(['failure_count' => 1, 'failure_retry_date' => '-1 hour'], $processor);
    $this->approveNextCharge();

    $this->assertSame([(int) $stillRetrying['id']], array_values(array_intersect([(int) $cancelled['id'], (int) $stillRetrying['id']], $this->retryIds($processor))));

    $this->runCron($processor);

    $this->assertCount(1, $this->http->callsTo('postTransaction'), 'Only the record still being retried is charged.');
    $this->assertSame([], $this->charges($cancelled));
    $this->assertCount(1, $this->charges($stillRetrying));
    $after = Fixture::reloadRecurring($cancelled['id']);
    $this->assertSame('Cancelled', $this->recurStatus($after));
    $this->assertEquals(2, $after['failure_count'], 'The cancelled record is not touched.');
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
   * The amount is sent as whole cents. Amounts under a dollar used to be sent
   * with a leading zero ("099"), because the decimal point was stripped from
   * the string (DEF-6).
   *
   * @dataProvider amountProvider
   * @testPlan PU-F8
   */
  public function testAmountIsSentToEwayInCents(string $amount, string $expectedCents): void {
    [$processor] = $this->dueRecurring(['amount' => $amount]);
    $this->approveNextCharge();

    $this->runCron($processor);

    $sent = $this->http->lastArgs('postTransaction')[0];
    $this->assertSame($expectedCents, (string) $sent['Payment']['TotalAmount']);
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
   * The schedule moves on after a successful charge: one interval from the date that was due (not from the payment
   * date), stepping on by further intervals until the date is after today, so a record that is several intervals
   * overdue is charged once and then waits for the first date in the future. CiviCRM core also moves the date when
   * it records the payment (to the payment date plus one interval when the date is under 48 hours ahead,
   * ContributionRecur::updateOnNewPayment), so the extension sets its own date last. A daily record therefore
   * ends tomorrow, not one day after whatever core chose.
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

    $this->assertSame($this->firstDateAfterToday($scheduled, $interval, $unit), $this->scheduleOf($recur));
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
   * The failure is recorded on the recurring contribution, the contribution
   * gets a note, and an "eWay Transaction Failed" activity is recorded on the
   * contact. The activity used to be silently skipped because the job asked for
   * it by its label, which CiviCRM rejects: it only accepts the option's name
   * (eWay_Transaction_Failed) (DEF-10).
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
    $this->assertSame('In Progress', $this->recurStatus($reloaded), 'Retries remain, so it is not marked Failed yet.');
    $expectedRetry = (new DateTime('+4 days'))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $this->assertSame($expectedRetry, date('Y-m-d H:i:s', strtotime($reloaded['failure_retry_date'])));

    $note = civicrm_api3('Note', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $charge['id']]);
    $this->assertSame('Transaction Error', $note['subject']);
    $this->assertStringContainsString(\Eway\Rapid::getMessage('D4401'), $note['note']);

    $activities = Activity::get(FALSE)
      ->addWhere('activity_type_id:label', '=', 'eWay Transaction Failed')
      ->addWhere('source_contact_id', '=', $recur['contact_id'])
      ->execute();
    $this->assertCount(1, $activities);
  }

  /**
   * A declined charge whose answer carries no ResponseMessage (null) must not raise a PHP deprecation: the job
   * split the message with explode(), and PHP 8.1 deprecates passing null to it (DEF-17).
   *
   * @testPlan PU-F28
   */
  public function testDeclinedChargeWithNoResponseMessageRaisesNoDeprecation(): void {
    [$processor, $recur] = $this->dueRecurring();
    $declined = ['ResponseMessage' => NULL] + Responses::paymentDeclined('D4401', self::nextTransactionId());
    $this->http->respond('postTransaction', $declined);

    $deprecations = [];
    set_error_handler(function ($level, $message) use (&$deprecations) {
      if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
        $deprecations[] = $message;
      }
      return TRUE;
    });
    try {
      $this->runCron($processor);
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $deprecations);
    $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
    $this->assertSame('Failed', $this->contributionStatus($charge));
  }

  /**
   * When the last permitted retry fails, the recurring contribution is marked
   * Failed (DEF-3). Nothing charges it again, it appears in the Failed Recurring
   * Contributions list and the system status check, and Reactivate resets it.
   *
   * @testPlan PU-F14
   */
  public function testRecurringContributionIsMarkedFailedWhenTheLastRetryFails(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 3);
    [$processor, $recur] = $this->dueRecurring(['failure_count' => 2, 'failure_retry_date' => '-1 hour']);
    $this->http->respond('postTransaction', Responses::paymentDeclined('D4401', self::nextTransactionId()));

    $this->runCron($processor);

    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(3, $reloaded['failure_count']);
    $this->assertSame('Failed', $this->recurStatus($reloaded));
    $this->assertNotEmpty($reloaded['failure_retry_date'], 'The retry date stays, so the status check can tell how long it has been stuck.');
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
  }

  /**
   * A failure that leaves retries available keeps the recurring contribution
   * In Progress, which is what the retry list selects.
   *
   * @testPlan PU-F14
   */
  public function testFailureBeforeTheLastRetryKeepsTheRecurringContributionInProgress(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 3);
    [$processor, $recur] = $this->dueRecurring(['failure_count' => 1, 'failure_retry_date' => '-1 hour']);
    $this->http->respond('postTransaction', Responses::paymentDeclined('D4401', self::nextTransactionId()));

    $this->runCron($processor);

    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(2, $reloaded['failure_count']);
    $this->assertSame('In Progress', $this->recurStatus($reloaded));
  }

  /**
   * A record already at the maximum (for example from before this was fixed) is
   * not charged again.
   *
   * @testPlan PU-F14
   */
  public function testRecurringContributionAlreadyAtTheMaximumIsNotChargedAgain(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 3);
    [$processor, $recur] = $this->dueRecurring(['failure_count' => 3, 'failure_retry_date' => '-1 hour']);

    $this->runCron($processor);

    $this->assertSame([], $this->http->requests, 'It is not charged again.');
    $this->assertSame([], $this->charges($recur));
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
  }

  /**
   * A successful retry clears the failures, leaves the recurring contribution
   * In Progress and records the "Transaction Succeeded" activity. That code
   * used to need the recurring contribution to be Failed, but a record that is
   * being retried is In Progress, so it never ran (DEF-3).
   *
   * @testPlan PU-F15
   */
  public function testSuccessfulRetryClearsTheFailuresAndRecordsASuccessActivity(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-1 day'));
    [$processor, $recur] = $this->dueRecurring(['failure_count' => 2, 'failure_retry_date' => '-1 hour', 'next_sched_contribution_date' => $due]);
    $this->approveNextCharge();

    $this->runCron($processor);

    $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
    $this->assertSame('Completed', $this->contributionStatus($charge));
    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(0, $reloaded['failure_count']);
    $this->assertEmpty($reloaded['failure_retry_date']);
    $this->assertSame('In Progress', $this->recurStatus($reloaded));
    $this->assertSame(date('Y-m-d 00:00:00', strtotime($due . ' +1 month')), $this->scheduleOf($recur), 'One interval after the date that was due (DEF-58).');
    $activities = Activity::get(FALSE)
      ->addSelect('details')
      ->addWhere('activity_type_id:label', '=', 'eWay Transaction Succeeded')
      ->addWhere('source_contact_id', '=', $recur['contact_id'])
      ->execute();
    $this->assertCount(1, $activities);
    $this->assertSame('Transaction Succeeded after 2 retries', $activities->first()['details']);
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
   * process_payment() rejects two consecutive responses with the same
   * TransactionID. The guard used to remember the previous response with
   * `static $prev_response; $prev_response = &$local;`, which PHP does not keep
   * between calls, so it never fired and the duplicate was only stopped later,
   * by CiviCRM's own duplicate check, leaving the second charge Pending (DEF-11).
   *
   * @testPlan PU-F20
   */
  public function testTheSameTransactionIdTwiceInOneRunIsRejected(): void {
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
    $this->assertSame(['Completed', 'Failed'], $statuses);
    $this->assertCount(1, $notes);
    $this->assertStringContainsString('duplicate transaction number: ' . $duplicated, $notes[0]);
  }

  /**
   * A request eWAY refuses (an error response) has no TransactionID, so two
   * refused charges in a row must not be mistaken for a duplicate.
   *
   * @testPlan PU-F20
   */
  public function testConsecutiveRefusedChargesAreNotMistakenForADuplicate(): void {
    $processor = Fixture::processor();
    [, $first] = $this->dueRecurring([], $processor);
    [, $second] = $this->dueRecurring([], $processor);
    $this->http->respond('postTransaction', Responses::errors('V6010'));
    $this->http->respond('postTransaction', Responses::errors('V6010'));

    $this->runCron($processor);

    foreach ([$first, $second] as $recur) {
      $charge = Fixture::reloadContribution($this->charges($recur)[0]['id']);
      $this->assertSame('Failed', $this->contributionStatus($charge));
      $note = civicrm_api3('Note', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $charge['id']]);
      $this->assertSame('Transaction Error', $note['subject'], 'It is a declined payment, not a duplicate.');
    }
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
   * A contact with no address at all is still charged, without PHP warnings.
   * The job used to read `$billing_address['id']` on null (DEF-12).
   *
   * @testPlan PU-F24
   */
  public function testContactWithoutAnAddressIsChargedWithoutWarnings(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact('No', 'Address', FALSE);
    $recur = Fixture::recurring($contactId, $processor);
    Fixture::completedContributionFor($recur);
    $transactionId = $this->approveNextCharge();

    // PHPUnit turns a PHP warning into an exception, which the job would catch and
    // record as a failed charge, so a clean Completed contribution means no warning.
    $this->runCron($processor);

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

  // ---------------------------------------------------------------------------
  // Records changed by a website-built update card page
  // ---------------------------------------------------------------------------

  /**
   * Replace the card on a payment token row in place, the way a website-built update card page does: a new token
   * value, with the expiry date and masked number cleared until the fill-metadata job refills them.
   */
  private function replaceCardInPlace(array $token, string $newToken): void {
    PaymentToken::update(FALSE)
      ->addWhere('id', '=', $token['id'])
      ->addValue('token', $newToken)
      ->addValue('expiry_date', NULL)
      ->addValue('masked_account_number', NULL)
      ->execute();
  }

  /**
   * Reset a Failed recurring contribution the way an update card page does: In Progress, no failures, no retry date,
   * and the schedule date the site chooses.
   */
  private function resetLikeAnUpdateCardPage(array $recur, string $nextSchedule): void {
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addValue('contribution_status_id:name', 'In Progress')
      ->addValue('failure_count', 0)
      ->addValue('next_sched_contribution_date', date('Y-m-d H:i:s', strtotime($nextSchedule)))
      ->execute();
    CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur', $recur['id'], 'failure_retry_date', '');
  }

  /**
   * A card replaced in place on the same payment token row is charged with its new value, and the recurring record
   * keeps pointing at the row.
   *
   * @testPlan PU-F25
   */
  public function testACardReplacedInPlaceOnTheTokenRowIsChargedWithTheNewToken(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'OLDCARDTOKEN01']);
    $recur = Fixture::recurring($contactId, $processor, ['token' => $token, 'processor_id' => 'LEGACY-IGNORED']);
    Fixture::completedContributionFor($recur);
    $this->replaceCardInPlace($token, 'NEWCARDTOKEN02');
    $this->approveNextCharge();

    $this->runCron($processor);

    $this->assertCount(1, $this->http->callsTo('postTransaction'));
    $this->assertSame('NEWCARDTOKEN02', $this->http->lastArgs('postTransaction')[0]['Customer']['TokenCustomerID']);
    $this->assertEquals($token['id'], Fixture::reloadRecurring($recur['id'])['payment_token_id'], 'The record still points at the same token row.');
    $this->assertSame('Completed', $this->contributionStatus(Fixture::reloadContribution($this->charges($recur)[0]['id'])));
  }

  /**
   * A reset record whose schedule date was moved into the future is not charged before that date, and is charged on
   * the first run after it.
   *
   * @testPlan PU-F26
   */
  public function testAResetFailedRecordWithAFutureScheduleDateWaitsForIt(): void {
    [$processor, $recur] = $this->dueRecurring([
      'contribution_status_id:name' => 'Failed',
      'failure_count' => 3,
      'failure_retry_date' => '-2 days',
    ]);
    $this->resetLikeAnUpdateCardPage($recur, '+3 days');

    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor));
    $this->runCron($processor);
    $this->assertSame([], $this->http->requests, 'Nothing is charged before the schedule date.');
    $this->assertSame([], $this->charges($recur));

    // Time passes: the schedule date is now in the past.
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addValue('next_sched_contribution_date', date('Y-m-d H:i:s', strtotime('-1 hour')))
      ->execute();
    $this->assertContains((int) $recur['id'], $this->dueIds($processor));
    $this->approveNextCharge();
    $this->runCron($processor);

    $this->assertCount(1, $this->http->callsTo('postTransaction'));
    $charges = $this->charges($recur);
    $this->assertCount(1, $charges);
    $this->assertSame('Completed', $this->contributionStatus(Fixture::reloadContribution($charges[0]['id'])));
    $this->assertSame('In Progress', $this->recurStatus(Fixture::reloadRecurring($recur['id'])));
  }

  /**
   * A reset record whose schedule was restarted from today is charged on the next run, and only once that day.
   *
   * @testPlan PU-F26
   */
  public function testAResetFailedRecordRestartedFromTodayIsChargedOnTheNextRun(): void {
    [$processor, $recur] = $this->dueRecurring([
      'contribution_status_id:name' => 'Failed',
      'failure_count' => 3,
      'failure_retry_date' => '-2 days',
    ]);
    $this->resetLikeAnUpdateCardPage($recur, date('Y-m-d 00:00:00'));

    $this->assertContains((int) $recur['id'], $this->dueIds($processor));
    $this->approveNextCharge();
    $this->runCron($processor);

    $this->assertCount(1, $this->http->callsTo('postTransaction'));
    $charges = $this->charges($recur);
    $this->assertCount(1, $charges);
    $this->assertSame('Completed', $this->contributionStatus(Fixture::reloadContribution($charges[0]['id'])));
    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->recurStatus($after));
    $this->assertEquals(0, $after['failure_count']);

    $this->runCron($processor);
    $this->assertCount(1, $this->charges($recur), 'It is not charged again the same day.');
  }

  // ---------------------------------------------------------------------------
  // The schedule date: a failure does not move it, a success does (DEF-46, DEF-58)
  //
  // The date moves only after a successful charge, first attempt or retry, from the date that was due by one
  // interval, stepping on until it is after today. So a record that failed and then succeeded keeps every month,
  // and a record left Failed for months is charged once when it is recovered, not once for each month it missed.
  // ---------------------------------------------------------------------------

  /**
   * Set the schedule date directly. Creating a Completed or Failed contribution for a record makes core move the
   * date on, so a test that builds such a contribution puts the date back afterwards.
   */
  private function setSchedule(array $recur, string $date): void {
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addValue('next_sched_contribution_date', $date)
      ->execute();
  }

  /**
   * A Pending contribution of a recurring record, as the cron leaves one while a charge is in progress.
   */
  private function pendingChargeFor(array $recur, string $receiveDate = 'now'): array {
    return Fixture::contribution($recur['contact_id'], [
      'total_amount' => $recur['amount'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => 'Pending',
      'receive_date' => date('Y-m-d H:i:s', strtotime($receiveDate)),
    ]);
  }

  private function cancelContribution(array $contribution): void {
    civicrm_api3('Contribution', 'create', ['id' => $contribution['id'], 'contribution_status_id' => 'Cancelled']);
  }

  private function notesOf(int $contributionId): array {
    return array_values(civicrm_api3('Note', 'get', [
      'entity_table' => 'civicrm_contribution',
      'entity_id' => $contributionId,
      'options' => ['sort' => 'id ASC', 'limit' => 0],
    ])['values']);
  }

  public function failureProvider(): array {
    return [
      'monthly, first attempt declined' => ['month', 1, [], 'decline'],
      'monthly, retry declined' => ['month', 1, ['failure_count' => 1, 'failure_retry_date' => '-1 hour'], 'decline'],
      'daily, first attempt declined' => ['day', 1, [], 'decline'],
      'every 2 weeks, first attempt declined' => ['week', 2, [], 'decline'],
      'yearly, first attempt declined' => ['year', 1, [], 'decline'],
      'request refused by eWAY (an error, no transaction id)' => ['month', 1, [], 'refused'],
      'no card token, eWAY is never called' => ['month', 1, ['token' => FALSE, 'processor_id' => ''], 'no token'],
    ];
  }

  /**
   * A failed charge leaves next_sched_contribution_date where it was, on every route to a failure: the date is the
   * payment that is owed. It used to move one interval on at the start of the run, so a record that ended up Failed
   * had skipped the payment it failed on (DEF-46). CiviCRM core also moves the date when a Failed contribution is
   * recorded (to today plus one interval when the date is under 48 hours ahead), so the extension puts it back.
   *
   * @dataProvider failureProvider
   * @testPlan PU-F29
   */
  public function testAFailedChargeDoesNotMoveTheScheduleDate(string $unit, int $interval, array $values, string $how): void {
    $due = date('Y-m-d 00:00:00', strtotime('-3 days'));
    [$processor, $recur] = $this->dueRecurring($values + [
      'frequency_unit' => $unit,
      'frequency_interval' => $interval,
      'next_sched_contribution_date' => $due,
    ]);
    if ($how === 'decline') {
      $this->declineNextCharge();
    }
    elseif ($how === 'refused') {
      $this->http->respond('postTransaction', Responses::errors('V6010'));
    }

    $this->runCron($processor);

    $this->assertSame($due, $this->scheduleOf($recur), 'The schedule date is unchanged by a failure.');
    $this->assertSame(['Failed' => 1], $this->chargeStatuses($recur));
    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(($values['failure_count'] ?? 0) + 1, $after['failure_count']);
    $this->assertNotEmpty($after['failure_retry_date']);
  }

  /**
   * A record that fails and then succeeds on a retry is next due one interval after the date it was due, so no month
   * is skipped. It used to be due two intervals on, because the first failed run and the successful retry each moved
   * the date (DEF-58). The Failed contribution from the first run is dated after the retry date, and must not hide
   * the record from the retry list.
   *
   * @testPlan PU-F30
   */
  public function testARecordThatFailsThenSucceedsOnARetryIsDueOneIntervalAfterItsDueDate(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-3 days'));
    [$processor, $recur] = $this->dueRecurring(['next_sched_contribution_date' => $due]);

    $this->declineNextCharge();
    $this->runCron($processor);

    $this->assertSame($due, $this->scheduleOf($recur));
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor), 'The retry waits for its date.');
    $this->makeRetryDue($recur);
    $this->assertContains((int) $recur['id'], $this->retryIds($processor), 'The Failed contribution does not hide it from the retry list.');

    $this->approveNextCharge();
    $this->runCron($processor);

    $this->assertSame(['Completed' => 1, 'Failed' => 1], $this->chargeStatuses($recur));
    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->recurStatus($after));
    $this->assertEquals(0, $after['failure_count']);
    $this->assertEmpty($after['failure_retry_date']);
    $this->assertSame(date('Y-m-d 00:00:00', strtotime($due . ' +1 month')), $this->scheduleOf($recur), 'One month on from the due date, not two.');
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor));

    $this->runCron($processor);
    $this->assertCount(2, $this->http->callsTo('postTransaction'), 'Nothing more is charged.');
  }

  /**
   * The lists skip a record that already has a contribution since its due date (or retry date), so that a record
   * is not charged twice. Only a Failed contribution is ignored: a failure leaves the due date in the past, so a
   * record that failed and was then recovered always has a Failed contribution after its due date, and would never
   * be charged again if that counted. A Pending contribution (a charge in progress or interrupted), a Completed one
   * and a Cancelled one (a person's decision not to collect the installment) still hide the record.
   *
   * @testPlan PU-F31
   */
  public function testOnlyAFailedContributionIsIgnoredWhenWorkingOutWhatIsAlreadyCharged(): void {
    $processor = Fixture::processor();
    $due = date('Y-m-d 00:00:00', strtotime('-1 day'));
    $expected = ['Failed' => TRUE, 'Cancelled' => FALSE, 'Pending' => FALSE, 'Completed' => FALSE];

    foreach (['normal list' => [], 'retry list' => ['failure_count' => 1, 'failure_retry_date' => '-1 hour']] as $list => $values) {
      foreach ($expected as $status => $listed) {
        [, $recur] = $this->dueRecurring($values + ['next_sched_contribution_date' => $due], $processor);
        $contribution = Fixture::contribution($recur['contact_id'], [
          'total_amount' => $recur['amount'],
          'contribution_status_id' => $status === 'Cancelled' ? 'Pending' : $status,
          'contribution_recur_id' => $recur['id'],
          'receive_date' => date('Y-m-d H:i:s'),
        ]);
        if ($status === 'Cancelled') {
          // Straight to the table: cancelling through the API makes the extension skip the installment (PU-F42),
          // and it is the lists that are under test here.
          CRM_Core_DAO::executeQuery('UPDATE civicrm_contribution SET contribution_status_id = %1 WHERE id = %2', [
            1 => [(int) CRM_eWAYRecurring_Utils::contribution_status_id('Cancelled'), 'Integer'],
            2 => [(int) $contribution['id'], 'Integer'],
          ]);
        }
        // Core moves the date when it records a Completed or Failed payment.
        $this->setSchedule($recur, $due);

        $ids = $list === 'normal list' ? $this->dueIds($processor) : $this->retryIds($processor);
        $this->assertSame($listed, in_array((int) $recur['id'], $ids, TRUE), "A $status contribution since the due date, $list");
      }
    }
  }

  /**
   * With a retry delay of 0 the retry date is today at midnight, which is before the Failed contribution the failure
   * has just created, so a record could never be retried.
   *
   * @testPlan PU-F32
   */
  public function testARetryDelayOfZeroStillRetriesTheRecord(): void {
    \Civi::settings()->set('eway_recurring_contribution_retry_delay', 0);
    [$processor, $recur] = $this->dueRecurring();
    $this->declineNextCharge();
    $this->runCron($processor);
    $this->assertSame(['Failed' => 1], $this->chargeStatuses($recur));

    $this->assertContains((int) $recur['id'], $this->retryIds($processor));
    $this->approveNextCharge();
    $this->runCron($processor);

    $this->assertSame(['Completed' => 1, 'Failed' => 1], $this->chargeStatuses($recur));
  }

  /**
   * Take a record through real failed runs until it is Failed, recover it with $recover, and check what the next
   * runs do (the recovery of DEF-13, and Reactivate): it is charged exactly once on the next run, whatever its due
   * date is, and the date then moves to the first date after today. It was due 70 days ago, so the months it missed
   * while it was Failed are not charged as arrears.
   */
  private function assertARecoveredRecordIsChargedOnceAndMovesOn(callable $recover, ?string $expectedToken): void {
    $due = date('Y-m-d 00:00:00', strtotime('-70 days'));
    [$processor, $recur] = $this->dueRecurring(['next_sched_contribution_date' => $due]);

    $this->runUntilFailed($processor, $recur, 2);

    $failed = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('Failed', $this->recurStatus($failed), 'Set-up');
    $this->assertEquals(2, $failed['failure_count'], 'Set-up');
    $this->assertNotEmpty($failed['failure_retry_date'], 'Set-up');
    $this->assertSame($due, $this->scheduleOf($recur), 'Failing leaves the due date in the past.');
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor));
    $this->assertCount(2, $this->http->callsTo('postTransaction'));

    $recover($processor, $recur);

    $recovered = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->recurStatus($recovered));
    $this->assertEquals(0, $recovered['failure_count']);
    $this->assertEmpty($recovered['failure_retry_date']);
    $this->assertSame($due, $this->scheduleOf($recur), 'Recovering does not move the date either.');
    $this->assertContains((int) $recur['id'], $this->dueIds($processor), 'The two Failed contributions since the due date do not hide it.');

    $this->approveNextCharge();
    $this->runCron($processor);

    $this->assertCount(3, $this->http->callsTo('postTransaction'), 'Charged exactly once by the run after the recovery.');
    if ($expectedToken !== NULL) {
      $this->assertSame($expectedToken, $this->http->lastArgs('postTransaction')[0]['Customer']['TokenCustomerID']);
    }
    $this->assertSame(['Completed' => 1, 'Failed' => 2], $this->chargeStatuses($recur));
    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->recurStatus($after));
    $this->assertEquals(0, $after['failure_count']);
    $this->assertEmpty($after['failure_retry_date']);
    $next = $this->scheduleOf($recur);
    $this->assertGreaterThan(time(), strtotime($next), 'The next date is in the future.');
    $this->assertSame($this->firstDateAfterToday($due, 1, 'month'), $next);
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));

    $this->runCron($processor);
    $this->assertCount(3, $this->http->callsTo('postTransaction'), 'Not charged again.');
  }

  /**
   * A Failed record whose card is updated through the Update Billing Details form is charged once by the next cron
   * run with the new card, goes back to In Progress with no failures, and then waits for a date in the future (DEF-46).
   *
   * @testPlan PU-F33
   */
  public function testAFailedRecordWhoseCardIsUpdatedIsChargedOnceByTheNextRun(): void {
    $this->assertARecoveredRecordIsChargedOnceAndMovesOn(function (array $processor, array $recur) {
      $this->updateTheCard($processor, $recur, 'NEWTOKEN001');
    }, 'NEWTOKEN001');
  }

  /**
   * The same for Reactivate on the Failed Recurring Contributions list (DEF-46).
   *
   * @testPlan PU-F34
   */
  public function testAReactivatedFailedRecordIsChargedOnceByTheNextRun(): void {
    $this->assertARecoveredRecordIsChargedOnceAndMovesOn(function (array $processor, array $recur) {
      $this->reactivate($recur);
    }, '912345678901');
  }

  /**
   * The date used to move before the charge, so a run that died after eWAY took the money could not charge again.
   * It now moves only after a success, so the guard is the Pending contribution the run creates before it charges:
   * a record with a contribution since its due date is not on the list. A run that dies after the gateway took
   * the payment leaves the date alone and the next run does not charge it again.
   *
   * @testPlan PU-F35
   */
  public function testARunThatDiesAfterTheGatewayTookThePaymentDoesNotChargeAgain(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    [$processor, $recur] = $this->dueRecurring(['next_sched_contribution_date' => $due]);
    $this->approveNextCharge();
    // An Error is not an Exception, so the job's own handling does not catch it: the process just ends.
    $listener = function ($event) {
      if ($event->getApiRequestSig() === '3.contribution.completetransaction') {
        throw new Error('Simulated crash after the gateway took the payment');
      }
    };
    \Civi::dispatcher()->addListener('civi.api.prepare', $listener);
    try {
      $this->runCron($processor);
      $this->fail('The simulated crash should have ended the run.');
    }
    catch (Error $e) {
      $this->assertStringContainsString('Simulated crash', $e->getMessage());
    }
    finally {
      \Civi::dispatcher()->removeListener('civi.api.prepare', $listener);
    }

    $this->assertCount(1, $this->http->callsTo('postTransaction'));
    $this->assertSame($due, $this->scheduleOf($recur), 'The date did not move, because the run did not finish.');
    $this->assertSame(['Pending' => 1], $this->chargeStatuses($recur));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor), 'The Pending contribution keeps it off the list.');

    $this->runCron($processor);

    $this->assertCount(1, $this->http->callsTo('postTransaction'), 'A second run does not charge again.');
    $this->assertSame(['Pending' => 1], $this->chargeStatuses($recur));
    $this->assertSame($due, $this->scheduleOf($recur));
  }

  /**
   * A run that dies after it created the Pending contribution but before eWAY answered also leaves the date alone and
   * is not charged again by the next run. It stays off the list until staff settle the Pending contribution; if they
   * cancel it the installment is skipped: the schedule moves on and the next run charges nothing.
   *
   * @testPlan PU-F36
   */
  public function testARunThatDiesBeforeTheGatewayAnswersIsNotChargedAgainAndCancellingThePendingContributionSkipsTheInstallment(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    [$processor, $recur] = $this->dueRecurring(['next_sched_contribution_date' => $due]);
    $this->http->respond('postTransaction', function () {
      throw new Error('Simulated crash while waiting for the gateway');
    });
    try {
      $this->runCron($processor);
      $this->fail('The simulated crash should have ended the run.');
    }
    catch (Error $e) {
      $this->assertStringContainsString('Simulated crash', $e->getMessage());
    }

    $this->assertSame($due, $this->scheduleOf($recur));
    $this->assertSame(['Pending' => 1], $this->chargeStatuses($recur));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
    $this->runCron($processor);
    $this->assertCount(1, $this->http->callsTo('postTransaction'), 'Not charged again while the Pending contribution is there.');

    civicrm_api3('Contribution', 'create', ['id' => $this->charges($recur)[0]['id'], 'contribution_status_id' => 'Cancelled']);

    // Cancelling is a decision not to collect this installment: the schedule moves on and nothing is charged (PU-F42).
    $this->assertSame($this->firstDateAfterToday($due, 1, 'month'), $this->scheduleOf($recur));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
    $this->runCron($processor);
    $this->assertCount(1, $this->http->callsTo('postTransaction'), 'The cancelled installment is not charged.');
    $this->assertSame(['Cancelled' => 1], $this->chargeStatuses($recur));
  }

  /**
   * Cancelling a contribution of an In Progress eWAY record, dated on or after the due date, is a decision not to
   * collect that installment: the next scheduled date moves on as it does after a charge (one interval from the due
   * date, never into the past) and the contribution gets a note that names both dates. The record stays In Progress.
   *
   * @testPlan PU-F42
   */
  public function testCancellingAnInstallmentSkipsItAndMovesTheScheduleOn(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    [, $recur] = $this->dueRecurring(['next_sched_contribution_date' => $due]);
    $charge = $this->pendingChargeFor($recur);
    $this->assertSame($due, $this->scheduleOf($recur));
    $this->captureLog();

    $this->cancelContribution($charge);

    $expected = $this->firstDateAfterToday($due, 1, 'month');
    $this->assertSame($expected, $this->scheduleOf($recur));
    $this->assertSame('In Progress', $this->recurStatus(Fixture::reloadRecurring($recur['id'])));
    $notes = $this->notesOf($charge['id']);
    $this->assertCount(1, $notes);
    $this->assertStringContainsString('skipped', $notes[0]['subject']);
    $this->assertStringContainsString(date('Y-m-d', strtotime($due)), $notes[0]['note']);
    $this->assertStringContainsString(date('Y-m-d', strtotime($expected)), $notes[0]['note']);
    $logged = $this->logged('info', 'eWAY Recurring: contribution {contribution_id} was cancelled');
    $this->assertCount(1, $logged);
    $this->assertSame((int) $recur['id'], (int) $logged[0]['context']['recur_id']);
  }

  /**
   * Cancelling does nothing to the schedule unless it is an installment the record is waiting on: a contribution
   * dated before the due date, a record that is not In Progress, a record of another processor, and a contribution
   * that belongs to no recurring record are all left alone, with no note.
   *
   * @testPlan PU-F43
   */
  public function testCancellingAContributionThatIsNotTheInstallmentBeingWaitedOnLeavesTheScheduleAlone(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    $dummyType = (int) \Civi\Api4\PaymentProcessorType::get(FALSE)->addWhere('name', '=', 'Dummy')->addSelect('id')->execute()->first()['id'];

    $cases = [
      'dated before the due date' => [[], '-3 months'],
      'record not In Progress' => [['contribution_status_id:name' => 'Failed'], 'now'],
      'record of another processor' => [['dummy' => TRUE], 'now'],
    ];
    foreach ($cases as $label => [$values, $receiveDate]) {
      $processor = !empty($values['dummy']) ? Fixture::processor(FALSE, ['payment_processor_type_id' => $dummyType]) : NULL;
      unset($values['dummy']);
      [, $recur] = $this->dueRecurring($values + ['next_sched_contribution_date' => $due], $processor);
      $charge = $this->pendingChargeFor($recur, $receiveDate);
      $this->setSchedule($recur, $due);

      $this->cancelContribution($charge);

      $this->assertSame($due, $this->scheduleOf($recur), "Cancelling a contribution: $label");
      $this->assertSame([], $this->notesOf($charge['id']), "No note: $label");
    }

    $loose = Fixture::contribution(Fixture::contact(), ['contribution_status_id' => 'Pending']);
    $this->cancelContribution($loose);
    $this->assertSame([], $this->notesOf($loose['id']), 'A contribution with no recurring record');
  }

  /**
   * Two contributions cancelled for the same due date move the schedule once, not twice: after the first the
   * schedule is past both of them.
   *
   * @testPlan PU-F44
   */
  public function testCancellingTwoContributionsOfTheSameInstallmentMovesTheScheduleOnlyOnce(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    [, $recur] = $this->dueRecurring(['next_sched_contribution_date' => $due]);
    $first = $this->pendingChargeFor($recur);
    $second = $this->pendingChargeFor($recur);
    $this->assertSame($due, $this->scheduleOf($recur));

    $this->cancelContribution($first);
    $this->cancelContribution($second);

    $this->assertSame($this->firstDateAfterToday($due, 1, 'month'), $this->scheduleOf($recur));
    $this->assertCount(1, $this->notesOf($first['id']));
    $this->assertSame([], $this->notesOf($second['id']), 'The second cancellation changed nothing, so it says nothing.');
  }

  /**
   * A record that was being retried: cancelling the installment it was retrying skips it, so the retry date and the
   * failure count are cleared as well as the schedule moving on, and neither list picks the record up.
   *
   * @testPlan PU-F45
   */
  public function testCancellingTheInstallmentOfARecordBeingRetriedSkipsItAndClearsTheRetry(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    [$processor, $recur] = $this->dueRecurring([
      'next_sched_contribution_date' => $due,
      'failure_count' => 1,
      'failure_retry_date' => '-1 hour',
    ]);
    $charge = $this->pendingChargeFor($recur);
    $this->assertSame($due, $this->scheduleOf($recur));

    $this->cancelContribution($charge);

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame($this->firstDateAfterToday($due, 1, 'month'), $this->scheduleOf($recur));
    $this->assertEmpty($after['failure_retry_date']);
    $this->assertSame(0, (int) $after['failure_count']);
    $this->assertSame('In Progress', $this->recurStatus($after));
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
    $notes = $this->notesOf($charge['id']);
    $this->assertCount(1, $notes);
    $this->assertStringContainsString('retry', $notes[0]['note']);
  }

  /**
   * A Cancelled contribution dated on or after the due date blocks the lists (it is a person's decision, so the
   * cron never charges that installment behind their back), and the cron run after a cancel charges nothing and
   * leaves the schedule where the cancel put it.
   *
   * @testPlan PU-F46
   */
  public function testTheCronChargesNothingForAnInstallmentThatWasCancelled(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    [$processor, $recur] = $this->dueRecurring(['next_sched_contribution_date' => $due]);
    $charge = $this->pendingChargeFor($recur);
    $this->cancelContribution($charge);
    $moved = $this->scheduleOf($recur);

    $this->runCron($processor);

    $this->assertSame([], $this->http->callsTo('postTransaction'), 'Nothing is charged.');
    $this->assertSame($moved, $this->scheduleOf($recur), 'The run left the schedule alone.');
    $this->assertSame(['Cancelled' => 1], $this->chargeStatuses($recur));

    // Put the schedule back, as a person might to collect it anyway: the cancelled contribution still blocks it,
    // so nothing is charged until that contribution is dealt with too.
    $this->setSchedule($recur, $due);
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
  }

  public function catchUpProvider(): array {
    return [
      'monthly, exactly three intervals overdue' => ['month', 1, '-3 months'],
      'monthly, three intervals and ten days overdue' => ['month', 1, '-3 months -10 days'],
      'daily, three days overdue' => ['day', 1, '-3 days'],
      'weekly, one day overdue' => ['week', 1, '-1 day'],
      'every 2 weeks, five weeks overdue' => ['week', 2, '-5 weeks'],
      'quarterly, seven months overdue' => ['month', 3, '-7 months'],
      'yearly, two years and twenty days overdue' => ['year', 1, '-2 years -20 days'],
    ];
  }

  /**
   * A record that is several intervals overdue (it sat Failed, or the job did not run) is charged once, and its next
   * date is the first date after today, so the donor is not charged for the months missed and not charged again on
   * the following runs.
   *
   * @dataProvider catchUpProvider
   * @testPlan PU-F37
   */
  public function testARecordSeveralIntervalsOverdueIsChargedOnceAndMovesToTheFirstFutureDate(string $unit, int $interval, string $overdue): void {
    $due = date('Y-m-d 00:00:00', strtotime($overdue));
    [$processor, $recur] = $this->dueRecurring([
      'frequency_unit' => $unit,
      'frequency_interval' => $interval,
      'next_sched_contribution_date' => $due,
    ]);
    $this->approveNextCharge();

    $this->runCron($processor);

    $this->assertCount(1, $this->http->callsTo('postTransaction'));
    $this->assertSame(['Completed' => 1], $this->chargeStatuses($recur));
    $next = strtotime($this->scheduleOf($recur));
    $this->assertGreaterThan(strtotime('today'), $next, 'After today.');
    $this->assertLessThanOrEqual(strtotime("tomorrow +{$interval} {$unit}s"), $next, 'No further away than the first date after today.');
    $this->assertSame($this->firstDateAfterToday($due, $interval, $unit), date('Y-m-d H:i:s', $next));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));

    $this->runCron($processor);
    $this->assertCount(1, $this->http->callsTo('postTransaction'), 'Not charged again.');
  }

  /**
   * The last installment ends the record as Completed only after its charge succeeds. It used to be marked Completed
   * before the charge was attempted, so a final installment that was declined left a Completed record that was never
   * retried (DEF-67).
   *
   * @testPlan PU-F38
   */
  public function testTheLastInstallmentIsCompletedOnlyAfterItsChargeSucceeds(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    // One contribution exists (the seeded one), so the second installment is the last.
    [$processor, $recur] = $this->dueRecurring(['installments' => 2, 'next_sched_contribution_date' => $due]);

    $this->declineNextCharge();
    $this->runCron($processor);

    $failed = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->recurStatus($failed), 'A declined final installment is not Completed.');
    $this->assertEmpty($failed['end_date']);
    $this->assertEquals(1, $failed['failure_count']);
    $this->assertSame($due, $this->scheduleOf($recur));
    $this->makeRetryDue($recur);
    $this->assertContains((int) $recur['id'], $this->retryIds($processor));

    $this->approveNextCharge();
    $this->runCron($processor);

    $this->assertSame(['Completed' => 1, 'Failed' => 1], $this->chargeStatuses($recur));
    $done = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('Completed', $this->recurStatus($done));
    $this->assertNotEmpty($done['end_date']);
    $this->assertEquals(0, $done['failure_count']);
    $this->assertEmpty($done['failure_retry_date']);
    $this->assertNotContains((int) $recur['id'], $this->retryIds($processor));
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor));
  }

  /**
   * The job looked up a record's contributions with APIv3's default of live contributions only, so a test-mode record
   * (a test-drive contribution page, or a test processor) found none: it printed "Undefined array key 0" on every run,
   * never read the tax amount, and counted no installments, so it was never Completed (DEF-69).
   *
   * @testPlan PU-F41
   */
  public function testATestModeRecordWithInstallmentsIsChargedAndCompletedLikeALiveOne(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    $processor = Fixture::processor(TRUE);
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $processor, ['installments' => 2, 'is_test' => 1, 'next_sched_contribution_date' => $due]);
    Fixture::contribution($contactId, [
      'total_amount' => $recur['amount'],
      'financial_type_id' => $recur['financial_type_id'],
      'contribution_status_id' => 'Completed',
      'contribution_recur_id' => $recur['id'],
      'receive_date' => date('Y-m-d H:i:s', strtotime('-2 months')),
      'payment_instrument_id' => 1,
      'is_test' => 1,
    ]);
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addValue('next_sched_contribution_date', $due)
      ->execute();

    $this->approveNextCharge();
    $this->runCron($processor);

    // One test contribution was there and the run made the second, which was the last installment.
    $this->assertSame(2, (int) civicrm_api3('Contribution', 'getcount', ['contribution_recur_id' => $recur['id'], 'is_test' => 1]));
    $done = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('Completed', $this->recurStatus($done), 'The last installment completed the test-mode record.');
    $this->assertNotEmpty($done['end_date']);
  }

  /**
   * A failed attempt is not an installment that was paid. A retry of a middle installment must not be taken for the
   * last one (it used to be, because the Failed contribution was counted, so a successful retry of installment 2 of 3
   * completed the record early), and the real last installment still completes it (DEF-67).
   *
   * @testPlan PU-F39
   */
  public function testARetryOfAMiddleInstallmentDoesNotCompleteTheRecord(): void {
    $due = date('Y-m-d 00:00:00', strtotime('-2 days'));
    [$processor, $recur] = $this->dueRecurring(['installments' => 3, 'next_sched_contribution_date' => $due]);

    $this->declineNextCharge();
    $this->runCron($processor);
    $this->makeRetryDue($recur);
    $this->approveNextCharge();
    $this->runCron($processor);

    $second = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->recurStatus($second), 'Installment 2 of 3 was paid on a retry: not the last.');
    $this->assertEmpty($second['end_date']);
    $this->assertSame(date('Y-m-d 00:00:00', strtotime($due . ' +1 month')), $this->scheduleOf($recur));

    // A month passes: the third installment falls due, after the charges so far.
    foreach ($this->charges($recur) as $charge) {
      if ($this->contributionStatus($charge) === 'Completed') {
        civicrm_api3('Contribution', 'create', ['id' => $charge['id'], 'receive_date' => date('Y-m-d H:i:s', strtotime('-30 days'))]);
      }
    }
    $this->setSchedule($recur, date('Y-m-d 00:00:00', strtotime('-1 day')));
    $this->approveNextCharge();
    $this->runCron($processor);

    $this->assertSame(['Completed' => 2, 'Failed' => 1], $this->chargeStatuses($recur));
    $third = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('Completed', $this->recurStatus($third));
    $this->assertNotEmpty($third['end_date']);
  }

  /**
   * Every unit and an interval above 1: a failure leaves the date, and the retry that succeeds moves it from the date
   * that was due.
   *
   * @dataProvider scheduleProvider
   * @testPlan PU-F40
   */
  public function testEveryFrequencyUnitKeepsItsDateOnFailureAndMovesFromItOnARetry(string $unit, int $interval): void {
    $due = date('Y-m-d 00:00:00', strtotime('-5 days'));
    [$processor, $recur] = $this->dueRecurring([
      'frequency_unit' => $unit,
      'frequency_interval' => $interval,
      'next_sched_contribution_date' => $due,
    ]);

    $this->declineNextCharge();
    $this->runCron($processor);
    $this->assertSame($due, $this->scheduleOf($recur));

    $this->makeRetryDue($recur);
    $this->approveNextCharge();
    $this->runCron($processor);

    $this->assertSame(['Completed' => 1, 'Failed' => 1], $this->chargeStatuses($recur));
    $this->assertSame($this->firstDateAfterToday($due, $interval, $unit), $this->scheduleOf($recur));
  }

}
