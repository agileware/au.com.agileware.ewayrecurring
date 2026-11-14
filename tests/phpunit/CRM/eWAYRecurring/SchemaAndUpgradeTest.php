<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;

/**
 * Install and upgrade bookkeeping.
 *
 * Test plan suite PU-L: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_SchemaAndUpgradeTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const KEY = 'au.com.agileware.ewayrecurring';

  /**
   * How many times a post hook ran for a recurring contribution.
   *
   * @var int
   */
  private $recurringHookCalls = 0;

  public function hook_civicrm_post($op, $objectName, $objectId, &$objectRef) {
    if ($objectName === 'ContributionRecur') {
      $this->recurringHookCalls++;
    }
  }

  public function tearDown(): void {
    \Civi::settings()->revert('eway_recurring_contribution_max_retry');
    FakeHttp::uninstall();
    parent::tearDown();
  }

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  private function latestUpgradeStep(): int {
    return (int) max((new CRM_eWAYRecurring_Upgrader())->getRevisions());
  }

  /**
   * @testPlan PU-L2
   */
  public function testAFreshInstallStoresTheNewestUpgradeStep(): void {
    $this->assertSame($this->latestUpgradeStep(), (int) CRM_Core_BAO_Extension::getSchemaVersion(self::KEY));
  }

  /**
   * The hook that runs after install used to store a fixed 20200, below the
   * newest step, and relied on something else correcting it afterwards (DEF-8).
   *
   * @testPlan PU-L2
   */
  public function testThePostInstallHookStoresTheNewestUpgradeStep(): void {
    CRM_Core_BAO_Extension::setSchemaVersion(self::KEY, 1);

    ewayrecurring_civicrm_postInstall();

    $this->assertSame($this->latestUpgradeStep(), (int) CRM_Core_BAO_Extension::getSchemaVersion(self::KEY));
  }

  // ---------------------------------------------------------------------------
  // upgrade_30000: recurring contributions left stuck by the old retry
  // behaviour (feature FR-2 in developer/FEATURES.md)
  // ---------------------------------------------------------------------------

  /**
   * A recurring contribution in the stuck state: In Progress with a retry date.
   * Pass 'failure_retry_date' => NULL for none. The cron's own lists never
   * select it, which is the problem the step repairs.
   */
  private function stuck(array $processor, int $contactId, array $values = []): array {
    $values += ['failure_count' => 3, 'failure_retry_date' => '-2 days', 'token' => FALSE];
    if ($values['failure_retry_date'] === NULL) {
      unset($values['failure_retry_date']);
    }
    return Fixture::recurring($contactId, $processor, $values);
  }

  /**
   * A processor that is not an eWAY one.
   */
  private function otherProcessor(): array {
    $id = \Civi\Api4\PaymentProcessor::create(FALSE)
      ->setValues([
        'payment_processor_type_id:name' => 'Dummy',
        'name' => 'Other processor ' . bin2hex(random_bytes(3)),
        'domain_id' => CRM_Core_Config::domainID(),
        'is_active' => 1,
        'is_test' => 0,
      ])
      ->execute()
      ->first()['id'];
    return Fixture::processorRow($id);
  }

  /**
   * The fields the step must or must not change, in a comparable form.
   */
  private function snapshot(int $id): array {
    $r = Fixture::reloadRecurring($id);
    return [
      'status' => (int) $r['contribution_status_id'],
      'failure_count' => (int) $r['failure_count'],
      'failure_retry_date' => empty($r['failure_retry_date']) ? NULL : strtotime($r['failure_retry_date']),
      'next_sched_contribution_date' => empty($r['next_sched_contribution_date']) ? NULL : strtotime($r['next_sched_contribution_date']),
    ];
  }

  private function statusName(int $id): string {
    return CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $this->snapshot($id)['status']);
  }

  private function runStep(): array {
    $this->recurringHookCalls = 0;
    return (new CRM_eWAYRecurring_Upgrader())->markStuckRecurringContributionsFailed();
  }

  /**
   * Run the step and check that none of these records changed.
   *
   * @param int[] $ids
   */
  private function assertStepLeavesAlone(array $ids): array {
    $before = [];
    foreach ($ids as $label => $id) {
      $before[$label] = $this->snapshot($id);
    }
    $result = $this->runStep();
    foreach ($ids as $label => $id) {
      $this->assertSame($before[$label], $this->snapshot($id), "$label must not change");
    }
    return $result;
  }

  /**
   * Rule 1.
   *
   * @testPlan PU-L6
   */
  public function testRecordsThatUsedUpTheirRetriesAreMarkedFailed(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 3);
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $atLimit = $this->stuck($processor, $contactId, ['failure_count' => 3]);
    $overLimit = $this->stuck($processor, $contactId, ['failure_count' => 5]);
    $before = [$this->snapshot($atLimit['id']), $this->snapshot($overLimit['id'])];

    $result = $this->runStep();

    $this->assertSame('Failed', $this->statusName($atLimit['id']));
    $this->assertSame('Failed', $this->statusName($overLimit['id']));
    foreach ([$atLimit, $overLimit] as $i => $recur) {
      $after = $this->snapshot($recur['id']);
      $this->assertSame($before[$i]['failure_count'], $after['failure_count']);
      $this->assertSame($before[$i]['failure_retry_date'], $after['failure_retry_date']);
      $this->assertSame($before[$i]['next_sched_contribution_date'], $after['next_sched_contribution_date']);
    }
    $this->assertEqualsCanonicalizing([(int) $atLimit['id'], (int) $overLimit['id']], $result['exhausted']);
    $this->assertSame([], $result['no_failures']);
  }

  /**
   * @testPlan PU-L7
   */
  public function testRecordsThatAreStillBeingRetriedOrNotInScopeAreLeftAloneByRuleOne(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 3);
    $processor = Fixture::processor();
    $other = $this->otherProcessor();
    $contactId = Fixture::contact();

    $ids = [
      'below the limit' => $this->stuck($processor, $contactId, ['failure_count' => 2])['id'],
      'no retry date' => $this->stuck($processor, $contactId, ['failure_retry_date' => NULL])['id'],
      'Completed' => $this->stuck($processor, $contactId, ['contribution_status_id:name' => 'Completed'])['id'],
      'Cancelled' => $this->stuck($processor, $contactId, ['contribution_status_id:name' => 'Cancelled'])['id'],
      'already Failed' => $this->stuck($processor, $contactId, ['contribution_status_id:name' => 'Failed'])['id'],
      'Pending' => $this->stuck($processor, $contactId, ['contribution_status_id:name' => 'Pending'])['id'],
      'test mode' => $this->stuck($processor, $contactId, ['is_test' => 1])['id'],
      'other processor' => $this->stuck($other, $contactId)['id'],
    ];

    $result = $this->assertStepLeavesAlone($ids);

    $this->assertSame([], $result['exhausted']);
    $this->assertSame([], $result['no_failures']);
  }

  /**
   * A saved limit of 0 must not make Rule 1 select records that have had no
   * failures. Rule 2 deals with those, and is reported separately.
   *
   * @testPlan PU-L7
   */
  public function testALimitOfZeroDoesNotMakeRuleOneSelectRecordsWithNoFailures(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 0);
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $healthy = $this->stuck($processor, $contactId, ['failure_count' => 0, 'failure_retry_date' => NULL]);
    $noFailures = $this->stuck($processor, $contactId, ['failure_count' => 0]);

    $result = $this->runStep();

    $this->assertSame([], $result['exhausted']);
    $this->assertSame([(int) $noFailures['id']], $result['no_failures']);
    $this->assertSame('In Progress', $this->statusName($healthy['id']));
  }

  /**
   * @testPlan PU-L8
   */
  public function testTheStepUsesTheRetryLimitSavedWhenItRuns(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 2);
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $atTwo = $this->stuck($processor, $contactId, ['failure_count' => 2]);
    $atOne = $this->stuck($processor, $contactId, ['failure_count' => 1]);

    $result = $this->runStep();

    $this->assertSame([(int) $atTwo['id']], $result['exhausted']);
    $this->assertSame('Failed', $this->statusName($atTwo['id']));
    $this->assertSame('In Progress', $this->statusName($atOne['id']));
  }

  /**
   * @testPlan PU-L8
   */
  public function testASecondRunChangesNothing(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $exhausted = $this->stuck($processor, $contactId);
    $noFailures = $this->stuck($processor, $contactId, ['failure_count' => 0]);
    $first = $this->runStep();
    $this->assertCount(1, $first['exhausted']);
    $this->assertCount(1, $first['no_failures']);
    $afterFirst = [$this->snapshot($exhausted['id']), $this->snapshot($noFailures['id'])];

    $second = $this->runStep();

    $this->assertSame(['exhausted' => [], 'no_failures' => []], $second);
    $this->assertSame($afterFirst, [$this->snapshot($exhausted['id']), $this->snapshot($noFailures['id'])]);
  }

  /**
   * The step is a single SQL update per rule: no hooks run, no activity is
   * created and nothing is sent. The upgrade runner calls it as a revision
   * with no queue context outside a real upgrade.
   *
   * @testPlan PU-L8
   */
  public function testTheStepFiresNoHooksAndCreatesNoActivities(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $this->stuck($processor, $contactId);
    $this->stuck($processor, $contactId, ['failure_count' => 0]);
    $activitiesBefore = (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_activity');
    $this->recurringHookCalls = 0;

    $this->assertTrue((new CRM_eWAYRecurring_Upgrader())->upgrade_30000());

    $this->assertSame(0, $this->recurringHookCalls, 'No recurring contribution hook runs');
    $this->assertSame($activitiesBefore, (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_activity'));
  }

  /**
   * Rule 2.
   *
   * @testPlan PU-L9
   */
  public function testRecordsWithARetryDateAndNoFailuresAreMarkedFailedAndNotCharged(): void {
    $http = (new FakeHttp())->install();
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = $this->stuck($processor, $contactId, ['failure_count' => 0]);
    $before = $this->snapshot($recur['id']);

    $result = $this->runStep();

    $this->assertSame([(int) $recur['id']], $result['no_failures']);
    $this->assertSame([], $result['exhausted']);
    $after = $this->snapshot($recur['id']);
    $this->assertSame('Failed', $this->statusName($recur['id']));
    $this->assertSame(0, $after['failure_count'], 'The failure count stays 0');
    $this->assertSame($before['failure_retry_date'], $after['failure_retry_date']);
    $this->assertSame($before['next_sched_contribution_date'], $after['next_sched_contribution_date']);
    $this->assertSame([], $http->requests, 'Nobody is charged');
    $failed = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('contribution_status_id:name', '=', 'Failed')
      ->addWhere('id', '=', $recur['id'])
      ->selectRowCount()
      ->execute()
      ->count();
    $this->assertSame(1, $failed, 'It now shows where the Failed list looks');
  }

  /**
   * @testPlan PU-L10
   */
  public function testRecordsThatMustNotChangeAreLeftAloneByRuleTwo(): void {
    \Civi::settings()->set('eway_recurring_contribution_max_retry', 3);
    $processor = Fixture::processor();
    $other = $this->otherProcessor();
    $contactId = Fixture::contact();

    $ids = [
      'being retried' => $this->stuck($processor, $contactId, ['failure_count' => 1])['id'],
      'healthy, no retry date' => $this->stuck($processor, $contactId, ['failure_count' => 0, 'failure_retry_date' => NULL])['id'],
      'Completed' => $this->stuck($processor, $contactId, ['failure_count' => 0, 'contribution_status_id:name' => 'Completed'])['id'],
      'Cancelled' => $this->stuck($processor, $contactId, ['failure_count' => 0, 'contribution_status_id:name' => 'Cancelled'])['id'],
      'already Failed' => $this->stuck($processor, $contactId, ['failure_count' => 0, 'contribution_status_id:name' => 'Failed'])['id'],
      'Pending' => $this->stuck($processor, $contactId, ['failure_count' => 0, 'contribution_status_id:name' => 'Pending'])['id'],
      'test mode' => $this->stuck($processor, $contactId, ['failure_count' => 0, 'is_test' => 1])['id'],
      'other processor' => $this->stuck($other, $contactId, ['failure_count' => 0])['id'],
    ];

    $first = $this->assertStepLeavesAlone($ids);
    $second = $this->assertStepLeavesAlone($ids);

    $this->assertSame([], $first['no_failures']);
    $this->assertSame([], $second['no_failures']);
  }

}
