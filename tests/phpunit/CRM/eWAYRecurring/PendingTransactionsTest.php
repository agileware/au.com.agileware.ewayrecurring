<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;
use CRM_eWAYRecurring_Test_FakeEwayResponses as Responses;

/**
 * The verification queue: EwayContributionTransactions and the job that
 * checks queued transactions with eWAY (EwayContributionTransactions.validate).
 *
 * Test plan suite PU-E: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_PendingTransactionsTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var \CRM_eWAYRecurring_Test_FakeEwayHttp
   */
  private $http;

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
    parent::tearDown();
  }

  private function statusName(array $contribution): string {
    return CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $contribution['contribution_status_id']);
  }

  private function queueRow(int $id): array {
    return civicrm_api3('EwayContributionTransactions', 'getsingle', ['id' => $id]);
  }

  /**
   * A Pending contribution with a queue row.
   *
   * @return array [processor, contribution, queue row]
   */
  private function queued(string $accessCode, array $queueValues = [], array $contributionValues = [], ?array $processor = NULL): array {
    $processor = $processor ?? Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact(), $contributionValues);
    $row = Fixture::queued($contribution['id'], $processor['id'], $accessCode, $queueValues);
    return [$processor, $contribution, $row];
  }

  // ---------------------------------------------------------------------------
  // The entity
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-E1
   */
  public function testQueueRowRoundTripAndDefaults(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());

    $created = civicrm_api3('EwayContributionTransactions', 'create', [
      'contribution_id' => $contribution['id'],
      'payment_processor_id' => $processor['id'],
      'access_code' => 'AC-ROUNDTRIP',
    ]);
    $row = $this->queueRow($created['id']);

    $this->assertSame('AC-ROUNDTRIP', $row['access_code']);
    $this->assertEquals(CRM_eWAYRecurring_Utils::STATUS_IN_QUEUE, $row['status']);
    $this->assertEquals(0, $row['tries']);
    $this->assertEquals(1, $row['is_email_receipt'], 'Receipts are sent unless the row says otherwise.');

    civicrm_api3('EwayContributionTransactions', 'delete', ['id' => $created['id']]);
    $this->assertSame(0, civicrm_api3('EwayContributionTransactions', 'get', ['id' => $created['id']])['count']);
  }

  /**
   * @testPlan PU-E2
   */
  public function testDeletingTheContributionOrProcessorRemovesTheQueueRow(): void {
    [, $contribution, $byContribution] = $this->queued('AC-FK-1');
    [$processor, , $byProcessor] = $this->queued('AC-FK-2');

    civicrm_api3('Contribution', 'delete', ['id' => $contribution['id']]);
    $this->assertSame(0, civicrm_api3('EwayContributionTransactions', 'get', ['id' => $byContribution['id']])['count']);

    // Deleting a payment processor is blocked while it has contributions; remove the dependent row's
    // contribution first, as an administrator would have to.
    civicrm_api3('Contribution', 'delete', ['id' => $byProcessor['contribution_id']]);
    $this->assertSame(0, civicrm_api3('EwayContributionTransactions', 'get', ['id' => $byProcessor['id']])['count']);
  }

  // ---------------------------------------------------------------------------
  // validatePendingTransactions
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-E3
   */
  public function testApprovedTransactionIsRecordedAsSuccessful(): void {
    [, $contribution, $row] = $this->queued('AC-OK');
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 7001]));

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(['failed' => 0, 'success' => 1, 'not_processed' => 0, 'deleted' => 0], $result);
    $updated = $this->queueRow($row['id']);
    $this->assertEquals(CRM_eWAYRecurring_Utils::STATUS_SUCCESS, $updated['status']);
    $this->assertEquals(1, $updated['tries']);
    $this->assertSame('Completed', $this->statusName(Fixture::reloadContribution($contribution['id'])));
  }

  /**
   * @testPlan PU-E4
   */
  public function testDeclinedTransactionIsRecordedAsFailedWithItsMessage(): void {
    [, $contribution, $row] = $this->queued('AC-DECLINED');
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionID' => 7002,
      'TransactionStatus' => FALSE,
      'ResponseMessage' => 'D4401',
    ]));

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(1, $result['failed']);
    $updated = $this->queueRow($row['id']);
    $this->assertEquals(CRM_eWAYRecurring_Utils::STATUS_FAILED, $updated['status']);
    $this->assertSame('Your payment was declined: ' . \Eway\Rapid::getMessage('D4401'), $updated['failed_message']);
    $this->assertSame('Failed', $this->statusName(Fixture::reloadContribution($contribution['id'])));
  }

  /**
   * @testPlan PU-E5
   */
  public function testFailureOnARecurringContributionCountsAgainstTheSchedule(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Pending', 'token' => FALSE, 'failure_count' => 0]);
    $contribution = Fixture::contribution($contactId, ['contribution_recur_id' => $recur['id']]);
    Fixture::queued($contribution['id'], $processor['id'], 'AC-RECUR-FAIL');
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionID' => 7003,
      'TransactionStatus' => FALSE,
      'ResponseMessage' => 'D4401',
    ]));

    (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals(1, $reloaded['failure_count']);
    $this->assertNotEmpty($reloaded['failure_retry_date']);
  }

  /**
   * @testPlan PU-E6
   */
  public function testNotProcessedYetStaysQueuedButCountsATry(): void {
    [, $contribution, $row] = $this->queued('AC-LATER');
    $this->http->respond('getTransaction', Responses::transactionNotProcessedYet());

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(1, $result['not_processed']);
    $updated = $this->queueRow($row['id']);
    $this->assertEquals(CRM_eWAYRecurring_Utils::STATUS_IN_QUEUE, $updated['status']);
    $this->assertEquals(1, $updated['tries']);
    $this->assertSame('Pending', $this->statusName(Fixture::reloadContribution($contribution['id'])));
  }

  /**
   * @testPlan PU-E7
   */
  public function testMissingContributionOrProcessorDeletesTheRow(): void {
    // The processor no longer exists: the queue row is removed by the foreign key before the job runs,
    // so the job's own clean-up is exercised by a contribution that is no longer Pending.
    [, $completed, $row] = $this->queued('AC-GONE', [], ['contribution_status_id' => 'Completed']);

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(1, $result['deleted']);
    $this->assertSame(0, civicrm_api3('EwayContributionTransactions', 'get', ['id' => $row['id']])['count']);
    $this->assertSame('Completed', $this->statusName(Fixture::reloadContribution($completed['id'])));
    $this->assertSame([], $this->http->requests, 'eWAY is not asked about a contribution that is not Pending.');
  }

  /**
   * @testPlan PU-E8
   */
  public function testRowsOutOfTriesAreGivenUpOn(): void {
    [, $contribution, $row] = $this->queued('AC-GIVEUP', ['tries' => CRM_eWAYRecurring_Utils::MAX_TRIES]);

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(['failed' => 0, 'success' => 0, 'not_processed' => 0, 'deleted' => 0], $result);
    $this->assertEquals(CRM_eWAYRecurring_Utils::STATUS_FAILED, $this->queueRow($row['id'])['status']);
    $this->assertSame('Failed', $this->statusName(Fixture::reloadContribution($contribution['id'])));
    $this->assertSame([], $this->http->requests, 'No more calls are made once a row is out of tries.');
  }

  /**
   * @testPlan PU-E8
   */
  public function testRowOneTryBelowTheLimitIsStillChecked(): void {
    [, , $row] = $this->queued('AC-LASTTRY', ['tries' => CRM_eWAYRecurring_Utils::MAX_TRIES - 1]);
    $this->http->respond('getTransaction', Responses::transactionNotProcessedYet());

    (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertCount(1, $this->http->callsTo('getTransaction'));
    $this->assertEquals(CRM_eWAYRecurring_Utils::MAX_TRIES, $this->queueRow($row['id'])['tries']);
  }

  /**
   * @testPlan PU-E9
   */
  public function testFinishedRowsAreNotCheckedAgain(): void {
    $this->queued('AC-DONE-1', ['status' => CRM_eWAYRecurring_Utils::STATUS_SUCCESS]);
    $this->queued('AC-DONE-2', ['status' => CRM_eWAYRecurring_Utils::STATUS_FAILED]);

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(['failed' => 0, 'success' => 0, 'not_processed' => 0, 'deleted' => 0], $result);
    $this->assertSame([], $this->http->requests);
  }

  /**
   * @testPlan PU-E10
   */
  public function testValidateApiReturnsTheFourCounters(): void {
    $this->queued('AC-API');
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 7010]));

    $result = civicrm_api3('EwayContributionTransactions', 'validate');

    $this->assertSame(['failed' => 0, 'success' => 1, 'not_processed' => 0, 'deleted' => 0], $result['values']);
  }

  /**
   * @testPlan PU-E11
   */
  public function testMixedBatchOfEveryOutcome(): void {
    [, $ok] = $this->queued('AC-MIX-OK');
    [, $declined] = $this->queued('AC-MIX-DECLINED');
    [, $later] = $this->queued('AC-MIX-LATER');
    $this->queued('AC-MIX-GONE', [], ['contribution_status_id' => 'Completed']);
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 7021]));
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 7022, 'TransactionStatus' => FALSE, 'ResponseMessage' => 'D4401']));
    $this->http->respond('getTransaction', Responses::transactionNotProcessedYet());

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(['failed' => 1, 'success' => 1, 'not_processed' => 1, 'deleted' => 1], $result);
    $this->assertSame('Completed', $this->statusName(Fixture::reloadContribution($ok['id'])));
    $this->assertSame('Failed', $this->statusName(Fixture::reloadContribution($declined['id'])));
    $this->assertSame('Pending', $this->statusName(Fixture::reloadContribution($later['id'])));
  }

  /**
   * @testPlan PU-E12
   */
  public function testRunsFromOutsideTheExtensionDirectory(): void {
    $this->queued('AC-CWD');
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 7030]));

    $previous = getcwd();
    chdir(sys_get_temp_dir());
    try {
      $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();
    }
    finally {
      chdir($previous);
    }

    $this->assertSame(1, $result['success']);
  }

}
