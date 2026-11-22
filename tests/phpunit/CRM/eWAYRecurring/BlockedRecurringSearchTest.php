<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\StatusPreference;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_ExtensionUtil as E;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;

/**
 * The Blocked Recurring Contributions Search Kit listing, the calculated field behind it, its Reactivate and Cancel
 * actions, its status check, and its page and menu entry (FEATURES.md, FR-6).
 *
 * A record is blocked when it is due to be charged but a Cancelled contribution dated on or after the date it is waiting
 * on stops the scheduled job charging it. The extension skips the installment when a contribution is cancelled through
 * the normal save (PU-F42), so the tests put the Cancelled status on straight in the table: that is how a record left
 * blocked before the upgrade, or by a change that bypassed the hook, comes about.
 *
 * Test plan suite PU-P: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_BlockedRecurringSearchTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * The least a user needs to see recurring records and edit them.
   */
  const EDIT_CONTRIBUTIONS_ONLY = ['access CiviCRM', 'view all contacts', 'access CiviContribute', 'edit contributions'];

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

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * A recurring record that is due (its next scheduled date was two days ago unless $values says otherwise).
   */
  private function recurring(array $values = [], ?array $processor = NULL): array {
    return Fixture::recurring(Fixture::contact(), $processor ?? Fixture::processor(), $values + [
      'next_sched_contribution_date' => '-2 days',
    ]);
  }

  /**
   * A contribution of the record with this status and date. Cancelled is set straight in the table, so that the
   * extension's own skip of a cancelled installment (PU-F42) does not move the record on.
   */
  private function contributionOf(array $recur, string $status, string $receiveDate = 'now'): array {
    $contribution = Fixture::contribution($recur['contact_id'], [
      'total_amount' => $recur['amount'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => $status === 'Cancelled' ? 'Pending' : $status,
      'receive_date' => date('Y-m-d H:i:s', strtotime($receiveDate)),
    ]);
    if ($status === 'Cancelled') {
      CRM_Core_DAO::executeQuery('UPDATE civicrm_contribution SET contribution_status_id = %1 WHERE id = %2', [
        1 => [(int) CRM_eWAYRecurring_Utils::contribution_status_id('Cancelled'), 'Integer'],
        2 => [(int) $contribution['id'], 'Integer'],
      ]);
    }
    // Recording a Completed or Failed contribution makes core move the date, so put it back to what the test asked for.
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->addValue('next_sched_contribution_date', $recur['next_sched_contribution_date'])
      ->execute();
    return $contribution;
  }

  private function isBlocked(array $recur): bool {
    $row = ContributionRecur::get(FALSE)
      ->addSelect('id', 'is_blocked_by_cancelled_contribution')
      ->addWhere('id', '=', $recur['id'])
      ->execute()
      ->single();
    return (bool) $row['is_blocked_by_cancelled_contribution'];
  }

  private function savedSearch(): array {
    $search = \Civi\Api4\SavedSearch::get(FALSE)
      ->addWhere('name', '=', 'Blocked_Recurring_Contributions')
      ->execute()
      ->first();
    $this->assertNotNull($search, 'The managed saved search is installed');
    return $search;
  }

  /**
   * The ids the listing shows, running the Search Kit display as the page does (with permission checks on), as a user who
   * holds exactly these permissions.
   *
   * @return int[]
   */
  private function listedIdsAs(array $permissions): array {
    CRM_Core_Config::singleton()->userPermissionClass->permissions = $permissions;
    $rows = civicrm_api4('SearchDisplay', 'run', [
      'checkPermissions' => TRUE,
      'savedSearch' => 'Blocked_Recurring_Contributions',
      'display' => 'Blocked_Recurring_Contribution_Listing',
      'return' => 'page:1',
    ])->getArrayCopy();
    return array_map(function ($row) {
      return (int) $row['data']['id'];
    }, $rows);
  }

  private function listedIds(): array {
    return $this->listedIdsAs(self::EDIT_CONTRIBUTIONS_ONLY);
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
    ContributionRecur::update(FALSE)->addWhere('id', '=', $id)->setValues($values)->execute();
  }

  private function runCheck(array $statusNames = [], bool $includeDisabled = FALSE): array {
    $messages = [];
    ewayrecurring_civicrm_check($messages, $statusNames, $includeDisabled);
    return $messages;
  }

  // ---------------------------------------------------------------------------
  // The calculated field (PU-P1)
  // ---------------------------------------------------------------------------

  /**
   * A record is blocked only when it is due and has a Cancelled contribution dated on or after its waiting date: the
   * next scheduled date, or the retry date while it has one.
   *
   * @testPlan PU-P1
   */
  public function testTheFieldIsSetOnlyForADueRecordWithACancelledContributionOnOrAfterItsWaitingDate(): void {
    $none = $this->recurring();
    $this->assertFalse($this->isBlocked($none), 'No contributions');

    $cancelledAfter = $this->recurring();
    $this->contributionOf($cancelledAfter, 'Cancelled');
    $this->assertTrue($this->isBlocked($cancelledAfter), 'A Cancelled contribution dated after the due date');

    $cancelledBefore = $this->recurring();
    $this->contributionOf($cancelledBefore, 'Cancelled', '-3 months');
    $this->assertFalse($this->isBlocked($cancelledBefore), 'A Cancelled contribution dated before the due date');

    foreach (['Failed', 'Pending', 'Completed'] as $status) {
      $other = $this->recurring();
      $this->contributionOf($other, $status);
      $this->assertFalse($this->isBlocked($other), "A $status contribution does not count");
    }

    $notDue = $this->recurring(['next_sched_contribution_date' => '+1 month']);
    $this->contributionOf($notDue, 'Cancelled', '+2 months');
    $this->assertFalse($this->isBlocked($notDue), 'A record that is not due is not waiting on anything yet');
  }

  /**
   * While a record is being retried the date it waits on is its retry date, not its next scheduled date.
   *
   * @testPlan PU-P1
   */
  public function testForARecordBeingRetriedTheWaitingDateIsTheRetryDate(): void {
    $after = $this->recurring(['failure_count' => 1, 'failure_retry_date' => '-1 hour']);
    $this->contributionOf($after, 'Cancelled');
    $this->assertTrue($this->isBlocked($after), 'Cancelled after the retry date');

    $between = $this->recurring(['failure_count' => 1, 'failure_retry_date' => '-1 hour']);
    $this->contributionOf($between, 'Cancelled', '-1 day');
    $this->assertFalse($this->isBlocked($between), 'Cancelled after the due date but before the retry date');
  }

  // ---------------------------------------------------------------------------
  // The listing (PU-P2)
  // ---------------------------------------------------------------------------

  /**
   * The listing shows blocked, live, In Progress eWAY records and nothing else, to a user who holds only "edit
   * contributions".
   *
   * @testPlan PU-P2
   */
  public function testTheListingShowsOnlyBlockedLiveInProgressEwayRecords(): void {
    $blocked = $this->recurring();
    $this->contributionOf($blocked, 'Cancelled');

    $retrying = $this->recurring(['failure_count' => 1, 'failure_retry_date' => '-1 hour']);
    $this->contributionOf($retrying, 'Cancelled');

    $notBlocked = $this->recurring();
    $this->contributionOf($notBlocked, 'Completed');

    $testMode = $this->recurring(['is_test' => 1]);
    $this->contributionOf($testMode, 'Cancelled');

    $failed = $this->recurring(['contribution_status_id:name' => 'Failed']);
    $this->contributionOf($failed, 'Cancelled');

    $otherType = (int) \Civi\Api4\PaymentProcessorType::get(FALSE)->addWhere('name', '=', 'Dummy')->addSelect('id')->execute()->first()['id'];
    $otherProcessor = Fixture::processor(FALSE, ['payment_processor_type_id' => $otherType]);
    $elsewhere = $this->recurring([], $otherProcessor);
    $this->contributionOf($elsewhere, 'Cancelled');

    $listed = $this->listedIds();
    sort($listed);
    $expected = [(int) $blocked['id'], (int) $retrying['id']];
    sort($expected);
    $this->assertSame($expected, $listed);

    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    $this->assertFalse(CRM_Core_Permission::check('edit contributions'), 'The page needs "edit contributions" (PU-P5)');
  }

  // ---------------------------------------------------------------------------
  // The actions (PU-P3)
  // ---------------------------------------------------------------------------

  /**
   * Reactivate with a date after the cancelled contribution's date unblocks the record; with no date it changes nothing
   * about the block, so the record stays on the list. Both clear the failures and keep it In Progress.
   *
   * @testPlan PU-P3
   */
  public function testReactivateWithADateAfterTheCancelledContributionUnblocksTheRecord(): void {
    $withDate = $this->recurring(['failure_count' => 2, 'failure_retry_date' => '-1 hour']);
    $this->contributionOf($withDate, 'Cancelled');
    $withoutDate = $this->recurring();
    $this->contributionOf($withoutDate, 'Cancelled');
    $this->assertEqualsCanonicalizing([(int) $withDate['id'], (int) $withoutDate['id']], $this->listedIds());

    $this->reactivate((int) $withDate['id'], date('Y-m-d', strtotime('+1 month')));
    $this->reactivate((int) $withoutDate['id']);

    $this->assertSame([(int) $withoutDate['id']], $this->listedIds(), 'Only the one with no new date is still blocked');
    $reloaded = Fixture::reloadRecurring($withDate['id']);
    $this->assertSame(0, (int) $reloaded['failure_count']);
    $this->assertEmpty($reloaded['failure_retry_date']);
    $this->assertSame('In Progress', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $reloaded['contribution_status_id']));
  }

  /**
   * The Cancel dialog does what core's Cancel Subscription does to the record: the status becomes Cancelled, with the
   * cancellation date and the reason, and the record leaves the list.
   *
   * @testPlan PU-P3
   */
  public function testCancelSetsTheRecordCancelledWithTheDateAndReasonAndRemovesItFromTheList(): void {
    $recur = $this->recurring();
    $this->contributionOf($recur, 'Cancelled');
    $this->assertSame([(int) $recur['id']], $this->listedIds());

    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recur['id'])
      ->setValues(['contribution_status_id:name' => 'Cancelled', 'cancel_date' => 'now', 'cancel_reason' => 'Donor asked to stop'])
      ->execute();

    $this->assertSame([], $this->listedIds());
    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('Cancelled', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $reloaded['contribution_status_id']));
    $this->assertNotEmpty($reloaded['cancel_date']);
    $this->assertSame('Donor asked to stop', $reloaded['cancel_reason']);
  }

  /**
   * Cancelling a contribution through the normal save skips the installment (PU-F42), so a record that way is never
   * blocked and never reaches the list.
   *
   * @testPlan PU-P3
   */
  public function testCancellingThroughTheNormalSaveLeavesNothingBlocked(): void {
    $recur = $this->recurring();
    $contribution = Fixture::contribution($recur['contact_id'], [
      'total_amount' => $recur['amount'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => 'Pending',
    ]);

    civicrm_api3('Contribution', 'create', ['id' => $contribution['id'], 'contribution_status_id' => 'Cancelled']);

    $this->assertFalse($this->isBlocked($recur));
    $this->assertSame([], $this->listedIds());
  }

  // ---------------------------------------------------------------------------
  // The status check (PU-P4)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-P4
   */
  public function testTheCheckSaysNothingWhenNothingIsBlockedAndReportsTheCountWithALinkWhenSomethingIs(): void {
    $this->assertSame([], $this->runCheck(['ewayrecurring_blocked']));

    $this->contributionOf($this->recurring(), 'Cancelled');
    $this->contributionOf($this->recurring(), 'Cancelled');
    $notBlocked = $this->recurring();
    $this->contributionOf($notBlocked, 'Completed');
    $testMode = $this->recurring(['is_test' => 1]);
    $this->contributionOf($testMode, 'Cancelled');

    $messages = $this->runCheck(['ewayrecurring_blocked']);

    $this->assertCount(1, $messages);
    $this->assertSame('ewayrecurring_blocked', $messages[0]->getName());
    $this->assertStringContainsString('Found 2 recurring contributions', $messages[0]->getMessage());
    $this->assertMatchesRegularExpression('#civicrm(/|%2F)contribute(/|%2F)blockedrecurring#', $messages[0]->getMessage());
  }

  /**
   * @testPlan PU-P4
   */
  public function testTheCheckHonoursTheStatusNamesFilterAndADisabledStatusPreference(): void {
    $this->contributionOf($this->recurring(), 'Cancelled');

    $this->assertSame([], $this->runCheck(['some_other_check']));
    $this->assertSame([], $this->runCheck(['ewayrecurring_failed']), 'The other check is a separate one');
    $this->assertCount(1, $this->runCheck());

    StatusPreference::create(FALSE)
      ->setValues(['name' => 'ewayrecurring_blocked', 'domain_id' => CRM_Core_Config::domainID(), 'is_active' => FALSE])
      ->execute();
    $this->assertSame([], $this->runCheck(), 'A disabled check stays quiet.');
    $this->assertCount(1, $this->runCheck([], TRUE), 'It is reported when disabled checks are asked for.');
  }

  // ---------------------------------------------------------------------------
  // The page, the menu entry and the actions as declared (PU-P5)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-P5
   */
  public function testThePageTheMenuEntryAndTheActionsAreDeclared(): void {
    $page = require E::path('ang/afsearchBlockedRecurringContributions.aff.php');
    $this->assertSame(['edit contributions'], $page['permission']);
    $this->assertSame('civicrm/contribute/blockedrecurring', $page['server_route']);
    $html = file_get_contents(E::path('ang/afsearchBlockedRecurringContributions.aff.html'));
    $this->assertStringContainsString('search-name="Blocked_Recurring_Contributions"', $html);
    $this->assertStringContainsString('display-name="Blocked_Recurring_Contribution_Listing"', $html);
    $this->assertStringContainsString('Reactivate', $html, 'The instructions explain Reactivate');

    $menu = \Civi\Api4\Navigation::get(FALSE)
      ->addWhere('name', '=', 'afsearchBlockedRecurringContributions')
      ->addWhere('domain_id', '=', CRM_Core_Config::domainID())
      ->addSelect('label', 'url', 'permission', 'permission_operator', 'parent_id:name')
      ->execute()
      ->single();
    $this->assertSame('Blocked Recurring Contributions', $menu['label']);
    $this->assertSame('civicrm/contribute/blockedrecurring', $menu['url']);
    $this->assertSame(['edit contributions'], $menu['permission']);
    $this->assertSame('AND', $menu['permission_operator']);
    $this->assertSame('Contributions', $menu['parent_id:name']);

    $tasks = [];
    ewayrecurring_civicrm_searchKitTasks($tasks, FALSE);
    $this->assertArrayHasKey('reset_recur_status', $tasks['ContributionRecur']);
    $this->assertArrayHasKey('cancel_recur', $tasks['ContributionRecur']);
    $this->assertFileExists(E::path('ang/ewayrecurring/crmSearchTaskCancelRecur.html'));
    $this->assertFileExists(E::path('ang/ewayrecurring/crmSearchTaskCancelRecur.ctrl.js'));

    $display = \Civi\Api4\SearchDisplay::get(FALSE)
      ->addWhere('name', '=', 'Blocked_Recurring_Contribution_Listing')
      ->execute()
      ->single();
    $this->assertContains('reset_recur_status', $display['settings']['actions']);
    $this->assertContains('cancel_recur', $display['settings']['actions']);
    $this->assertSame('Blocked_Recurring_Contributions', $this->savedSearch()['name']);
  }

}
