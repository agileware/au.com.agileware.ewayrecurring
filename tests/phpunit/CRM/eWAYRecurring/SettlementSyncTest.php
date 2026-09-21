<?php
declare(strict_types = 1);

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorType;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for CRM_eWAYRecurring_SettlementSync.
 *
 * @group headless
 */
class CRM_eWAYRecurring_SettlementSyncTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \Civi::settings()->set('eway_settlement_window_days', 1);
  }

  public function tearDown(): void {
    \Civi::settings()->revert('eway_settlement_window_days');
    \Civi::settings()->revert('eway_settlement_processors');
    parent::tearDown();
  }

  /**
   * Add one or more payment processor ids to the eway_settlement_processors
   * allowlist setting. TransactionalInterface rolls back DB rows but not the
   * in-process Civi settings cache, so tearDown() reverts this.
   */
  private function allowProcessors(int ...$ids): void {
    $current = (array) \Civi::settings()->get('eway_settlement_processors');
    \Civi::settings()->set(
      'eway_settlement_processors',
      array_values(array_unique(array_merge(array_map('intval', $current), $ids)))
    );
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Create an eWAY payment processor for tests.
   *
   * Uses a static counter to ensure unique names within the test run
   * (payment_processor has a domain_id + name uniqueness constraint).
   */
  private function createEwayProcessor(bool $isTest = FALSE, string $userName = 'test-api-key', string $password = 'test-api-password'): int {
    static $counter = 0;
    $counter++;

    $typeId = PaymentProcessorType::get(FALSE)
      ->addWhere('name', '=', 'eWay_Recurring')
      ->addSelect('id')
      ->execute()
      ->first()['id'];

    return PaymentProcessor::create(FALSE)
      ->addValue('payment_processor_type_id', $typeId)
      ->addValue('name', 'Test eWAY Processor ' . $counter)
      ->addValue('title', 'Test eWAY Processor ' . $counter)
      ->addValue('user_name', $userName)
      ->addValue('password', $password)
      ->addValue('is_test', $isTest ? 1 : 0)
      ->addValue('is_active', 1)
      ->addValue('domain_id', \CRM_Core_Config::domainID())
      ->execute()
      ->first()['id'];
  }

  /**
   * Create a Completed eWAY contribution for tests.
   */
  private function createCompletedEwayContribution(
    int $processorId,
    string $trxnId,
    float $totalAmount = 100.00,
    float $feeAmount = 0.00,
    string $receiveDate = 'now'
  ): int {
    $contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Contributor')
      ->execute()
      ->first()['id'];

    $date = date('Y-m-d H:i:s', strtotime($receiveDate));

    $contributionId = Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', $totalAmount)
      ->addValue('fee_amount', $feeAmount)
      ->addValue('net_amount', $totalAmount - $feeAmount)
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('trxn_id', $trxnId)
      ->addValue('receive_date', $date)
      ->execute()
      ->first()['id'];

    // Explicitly create a FinancialTrxn + EntityFinancialTrxn with payment_processor_id
    // set so the EntityFinancialTrxn bridge join in getUnreconciledContributions() works.
    // The headless API does not auto-create these records for direct Contribution::create() calls.
    $this->linkPaymentProcessorToContribution($contributionId, $processorId, $totalAmount, $date);

    return $contributionId;
  }

  /**
   * Create a FinancialTrxn with payment_processor_id set and link it to a contribution
   * via EntityFinancialTrxn, simulating what the eWAY payment processor creates.
   *
   * Uses API v3 because entity_table is not exposed in the EntityFinancialTrxn API v4 entity.
   */
  private function linkPaymentProcessorToContribution(int $contributionId, int $processorId, float $amount, string $date): void {
    $toAccountId = (int) civicrm_api3('FinancialAccount', 'getvalue', [
      'return' => 'id',
      'is_active' => 1,
      'options' => ['limit' => 1, 'sort' => 'id ASC'],
    ]);

    // Passing entity_id + entity_table to FinancialTrxn.create causes CiviCRM
    // to automatically create the EntityFinancialTrxn link record.
    civicrm_api3('FinancialTrxn', 'create', [
      'payment_processor_id' => $processorId,
      'to_financial_account_id' => $toAccountId,
      'trxn_date' => $date,
      'total_amount' => $amount,
      'net_amount' => $amount,
      'fee_amount' => 0,
      'status_id' => 1,
      'payment_instrument_id' => 1,
      'entity_id' => $contributionId,
      'entity_table' => 'civicrm_contribution',
    ]);
  }

  /**
   * Build a SettlementSync with a mocked HTTP client.
   *
   * @param Response[] $responses Guzzle responses to return in sequence.
   */
  private function syncWithMockedHttp(array $responses): CRM_eWAYRecurring_SettlementSync {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new \GuzzleHttp\Client(['handler' => $handlerStack]);
    return new CRM_eWAYRecurring_SettlementSync($client);
  }

  /**
   * Build a Guzzle response representing a settlement search result page.
   */
  private function makeSettlementResponse(array $transactions): Response {
    $body = json_encode([
      'SettlementTransactions' => $transactions,
      'Errors' => '',
    ]);
    return new Response(200, ['Content-Type' => 'application/json'], $body);
  }

  /**
   * A settlement response representing eWAY's "report still building" state.
   */
  private function makeNotReadyResponse(): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'SettlementTransactions' => [],
      'Errors' => 'If you are querying the settlement report with this date range for the first time, the data will be available in 60 mins approx. Thank you.',
    ]));
  }

  /**
   * A settlement response carrying an arbitrary (non "not ready") API error.
   */
  private function makeErrorResponse(string $error): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'SettlementTransactions' => [],
      'Errors' => $error,
    ]));
  }

  // ---------------------------------------------------------------------------
  // Task 2: SettlementNotReadyException
  // ---------------------------------------------------------------------------

  public function testSettlementNotReadyExceptionIsARuntimeException(): void {
    $e = new CRM_eWAYRecurring_SettlementNotReadyException('report building');
    $this->assertInstanceOf(\RuntimeException::class, $e);
    $this->assertSame('report building', $e->getMessage());
  }

  // ---------------------------------------------------------------------------
  // getSettlementProcessorOptions()
  // ---------------------------------------------------------------------------

  public function testGetSettlementProcessorOptionsListsLiveEwayProcessorsOnly(): void {
    $liveId = $this->createEwayProcessor(FALSE);
    $testId = $this->createEwayProcessor(TRUE);

    $options = CRM_eWAYRecurring_SettlementSync::getSettlementProcessorOptions();

    $this->assertArrayHasKey($liveId, $options, 'Live eWAY processor should be offered as an option');
    $this->assertArrayNotHasKey($testId, $options, 'Test eWAY processor must not be offered');
    $this->assertNotEmpty($options[$liveId], 'Option label should be non-empty');
  }

  // ---------------------------------------------------------------------------
  // getEwayProcessors()
  // ---------------------------------------------------------------------------

  public function testGetEwayProcessorsReturnsOnlyLiveProcessors(): void {
    $liveId = $this->createEwayProcessor(FALSE);
    $testId = $this->createEwayProcessor(TRUE);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getEwayProcessors();

    $ids = array_column($result, 'id');
    $this->assertContains($liveId, $ids, 'Live processor should be returned');
    $this->assertNotContains($testId, $ids, 'Test processor should never be returned');
  }

  public function testGetEwayProcessorsReturnsCredentials(): void {
    $createdId = $this->createEwayProcessor(FALSE);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getEwayProcessors();

    $processor = NULL;
    // Find the processor we just created (there may be others in the DB).
    foreach ($result as $p) {
      if ($p['id'] === $createdId) {
        $processor = $p;
        break;
      }
    }

    $this->assertNotNull($processor, 'Created processor should be in results');
    $this->assertArrayHasKey('user_name', $processor);
    $this->assertArrayHasKey('password', $processor);
    $this->assertArrayHasKey('is_test', $processor);
  }

  // ---------------------------------------------------------------------------
  // Task 4: getUnreconciledContributions()
  // ---------------------------------------------------------------------------

  public function testGetUnreconciledContributionsReturnsEmptyWhenNoProcessorsConfigured(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $this->createCompletedEwayContribution($processorId, 'TXN_NOCFG_01', 100.00, 0.00);

    // eway_settlement_processors is unset (default []).
    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $this->assertSame([], $result, 'With no processors configured, an unscoped run has no candidates');
  }

  public function testGetUnreconciledContributionsRespectsProcessorAllowlist(): void {
    $allowedProcessorId = $this->createEwayProcessor(FALSE);
    $otherProcessorId = $this->createEwayProcessor(FALSE);
    $allowedCid = $this->createCompletedEwayContribution($allowedProcessorId, 'TXN_ALLOW_01', 100.00, 0.00);
    $otherCid = $this->createCompletedEwayContribution($otherProcessorId, 'TXN_ALLOW_02', 100.00, 0.00);

    $this->allowProcessors($allowedProcessorId);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $this->assertContains($allowedCid, $ids, 'Contribution from an allowlisted processor is a candidate');
    $this->assertNotContains($otherCid, $ids, 'Contribution from a non-allowlisted processor is excluded');
  }

  public function testAllowlistSavedViaApi3SettingCreateIsRecognised(): void {
    // The settings form saves through APIv3 setting.create, not
    // Civi::settings()->set(); the two must agree on the stored format.
    $processorId = $this->createEwayProcessor(FALSE);
    $cid = $this->createCompletedEwayContribution($processorId, 'TXN_API3_01', 100.00, 0.00);

    civicrm_api3('Setting', 'create', ['eway_settlement_processors' => [(string) $processorId]]);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $ids = array_column($sync->getUnreconciledContributions(), 'id');
    $this->assertContains($cid, $ids, 'A processor saved through APIv3 setting.create must be recognised');
  }

  public function testLegacyBookendStringAllowlistIsRecognised(): void {
    // Value as written by earlier builds via APIv3 (type String + array).
    $processorId = $this->createEwayProcessor(FALSE);
    $cid = $this->createCompletedEwayContribution($processorId, 'TXN_LEGACY_01', 100.00, 0.00);

    $sep = CRM_Core_DAO::VALUE_SEPARATOR;
    \Civi::settings()->set('eway_settlement_processors', $sep . $processorId . $sep);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $ids = array_column($sync->getUnreconciledContributions(), 'id');
    $this->assertContains($cid, $ids, 'A legacy separator-delimited allowlist value must still be honoured');
  }

  public function testGetUnreconciledContributionsExcludesTestProcessorEvenWhenAllowlisted(): void {
    $testProcessorId = $this->createEwayProcessor(TRUE);
    $testCid = $this->createCompletedEwayContribution($testProcessorId, 'TXN_TESTP_01', 100.00, 0.00);

    // Force the test processor into the setting, bypassing the option-list
    // filter, to prove the hard is_test guard in the query.
    $this->allowProcessors($testProcessorId);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $this->assertNotContains($testCid, $ids, 'A test processor is never settlement-synced, even if allowlisted');
  }

  public function testGetUnreconciledContributionsReturnsUnreconciledOnly(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $unreconciledId = $this->createCompletedEwayContribution($processorId, 'TXN001', 100.00, 0.00);
    $reconciledId = $this->createCompletedEwayContribution($processorId, 'TXN002', 100.00, 0.55);
    $this->allowProcessors($processorId);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $this->assertContains($unreconciledId, $ids, 'Unreconciled contribution should be returned');
    $this->assertNotContains($reconciledId, $ids, 'Already reconciled contribution should not be returned');
  }

  public function testWindowSettingDefaultsToTen(): void {
    // revert() clears any value set by setUp() / a previous test, exposing the
    // metadata default from settings/eWAYRecurring.setting.php.
    \Civi::settings()->revert('eway_settlement_window_days');
    $this->assertEquals(10, (int) \Civi::settings()->get('eway_settlement_window_days'));
  }

  public function testGetUnreconciledContributionsRespectsLookbackWindow(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $recentId = $this->createCompletedEwayContribution($processorId, 'TXN003', 100.00, 0.00, '-3 days');
    $oldId = $this->createCompletedEwayContribution($processorId, 'TXN004', 100.00, 0.00, '-10 days');

    // Explicitly set lookback to 5 days so the test is not sensitive to the default.
    \Civi::settings()->set('eway_settlement_window_days', 5);
    $this->allowProcessors($processorId);

    try {
      $sync = new CRM_eWAYRecurring_SettlementSync();
      $result = $sync->getUnreconciledContributions();

      $ids = array_column($result, 'id');
      $this->assertContains($recentId, $ids, 'Recent contribution should be returned');
      $this->assertNotContains($oldId, $ids, 'Old contribution should not be returned');
    }
    finally {
      \Civi::settings()->revert('eway_settlement_window_days');
    }
  }

  public function testGetUnreconciledContributionsExcludesNonEwayContributions(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $ewayContributionId = $this->createCompletedEwayContribution($processorId, 'TXN005');
    $this->allowProcessors($processorId);

    // Create a contribution with no payment processor (e.g. cash/cheque).
    $contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Cash')
      ->addValue('last_name', 'Donor')
      ->execute()
      ->first()['id'];
    $cashContributionId = Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', 100.00)
      ->addValue('fee_amount', 0.00)
      ->addValue('net_amount', 100.00)
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('trxn_id', 'TXN006')
      ->addValue('receive_date', date('Y-m-d H:i:s'))
      ->execute()
      ->first()['id'];

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $this->assertContains($ewayContributionId, $ids, 'eWAY contribution should be returned');
    $this->assertNotContains($cashContributionId, $ids, 'Non-eWAY contribution should not be returned');
  }

  public function testGetUnreconciledContributionsDeduplicatesMultipleFinancialTrxn(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, 'TXN007');

    // Add a second FinancialTrxn + EntityFinancialTrxn for the same contribution,
    // simulating e.g. a fee transaction alongside the main payment transaction.
    $this->linkPaymentProcessorToContribution($contributionId, $processorId, 0.50, date('Y-m-d H:i:s'));
    $this->allowProcessors($processorId);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $ids = array_column($result, 'id');
    $occurrences = array_count_values($ids);
    $this->assertContains($contributionId, $ids, 'Contribution should be returned');
    $this->assertEquals(1, $occurrences[$contributionId], 'Contribution should appear exactly once despite multiple EntityFinancialTrxn rows');
  }

  public function testGetUnreconciledContributionsExposesProcessorId(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $cid = $this->createCompletedEwayContribution($processorId, 'TXN_PID_01', 100.00, 0.00);
    $this->allowProcessors($processorId);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions();

    $row = NULL;
    foreach ($result as $r) {
      if ($r['id'] === $cid) {
        $row = $r;
        break;
      }
    }
    $this->assertNotNull($row, 'Created contribution should be in the result set');
    $this->assertEquals($processorId, (int) $row['processor.id'], 'Row should carry its payment processor id');
  }

  public function testGetUnreconciledContributionsScopedToContributionId(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $targetId = $this->createCompletedEwayContribution($processorId, 'TXN_SCOPE_01', 100.00, 0.00);
    $otherId = $this->createCompletedEwayContribution($processorId, 'TXN_SCOPE_02', 100.00, 0.00);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions($targetId);

    $ids = array_column($result, 'id');
    $this->assertContains($targetId, $ids, 'Scoped contribution should be returned');
    $this->assertNotContains($otherId, $ids, 'Other unreconciled contributions should be excluded when scoped');
  }

  public function testGetUnreconciledContributionsScopedRunIgnoresAllowlist(): void {
    // The processor is deliberately NOT in the allowlist; a scoped run must
    // still return the contribution.
    $processorId = $this->createEwayProcessor(FALSE);
    $targetId = $this->createCompletedEwayContribution($processorId, 'TXN_SCOPE_NOALLOW', 100.00, 0.00);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions($targetId);

    $ids = array_column($result, 'id');
    $this->assertContains($targetId, $ids, 'A scoped run is not filtered by the processor allowlist');
  }

  public function testGetUnreconciledContributionsScopedRunStillExcludesTestProcessor(): void {
    $testProcessorId = $this->createEwayProcessor(TRUE);
    $targetId = $this->createCompletedEwayContribution($testProcessorId, 'TXN_SCOPE_TESTP', 100.00, 0.00);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions($targetId);

    $this->assertEmpty($result, 'A scoped run still excludes a contribution taken by a test processor');
  }

  public function testGetUnreconciledContributionsScopedNowRespectsWindow(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    // Older than any sane window.
    $oldId = $this->createCompletedEwayContribution($processorId, 'TXN_SCOPE_03', 100.00, 0.00, '-90 days');

    \Civi::settings()->set('eway_settlement_window_days', 5);
    try {
      $sync = new CRM_eWAYRecurring_SettlementSync();
      $result = $sync->getUnreconciledContributions($oldId);
      $this->assertEmpty($result, 'A scoped run must now exclude a contribution older than the window');
    }
    finally {
      \Civi::settings()->revert('eway_settlement_window_days');
    }
  }

  public function testGetUnreconciledContributionsScopedStillGuardsAgainstReconciled(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $reconciledId = $this->createCompletedEwayContribution($processorId, 'TXN_SCOPE_04', 100.00, 0.55);

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $result = $sync->getUnreconciledContributions($reconciledId);

    $this->assertEmpty($result, 'Scoped run must still skip an already-reconciled contribution');
  }

  // ---------------------------------------------------------------------------
  // Task 5: reconcileContribution()
  // ---------------------------------------------------------------------------

  public function testReconcileContributionSetsFeeAndNetAmount(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, 'TXN010', 100.00);

    $settlementData = [
      'TransactionID' => 12345,
      'FeePerTransaction' => 55,  // 55 cents in eWAY API
      'Amount' => 10000,
    ];

    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('id', 'total_amount')
      ->execute()
      ->first();

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $sync->reconcileContribution($contribution, $settlementData);

    $updated = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount', 'net_amount', 'total_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.55, $updated['fee_amount'], 'fee_amount should be set to FeePerTransaction in dollars');
    $this->assertEquals(99.45, $updated['net_amount'], 'net_amount should be total_amount minus fee_amount');
    $this->assertEquals(100.00, $updated['total_amount'], 'total_amount should not be changed');
  }

  public function testReconcileContributionRoundsToTwoDp(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, 'TXN011', 50.00);

    // 33 cents — deliberately not a clean decimal
    $settlementData = ['TransactionID' => 12346, 'FeePerTransaction' => 33, 'Amount' => 5000];
    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('id', 'total_amount')
      ->execute()
      ->first();

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $sync->reconcileContribution($contribution, $settlementData);

    $updated = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount', 'net_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.33, $updated['fee_amount']);
    $this->assertEquals(49.67, $updated['net_amount']);
  }

  public function testReconcileContributionDoesNotOverwriteFeeSetAfterSelection(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, 'TXN_RACE', 100.00, 0.00);

    // Snapshot as the sync would have, while fee_amount is still 0.
    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('id', 'total_amount')
      ->execute()
      ->first();

    // A manual reconciliation lands between candidate selection and reconcile.
    Contribution::update(FALSE)
      ->addValue('fee_amount', 0.60)
      ->addValue('net_amount', 99.40)
      ->addWhere('id', '=', $contributionId)
      ->execute();

    $sync = new CRM_eWAYRecurring_SettlementSync();
    $sync->reconcileContribution($contribution, ['TransactionID' => 1, 'FeePerTransaction' => 55]);

    $after = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount', 'net_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.60, $after['fee_amount'], 'A fee written after selection must not be overwritten');
    $this->assertEquals(99.40, $after['net_amount']);
  }

  // ---------------------------------------------------------------------------
  // fetchSettlementDay()
  // ---------------------------------------------------------------------------

  public function testFetchSettlementDaySinglePage(): void {
    $transactions = [
      ['TransactionID' => 111, 'FeePerTransaction' => 50, 'Amount' => 1000],
      ['TransactionID' => 222, 'FeePerTransaction' => 75, 'Amount' => 2000],
    ];
    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($transactions),
    ]);
    $processor = ['id' => 1, 'user_name' => 'key', 'password' => 'pass', 'is_test' => FALSE];
    $result = $sync->fetchSettlementDay($processor, '2026-08-15');

    $this->assertCount(2, $result);
    $this->assertEquals(111, $result[0]['TransactionID']);
    $this->assertEquals(222, $result[1]['TransactionID']);
  }

  public function testFetchSettlementDayMultiplePages(): void {
    $fullPage = array_fill(0, CRM_eWAYRecurring_SettlementSync::PAGE_SIZE, ['TransactionID' => 1, 'FeePerTransaction' => 50, 'Amount' => 1000]);
    $lastPage = [['TransactionID' => 999, 'FeePerTransaction' => 30, 'Amount' => 500]];
    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($fullPage),
      $this->makeSettlementResponse($lastPage),
    ]);
    $processor = ['id' => 1, 'user_name' => 'key', 'password' => 'pass', 'is_test' => FALSE];
    $result = $sync->fetchSettlementDay($processor, '2026-08-15');

    $this->assertCount(CRM_eWAYRecurring_SettlementSync::PAGE_SIZE + 1, $result);
  }

  public function testFetchSettlementDayUsesSandboxUrlAndSingleDay(): void {
    $container = [];
    $history = \GuzzleHttp\Middleware::history($container);
    $mock = new MockHandler([$this->makeSettlementResponse([])]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $client = new \GuzzleHttp\Client(['handler' => $handlerStack]);

    $sync = new CRM_eWAYRecurring_SettlementSync($client);
    $processor = ['id' => 1, 'user_name' => 'key', 'password' => 'pass', 'is_test' => TRUE];
    $sync->fetchSettlementDay($processor, '2026-08-15');

    $uri = (string) $container[0]['request']->getUri();
    $this->assertStringStartsWith(CRM_eWAYRecurring_SettlementSync::SETTLEMENT_URL_SANDBOX, $uri);
    $this->assertStringContainsString('StartDate=2026-08-15', $uri);
    $this->assertStringContainsString('EndDate=2026-08-15', $uri);
  }

  public function testFetchSettlementDayThrowsNotReadyWhenReportBuilding(): void {
    $sync = $this->syncWithMockedHttp([$this->makeNotReadyResponse()]);
    $processor = ['id' => 1, 'user_name' => 'key', 'password' => 'pass', 'is_test' => FALSE];

    $this->expectException(CRM_eWAYRecurring_SettlementNotReadyException::class);
    $sync->fetchSettlementDay($processor, '2026-08-15');
  }

  public function testFetchSettlementDayThrowsRuntimeExceptionForOtherErrors(): void {
    $sync = $this->syncWithMockedHttp([$this->makeErrorResponse('Invalid credentials supplied')]);
    $processor = ['id' => 1, 'user_name' => 'key', 'password' => 'pass', 'is_test' => FALSE];

    // Catch explicitly: expectException(\RuntimeException) alone would also be
    // satisfied by CRM_eWAYRecurring_SettlementNotReadyException (a subclass),
    // which would wrongly turn a hard error into a soft per-day skip.
    try {
      $sync->fetchSettlementDay($processor, '2026-08-15');
      $this->fail('Expected a RuntimeException for a non "not ready" API error');
    }
    catch (\RuntimeException $e) {
      $this->assertNotInstanceOf(
        CRM_eWAYRecurring_SettlementNotReadyException::class,
        $e,
        'A generic API error must not be treated as the not-ready marker'
      );
      $this->assertInstanceOf(\RuntimeException::class, $e);
      $this->assertMatchesRegularExpression('/Invalid credentials/', $e->getMessage());
    }
  }

  // ---------------------------------------------------------------------------
  // Task 7: sync()
  // ---------------------------------------------------------------------------

  public function testSyncDoesNothingWhenNoProcessorsConfigured(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $this->createCompletedEwayContribution($processorId, 'NOCFG', 100.00);

    // eway_settlement_processors is unset. Empty mock queue: any HTTP attempt
    // throws "Mock queue is empty".
    $sync = $this->syncWithMockedHttp([]);
    $sync->sync();

    $c = Contribution::get(FALSE)
      ->addWhere('trxn_id', '=', 'NOCFG')
      ->addSelect('fee_amount')
      ->execute()
      ->first();
    $this->assertEquals(0.00, $c['fee_amount'], 'With no processors configured, an unscoped sync makes no API call and reconciles nothing');
  }

  public function testSyncIgnoresContributionsFromNonAllowlistedProcessor(): void {
    $allowedProcessor = $this->createEwayProcessor(FALSE);
    $otherProcessor = $this->createEwayProcessor(FALSE);
    $allowedCid = $this->createCompletedEwayContribution($allowedProcessor, 'ALLOWED', 100.00);
    $otherCid = $this->createCompletedEwayContribution($otherProcessor, 'NOTALLOWED', 100.00);

    $this->allowProcessors($allowedProcessor);

    // Only the allowlisted processor is queried (1 processor x 2 days), so only
    // two responses are consumed even though settlement data would match both.
    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse([
        ['TransactionID' => 'ALLOWED', 'FeePerTransaction' => 55, 'Amount' => 10000],
        ['TransactionID' => 'NOTALLOWED', 'FeePerTransaction' => 77, 'Amount' => 10000],
      ]),
      $this->makeSettlementResponse([]),
    ]);

    $sync->sync();

    $allowed = Contribution::get(FALSE)->addWhere('id', '=', $allowedCid)->addSelect('fee_amount')->execute()->first();
    $other = Contribution::get(FALSE)->addWhere('id', '=', $otherCid)->addSelect('fee_amount')->execute()->first();
    $this->assertEquals(0.55, $allowed['fee_amount'], 'Allowlisted processor contribution is reconciled');
    $this->assertEquals(0.00, $other['fee_amount'], 'Non-allowlisted processor contribution is left untouched');
  }

  public function testSyncReconcileMatchingContributions(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, '11111', 100.00);
    $this->allowProcessors($processorId);

    $settlementTransactions = [
      ['TransactionID' => 11111, 'FeePerTransaction' => 55, 'Amount' => 10000],
      ['TransactionID' => 99999, 'FeePerTransaction' => 30, 'Amount' => 5000],
    ];

    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($settlementTransactions),
      $this->makeSettlementResponse([]),
    ]);

    $sync->sync();

    $updated = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount', 'net_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.55, $updated['fee_amount']);
    $this->assertEquals(99.45, $updated['net_amount']);
  }

  public function testSyncSkipsAlreadyReconciledContributions(): void {
    // The already-reconciled contribution (fee_amount != 0) is now excluded at
    // candidate selection, so sync() returns before any HTTP call and the
    // queued responses go unused. This now verifies exclusion-at-selection,
    // not the matching path - still a valid regression guard.
    $processorId = $this->createEwayProcessor(FALSE);
    $contributionId = $this->createCompletedEwayContribution($processorId, '22222', 100.00, 0.55);
    $this->allowProcessors($processorId);

    $settlementTransactions = [
      ['TransactionID' => 22222, 'FeePerTransaction' => 99, 'Amount' => 10000],
    ];

    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($settlementTransactions),
      $this->makeSettlementResponse([]),
    ]);

    $sync->sync();

    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.55, $contribution['fee_amount']);
  }

  public function testSyncScopedToContributionIdLeavesOthersUntouched(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $targetId = $this->createCompletedEwayContribution($processorId, '44444', 100.00);
    $otherId = $this->createCompletedEwayContribution($processorId, '55555', 100.00);

    // Settlement data is available for BOTH contributions...
    $settlementTransactions = [
      ['TransactionID' => 44444, 'FeePerTransaction' => 55, 'Amount' => 10000],
      ['TransactionID' => 55555, 'FeePerTransaction' => 77, 'Amount' => 10000],
    ];

    $sync = $this->syncWithMockedHttp([
      $this->makeSettlementResponse($settlementTransactions),
      $this->makeSettlementResponse([]),
    ]);

    // ...but the run is scoped to the target only.
    $sync->sync($targetId);

    $target = Contribution::get(FALSE)
      ->addWhere('id', '=', $targetId)
      ->addSelect('fee_amount', 'net_amount')
      ->execute()
      ->first();
    $other = Contribution::get(FALSE)
      ->addWhere('id', '=', $otherId)
      ->addSelect('fee_amount', 'net_amount')
      ->execute()
      ->first();

    $this->assertEquals(0.55, $target['fee_amount'], 'Scoped contribution should be reconciled');
    $this->assertEquals(99.45, $target['net_amount']);
    $this->assertEquals(0.00, $other['fee_amount'], 'Non-scoped contribution must be left untouched');
  }

  public function testSyncDefersNotReadyDayButReconcilesReadyDay(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $cid = $this->createCompletedEwayContribution($processorId, 'RDY', 100.00);
    $this->allowProcessors($processorId);

    // window = 1 => days [today-1, today], iterated oldest first. Day 1
    // (today-1) is still building; day 2 (today) is ready and carries the
    // match. The reconciliation on day 2 is only reachable if the not-ready
    // exception on day 1 was caught and the per-day loop did `continue`
    // instead of aborting the processor - so this proves the per-day skip.
    $sync = $this->syncWithMockedHttp([
      $this->makeNotReadyResponse(),
      $this->makeSettlementResponse([['TransactionID' => 'RDY', 'FeePerTransaction' => 25, 'Amount' => 10000]]),
    ]);

    $sync->sync();

    $updated = Contribution::get(FALSE)
      ->addWhere('id', '=', $cid)
      ->addSelect('fee_amount', 'net_amount')
      ->execute()
      ->first();
    $this->assertEquals(0.25, $updated['fee_amount'], 'Reconciled from the ready day');
    $this->assertEquals(99.75, $updated['net_amount']);
  }

  public function testSyncHardErrorOnOneProcessorDoesNotBlockOthers(): void {
    $processorA = $this->createEwayProcessor(FALSE);
    $processorB = $this->createEwayProcessor(FALSE);
    $cidA = $this->createCompletedEwayContribution($processorA, 'AAA', 100.00);
    $cidB = $this->createCompletedEwayContribution($processorB, 'BBB', 100.00);
    $this->allowProcessors($processorA, $processorB);

    // Processor A is visited first because getProcessorsById() orders by
    // `id ASC` and A was created first (do not remove that ORDER BY): its
    // first day 401s, which aborts A. Processor B: match then empty.
    $sync = $this->syncWithMockedHttp([
      new Response(401, [], 'Unauthorized'),
      $this->makeSettlementResponse([['TransactionID' => 'BBB', 'FeePerTransaction' => 40, 'Amount' => 10000]]),
      $this->makeSettlementResponse([]),
    ]);

    $sync->sync();

    $a = Contribution::get(FALSE)->addWhere('id', '=', $cidA)->addSelect('fee_amount')->execute()->first();
    $b = Contribution::get(FALSE)->addWhere('id', '=', $cidB)->addSelect('fee_amount')->execute()->first();
    $this->assertEquals(0.00, $a['fee_amount'], 'Processor A aborted on 401; contribution untouched');
    $this->assertEquals(0.40, $b['fee_amount'], 'Processor B still processed after A failed');
  }

  public function testTrxnIdCollisionAcrossProcessorsCannotBeCreated(): void {
    // SettlementSync matches contributions by trxn_id, so it could in
    // principle mis-reconcile contribution B using a settlement record
    // meant for contribution A if the two ever shared a trxn_id across
    // different processors. That can't happen: civicrm_contribution.trxn_id
    // carries a DB-level unique index (UI_contrib_trxn_id), so CiviCRM
    // itself refuses to create a second contribution with a trxn_id that
    // already exists, whichever processor it belongs to.
    //
    // This asserts the index rather than provoking the duplicate-key error:
    // a Contribution::create() failure inside CiviCRM's nested transaction
    // marks it for rollback and breaks later tests in the same run.
    $nonUnique = \CRM_Core_DAO::singleValueQuery(
      "SELECT non_unique FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'civicrm_contribution'
         AND index_name = 'UI_contrib_trxn_id'
         AND column_name = 'trxn_id'"
    );
    $this->assertNotNull($nonUnique, 'UI_contrib_trxn_id index exists on civicrm_contribution.trxn_id');
    $this->assertSame(0, (int) $nonUnique, 'trxn_id index is unique, so cross-processor collisions cannot exist');
  }

  public function testSyncScopedOlderThanWindowMakesNoHttpCall(): void {
    $processorId = $this->createEwayProcessor(FALSE);
    $oldId = $this->createCompletedEwayContribution($processorId, 'OLD', 100.00, 0.00, '-90 days');

    // Empty mock queue: any HTTP attempt throws "Mock queue is empty".
    $sync = $this->syncWithMockedHttp([]);
    $sync->sync($oldId);

    $c = Contribution::get(FALSE)->addWhere('id', '=', $oldId)->addSelect('fee_amount')->execute()->first();
    $this->assertEquals(0.00, $c['fee_amount'], 'Contribution older than the window is not reconciled and triggers no API call');
  }

  public function testSyncScopedQueriesOnlyItsOwnProcessor(): void {
    $this->createEwayProcessor(FALSE, 'other-key', 'other-pass');
    $processorTarget = $this->createEwayProcessor(FALSE, 'target-key', 'target-pass');
    $targetCid = $this->createCompletedEwayContribution($processorTarget, 'TGT', 100.00);

    $container = [];
    $history = \GuzzleHttp\Middleware::history($container);
    $mock = new MockHandler([
      $this->makeSettlementResponse([['TransactionID' => 'TGT', 'FeePerTransaction' => 55, 'Amount' => 10000]]),
      $this->makeSettlementResponse([]),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push($history);
    $client = new \GuzzleHttp\Client(['handler' => $stack]);
    $sync = new CRM_eWAYRecurring_SettlementSync($client);

    $sync->sync($targetCid);

    $this->assertCount(2, $container, 'Only the target contribution\'s own processor is queried (1 processor x 2 days)');
    foreach ($container as $txn) {
      $this->assertEquals(
        'Basic ' . base64_encode('target-key:target-pass'),
        $txn['request']->getHeaderLine('Authorization'),
        'Every request authenticates as the target processor'
      );
    }

    // One request per calendar day, oldest first, each querying a single day
    // (StartDate == EndDate). window = 1 => [today-1, today].
    $yesterday = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P1D'))->format('Y-m-d');
    $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

    $uri0 = (string) $container[0]['request']->getUri();
    $this->assertStringContainsString('StartDate=' . $yesterday, $uri0, 'First request starts on today-1');
    $this->assertStringContainsString('EndDate=' . $yesterday, $uri0, 'First request ends on today-1 (single day)');

    $uri1 = (string) $container[1]['request']->getUri();
    $this->assertStringContainsString('StartDate=' . $today, $uri1, 'Second request starts on today');
    $this->assertStringContainsString('EndDate=' . $today, $uri1, 'Second request ends on today (single day)');
  }

}
