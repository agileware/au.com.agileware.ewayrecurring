<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * The settlement sync cases that SettlementSyncTest does not cover: the API
 * wrapper, the settings form, bad credentials and a large, paged settlement.
 *
 * Test plan suite PU-M: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_SettlementSyncGapsTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * What the sync wrote to the CiviCRM log.
   *
   * @var array[] Each: ['level', 'message', 'context']
   */
  private $logRecords = [];

  /**
   * @var \Civi\Core\LogManager|null
   */
  private $logManager;

  /**
   * The log channels as they were before captureLog().
   *
   * @var array|null
   */
  private $originalLogChannels;

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
    $this->releaseLog();
    \Civi::settings()->revert('eway_settlement_window_days');
    \Civi::settings()->revert('eway_settlement_processors');
    parent::tearDown();
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Record what is written to Civi::log() instead of sending it to the log
   * file. A PHPUnit mock of the PSR-3 interface is used so that the test does
   * not depend on which version of psr/log is installed.
   */
  private function captureLog(): void {
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
      $logger->method($level)->willReturnCallback(function ($message, $context = []) use ($level) {
        $this->logRecords[] = ['level' => $level, 'message' => (string) $message, 'context' => (array) $context];
      });
    }

    $this->logManager = \Civi::container()->get('psr_log_manager');
    $channels = new ReflectionProperty($this->logManager, 'channels');
    $channels->setAccessible(TRUE);
    $this->originalLogChannels = $channels->getValue($this->logManager);
    $channels->setValue($this->logManager, ['default' => $logger] + $this->originalLogChannels);
    $this->logRecords = [];
  }

  private function releaseLog(): void {
    if ($this->logManager !== NULL) {
      $channels = new ReflectionProperty($this->logManager, 'channels');
      $channels->setAccessible(TRUE);
      $channels->setValue($this->logManager, $this->originalLogChannels);
      $this->logManager = NULL;
      $this->originalLogChannels = NULL;
    }
  }

  /**
   * The captured log records whose message starts with a prefix.
   *
   * @return array[]
   */
  private function logged(string $level, string $messagePrefix): array {
    return array_values(array_filter($this->logRecords, function ($record) use ($level, $messagePrefix) {
      return $record['level'] === $level && strpos($record['message'], $messagePrefix) === 0;
    }));
  }

  /**
   * A Completed eWAY contribution, linked to the processor that took it
   * through a financial transaction, as the sync's query expects.
   */
  private function completedContribution(int $processorId, string $trxnId, float $totalAmount = 100.00, float $feeAmount = 0.00, string $receiveDate = 'now'): int {
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

    $toAccountId = (int) civicrm_api3('FinancialAccount', 'getvalue', [
      'return' => 'id',
      'is_active' => 1,
      'options' => ['limit' => 1, 'sort' => 'id ASC'],
    ]);
    civicrm_api3('FinancialTrxn', 'create', [
      'payment_processor_id' => $processorId,
      'to_financial_account_id' => $toAccountId,
      'trxn_date' => $date,
      'total_amount' => $totalAmount,
      'net_amount' => $totalAmount,
      'fee_amount' => 0,
      'status_id' => 1,
      'payment_instrument_id' => 1,
      'entity_id' => $contributionId,
      'entity_table' => 'civicrm_contribution',
    ]);

    return (int) $contributionId;
  }

  private function feeOf(int $contributionId): float {
    return (float) Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('fee_amount')
      ->execute()
      ->first()['fee_amount'];
  }

  private function netOf(int $contributionId): float {
    return (float) Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addSelect('net_amount')
      ->execute()
      ->first()['net_amount'];
  }

  /**
   * A sync whose HTTP client answers from a queue and records every request.
   *
   * @param Response[] $responses
   * @param array $history
   *   Filled with one entry per request, each with a 'request' key.
   */
  private function syncWithHistory(array $responses, array &$history): CRM_eWAYRecurring_SettlementSync {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(\GuzzleHttp\Middleware::history($history));
    return new CRM_eWAYRecurring_SettlementSync(new \GuzzleHttp\Client(['handler' => $stack]));
  }

  private function settlementResponse(array $transactions): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'SettlementTransactions' => $transactions,
      'Errors' => '',
    ]));
  }

  /**
   * Save the settings form the way a submit does, without rendering it.
   *
   * @param array $submitted
   *   The values the browser posts, keyed by field name.
   */
  private function submitSettingsForm(array $submitted): void {
    $form = new CRM_eWAYRecurring_Form_Settings();
    // The form keeps the posted values in a property it does not declare.
    @$form->_submittedValues = $submitted;
    $form->saveSettings();
  }

  // ---------------------------------------------------------------------------
  // EwaySettlement.Sync API wrapper (PU-M1)
  // ---------------------------------------------------------------------------
  //
  // The API builds its own SettlementSync with a real HTTP client, so these cases
  // use inputs that never reach eWAY: nothing configured, or a contribution that
  // is not eligible. The scoped sync against a mocked client is in SettlementSyncTest.

  /**
   * @testPlan PU-M1
   */
  public function testTheApiWithNoParametersDoesNothingWhenNoProcessorIsConfigured(): void {
    $processor = Fixture::processor();
    $contributionId = $this->completedContribution($processor['id'], 'M1_NOPARAMS');
    $this->captureLog();

    $result = civicrm_api3('EwaySettlement', 'Sync', []);

    $this->assertEquals(0, $result['is_error']);
    $this->assertSame([], $result['values']);
    $this->assertSame(0.0, $this->feeOf($contributionId), 'Nothing was reconciled');
    $this->assertCount(1, $this->logged('info', 'eWAY Settlement Sync: no payment processors configured'));
  }

  /**
   * @testPlan PU-M1
   */
  public function testTheApiPassesAContributionIdThroughToTheSync(): void {
    // Not eligible (already has a fee), so the sync stops before any request. It says which id it was given.
    $processor = Fixture::processor();
    $contributionId = $this->completedContribution($processor['id'], 'M1_SCOPED', 100.00, 0.55);
    $this->captureLog();

    $result = civicrm_api3('EwaySettlement', 'Sync', ['contribution_id' => $contributionId]);

    $this->assertEquals(0, $result['is_error']);
    $notEligible = $this->logged('info', 'eWAY Settlement Sync: contribution {id} not eligible');
    $this->assertCount(1, $notEligible);
    $this->assertEquals($contributionId, $notEligible[0]['context']['id']);
    $this->assertCount(0, $this->logged('info', 'eWAY Settlement Sync: no payment processors configured'), 'It was a scoped run, not an unscoped one');
    $this->assertSame(0.55, $this->feeOf($contributionId), 'The existing fee is untouched');
  }

  /**
   * @testPlan PU-M1
   */
  public function testTheApiWithAnIdThatDoesNotExistSucceedsAndReconcilesNothing(): void {
    $processor = Fixture::processor();
    $contributionId = $this->completedContribution($processor['id'], 'M1_BADID');
    $missingId = $contributionId + 100000;
    $this->captureLog();

    $result = civicrm_api3('EwaySettlement', 'Sync', ['contribution_id' => $missingId]);

    $this->assertEquals(0, $result['is_error']);
    $notEligible = $this->logged('info', 'eWAY Settlement Sync: contribution {id} not eligible');
    $this->assertCount(1, $notEligible);
    $this->assertEquals($missingId, $notEligible[0]['context']['id']);
    $this->assertSame(0.0, $this->feeOf($contributionId), 'Other contributions are not touched');
  }

  /**
   * An id of 0 is not an id: the API treats it as no id, so the run is unscoped.
   *
   * @testPlan PU-M1
   */
  public function testTheApiTreatsAContributionIdOfZeroAsNoScope(): void {
    $this->captureLog();

    $result = civicrm_api3('EwaySettlement', 'Sync', ['contribution_id' => 0]);

    $this->assertEquals(0, $result['is_error']);
    $this->assertCount(1, $this->logged('info', 'eWAY Settlement Sync: no payment processors configured'));
    $this->assertCount(0, $this->logged('info', 'eWAY Settlement Sync: contribution {id} not eligible'));
  }

  /**
   * A scoped call through the API that does reach eWAY, with the client the API builds replaced by a
   * fake through the factory seam (DEF-32).
   *
   * @testPlan PU-M1
   */
  public function testTheApiReconcilesAnEligibleContributionThroughTheInjectedClient(): void {
    $processor = Fixture::processor(FALSE, ['user_name' => 'm1-key', 'password' => 'm1-pass']);
    $contributionId = $this->completedContribution($processor['id'], 'M1_ELIGIBLE', 100.00, 0.00, '-1 day');
    $this->submitSettingsForm([
      'eway_settlement_processors' => [(string) $processor['id']],
      'eway_settlement_window_days' => '1',
    ]);

    $history = [];
    $responses = [
      $this->settlementResponse([['TransactionID' => 'M1_ELIGIBLE', 'FeePerTransaction' => 75, 'Amount' => 10000]]),
      $this->settlementResponse([]),
      $this->settlementResponse([]),
      $this->settlementResponse([]),
    ];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(\GuzzleHttp\Middleware::history($history));
    CRM_eWAYRecurring_SettlementSync::setHttpClientFactory(function () use ($stack) {
      return new \GuzzleHttp\Client(['handler' => $stack]);
    });

    try {
      $result = civicrm_api3('EwaySettlement', 'Sync', ['contribution_id' => $contributionId]);
    }
    finally {
      CRM_eWAYRecurring_SettlementSync::setHttpClientFactory(NULL);
    }

    $this->assertEquals(0, $result['is_error']);
    $this->assertNotEmpty($history, 'The API used the injected client, so nothing reached eWAY.');
    foreach ($history as $entry) {
      $this->assertSame('Basic ' . base64_encode('m1-key:m1-pass'), $entry['request']->getHeaderLine('Authorization'));
    }
    $this->assertSame(0.75, $this->feeOf($contributionId));
    $this->assertSame(99.25, $this->netOf($contributionId));
  }

  // ---------------------------------------------------------------------------
  // The settings form drives the sync (PU-M2)
  // ---------------------------------------------------------------------------

  /**
   * The form posts the processor ids as strings and the window as text. What it
   * saves must be what the sync reads: the allowlist selects the processor, and
   * the window decides both which contributions are candidates and how many days
   * are asked of eWAY.
   *
   * @testPlan PU-M2
   */
  public function testTheSyncRespectsTheSettingsSavedByTheForm(): void {
    $allowed = Fixture::processor(FALSE, ['user_name' => 'm2-allowed-key', 'password' => 'm2-allowed-pass']);
    $other = Fixture::processor(FALSE, ['user_name' => 'm2-other-key', 'password' => 'm2-other-pass']);
    $inside = $this->completedContribution($allowed['id'], 'M2_INSIDE', 100.00, 0.00, '-3 days');
    $outside = $this->completedContribution($allowed['id'], 'M2_OUTSIDE', 100.00, 0.00, '-8 days');
    $notAllowed = $this->completedContribution($other['id'], 'M2_OTHER', 100.00, 0.00, 'now');

    $this->submitSettingsForm([
      'eway_settlement_processors' => [(string) $allowed['id']],
      'eway_settlement_window_days' => '5',
    ]);

    $this->assertSame([(int) $allowed['id']], CRM_eWAYRecurring_SettlementSync::normaliseProcessorIds(\Civi::settings()->get('eway_settlement_processors')));
    $this->assertEquals(5, \Civi::settings()->get('eway_settlement_window_days'));

    // Window 5 means six calendar days (today minus 5 up to today), so six requests. The first day has
    // a row for every contribution, but only the one that is a candidate may be reconciled.
    $history = [];
    $responses = [
      $this->settlementResponse([
        ['TransactionID' => 'M2_INSIDE', 'FeePerTransaction' => 50, 'Amount' => 10000],
        ['TransactionID' => 'M2_OUTSIDE', 'FeePerTransaction' => 60, 'Amount' => 10000],
        ['TransactionID' => 'M2_OTHER', 'FeePerTransaction' => 70, 'Amount' => 10000],
      ]),
    ];
    for ($i = 1; $i < 6; $i++) {
      $responses[] = $this->settlementResponse([]);
    }
    $sync = $this->syncWithHistory($responses, $history);

    $sync->sync();

    $this->assertCount(6, $history, 'One request per day of the saved window');
    foreach ($history as $entry) {
      $this->assertSame(
        'Basic ' . base64_encode('m2-allowed-key:m2-allowed-pass'),
        $entry['request']->getHeaderLine('Authorization'),
        'Only the processor chosen on the form is queried'
      );
    }
    $this->assertSame(0.50, $this->feeOf($inside), 'The contribution inside the window is reconciled');
    $this->assertSame(99.50, $this->netOf($inside));
    $this->assertSame(0.0, $this->feeOf($outside), 'A contribution older than the saved window is left alone');
    $this->assertSame(0.0, $this->feeOf($notAllowed), 'A processor that was not chosen is left alone');
  }

  /**
   * A multi-select posts nothing when every option is deselected. The form must
   * save that as an empty list, so that the sync then does nothing.
   *
   * @testPlan PU-M2
   */
  public function testDeselectingEveryProcessorOnTheFormStopsTheSync(): void {
    $processor = Fixture::processor();
    $contributionId = $this->completedContribution($processor['id'], 'M2_DESELECT');

    $this->submitSettingsForm(['eway_settlement_processors' => [(string) $processor['id']]]);
    $this->assertSame([(int) $processor['id']], CRM_eWAYRecurring_SettlementSync::normaliseProcessorIds(\Civi::settings()->get('eway_settlement_processors')));

    $this->submitSettingsForm([]);

    $this->assertSame([], CRM_eWAYRecurring_SettlementSync::normaliseProcessorIds(\Civi::settings()->get('eway_settlement_processors')));
    $history = [];
    $sync = $this->syncWithHistory([], $history);
    $sync->sync();
    $this->assertCount(0, $history, 'With nothing selected the sync makes no request');
    $this->assertSame(0.0, $this->feeOf($contributionId));
  }

  // ---------------------------------------------------------------------------
  // Bad credentials (PU-M3)
  // ---------------------------------------------------------------------------

  /**
   * eWAY answers 401 for a processor whose key or password is wrong. The sync
   * logs a warning naming that processor, gives up on it, and carries on with
   * the next one.
   *
   * @testPlan PU-M3
   */
  public function testABadCredentialResponseIsLoggedAndTheSyncContinuesWithTheNextProcessor(): void {
    $bad = Fixture::processor(FALSE, ['user_name' => 'm3-bad-key', 'password' => 'm3-bad-pass']);
    $good = Fixture::processor(FALSE, ['user_name' => 'm3-good-key', 'password' => 'm3-good-pass']);
    $badContribution = $this->completedContribution($bad['id'], 'M3_BAD');
    $goodContribution = $this->completedContribution($good['id'], 'M3_GOOD');
    \Civi::settings()->set('eway_settlement_processors', [(int) $bad['id'], (int) $good['id']]);
    $this->captureLog();

    // The processors are visited in id order, so the bad one is first. Its first day is refused, which
    // ends its turn. The good one gets two days: a match, then nothing.
    $history = [];
    $sync = $this->syncWithHistory([
      new Response(401, [], 'Unauthorized'),
      $this->settlementResponse([['TransactionID' => 'M3_GOOD', 'FeePerTransaction' => 40, 'Amount' => 10000]]),
      $this->settlementResponse([]),
    ], $history);

    $sync->sync();

    $warnings = $this->logged('warning', 'eWAY Settlement Sync failed for processor');
    $this->assertCount(1, $warnings, 'The failure is logged once');
    $this->assertEquals($bad['id'], $warnings[0]['context']['id'], 'The warning names the processor that failed');
    $this->assertStringContainsString('401', $warnings[0]['context']['msg']);

    $this->assertCount(3, $history, 'One refused request, then two for the next processor');
    $this->assertSame('Basic ' . base64_encode('m3-bad-key:m3-bad-pass'), $history[0]['request']->getHeaderLine('Authorization'));
    $this->assertSame('Basic ' . base64_encode('m3-good-key:m3-good-pass'), $history[1]['request']->getHeaderLine('Authorization'));

    $this->assertSame(0.0, $this->feeOf($badContribution), 'Nothing was reconciled for the refused processor');
    $this->assertSame(0.40, $this->feeOf($goodContribution), 'The next processor was still processed');

    $complete = $this->logged('info', 'eWAY Settlement Sync complete');
    $this->assertCount(1, $complete);
    $this->assertEquals(1, $complete[0]['context']['reconciled']);
    $this->assertEquals(1, $complete[0]['context']['unmatched'], 'The refused processor\'s contribution is counted as unmatched');
    $this->assertEquals(2, $complete[0]['context']['queried'], 'Only the good processor\'s two days were read');
  }

  // ---------------------------------------------------------------------------
  // A large, paged settlement (PU-M4)
  // ---------------------------------------------------------------------------

  /**
   * One day of settlement runs over three pages (two full pages and a short
   * one), the next day has one page. Contributions are matched on every page,
   * on the second day, and some are not matched at all. The page that has a
   * row without a fee leaves that contribution alone.
   *
   * @testPlan PU-M4
   */
  public function testALargeSettlementAcrossPagesReconcilesTheMatchesAndLeavesTheRest(): void {
    $processor = Fixture::processor();
    $onPageOne = $this->completedContribution($processor['id'], 'M4_PAGE1', 100.00);
    $onPageTwo = $this->completedContribution($processor['id'], 'M4_PAGE2', 100.00);
    $onPageThree = $this->completedContribution($processor['id'], 'M4_PAGE3', 100.00);
    $onSecondDay = $this->completedContribution($processor['id'], 'M4_DAY2', 100.00);
    $notSettled = $this->completedContribution($processor['id'], 'M4_NOTSETTLED_A', 100.00);
    $alsoNotSettled = $this->completedContribution($processor['id'], 'M4_NOTSETTLED_B', 100.00);
    $noFee = $this->completedContribution($processor['id'], 'M4_NOFEE', 100.00);
    \Civi::settings()->set('eway_settlement_processors', [(int) $processor['id']]);
    $this->captureLog();

    $pageSize = CRM_eWAYRecurring_SettlementSync::PAGE_SIZE;
    $filler = function (int $count, int $from) {
      $rows = [];
      for ($i = 0; $i < $count; $i++) {
        // Ids that belong to no contribution in this database.
        $rows[] = ['TransactionID' => 'M4_OTHER_' . ($from + $i), 'FeePerTransaction' => 10, 'Amount' => 1000];
      }
      return $rows;
    };

    $pageOneRows = $filler($pageSize, 0);
    $pageOneRows[5] = ['TransactionID' => 'M4_PAGE1', 'FeePerTransaction' => 55, 'Amount' => 10000];
    $pageTwoRows = $filler($pageSize, 1000);
    $pageTwoRows[7] = ['TransactionID' => 'M4_PAGE2', 'FeePerTransaction' => 66, 'Amount' => 10000];
    $pageTwoRows[100] = ['TransactionID' => 'M4_NOFEE', 'Amount' => 10000];
    $pageThreeRows = $filler(50, 2000);
    $pageThreeRows[3] = ['TransactionID' => 'M4_PAGE3', 'FeePerTransaction' => 77, 'Amount' => 10000];

    $history = [];
    $sync = $this->syncWithHistory([
      $this->settlementResponse($pageOneRows),
      $this->settlementResponse($pageTwoRows),
      $this->settlementResponse($pageThreeRows),
      $this->settlementResponse([
        ['TransactionID' => 'M4_DAY2', 'FeePerTransaction' => 88, 'Amount' => 10000],
        ['TransactionID' => 'M4_NOT_IN_CIVICRM', 'FeePerTransaction' => 99, 'Amount' => 10000],
      ]),
    ], $history);

    $sync->sync();

    $this->assertSame(0.55, $this->feeOf($onPageOne));
    $this->assertSame(99.45, $this->netOf($onPageOne));
    $this->assertSame(0.66, $this->feeOf($onPageTwo));
    $this->assertSame(0.77, $this->feeOf($onPageThree));
    $this->assertSame(0.88, $this->feeOf($onSecondDay));
    $this->assertSame(0.0, $this->feeOf($notSettled), 'No settlement row, so nothing is reconciled');
    $this->assertSame(0.0, $this->feeOf($alsoNotSettled));
    $this->assertSame(0.0, $this->feeOf($noFee), 'A row with no fee is skipped, not reconciled as free');
    $this->assertSame(100.0, $this->netOf($noFee));

    // Pages are followed in order for the first day, and the short page ends it.
    $this->assertCount(4, $history);
    $asked = array_map(function ($entry) {
      parse_str($entry['request']->getUri()->getQuery(), $query);
      return [$query['StartDate'], (int) $query['Page']];
    }, $history);
    $yesterday = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P1D'))->format('Y-m-d');
    $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
    $this->assertSame([[$yesterday, 1], [$yesterday, 2], [$yesterday, 3], [$today, 1]], $asked);

    $complete = $this->logged('info', 'eWAY Settlement Sync complete');
    $this->assertCount(1, $complete);
    $this->assertEquals(4, $complete[0]['context']['reconciled']);
    $this->assertEquals(3, $complete[0]['context']['unmatched']);
    $this->assertEquals(2, $complete[0]['context']['queried']);
    $this->assertEquals(0, $complete[0]['context']['deferred']);
  }

}
