import { test, expect } from '../../fixtures/base';
import { evaluate } from '../../fixtures/civi';
import {
  hasSandboxCredentials,
  prepareSandboxSite,
  removeSandboxCredentials,
  SANDBOX_PROCESSOR,
  SKIP_MESSAGE,
} from '../../fixtures/sandbox';

/**
 * PW-X-04: the Settlement Search call, made for real against eWAY's sandbox. The extension leaves test processors out of
 * the settlement sync (the sandbox holds no settlement data), so this does not test the sync. It tests the contract the
 * sync relies on: that eWAY's sandbox answers the search the way CRM_eWAYRecurring_SettlementSync::fetchSettlementDay()
 * expects, either with a list of transactions (empty here: nothing settles in a sandbox) or with the "report not ready"
 * answer, which the class turns into CRM_eWAYRecurring_SettlementNotReadyException. Any other answer from eWAY is a
 * change the sync would treat as a hard failure. Test plan: developer/TEST_PLAN.md section 5.4. The credentials and the
 * stub bypass are explained in fixtures/sandbox.ts. Only the outcome is read back, never the processor row.
 */
interface SearchOutcome {
  day: string;
  outcome: 'rows' | 'not-ready' | 'error';
  count?: number;
  /** The keys of the first row, when there is one. */
  firstRowKeys?: string[];
  /** The exception class and its text, for 'not-ready' and 'error'. The text carries no credentials (they travel in a header). */
  exception?: string;
  message?: string;
}

/** Ask the sandbox for one day's settlement transactions the way the sync does, and report what came back. */
function searchSandbox(daysAgo: number, credentials: 'saved' | 'invalid' = 'saved'): SearchOutcome {
  const out = evaluate(`
    $p = ${credentials === 'invalid'
      ? "['user_name' => 'not-a-real-key', 'password' => 'not-a-real-password', 'is_test' => 1]"
      : `\\Civi\\Api4\\PaymentProcessor::get(FALSE)->addWhere('name', '=', ${JSON.stringify(SANDBOX_PROCESSOR)})->addWhere('is_test', '=', TRUE)->execute()->first()`};
    $day = date('Y-m-d', strtotime('-${Number(daysAgo)} days'));
    $result = ['day' => $day];
    try {
      $rows = (new CRM_eWAYRecurring_SettlementSync())->fetchSettlementDay($p, $day);
      $result += ['outcome' => 'rows', 'count' => count($rows), 'firstRowKeys' => $rows ? array_keys($rows[0]) : []];
    }
    catch (CRM_eWAYRecurring_SettlementNotReadyException $e) {
      $result += ['outcome' => 'not-ready', 'exception' => get_class($e), 'message' => substr($e->getMessage(), 0, 300)];
    }
    catch (\\Throwable $e) {
      $result += ['outcome' => 'error', 'exception' => get_class($e), 'message' => substr($e->getMessage(), 0, 300)];
    }
    echo json_encode($result);
  `);
  return JSON.parse(out.slice(out.indexOf('{"day"'), out.lastIndexOf('}') + 1)) as SearchOutcome;
}

test.describe('settlement search on the real sandbox', { tag: '@sandbox' }, () => {
  test.skip(!hasSandboxCredentials(), SKIP_MESSAGE);

  test.beforeAll(() => {
    if (hasSandboxCredentials()) {
      prepareSandboxSite();
    }
  });

  test.afterAll(() => {
    if (hasSandboxCredentials()) {
      removeSandboxCredentials();
    }
  });

  test('PW-X-04 the sandbox answers a settlement search with an empty list or the not-ready message', async () => {
    test.setTimeout(120_000);
    const outcome = searchSandbox(1);
    test.info().annotations.push({ type: 'observed', description: JSON.stringify(outcome) });
    expect(['rows', 'not-ready']).toContain(outcome.outcome);
    if (outcome.outcome === 'rows') {
      expect(outcome.count).toBe(0);
    } else {
      expect(outcome.message).toMatch(/data will be available/i);
    }
  });

  test('PW-X-04 a settlement search with credentials eWAY does not know is refused with an HTTP error the sync treats as a hard failure', async () => {
    test.setTimeout(120_000);
    // Made-up credentials, never the real ones.
    const outcome = searchSandbox(1, 'invalid');
    test.info().annotations.push({ type: 'observed', description: JSON.stringify(outcome) });
    expect(outcome.outcome).toBe('error');
    expect(outcome.exception).not.toBe('CRM_eWAYRecurring_SettlementNotReadyException');
  });
});
