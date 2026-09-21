<?php

use Civi\Api4\Contribution;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorType;

/**
 * Reconciles eWAY settlement data against CiviCRM contribution records.
 *
 * Queries the eWAY Settlement Search API for recent settlement transactions
 * and updates fee_amount and net_amount on matching Completed contributions.
 */
class CRM_eWAYRecurring_SettlementSync {

  const SETTLEMENT_URL_PRODUCTION = 'https://api.ewaypayments.com/Search/Settlement';
  const SETTLEMENT_URL_SANDBOX = 'https://api.sandbox.ewaypayments.com/Search/Settlement';
  const PAGE_SIZE = 200;

  // Settlement window = single config drives both the contribution candidacy query and the API date range

  /**
   * @var \GuzzleHttp\Client
   */
  private \GuzzleHttp\Client $httpClient;

  public function __construct(?\GuzzleHttp\Client $httpClient = NULL) {
    $this->httpClient = $httpClient ?? new \GuzzleHttp\Client();
  }

  /**
   * Options for the eway_settlement_processors setting: every active,
   * non-test eWAY payment processor as id => label. Used as the
   * pseudoconstant callback for the setting so the settings form renders a
   * multi-select and API / drush consumers get a documented option list.
   *
   * Test processors are never settlement-synced (the eWAY sandbox does not
   * return settlement data) so they are deliberately not offered.
   *
   * @return array<int, string> Processor id => label pairs.
   */
  public static function getSettlementProcessorOptions(): array {
    $processors = PaymentProcessor::get(FALSE)
      ->addSelect('id', 'title', 'name')
      ->addWhere('payment_processor_type_id:name', '=', 'eWay_Recurring')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', '=', FALSE)
      ->addOrderBy('title', 'ASC')
      ->execute();

    $options = [];
    foreach ($processors as $processor) {
      $options[(int) $processor['id']] = $processor['title']
        ?: ($processor['name'] ?: ('Payment Processor ' . $processor['id']));
    }
    return $options;
  }

  /**
   * The configured settlement-sync processor allowlist, as a list of integer
   * payment processor ids. Empty when the setting is unset or empty.
   *
   * @return int[]
   */
  private function getProcessorAllowlist(): array {
    return self::normaliseProcessorIds(Civi::settings()->get('eway_settlement_processors'));
  }

  /**
   * Normalise a stored eway_settlement_processors value to a list of ints.
   *
   * Accepts an array, or the legacy separator-delimited string that earlier
   * builds wrote via APIv3 (type String + array => "\x01 12 \x01 15 \x01").
   *
   * @param mixed $raw
   * @return int[]
   */
  public static function normaliseProcessorIds($raw): array {
    if (is_string($raw)) {
      $raw = explode(CRM_Core_DAO::VALUE_SEPARATOR, $raw);
    }
    elseif (!is_array($raw)) {
      $raw = [];
    }
    return array_values(array_filter(array_map('intval', $raw)));
  }

  /**
   * Returns all active, non-test eWAY payment processors.
   *
   * Retained as a helper for console / API consumers; the sync itself derives
   * the processors it queries from the candidate contributions.
   *
   * @return array Array of processor records with id, user_name, password, is_test.
   */
  public function getEwayProcessors(): array {
    return PaymentProcessor::get(FALSE)
      ->addSelect('id', 'user_name', 'password', 'is_test')
      ->addWhere('payment_processor_type_id:name', '=', 'eWay_Recurring')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', '=', FALSE)
      ->addOrderBy('id', 'ASC')
      ->execute()
      ->getArrayCopy();
  }

