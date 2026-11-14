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

}
