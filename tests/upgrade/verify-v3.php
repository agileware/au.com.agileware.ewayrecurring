<?php
/**
 * Checks after the upgrade from release 2.8.1 to the version being tested.
 *
 * Run with `cv php:script` by ci-upgrade-test.sh. It reads the state file that
 * seed-v2.8.1.php wrote, prints one line per check, and exits non-zero if any
 * check fails.
 *
 * Test plan: developer/TEST_PLAN.md, the UP suite. Each check carries its ID in
 * a "testPlan" comment.
 */

$key = 'au.com.agileware.ewayrecurring';
$stateFile = getenv('EWAYRECURRING_UPGRADE_STATE') ?: '/tmp/ewayrecurring-upgrade-state.json';
$state = json_decode(file_get_contents($stateFile), TRUE);
if (!$state) {
  fwrite(STDERR, "Cannot read the seed state in $stateFile\n");
  exit(2);
}

// cv runs this file from inside a function, so a plain top-level variable is local to that function and
// a `global` in check() would not see it. Count failures in $GLOBALS so the exit code is right.
$GLOBALS['ewayrecurring_upgrade_failures'] = 0;
function check(string $id, string $what, bool $ok, string $detail = ''): void {
  fwrite(STDERR, sprintf("%s %s %s%s\n", $ok ? 'PASS' : 'FAIL', $id, $what, ($ok || $detail === '') ? '' : "\n       $detail"));
  if (!$ok) {
    $GLOBALS['ewayrecurring_upgrade_failures']++;
  }
}

/**
 * Compare a record with what was seeded.
 *
 * @return string An empty string when they match, otherwise what differs.
 */
function differences(string $entity, int $id, array $expected): string {
  try {
    $row = civicrm_api3($entity, 'getsingle', ['id' => $id]);
  }
  catch (Exception $e) {
    return "$entity $id is missing: " . $e->getMessage();
  }
  $diff = [];
  foreach ($expected as $field => $value) {
    $now = isset($row[$field]) ? (string) $row[$field] : NULL;
    if ($now !== $value) {
      $diff[] = "$field was " . var_export($value, TRUE) . ' now ' . var_export($now, TRUE);
    }
  }
  return implode('; ', $diff);
}

// testPlan UP-01
$revisions = (new CRM_eWAYRecurring_Upgrader())->getRevisions();
$latest = (int) max($revisions);
$stored = (int) CRM_Core_BAO_Extension::getSchemaVersion($key);
check('UP-01', "stored schema version $stored equals the latest upgrade step $latest", $stored === $latest);
check('UP-01', 'the extension is still enabled', CRM_Extension_System::singleton()->getManager()->getStatus($key) === CRM_Extension_Manager::STATUS_INSTALLED);

// testPlan UP-02
foreach ($state['processors'] as $mode => $processor) {
  $diff = differences('PaymentProcessor', $processor['id'], $processor['fields']);
  check('UP-02', "the $mode eWAY processor is unchanged", $diff === '', $diff);
}

// testPlan UP-03
foreach ($state['tokens'] as $token) {
  $diff = differences('PaymentToken', $token['id'], $token['fields']);
  check('UP-03', "payment token {$token['id']} is unchanged", $diff === '', $diff);
}
foreach ($state['transactions'] as $row) {
  $diff = differences('EwayContributionTransactions', $row['id'], $row['fields']);
  check('UP-03', "verification queue row {$row['id']} is unchanged", $diff === '', $diff);
}
foreach (['civicrm_eway_contribution_transactions' => ['contribution_id', 'payment_processor_id']] as $table => $columns) {
  foreach ($columns as $column) {
    $count = (int) CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %1 AND COLUMN_NAME = %2 AND REFERENCED_TABLE_NAME IS NOT NULL',
      [1 => [$table, 'String'], 2 => [$column, 'String']]
    );
    check('UP-03', "$table.$column still has its foreign key", $count >= 1);
  }
}
$unchangedLabels = [];
foreach ($state['recurring'] as $label => $recur) {
  if ($recur['expect'] !== 'unchanged') {
    continue;
  }
  $unchangedLabels[] = $label;
  $diff = differences('ContributionRecur', $recur['id'], $recur['fields']);
  check('UP-03', "recurring contribution '$label' is unchanged", $diff === '', $diff);
}

// testPlan UP-10
// testPlan UP-11
$failedStatus = (string) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Failed');
foreach ($state['recurring'] as $label => $recur) {
  if ($recur['expect'] !== 'Failed') {
    continue;
  }
  $id = $label === 'retry date, no failures' ? 'UP-11' : 'UP-10';
  $expected = ['contribution_status_id' => $failedStatus] + $recur['fields'];
  $diff = differences('ContributionRecur', $recur['id'], $expected);
  check($id, "recurring contribution '$label' is now Failed and nothing else changed", $diff === '', $diff);
  $listed = civicrm_api4('ContributionRecur', 'get', [
    'select' => ['id'],
    'where' => [['id', '=', $recur['id']], ['contribution_status_id:name', '=', 'Failed']],
    'checkPermissions' => FALSE,
  ])->count();
  check($id, "recurring contribution '$label' is where the Failed Recurring list looks", $listed === 1);
}