  /**
   * Returns all Completed, non-test eWAY contributions that have not been
   * reconciled (fee_amount = 0) within the settlement window.
   *
   * The join path follows the CiviCRM financial data model:
   *   Contribution → EntityFinancialTrxn (bridge) → FinancialTrxn → PaymentProcessor → PaymentProcessorType
   *
   * addGroupBy('id') collapses duplicate rows when a contribution has multiple
   * linked financial transactions.
   *
   * Unscoped runs are restricted to the payment processors named in the
   * eway_settlement_processors setting (the allowlist). An empty allowlist
   * means no processor is configured for settlement sync, so no contribution
   * is a candidate and an empty array is returned.
   *
   * When $contributionId is given the query is scoped to that single
   * contribution: the explicit id is the scope and the processor allowlist is
   * skipped, but the window guard (receive_date >= today - window) and the
   * safety guards (Completed, eWAY, non-test, fee_amount = 0, non-empty
   * trxn_id) all still apply. A contribution older than the window, or taken
   * by a test processor, returns no rows.
   *
   * Each row carries 'processor.id' — the payment processor that took the
   * contribution — so the caller can match it only against that processor's
   * settlement data.
   *
   * @param int|null $contributionId Optional. Restrict to a single contribution.
   * @return array Array of contribution records with id, trxn_id, total_amount, receive_date, processor.id.
   */
  public function getUnreconciledContributions(?int $contributionId = NULL): array {
    $query = Contribution::get(FALSE)
      ->addSelect('id', 'trxn_id', 'total_amount', 'receive_date', 'processor.id')
      ->addJoin('FinancialTrxn AS ft', 'INNER', 'EntityFinancialTrxn')
      ->addJoin('PaymentProcessor AS processor', 'INNER', ['processor.id', '=', 'ft.payment_processor_id'])
      ->addJoin('PaymentProcessorType AS processor_type', 'INNER', ['processor_type.id', '=', 'processor.payment_processor_type_id'])
      ->addWhere('processor_type.name', '=', 'eWay_Recurring')
      ->addWhere('processor.is_active', '=', TRUE)
      ->addWhere('processor.is_test', '=', FALSE)
      ->addWhere('contribution_status_id', '=', 1)
      ->addWhere('fee_amount', '=', 0)
      ->addWhere('trxn_id', 'IS NOT NULL')
      ->addWhere('trxn_id', 'IS NOT EMPTY')
      ->addGroupBy('id')
      ->addGroupBy('processor.id')
      ->addOrderBy('id', 'ASC');

    $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . $this->getWindowDays() . ' days'));
    // Note: cutoff uses a full datetime while the eWAY Settlement API uses
    // calendar dates (Y-m-d). Both derive from the same window value, so
    // edge-of-day contributions are consistently included on both sides.
    $query->addWhere('receive_date', '>=', $cutoffDate);

    if ($contributionId !== NULL) {
      // The explicit id is the scope; the window guard above still applies so a
      // scoped run can never reconcile something older than the unscoped run
      // would. The processor allowlist is intentionally not applied here.
      $query->addWhere('id', '=', $contributionId);
    }
    else {
      $allowlist = $this->getProcessorAllowlist();
      if (empty($allowlist)) {
        // No processors configured for settlement sync: nothing is a candidate.
        return [];
      }
      $query->addWhere('processor.id', 'IN', $allowlist);
    }

    return $query->execute()->getArrayCopy();
  }

  /**
   * Settlement sync window size in days, back from today. Bounds both the
   * unreconciled-contribution candidacy query and the per-day settlement
   * report iteration. Falls back to 10 (the setting metadata default) if the
   * setting is unset or zero, and is lower-bounded to 1 so a negative or zero
   * value written via the API cannot blank the candidacy window or produce a
   * broken strtotime('--3 days') expression.
   */
  private function getWindowDays(): int {
    return max(1, (int) Civi::settings()->get('eway_settlement_window_days') ?: 10);
  }

  /**
   * Updates fee_amount and net_amount on a contribution from eWAY settlement data.
   *
   * @param array $contribution Contribution record with id and total_amount.
   * @param array $settlementData Settlement transaction from eWAY API.
   *   Must include FeePerTransaction (integer, in cents). Caller should skip if missing.
   */
  public function reconcileContribution(array $contribution, array $settlementData): void {
    $feeAmount = round($settlementData['FeePerTransaction'] / 100, 2);
    $netAmount = round((float) $contribution['total_amount'] - $feeAmount, 2);

    $result = Contribution::update(FALSE)
      ->addValue('fee_amount', $feeAmount)
      ->addValue('net_amount', $netAmount)
      ->addWhere('id', '=', $contribution['id'])
      // Re-assert the unreconciled predicate: if a manual reconciliation wrote
      // fee_amount between candidate selection and now, this update matches
      // nothing and the existing data is preserved. This narrows the race
      // window but does not close it: APIv4 Update resolves matching ids and
      // then writes via the BAO, and the emitted UPDATE statement does not
      // carry this predicate, so a write landing between the internal select
      // and the write can still clobber.
      ->addWhere('fee_amount', '=', 0)
      ->execute();

    if (count($result) === 0) {
      Civi::log()->debug(
        'eWAY Settlement Sync: contribution {id} already reconciled elsewhere; skipped',
        ['id' => $contribution['id']]
      );
    }
  }

  /**
   * Fetches all settlement transactions for a processor for a single calendar
   * day, following pagination. The day is queried as StartDate == EndDate so
   * the request is byte-for-byte identical on every run and eWAY's
   * server-side report cache is reused.
   *
   * @param array $processor Processor record with user_name, password, is_test.
   * @param string $day Calendar day, 'Y-m-d'.
   * @return array Flat array of settlement transaction records.
   * @throws \CRM_eWAYRecurring_SettlementNotReadyException if eWAY has not
   *   built the report for this date range yet.
   * @throws \RuntimeException on any other eWAY API-level error.
   */
  public function fetchSettlementDay(array $processor, string $day): array {
    $all = [];
    $page = 1;

    do {
      $response = $this->fetchSettlementPage($processor, $day, $page);
      $transactions = $response['SettlementTransactions'] ?? [];
      $all = array_merge($all, $transactions);
      $page++;
      // Hard ceiling: a misbehaving API that ignores the Page parameter would
      // otherwise loop forever returning a full page. 100 pages x PAGE_SIZE
      // (200) = 20,000 rows/day, far beyond any real settlement volume.
    } while (count($transactions) >= self::PAGE_SIZE && $page <= 100);

    return $all;
  }

  /**
   * Fetches a single page of settlement data for one calendar day.
   *
   * NOTE: exact parameter names / ReportMode are not yet confirmed against
   * https://eway.io/api-v3/#settlement-search — see the follow-up spike in the
   * design doc. Per-day iteration is correct under either the transaction-date
   * or settlement-date interpretation.
   *
   * @param array $processor
   * @param string $day Calendar day, 'Y-m-d', used for both StartDate and EndDate.
   * @param int $page 1-indexed page number.
   * @return array Decoded response body.
   * @throws \CRM_eWAYRecurring_SettlementNotReadyException on eWAY's async-build response.
   * @throws \RuntimeException on any other non-empty Errors field.
   */
  private function fetchSettlementPage(array $processor, string $day, int $page): array {
    $baseUrl = $processor['is_test']
      ? self::SETTLEMENT_URL_SANDBOX
      : self::SETTLEMENT_URL_PRODUCTION;

    $response = $this->httpClient->get($baseUrl, [
      'auth' => [$processor['user_name'], $processor['password']],
      'query' => [
        'ReportMode' => 'TransactionOnly',
        'StartDate' => $day,
        'EndDate' => $day,
        'Page' => $page,
        'PageSize' => self::PAGE_SIZE,
      ],
    ]);

    $body = json_decode($response->getBody()->getContents(), TRUE) ?? [];
    if (!empty($body['Errors'])) {
      // eWAY may return Errors as a string or as an array of strings. Normalise
      // to a string first: an array payload would otherwise trigger an "Array to
      // string conversion" notice and, worse, silently miss the not-ready
      // marker below - escalating a soft skip to a hard processor abort.
      $errors = is_array($body['Errors'])
        ? implode(', ', $body['Errors'])
        : (string) $body['Errors'];
      // eWAY builds each (StartDate, EndDate) report asynchronously; the first
      // request for a range returns this marker and data follows ~60 min later.
      // Pre-enablement / genuinely-unavailable ranges surface either this
      // marker or an empty result; both are safe to skip. Any other error text
      // is treated as a hard failure.
      if (stripos($errors, 'data will be available') !== FALSE) {
        throw new CRM_eWAYRecurring_SettlementNotReadyException(
          'eWAY settlement report not ready for ' . $day . ': ' . $errors
        );
      }
      throw new \RuntimeException('eWAY Settlement API error: ' . $errors);
    }
    return $body;
  }

  /**
   * Main entry point. Fetches all unreconciled eWAY contributions once, groups
   * them by the payment processor that took each one, then for each such
   * processor iterates the settlement window one calendar day at a time and
   * reconciles matches from that processor's settlement data only.
   *
   * Each day is queried as StartDate == EndDate, so the request is identical
   * on every run and eWAY's server-side report cache is reused. A day whose
   * report eWAY has not built yet is logged and skipped; the next scheduled
   * run re-issues the identical query. Which processors are covered is set by
   * the eway_settlement_processors allowlist via the contribution query; an
   * empty allowlist means an unscoped run does nothing.
   *
   * @param int|null $contributionId Optional. Restrict the run to a single
   *   contribution (manual / QA). The contribution must still satisfy every
   *   guard and fall within eway_settlement_window_days; only that
   *   contribution's own processor is queried, and the allowlist is not
   *   consulted.
   */
  public function sync(?int $contributionId = NULL): void {
    if ($contributionId === NULL && empty($this->getProcessorAllowlist())) {
      Civi::log()->info(
        'eWAY Settlement Sync: no payment processors configured for settlement sync (eway_settlement_processors); nothing to do'
      );
      return;
    }

    $contributions = $this->getUnreconciledContributions($contributionId);
    if (empty($contributions)) {
      if ($contributionId !== NULL) {
        Civi::log()->info(
          'eWAY Settlement Sync: contribution {id} not eligible (not an unreconciled Completed eWAY contribution within the settlement window)',
          ['id' => $contributionId]
        );
      }
      return;
    }

    // Group candidates by their own payment processor: a contribution is only
    // ever matched against settlement data from the processor that took it.
    $mapByProcessor = [];
    foreach ($contributions as $contribution) {
      $processorId = (int) $contribution['processor.id'];
      $mapByProcessor[$processorId][(string) $contribution['trxn_id']] = $contribution;
    }

    $processors = $this->getProcessorsById(array_keys($mapByProcessor));
    $days = $this->settlementWindowDays();

    // A candidate carries the processor that took it; if that processor is not
    // in the loaded set (deactivated, deleted, or filtered out) its
    // contributions would be skipped silently. Surface that.
    $loadedProcessorIds = array_map('intval', array_column($processors, 'id'));
    $missingProcessorIds = array_diff(
      array_map('intval', array_keys($mapByProcessor)),
      $loadedProcessorIds
    );
    if (!empty($missingProcessorIds)) {
      Civi::log()->warning(
        'eWAY Settlement Sync: {count} candidate processor(s) not found / not loaded: {ids}',
        [
          'count' => count($missingProcessorIds),
          'ids' => implode(', ', $missingProcessorIds),
        ]
      );
    }

    $reconciled = 0;
    $deferred = 0;
    $queried = 0;
    $unmatched = 0;

    foreach ($processors as $processor) {
      $processorId = (int) $processor['id'];
      $map = $mapByProcessor[$processorId] ?? [];

      try {
        foreach ($days as $day) {
          try {
            $rows = $this->fetchSettlementDay($processor, $day);
          }
          catch (CRM_eWAYRecurring_SettlementNotReadyException $e) {
            Civi::log()->info(
              'eWAY Settlement Sync: report for {date} (processor {id}) not built yet; next run will retry',
              ['date' => $day, 'id' => $processorId]
            );
            $deferred++;
            continue;
          }

          $queried++;
          foreach ($rows as $txn) {
            $trxnId = (string) ($txn['TransactionID'] ?? '');
            if ($trxnId !== '' && isset($map[$trxnId]) && isset($txn['FeePerTransaction'])) {
              $this->reconcileContribution($map[$trxnId], $txn);
              unset($map[$trxnId]);
              $reconciled++;
            }
          }
        }
      }
      catch (\Exception $e) {
        Civi::log()->warning('eWAY Settlement Sync failed for processor {id}: {msg}', [
          'id' => $processorId,
          'msg' => $e->getMessage(),
          'exception' => $e,
        ]);
      }

      $unmatched += count($map);
    }

    Civi::log()->info(
      'eWAY Settlement Sync complete: {processors} processor(s), {queried} day(s) queried, {deferred} deferred, {reconciled} reconciled, {unmatched} unmatched',
      [
        'processors' => count($processors),
        'queried' => $queried,
        'deferred' => $deferred,
        'reconciled' => $reconciled,
        'unmatched' => $unmatched,
      ]
    );
  }

  /**
   * Ordered list of calendar days (oldest first) in the settlement window:
   * today - getWindowDays() .. today, inclusive, as 'Y-m-d' strings.
   *
   * @return string[]
   */
  private function settlementWindowDays(): array {
    $today = new \DateTimeImmutable('today');
    $days = [];
    for ($i = $this->getWindowDays(); $i >= 0; $i--) {
      $days[] = $today->sub(new \DateInterval('P' . $i . 'D'))->format('Y-m-d');
    }
    return $days;
  }

  /**
   * Payment processor credential records for the given ids.
   *
   * @param int[] $ids
   * @return array
   */
  private function getProcessorsById(array $ids): array {
    if (empty($ids)) {
      return [];
    }
    return PaymentProcessor::get(FALSE)
      ->addSelect('id', 'user_name', 'password', 'is_test')
      ->addWhere('id', 'IN', $ids)
      // Test processors are never settlement-synced; this also makes the
      // is_test predicate explicit so GetActionDefaultsProvider does not
      // inject its own.
      ->addWhere('is_test', '=', FALSE)
      ->addOrderBy('id', 'ASC')
      ->execute()
      ->getArrayCopy();
  }

}
