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

  // ---------------------------------------------------------------------------
  // The table a fresh install creates (PU-L1, PU-L5)
  // ---------------------------------------------------------------------------

  private const TABLE = 'civicrm_eway_contribution_transactions';

  /**
   * The table's columns as the database reports them, keyed by column name.
   *
   * @return array[] name => ['type', 'default', 'key', 'extra']
   */
  private function tableColumns(): array {
    $rows = CRM_Core_DAO::executeQuery(
      'SELECT COLUMN_NAME AS col_name, COLUMN_TYPE AS col_type, COLUMN_DEFAULT AS col_default,
              COLUMN_KEY AS col_key, EXTRA AS col_extra
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %1
       ORDER BY ORDINAL_POSITION',
      [1 => [self::TABLE, 'String']]
    )->fetchAll();

    $columns = [];
    foreach ($rows as $row) {
      $columns[$row['col_name']] = [
        'type' => strtolower($row['col_type']),
        'default' => $row['col_default'],
        'key' => $row['col_key'],
        'extra' => strtolower((string) $row['col_extra']),
      ];
    }
    return $columns;
  }

  /**
   * The table's foreign keys, keyed by the column they are on.
   *
   * @return array[] column => ['table', 'column', 'on_delete']
   */
  private function tableForeignKeys(): array {
    $rows = CRM_Core_DAO::executeQuery(
      'SELECT kcu.COLUMN_NAME AS fk_column, kcu.REFERENCED_TABLE_NAME AS ref_table,
              kcu.REFERENCED_COLUMN_NAME AS ref_column, rc.DELETE_RULE AS delete_rule
       FROM information_schema.KEY_COLUMN_USAGE kcu
       INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
         ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
         AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         AND rc.TABLE_NAME = kcu.TABLE_NAME
       WHERE kcu.TABLE_SCHEMA = DATABASE()
         AND kcu.TABLE_NAME = %1
         AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
      [1 => [self::TABLE, 'String']]
    )->fetchAll();

    $keys = [];
    foreach ($rows as $row) {
      $keys[$row['fk_column']] = [
        'table' => $row['ref_table'],
        'column' => $row['ref_column'],
        'on_delete' => strtoupper($row['delete_rule']),
      ];
    }
    return $keys;
  }

  /**
   * @testPlan PU-L1
   */
  public function testAFreshInstallCreatesTheVerificationQueueTableWithAllColumns(): void {
    $columns = $this->tableColumns();

    $this->assertEqualsCanonicalizing(
      ['id', 'contribution_id', 'payment_processor_id', 'access_code', 'failed_message', 'status', 'tries', 'is_email_receipt'],
      array_keys($columns)
    );
    // MySQL 8 reports "int unsigned", MariaDB reports "int(10) unsigned".
    foreach (['id', 'contribution_id', 'payment_processor_id', 'status', 'tries', 'is_email_receipt'] as $name) {
      $this->assertMatchesRegularExpression('/^int(\(\d+\))? unsigned$/', $columns[$name]['type'], "$name is an unsigned integer");
    }
    foreach (['access_code', 'failed_message'] as $name) {
      $this->assertSame('text', $columns[$name]['type'], "$name is text");
    }
    $this->assertSame('PRI', $columns['id']['key']);
    $this->assertStringContainsString('auto_increment', $columns['id']['extra']);
    $this->assertEquals(0, $columns['status']['default']);
    $this->assertEquals(0, $columns['tries']['default']);
    $this->assertEquals(1, $columns['is_email_receipt']['default']);
  }

  /**
   * @testPlan PU-L1
   */
  public function testAFreshInstallCreatesBothForeignKeysWithCascadingDelete(): void {
    $keys = $this->tableForeignKeys();

    $this->assertEqualsCanonicalizing(['contribution_id', 'payment_processor_id'], array_keys($keys));
    $this->assertSame(['table' => 'civicrm_contribution', 'column' => 'id', 'on_delete' => 'CASCADE'], $keys['contribution_id']);
    $this->assertSame(['table' => 'civicrm_payment_processor', 'column' => 'id', 'on_delete' => 'CASCADE'], $keys['payment_processor_id']);
  }

  /**
   * The DAO and BAO read their metadata from schema/EwayContributionTransactions.entityType.php, and the
   * table is created from the same file, so the two must agree.
   *
   * @testPlan PU-L5
   */
  public function testDaoAndBaoMetadataMatchTheTable(): void {
    $dao = 'CRM_eWAYRecurring_DAO_EwayContributionTransactions';
    $bao = 'CRM_eWAYRecurring_BAO_EwayContributionTransactions';
    $columns = $this->tableColumns();

    $this->assertTrue(is_subclass_of($bao, $dao), 'The BAO extends the DAO');
    $this->assertSame(self::TABLE, $dao::getTableName());
    $this->assertSame(self::TABLE, $bao::getTableName());
    $this->assertSame(['id'], (new $bao())->keys());

    $fields = $bao::fields();
    $this->assertEqualsCanonicalizing(array_keys($columns), array_column($fields, 'name'), 'The DAO describes exactly the columns of the table');
    foreach ($fields as $field) {
      $name = $field['name'];
      $this->assertSame(self::TABLE . '.' . $name, $field['where'], "$name is read from the table");
      $expectedType = $columns[$name]['type'] === 'text' ? CRM_Utils_Type::T_TEXT : CRM_Utils_Type::T_INT;
      $this->assertSame($expectedType, $field['type'], "$name has the DAO type that matches its column");
    }
    foreach (['status', 'tries', 'is_email_receipt'] as $name) {
      $this->assertEquals($columns[$name]['default'], $fields[$name]['default'], "$name default matches the table");
    }

    // The entity references point at the tables the foreign keys point at.
    $keys = $this->tableForeignKeys();
    $this->assertSame($keys['contribution_id']['table'], ($fields['contribution_id']['FKClassName'])::getTableName());
    $this->assertSame($keys['payment_processor_id']['table'], ($fields['payment_processor_id']['FKClassName'])::getTableName());
  }

  /**
   * @testPlan PU-L5
   */
  public function testARowWrittenThroughTheApiTakesTheTableDefaults(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());

    $created = civicrm_api3('EwayContributionTransactions', 'create', [
      'contribution_id' => $contribution['id'],
      'payment_processor_id' => $processor['id'],
      'access_code' => 'SCHEMADEFAULTS01',
    ]);
    $row = civicrm_api3('EwayContributionTransactions', 'getsingle', ['id' => $created['id']]);

    $this->assertSame('SCHEMADEFAULTS01', $row['access_code']);
    $this->assertEquals($contribution['id'], $row['contribution_id']);
    $this->assertEquals($processor['id'], $row['payment_processor_id']);
    $this->assertEquals(0, $row['status']);
    $this->assertEquals(0, $row['tries']);
    $this->assertEquals(1, $row['is_email_receipt']);
  }

  // ---------------------------------------------------------------------------
  // Upgrade steps (PU-L3, PU-L4)
  // ---------------------------------------------------------------------------

  /**
   * An upgrader that has a task context, as it has when the upgrade runner calls a step. Most steps
   * write to the upgrade log through it.
   */
  private function upgraderWithContext(): CRM_eWAYRecurring_Upgrader {
    $log = new class() {

      public $messages = [];

      public function info($message) {
        $this->messages[] = $message;
      }

    };
    $context = new stdClass();
    $context->log = $log;

    $upgrader = new CRM_eWAYRecurring_Upgrader();
    $property = new ReflectionProperty($upgrader, 'ctx');
    $property->setAccessible(TRUE);
    $property->setValue($upgrader, $context);
    return $upgrader;
  }

  /**
   * What the steps before 3.0.0 change: the processor type row, the verification queue table and the
   * tables that release 2.3.0 dropped.
   */
  private function upgradeState(): array {
    return [
      'type' => CRM_Core_DAO::executeQuery(
        "SELECT user_name_label, password_label, billing_mode, class_name
         FROM civicrm_payment_processor_type WHERE name = 'eWay_Recurring'"
      )->fetchAll(),
      'columns' => $this->tableColumns(),
      'dropped tables present' => (int) CRM_Core_DAO::singleValueQuery(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('civicrm_ewayrecurring', 'civicrm_contribution_page_recur_cycle')"
      ),
    ];
  }

  /**
   * The steps the upgrader has. Listed by hand so that each one runs in its own test: a step that
   * changes the table ends the test's database transaction, and nothing after it should depend on
   * the rollback. testTheListOfUpgradeStepsIsComplete() fails when a step is added and not listed.
   */
  public static function upgradeSteps(): array {
    return [
      'upgrade_6' => ['upgrade_6'],
      'upgrade_7' => ['upgrade_7'],
      'upgrade_20201' => ['upgrade_20201'],
      'upgrade_20300' => ['upgrade_20300'],
      'upgrade_20601' => ['upgrade_20601'],
      'upgrade_30000' => ['upgrade_30000'],
    ];
  }

  /**
   * @testPlan PU-L3
   */
  public function testTheListOfUpgradeStepsIsComplete(): void {
    $listed = array_map(function ($row) {
      return substr($row[0], strlen('upgrade_'));
    }, array_values(self::upgradeSteps()));

    $this->assertEqualsCanonicalizing(
      array_map('strval', (new CRM_eWAYRecurring_Upgrader())->getRevisions()),
      $listed,
      'Add the new step to upgradeSteps() so the idempotency test covers it'
    );
  }

  /**
   * Running a step again must not fail and must not change anything. The step that adds the receipt
   * column to the queue table used to fail the second time (and on a fresh install, which already has
   * the column).
   *
   * @dataProvider upgradeSteps
   * @testPlan PU-L3
   */
  public function testAnUpgradeStepCanRunTwice(string $step): void {
    $upgrader = $this->upgraderWithContext();

    $this->assertTrue($upgrader->$step(), "$step runs");
    $afterFirstRun = $this->upgradeState();

    $this->assertTrue($upgrader->$step(), "$step runs a second time");
    $this->assertSame($afterFirstRun, $this->upgradeState(), "A second run of $step changes nothing");
  }

  private const LEGACY_CLASS = 'au.com.agileware.ewayrecurring';

  private function classNameOf(string $table, int $id): ?string {
    return CRM_Core_DAO::singleValueQuery("SELECT class_name FROM $table WHERE id = %1", [1 => [$id, 'Integer']]);
  }

  private function setClassName(string $table, int $id, string $className): void {
    CRM_Core_DAO::executeQuery("UPDATE $table SET class_name = %1 WHERE id = %2", [
      1 => [$className, 'String'],
      2 => [$id, 'Integer'],
    ]);
  }

  /**
   * Releases before 2.6.1 stored the extension's key as the class name. The step rewrites it on the
   * eWAY processor type and on live processors that still have it, and leaves everything else alone.
   *
   * @testPlan PU-L4
   */
  public function testUpgrade20601RewritesLegacyClassNamesOnTheTypeAndLiveProcessorsOnly(): void {
    $typeId = (int) CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_payment_processor_type WHERE name = 'eWay_Recurring'");
    $liveOne = Fixture::processor(FALSE);
    $liveTwo = Fixture::processor(FALSE);
    $test = Fixture::processor(TRUE);
    $alreadyCurrent = Fixture::processor(FALSE);
    $other = $this->otherProcessor();

    $this->setClassName('civicrm_payment_processor_type', $typeId, self::LEGACY_CLASS);
    foreach ([$liveOne, $liveTwo, $test] as $processor) {
      $this->setClassName('civicrm_payment_processor', (int) $processor['id'], self::LEGACY_CLASS);
    }
    $this->assertSame('Payment_eWAYRecurring', $this->classNameOf('civicrm_payment_processor', (int) $alreadyCurrent['id']), 'Set-up: this one is current');
    $otherBefore = $this->classNameOf('civicrm_payment_processor', (int) $other['id']);
    $this->assertNotSame(self::LEGACY_CLASS, $otherBefore, 'Set-up: the other processor is not a legacy one');

    $this->assertTrue($this->upgraderWithContext()->upgrade_20601());

    $this->assertSame('Payment_eWAYRecurring', $this->classNameOf('civicrm_payment_processor_type', $typeId), 'The processor type is rewritten');
    $this->assertSame('Payment_eWAYRecurring', $this->classNameOf('civicrm_payment_processor', (int) $liveOne['id']));
    $this->assertSame('Payment_eWAYRecurring', $this->classNameOf('civicrm_payment_processor', (int) $liveTwo['id']));
    $this->assertSame(self::LEGACY_CLASS, $this->classNameOf('civicrm_payment_processor', (int) $test['id']), 'A test-mode processor is not rewritten');
    $this->assertSame('Payment_eWAYRecurring', $this->classNameOf('civicrm_payment_processor', (int) $alreadyCurrent['id']));
    $this->assertSame($otherBefore, $this->classNameOf('civicrm_payment_processor', (int) $other['id']), 'Another type of processor is untouched');
  }

}