// testPlan UP-04
foreach ($state['jobs'] as $name => $before) {
  // "update: never": an administrator's changes stay.
  $job = civicrm_api3('Job', 'getsingle', ['id' => $before['id']]);
  check('UP-04', "job '$name' keeps the administrator's changes", (string) $job['run_frequency'] === $before['run_frequency'] && (string) $job['is_active'] === $before['is_active'],
    "run_frequency {$before['run_frequency']} -> {$job['run_frequency']}, is_active {$before['is_active']} -> {$job['is_active']}");
}
$recurringJob = civicrm_api3('Job', 'get', ['name' => 'eWay Recurring Payments']);
check('UP-04', "the 'eWay Recurring Payments' job exists, is active and runs always",
  $recurringJob['count'] === 1 && (int) reset($recurringJob['values'])['is_active'] === 1 && reset($recurringJob['values'])['run_frequency'] === 'Always');
$settlement = civicrm_api3('Job', 'getsingle', ['name' => 'eWay Settlement Sync']);
check('UP-04', 'the Settlement Sync job is still disabled unless an administrator enabled it', (int) $settlement['is_active'] === 0);

// testPlan UP-05
foreach ($state['settings'] as $name => $value) {
  $now = Civi::settings()->get($name);
  check('UP-05', "setting $name keeps its value", $now == $value, 'now ' . json_encode($now));
}
foreach (['eway_recurring_contribution_max_retry', 'eway_recurring_contribution_retry_delay', 'eway_recurring_keep_sending_receipts', 'eway_settlement_window_days', 'eway_settlement_processors'] as $name) {
  $threw = FALSE;
  try {
    Civi::settings()->get($name);
  }
  catch (Exception $e) {
    $threw = TRUE;
  }
  check('UP-05', "setting $name can be read", !$threw);
}

// testPlan UP-06
$search = civicrm_api4('SavedSearch', 'get', ['where' => [['name', '=', 'Failed_Recurring_Contributions']], 'checkPermissions' => FALSE])->first();
check('UP-06', 'the Failed Recurring Contributions saved search exists', !empty($search));
if (!empty($state['search'])) {
  check('UP-06', 'a locally edited managed saved search keeps its label', ($search['label'] ?? NULL) === $state['search']['label'], 'label is ' . var_export($search['label'] ?? NULL, TRUE));
}
$display = civicrm_api4('SearchDisplay', 'get', ['where' => [['saved_search_id.name', '=', 'Failed_Recurring_Contributions']], 'checkPermissions' => FALSE])->count();
check('UP-06', 'the Failed Recurring Contributions display exists', $display >= 1);
$nav = civicrm_api4('Navigation', 'get', ['where' => [['name', '=', 'afsearchFailedRecurringContributions']], 'checkPermissions' => FALSE])->count();
check('UP-06', 'the Failed Recurring Contributions navigation item exists', $nav >= 1);

// testPlan UP-08
// Run the three scheduled jobs the way the scheduler does. Nothing is due (see
// the seed), so none of them calls eWAY.
foreach (['eWay Recurring Payments', 'eWay Transaction Verifications', 'eWay Recurring: fill missing tokens metadata'] as $name) {
  try {
    $job = civicrm_api3('Job', 'getsingle', ['name' => $name]);
    $result = civicrm_api3('Job', 'execute', ['id' => $job['id']]);
    check('UP-08', "the '$name' job runs without error", empty($result['is_error']));
  }
  catch (Exception $e) {
    check('UP-08', "the '$name' job runs without error", FALSE, $e->getMessage());
  }
}
foreach ($state['recurring'] as $label => $recur) {
  // Running the jobs must not have charged or changed anything it should not.
  if ($recur['expect'] === 'unchanged') {
    $diff = differences('ContributionRecur', $recur['id'], $recur['fields']);
    check('UP-08', "recurring contribution '$label' is still unchanged after the jobs ran", $diff === '', $diff);
  }
}

// testPlan UP-09
// Core keeps a menu item's is_ajax flag in module_data, as the strings "true" and "false" in some versions and
// as 1 and 0 in others, so compare it as a boolean.
$menu = [];
$dao = CRM_Core_DAO::executeQuery("SELECT path, module_data FROM civicrm_menu WHERE path LIKE 'civicrm/ewayrecurring/%'");
while ($dao->fetch()) {
  $data = $dao->module_data ? unserialize($dao->module_data) : [];
  $menu[$dao->path] = array_key_exists('is_ajax', $data) ? filter_var($data['is_ajax'], FILTER_VALIDATE_BOOLEAN) : NULL;
}
check('UP-09', 'createtoken is flagged is_ajax (a plain GET is refused)', ($menu['civicrm/ewayrecurring/createtoken'] ?? NULL) === TRUE, json_encode($menu));
check('UP-09', 'savetoken is flagged not is_ajax', ($menu['civicrm/ewayrecurring/savetoken'] ?? NULL) === FALSE, json_encode($menu));
check('UP-09', 'the removed verifyupdatetoken route is gone', !array_key_exists('civicrm/ewayrecurring/verifyupdatetoken', $menu), json_encode($menu));

$failures = $GLOBALS['ewayrecurring_upgrade_failures'];
fwrite(STDERR, $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n");
exit($failures ? 1 : 0);
