<?php

use Civi\Api4\ContributionRecur;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_ExtensionUtil as E;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;

/**
 * The Failed Recurring Contributions Search Kit listing and its Reactivate action.
 *
 * The saved search and display are managed entities (managed/SavedSearch_Failed_Recurring_Contributions.mgd.php).
 * The listing is read here by running the installed saved search's own APIv4 call, and Reactivate by
 * making the update call that the task's dialog makes (ang/ewayrecurring/crmSearchTaskResetRecur.ctrl.js).
 * How the page looks and the dialog works is covered by the Playwright tests.
 *
 * Test plan suite PU-O: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_FailedRecurringSearchTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function tearDown(): void {
    // Back to "everything is allowed", which is what the test framework starts with.
    CRM_Core_Config::singleton()->userPermissionClass->permissions = NULL;
    parent::tearDown();
  }

  /**
   * What the `finance` test persona holds (developer/TEST_PLAN.md, personas).
   */
  const FINANCE = ['access CiviCRM', 'view all contacts', 'access CiviContribute', 'edit contributions', 'view payment tokens', 'edit payment tokens'];

  /**
   * The least a user needs to see recurring records and edit them: no permission about payment tokens, none to administer.
   */
  const EDIT_CONTRIBUTIONS_ONLY = ['access CiviCRM', 'view all contacts', 'access CiviContribute', 'edit contributions'];

  /**
   * Act as a user who holds exactly these permissions.
   */
  private function holding(array $permissions): void {
    CRM_Core_Config::singleton()->userPermissionClass->permissions = $permissions;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

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

  private function savedSearch(): array {
    $search = \Civi\Api4\SavedSearch::get(FALSE)
      ->addWhere('name', '=', 'Failed_Recurring_Contributions')
      ->execute()
      ->first();
    $this->assertNotNull($search, 'The managed saved search is installed');
    return $search;
  }

  /**
   * Run the saved search's APIv4 call, as Search Kit does, optionally narrowed to some statuses like
   * the page's status filter. Returns the ids of the recurring contributions listed.
   *
   * @return int[]
   */
  private function listedIds(?array $statusNames = NULL): array {
    $params = $this->savedSearch()['api_params'];
    unset($params['version']);
    $params['checkPermissions'] = FALSE;
    if ($statusNames !== NULL) {
      $params['where'][] = ['contribution_status_id:name', 'IN', $statusNames];
    }
    $rows = civicrm_api4('ContributionRecur', 'get', $params);
    return array_map('intval', array_column($rows->getArrayCopy(), 'id'));
  }

  /**
   * The same call as listedIds(), made the way the page makes it: with permission checks on, as a user who holds exactly
   * these permissions. Returns the ids listed.
   *
   * @return int[]
   */
  private function listedIdsAs(array $permissions): array {
    $this->holding($permissions);
    $params = $this->savedSearch()['api_params'];
    unset($params['version']);
    $params['checkPermissions'] = TRUE;
    $rows = civicrm_api4('ContributionRecur', 'get', $params);
    return array_map('intval', array_column($rows->getArrayCopy(), 'id'));
  }

  /**
   * Run the Search Kit display as the page does (with permission checks on), as a user who holds exactly these permissions.
   *
   * @return array[] The rows, each with its `data` (the values by column key).
   */
  private function displayRowsAs(array $permissions): array {
    $this->holding($permissions);
    $result = civicrm_api4('SearchDisplay', 'run', [
      'checkPermissions' => TRUE,
      'savedSearch' => 'Failed_Recurring_Contributions',
      'display' => 'Failed_Recurring_Contribution_Listing',
      'return' => 'page:1',
    ]);
    return $result->getArrayCopy();
  }

  /**
   * The update the Reactivate dialog sends for each selected record.
   */
  private function reactivate(int $id, ?string $nextDate = NULL): void {
    $values = [
      'contribution_status_id:name' => 'In Progress',
      'failure_count' => 0,
      'failure_retry_date' => '',
    ];
    if ($nextDate) {
      $values['next_sched_contribution_date'] = $nextDate;
    }
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $id)
      ->setValues($values)
      ->execute();
  }

  private function statusName(array $recur): string {
    return CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur['contribution_status_id']);
  }

  /**
   * The fields Reactivate must not touch (without a new date, the schedule date is among them).
   */
  private function untouched(array $recur): array {
    $keep = ['contact_id', 'amount', 'currency', 'frequency_unit', 'frequency_interval', 'installments',
      'start_date', 'end_date', 'payment_token_id', 'payment_processor_id', 'processor_id',
      'financial_type_id', 'is_test'];
    return array_intersect_key($recur, array_flip($keep));
  }

  /**
   * The ids the cron's normal list would charge for this processor.
   *
   * @return int[]
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
   * A Failed record that has used up its retries, with a Completed contribution so that the cron could
   * charge it again once it is back In Progress.
   */
  private function failedRecurring(array $processor, int $contactId, array $values = []): array {
    $recur = Fixture::recurring($contactId, $processor, $values + [
      'contribution_status_id:name' => 'Failed',
      'failure_count' => 3,
      'failure_retry_date' => '-2 days',
      'next_sched_contribution_date' => '-3 days',
    ]);
    Fixture::completedContributionFor($recur);
    return $recur;
  }

  // ---------------------------------------------------------------------------
  // What the saved search selects (PU-O1)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-O1
   */
  public function testTheSavedSearchListsOnlyLiveEwayRecordsThatHaveAToken(): void {
    $processor = Fixture::processor();
    $other = $this->otherProcessor();
    $contactId = Fixture::contact();

    $listed = Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Failed', 'failure_count' => 3, 'failure_retry_date' => '-2 days']);
    $listedToo = Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'In Progress']);
    $testMode = Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Failed', 'is_test' => 1]);
    $noToken = Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Failed', 'token' => FALSE]);
    $otherProcessorRecord = Fixture::recurring($contactId, $other, [
      'contribution_status_id:name' => 'Failed',
      'token' => Fixture::token($contactId, $other['id'], ['token' => 'OTHERPROC0002']),
    ]);

    $ids = $this->listedIds();

    $this->assertContains((int) $listed['id'], $ids, 'A live eWAY record with a token is listed');
    $this->assertContains((int) $listedToo['id'], $ids, 'The saved search does not filter on status, the page does');
    $this->assertNotContains((int) $testMode['id'], $ids, 'A test-mode record is not listed');
    $this->assertNotContains((int) $noToken['id'], $ids, 'A record with no payment token is not listed');
    $this->assertNotContains((int) $otherProcessorRecord['id'], $ids, 'A record on another type of processor is not listed');
  }

  /**
   * The page filters the listing by status, defaulting to Failed and Failing.
   *
   * @testPlan PU-O1
   */
  public function testTheStatusFilterOnThePageNarrowsTheListingToFailedRecords(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $failed = Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Failed']);
    $running = Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'In Progress']);

    $ids = $this->listedIds(['Failed', 'Failing']);

    $this->assertContains((int) $failed['id'], $ids);
    $this->assertNotContains((int) $running['id'], $ids);
  }

  // ---------------------------------------------------------------------------
  // The display (PU-O2)
  // ---------------------------------------------------------------------------

  private function display(): array {
    $display = \Civi\Api4\SearchDisplay::get(FALSE)
      ->addWhere('name', '=', 'Failed_Recurring_Contribution_Listing')
      ->execute()
      ->first();
    $this->assertNotNull($display, 'The managed search display is installed');
    return $display;
  }

  /**
   * @testPlan PU-O2
   */
  public function testTheDisplayHasTheDocumentedColumnsInOrder(): void {
    $settings = $this->display()['settings'];

    $columns = [];
    foreach ($settings['columns'] as $column) {
      if ($column['type'] === 'field') {
        $columns[] = [$column['key'], $column['label']];
      }
      else {
        $columns[] = [$column['type'], implode(',', array_column($column['links'], 'text'))];
      }
    }
    $this->assertSame([
      ['contact_id.display_name', 'Contact'],
      ['id', 'ID'],
      ['start_date', 'Start Date'],
      ['next_sched_contribution_date', 'Next Scheduled Date'],
      ['contribution_status_id:label', 'Status'],
      ['amount', 'Amount'],
      ['frequency_unit:label', 'Frequency'],
      ['failure_count', 'Number of Failures'],
      ['failure_retry_date', 'Retry Failed Attempt On'],
      ['buttons', 'Reactivate'],
    ], $columns);
  }

  /**
   * @testPlan PU-O2
   */
  public function testTheDisplaySortsByStatusThenRetryDate(): void {
    $settings = $this->display()['settings'];

    $this->assertSame([['contribution_status_id:label', 'ASC'], ['failure_retry_date', 'ASC']], $settings['sort']);
  }

  /**
   * Every column must be something the saved search selects, or it shows blank. The Reactivate button
   * is offered for Failing and Failed records and runs the task that the extension registers.
   *
   * @testPlan PU-O2
   */
  public function testTheDisplayColumnsComeFromTheSavedSearchAndReactivateIsOfferedForFailedRecords(): void {
    $settings = $this->display()['settings'];
    $selected = $this->savedSearch()['api_params']['select'];

    foreach ($settings['columns'] as $column) {
      if ($column['type'] === 'field') {
        $this->assertContains($column['key'], $selected, $column['key'] . ' is selected by the saved search');
      }
    }

    $buttons = array_values(array_filter($settings['columns'], function ($column) {
      return $column['type'] === 'buttons';
    }));
    $this->assertCount(1, $buttons);
    $link = $buttons[0]['links'][0];
    $this->assertSame('reset_recur_status', $link['task']);
    $this->assertSame('ContributionRecur', $link['entity']);
    $this->assertSame([['contribution_status_id:name', 'IN', ['Failing', 'Failed']]], $link['conditions']);
    $this->assertContains('reset_recur_status', $settings['actions'], 'The task is also in the bulk actions menu');

    $tasks = [];
    ewayrecurring_civicrm_searchKitTasks($tasks, FALSE);
    $this->assertArrayHasKey('reset_recur_status', $tasks['ContributionRecur'], 'The extension registers the task');
    $this->assertSame('ewayrecurring', $tasks['ContributionRecur']['reset_recur_status']['module']);
  }

  /**
   * The page's status filter defaults to the ids of Failed and Failing. A number written into the page
   * would silently show the wrong records if the ids ever differed.
   *
   * @testPlan PU-O2
   */
  public function testThePageStatusFilterDefaultsToTheFailedAndFailingStatusIds(): void {
    $html = file_get_contents(E::path('ang/afsearchFailedRecurringContributions.aff.html'));
    $this->assertSame(1, preg_match('/afform_default:\s*\[([^\]]*)\]/', $html, $matches), 'The page sets a default status filter');
    $defaults = array_map('intval', array_map('trim', explode(',', $matches[1])));

    $expected = array_filter([
      (int) CRM_eWAYRecurring_Utils::contribution_status_id('Failed', TRUE),
      (int) CRM_eWAYRecurring_Utils::contribution_status_id('Failing', TRUE),
    ]);
    $this->assertEqualsCanonicalizing($expected, $defaults);
  }

  // ---------------------------------------------------------------------------
  // Reactivate (PU-O3, PU-O4, PU-O5)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-O3
   */
  public function testReactivateClearsTheFailuresAndLeavesTheRestOfTheRecord(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = $this->failedRecurring($processor, $contactId, ['amount' => 25.50, 'end_date' => '+1 year']);
    $before = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('Failed', $this->statusName($before), 'Set-up');
    $this->assertEquals(3, $before['failure_count'], 'Set-up');
    $this->assertNotEmpty($before['failure_retry_date'], 'Set-up');

    $this->reactivate((int) $recur['id']);

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->statusName($after));
    $this->assertEquals(0, $after['failure_count']);
    $this->assertEmpty($after['failure_retry_date'], 'The retry date is cleared, not set to some default date');
    $this->assertSame(strtotime($before['next_sched_contribution_date']), strtotime($after['next_sched_contribution_date']), 'With no new date the schedule date is unchanged');
    $this->assertEquals($this->untouched($before), $this->untouched($after), 'Nothing else changes');
  }

  /**
   * @testPlan PU-O3
   */
  public function testReactivateWithADateAlsoSetsTheNextScheduledDate(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = $this->failedRecurring($processor, $contactId);
    $before = Fixture::reloadRecurring($recur['id']);
    $newDate = date('Y-m-d', strtotime('+10 days'));

    $this->reactivate((int) $recur['id'], $newDate);

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->statusName($after));
    $this->assertEquals(0, $after['failure_count']);
    $this->assertEmpty($after['failure_retry_date']);
    $this->assertSame($newDate, date('Y-m-d', strtotime($after['next_sched_contribution_date'])));
    $this->assertEquals($this->untouched($before), $this->untouched($after), 'Nothing else changes');
  }

  /**
   * @testPlan PU-O3
   */
  public function testReactivateCanBeAppliedToSeveralRecordsInOneCall(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $one = $this->failedRecurring($processor, $contactId);
    $two = $this->failedRecurring($processor, $contactId, ['failure_count' => 5]);

    ContributionRecur::update(FALSE)
      ->addWhere('id', 'IN', [$one['id'], $two['id']])
      ->setValues(['contribution_status_id:name' => 'In Progress', 'failure_count' => 0, 'failure_retry_date' => ''])
      ->execute();

    foreach ([$one, $two] as $recur) {
      $after = Fixture::reloadRecurring($recur['id']);
      $this->assertSame('In Progress', $this->statusName($after));
      $this->assertEquals(0, $after['failure_count']);
      $this->assertEmpty($after['failure_retry_date']);
    }
  }

  /**
   * @testPlan PU-O4
   */
  public function testAReactivatedRecordIsChargedByTheNextRunOnceItsDateHasPassed(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = $this->failedRecurring($processor, $contactId);
    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor), 'A Failed record is not charged');

    $this->reactivate((int) $recur['id']);

    $this->assertContains((int) $recur['id'], $this->dueIds($processor), 'Reactivated with its schedule date in the past, it is charged next run');
  }

  /**
   * @testPlan PU-O4
   */
  public function testAReactivatedRecordWithAFutureDateWaitsForThatDate(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = $this->failedRecurring($processor, $contactId);

    $this->reactivate((int) $recur['id'], date('Y-m-d', strtotime('+5 days')));

    $this->assertNotContains((int) $recur['id'], $this->dueIds($processor), 'Not charged before the new date');

    // The date passes.
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addValue('next_sched_contribution_date', date('Y-m-d H:i:s', strtotime('-1 hour')))
      ->execute();

    $this->assertContains((int) $recur['id'], $this->dueIds($processor), 'Charged once the date has passed');
  }

  /**
   * Reactivate is a plain update, so it can be applied to a record from any search, including one on
   * another type of processor. It must change only the status fields there.
   *
   * @testPlan PU-O5
   */
  public function testReactivateOnARecordThatIsNotEwayChangesOnlyItsStatusFields(): void {
    $other = $this->otherProcessor();
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $other, [
      'token' => FALSE,
      'amount' => 40,
      'processor_id' => 'OTHERREF0001',
      'contribution_status_id:name' => 'Failed',
      'failure_count' => 2,
      'failure_retry_date' => '-1 day',
      'next_sched_contribution_date' => '-5 days',
    ]);
    $before = Fixture::reloadRecurring($recur['id']);

    $this->reactivate((int) $recur['id']);

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->statusName($after));
    $this->assertEquals(0, $after['failure_count']);
    $this->assertEmpty($after['failure_retry_date']);
    $this->assertSame(strtotime($before['next_sched_contribution_date']), strtotime($after['next_sched_contribution_date']));
    $this->assertEquals($this->untouched($before), $this->untouched($after), 'Amount, processor, reference and the rest are unchanged');
    $this->assertEquals($other['id'], $after['payment_processor_id']);
    $this->assertSame('OTHERREF0001', $after['processor_id']);
  }

  // ---------------------------------------------------------------------------
  // Users who may not read payment processors (PU-O6 to PU-O9, FR-4)
  // ---------------------------------------------------------------------------

  /**
   * A site with an eWAY processor, a Failed record on it, and records the list must not show.
   *
   * @return array The ids by role.
   */
  private function listAndTheRecordsAroundIt(): array {
    $processor = Fixture::processor();
    $other = $this->otherProcessor();
    $contactId = Fixture::contact();
    return [
      'processor' => $processor,
      'other' => $other,
      'listed' => (int) Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Failed', 'failure_count' => 3, 'failure_retry_date' => '-2 days'])['id'],
      'failing' => (int) Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Failing', 'failure_count' => 1])['id'],
      'testMode' => (int) Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Failed', 'is_test' => 1])['id'],
      'noToken' => (int) Fixture::recurring($contactId, $processor, ['contribution_status_id:name' => 'Failed', 'token' => FALSE])['id'],
      'otherProcessor' => (int) Fixture::recurring($contactId, $other, [
        'contribution_status_id:name' => 'Failed',
        'token' => Fixture::token($contactId, $other['id'], ['token' => 'OTHERPROC0003']),
      ])['id'],
    ];
  }

  /**
   * @testPlan PU-O6
   */
  public function testAUserWhoMayNotReadPaymentProcessorsStillSeesOnlyTheLiveEwayRecordsThatHaveAToken(): void {
    $site = $this->listAndTheRecordsAroundIt();

    foreach ([self::FINANCE, self::EDIT_CONTRIBUTIONS_ONLY] as $permissions) {
      $label = implode(', ', $permissions);
      $ids = $this->listedIdsAs($permissions);
      $this->assertFalse(CRM_Core_Permission::check('administer CiviCRM'), "Set-up: the user cannot administer ($label)");

      $this->assertContains($site['listed'], $ids, "A live eWAY record with a token is listed ($label)");
      $this->assertContains($site['failing'], $ids, "A Failing one too ($label)");
      $this->assertNotContains($site['testMode'], $ids, "A test-mode record is not listed ($label)");
      $this->assertNotContains($site['noToken'], $ids, "A record with no payment token is not listed ($label)");
      $this->assertNotContains($site['otherProcessor'], $ids, "A record on another type of processor is not listed ($label)");
    }
  }

  /**
   * The page's own request: the Search Kit display run for a user with only "edit contributions" returns the rows and
   * fills every column, and nothing about a processor.
   *
   * @testPlan PU-O6
   */
  public function testTheSearchKitDisplayRunsForAUserWhoMayNotReadPaymentProcessors(): void {
    $site = $this->listAndTheRecordsAroundIt();

    $rows = $this->displayRowsAs(self::EDIT_CONTRIBUTIONS_ONLY);

    $ids = array_map(function ($row) {
      return (int) $row['data']['id'];
    }, $rows);
    $this->assertContains($site['listed'], $ids);
    $this->assertContains($site['failing'], $ids);
    $this->assertNotContains($site['otherProcessor'], $ids);
    $this->assertNotContains($site['testMode'], $ids);
    $this->assertNotContains($site['noToken'], $ids);

    $row = $rows[array_search($site['listed'], $ids)];
    $this->assertSame('Failed', $row['data']['contribution_status_id:label']);
    $this->assertEquals(3, $row['data']['failure_count']);
    $this->assertNotEmpty($row['data']['contact_id.display_name']);
    $this->assertDoesNotMatchRegularExpression('/processor|user_name|password|signature|subject/i', implode(',', array_keys($row['data'])), 'No processor value is part of a row');
  }

  /**
   * The point of the change: a user who may not administer cannot read any processor field through ContributionRecur,
   * with a join of either kind, with the new field, or through the page.
   *
   * @testPlan PU-O7
   */
  public function testAUserWhoMayNotReadPaymentProcessorsCannotReadAnyProcessorFieldThroughTheRecurringRecords(): void {
    $site = $this->listAndTheRecordsAroundIt();
    $secrets = [
      (string) $site['processor']['user_name'],
      (string) $site['processor']['password'],
      (string) $site['processor']['name'],
    ];
    $this->assertNotContains('', $secrets, 'Set-up: the processor has a name and credentials to look for');

    foreach ([self::FINANCE, self::EDIT_CONTRIBUTIONS_ONLY] as $permissions) {
      $this->holding($permissions);
      $label = implode(', ', $permissions);

      // The entity itself is refused.
      try {
        civicrm_api4('PaymentProcessor', 'get', ['checkPermissions' => TRUE, 'select' => ['id']]);
        $this->fail("PaymentProcessor.get was allowed ($label)");
      }
      catch (\Civi\API\Exception\UnauthorizedException $e) {
        $this->assertStringContainsString('Authorization failed', $e->getMessage());
      }

      // Implicit joins (dot notation) and an explicit join are dropped, whatever field they name.
      foreach (['id', 'name', 'title', 'user_name', 'password', 'signature', 'url_site', 'class_name', 'payment_processor_type_id:name'] as $field) {
        $implicit = civicrm_api4('ContributionRecur', 'get', [
          'checkPermissions' => TRUE,
          'select' => ['id', "payment_processor_id.$field"],
          'where' => [['id', '=', $site['listed']]],
        ])->first();
        $this->assertSame(['id' => $site['listed']], array_map('intval', $implicit), "payment_processor_id.$field is not returned ($label)");

        $explicit = civicrm_api4('ContributionRecur', 'get', [
          'checkPermissions' => TRUE,
          'select' => ['id', "pp.$field"],
          'join' => [['PaymentProcessor AS pp', 'LEFT', ['payment_processor_id', '=', 'pp.id']]],
          'where' => [['id', '=', $site['listed']]],
        ])->first();
        $this->assertSame(['id' => $site['listed']], array_map('intval', $explicit), "An explicit join's $field is not returned ($label)");
      }

      // A condition on a processor field cannot be used to probe it either: the field does not exist for this user.
      foreach (['payment_processor_id.user_name', 'payment_processor_id.password', 'payment_processor_id.name'] as $field) {
        try {
          civicrm_api4('ContributionRecur', 'get', [
            'checkPermissions' => TRUE,
            'select' => ['id'],
            'where' => [[$field, 'LIKE', '%']],
          ]);
          $this->fail("A condition on $field was accepted ($label)");
        }
        catch (\CRM_Core_Exception $e) {
          $this->assertStringContainsString('Invalid field', $e->getMessage(), $field);
        }
      }

      // The new field is a plain yes or no and nothing else.
      $rows = civicrm_api4('ContributionRecur', 'get', [
        'checkPermissions' => TRUE,
        'select' => ['id', 'is_eway_processor'],
        'where' => [['id', 'IN', [$site['listed'], $site['otherProcessor']]]],
      ])->indexBy('id')->getArrayCopy();
      $this->assertSame(['id' => $site['listed'], 'is_eway_processor' => TRUE], $rows[$site['listed']], "Yes for an eWAY record ($label)");
      $this->assertSame(['id' => $site['otherProcessor'], 'is_eway_processor' => FALSE], $rows[$site['otherProcessor']], "No for another processor's ($label)");

      // The page's rows and every value in them: no processor name, key or password anywhere.
      $page = json_encode($this->displayRowsAs($permissions));
      foreach ($secrets as $secret) {
        $this->assertStringNotContainsString($secret, $page, "The page's data holds no processor value ($label)");
      }
      $this->holding($permissions);
      $plain = json_encode(civicrm_api4('ContributionRecur', 'get', ['checkPermissions' => TRUE, 'where' => [['id', '=', $site['listed']]]])->getArrayCopy());
      foreach ($secrets as $secret) {
        $this->assertStringNotContainsString($secret, $plain, "A plain ContributionRecur read holds no processor value ($label)");
      }
    }
  }

  /**
   * The saved search has no part of it that names the payment processor entity, so no setting of the site can turn it back
   * into a request that needs that entity.
   *
   * @testPlan PU-O7
   */
  public function testTheSavedSearchDoesNotReachThePaymentProcessorEntity(): void {
    $params = json_encode($this->savedSearch()['api_params']);

    $this->assertStringNotContainsStringIgnoringCase('PaymentProcessor', $params);
    $this->assertStringContainsString('is_eway_processor', $params);
  }

  /**
   * The eWAY test is worked out when the list is read, so it follows the processors with nothing to refresh: a new eWAY
   * processor's records are listed at once, and a processor changed to another type drops out.
   *
   * @testPlan PU-O8
   */
  public function testTheEwayTestFollowsTheProcessorsWithNothingToRefresh(): void {
    $contactId = Fixture::contact();
    $first = Fixture::processor();
    $firstRecord = (int) Fixture::recurring($contactId, $first, ['contribution_status_id:name' => 'Failed'])['id'];
    $this->assertContains($firstRecord, $this->listedIdsAs(self::EDIT_CONTRIBUTIONS_ONLY));

    // A second eWAY processor is added after the first read.
    $second = Fixture::processor(FALSE, ['name' => 'eway second ' . bin2hex(random_bytes(3))]);
    $secondRecord = (int) Fixture::recurring($contactId, $second, ['contribution_status_id:name' => 'Failed'])['id'];
    $ids = $this->listedIdsAs(self::EDIT_CONTRIBUTIONS_ONLY);
    $this->assertContains($firstRecord, $ids);
    $this->assertContains($secondRecord, $ids, 'A processor added later is included without any refresh');

    // One of them stops being an eWAY processor.
    \Civi\Api4\PaymentProcessor::update(FALSE)
      ->addWhere('id', '=', $second['id'])
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->execute();
    $ids = $this->listedIdsAs(self::EDIT_CONTRIBUTIONS_ONLY);
    $this->assertContains($firstRecord, $ids);
    $this->assertNotContains($secondRecord, $ids, 'A processor changed to another type is dropped without any refresh');

    // A record with no processor at all is not an eWAY record.
    $none = (int) Fixture::recurring($contactId, $first, ['contribution_status_id:name' => 'Failed', 'payment_processor_id' => NULL])['id'];
    $this->assertNotContains($none, $this->listedIdsAs(self::EDIT_CONTRIBUTIONS_ONLY));
  }

  /**
   * The field changes nothing for any other call: reading recurring records without it returns every record as before, it is
   * not part of a plain read, and it cannot be written.
   *
   * @testPlan PU-O8
   */
  public function testTheNewFieldChangesNothingForOtherCalls(): void {
    $site = $this->listAndTheRecordsAroundIt();
    $everyone = [$site['listed'], $site['failing'], $site['testMode'], $site['noToken'], $site['otherProcessor']];

    foreach ([self::EDIT_CONTRIBUTIONS_ONLY, ['administer CiviCRM', 'access CiviCRM', 'view all contacts', 'access CiviContribute', 'edit contributions']] as $permissions) {
      $this->holding($permissions);
      $rows = civicrm_api4('ContributionRecur', 'get', [
        'checkPermissions' => TRUE,
        'where' => [['id', 'IN', $everyone]],
      ])->getArrayCopy();
      $this->assertEqualsCanonicalizing($everyone, array_map('intval', array_column($rows, 'id')), 'Another call still returns every record, whoever the processor is');
      $this->assertArrayNotHasKey('is_eway_processor', $rows[0], 'It is not part of a plain read');
    }

    $this->holding(self::FINANCE);
    foreach (['create', 'update'] as $action) {
      $fields = civicrm_api4('ContributionRecur', 'getFields', ['checkPermissions' => FALSE, 'action' => $action, 'select' => ['name']])->column('name');
      $this->assertNotContains('is_eway_processor', $fields, "It is not a field of $action");
    }
    $fields = civicrm_api4('ContributionRecur', 'getFields', ['checkPermissions' => FALSE, 'action' => 'get', 'select' => ['name']])->column('name');
    $this->assertContains('is_eway_processor', $fields);
    $this->assertNotContains('is_eway_processor', array_keys(\Civi\Api4\PaymentProcessor::getFields(FALSE)->execute()->indexBy('name')->getArrayCopy()), 'It is a field of ContributionRecur only');
  }

  /**
   * An administrator sees what they saw before the change: the same records as the old join gave.
   *
   * @testPlan PU-O8
   */
  public function testAnAdministratorSeesTheSameRecordsAsBefore(): void {
    $site = $this->listAndTheRecordsAroundIt();
    $admin = ['administer CiviCRM', 'access CiviCRM', 'view all contacts', 'access CiviContribute', 'edit contributions'];

    $ids = $this->listedIdsAs($admin);
    $this->assertEqualsCanonicalizing($this->listedIds(), $ids, 'The same as with no permission check');
    $this->assertContains($site['listed'], $ids);
    $this->assertNotContains($site['otherProcessor'], $ids);

    // The old definition, with its join, still gives an administrator exactly the same list.
    $old = $this->savedSearch()['api_params'];
    unset($old['version']);
    $old['select'][] = 'ContributionRecur_PaymentProcessor_payment_processor_id_01.title';
    $old['where'][0] = ['ContributionRecur_PaymentProcessor_payment_processor_id_01.payment_processor_type_id:name', '=', 'eWay_Recurring'];
    $old['join'][] = ['PaymentProcessor AS ContributionRecur_PaymentProcessor_payment_processor_id_01', 'INNER', ['payment_processor_id', '=', 'ContributionRecur_PaymentProcessor_payment_processor_id_01.id']];
    $old['checkPermissions'] = TRUE;
    $this->holding($admin);
    $oldIds = array_map('intval', civicrm_api4('ContributionRecur', 'get', $old)->column('id'));
    $this->assertEqualsCanonicalizing($oldIds, $ids, 'The old join and the new field agree');
  }

  /**
   * Reactivate: the update the dialog sends works for a user with "edit contributions" and no more, and the
   * list is still refused to a user without it.
   *
   * @testPlan PU-O9
   */
  public function testReactivateWorksForAUserWithEditContributionsAndOnlyThat(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = $this->failedRecurring($processor, $contactId);

    $this->holding(self::EDIT_CONTRIBUTIONS_ONLY);
    ContributionRecur::update(TRUE)
      ->addWhere('id', '=', $recur['id'])
      ->setValues(['contribution_status_id:name' => 'In Progress', 'failure_count' => 0, 'failure_retry_date' => ''])
      ->execute();

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->statusName($after));
    $this->assertEquals(0, $after['failure_count']);
    $this->assertEmpty($after['failure_retry_date']);
    $this->assertEquals($processor['id'], $after['payment_processor_id'], 'The processor is untouched');
  }

  /**
   * @testPlan PU-O9
   */
  public function testAUserWithoutEditContributionsCannotReadTheListOrReactivate(): void {
    $site = $this->listAndTheRecordsAroundIt();

    foreach ([['access CiviCRM'], ['access CiviCRM', 'view all contacts', 'access CiviContribute']] as $permissions) {
      $label = implode(', ', $permissions);
      $this->holding($permissions);
      try {
        ContributionRecur::update(TRUE)
          ->addWhere('id', '=', $site['listed'])
          ->setValues(['contribution_status_id:name' => 'In Progress', 'failure_count' => 0])
          ->execute();
        $this->fail("Reactivate was allowed ($label)");
      }
      catch (\Civi\API\Exception\UnauthorizedException $e) {
        $this->assertStringContainsString('Authorization failed', $e->getMessage(), $label);
      }
      $this->assertSame('Failed', $this->statusName(Fixture::reloadRecurring($site['listed'])), "The record is untouched ($label)");
    }

    // The page's own permission is "edit contributions" (the Afform and the menu entry, checked in PU-O10).
    $this->holding(['access CiviCRM']);
    $this->assertFalse(CRM_Core_Permission::check('edit contributions'));
  }

  // ---------------------------------------------------------------------------
  // The page and menu entry, and an upgrade of a site that has the older definitions (PU-O10)
  // ---------------------------------------------------------------------------

  private function navigationRow(): array {
    return \Civi\Api4\Navigation::get(FALSE)
      ->addWhere('name', '=', 'afsearchFailedRecurringContributions')
      ->addWhere('domain_id', '=', CRM_Core_Config::domainID())
      ->execute()
      ->single();
  }

  private function managedFor(string $entityType, string $name): array {
    return \Civi\Api4\Managed::get(FALSE)
      ->addWhere('module', '=', 'au.com.agileware.ewayrecurring')
      ->addWhere('name', '=', $name)
      ->addWhere('entity_type', '=', $entityType)
      ->execute()
      ->single();
  }

  /**
   * @testPlan PU-O10
   */
  public function testThePageAndTheMenuEntryNeedEditContributions(): void {
    $page = require E::path('ang/afsearchFailedRecurringContributions.aff.php');
    $this->assertSame(['edit contributions'], $page['permission']);

    $menu = $this->navigationRow();
    $this->assertSame(['edit contributions'], $menu['permission']);
    $this->assertSame('AND', $menu['permission_operator']);
    $this->assertSame('civicrm/contribute/failedrecurring', $menu['url']);

    $declared = require E::path('managed/Navigation_afsearchFailedRecurringContributions.mgd.php');
    $this->assertSame(['edit contributions'], $declared[0]['params']['values']['permission']);
  }

  /**
   * A site that has the administrator-only menu entry (a development build between the DEF-62 fix and this one) is brought
   * back to "edit contributions" by the ordinary reconcile that runs when the extension is upgraded or the caches are
   * cleared, because the record is managed with the policy "unmodified". A release that already had "edit contributions"
   * (2.8.2) needs no change.
   *
   * @testPlan PU-O10
   */
  public function testAnAdministratorOnlyMenuEntryIsUpdatedOnUpgradeUnlessSomeoneChangedIt(): void {
    $managed = $this->managedFor('Navigation', 'Navigation_afsearchFailedRecurringContributions');
    $nav = $this->navigationRow();
    $this->assertNotEmpty($managed['entity_id']);

    // Put the entry as the interim build left it. Plain SQL, so it is not recorded as a change made by a person.
    CRM_Core_DAO::executeQuery("UPDATE civicrm_navigation SET permission = 'administer CiviCRM' WHERE id = %1", [1 => [$nav['id'], 'Integer']]);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_managed SET checksum = 'older declaration', entity_modified_date = NULL WHERE id = %1", [1 => [$managed['id'], 'Integer']]);
    $this->assertSame(['administer CiviCRM'], $this->navigationRow()['permission'], 'Set-up');

    \Civi\Api4\Managed::reconcile(FALSE)->execute();
    $this->assertSame(['edit contributions'], $this->navigationRow()['permission'], 'An unmodified entry is updated by the reconcile');

    // An administrator who changed the entry themselves keeps their change.
    CRM_Core_DAO::executeQuery("UPDATE civicrm_navigation SET permission = 'administer CiviCRM' WHERE id = %1", [1 => [$nav['id'], 'Integer']]);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_managed SET checksum = 'older declaration', entity_modified_date = NOW() WHERE id = %1", [1 => [$managed['id'], 'Integer']]);
    \Civi\Api4\Managed::reconcile(FALSE)->execute();
    $this->assertSame(['administer CiviCRM'], $this->navigationRow()['permission'], 'A modified entry is left alone');
  }

  /**
   * The saved search is managed the same way, so a site with the older definition (the join to the payment processor)
   * gets the new one on the same reconcile; one a person edited in Search Kit is left as it is.
   *
   * @testPlan PU-O10
   */
  public function testTheSavedSearchWithTheOlderJoinIsReplacedOnUpgradeUnlessSomeoneChangedIt(): void {
    $managed = $this->managedFor('SavedSearch', 'SavedSearch_Failed_Recurring_Contributions');
    $search = $this->savedSearch();
    $older = $search['api_params'];
    $older['where'][0] = ['ContributionRecur_PaymentProcessor_payment_processor_id_01.payment_processor_type_id:name', '=', 'eWay_Recurring'];
    $older['join'][] = ['PaymentProcessor AS ContributionRecur_PaymentProcessor_payment_processor_id_01', 'INNER', ['payment_processor_id', '=', 'ContributionRecur_PaymentProcessor_payment_processor_id_01.id']];
    CRM_Core_DAO::executeQuery('UPDATE civicrm_saved_search SET api_params = %1 WHERE id = %2', [
      1 => [json_encode($older), 'String'],
      2 => [$search['id'], 'Integer'],
    ]);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_managed SET checksum = 'older declaration', entity_modified_date = NULL WHERE id = %1", [1 => [$managed['id'], 'Integer']]);
    $this->assertStringContainsString('PaymentProcessor', json_encode($this->savedSearch()['api_params']), 'Set-up');

    \Civi\Api4\Managed::reconcile(FALSE)->execute();
    $after = json_encode($this->savedSearch()['api_params']);
    $this->assertStringNotContainsString('PaymentProcessor', $after, 'An unmodified saved search is replaced');
    $this->assertStringContainsString('is_eway_processor', $after);

    // The same site, edited in Search Kit: left alone.
    CRM_Core_DAO::executeQuery('UPDATE civicrm_saved_search SET api_params = %1 WHERE id = %2', [
      1 => [json_encode($older), 'String'],
      2 => [$search['id'], 'Integer'],
    ]);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_managed SET checksum = 'older declaration', entity_modified_date = NOW() WHERE id = %1", [1 => [$managed['id'], 'Integer']]);
    \Civi\Api4\Managed::reconcile(FALSE)->execute();
    $this->assertStringContainsString('PaymentProcessor', json_encode($this->savedSearch()['api_params']), 'A modified saved search is left alone');
  }

}
