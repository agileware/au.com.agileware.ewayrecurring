<?php
/**
 * Seed data for the upgrade test, written while release 2.8.1 is installed.
 *
 * Run with `cv php:script` by ci-upgrade-test.sh. It must only use what 2.8.1
 * provides. It records what it created, and the values read back from the
 * database, in the state file that verify-v3.php checks after the upgrade.
 *
 * Test plan: developer/TEST_PLAN.md, the UP suite.
 */

$stateFile = getenv('EWAYRECURRING_UPGRADE_STATE') ?: '/tmp/ewayrecurring-upgrade-state.json';
$state = ['processors' => [], 'tokens' => [], 'recurring' => [], 'transactions' => [], 'settings' => [], 'jobs' => []];

/**
 * The fields to compare for each kind of record, read back as the database
 * holds them so that formatting differences cannot cause false failures.
 */
function snapshot(string $entity, int $id, array $fields): array {
  $row = civicrm_api3($entity, 'getsingle', ['id' => $id]);
  $snap = [];
  foreach ($fields as $field) {
    $snap[$field] = isset($row[$field]) ? (string) $row[$field] : NULL;
  }
  return $snap;
}

const PROCESSOR_FIELDS = ['name', 'payment_processor_type_id', 'class_name', 'user_name', 'password', 'is_test', 'is_active', 'domain_id'];
const TOKEN_FIELDS = ['contact_id', 'payment_processor_id', 'token', 'expiry_date', 'masked_account_number'];
const RECUR_FIELDS = ['contact_id', 'contribution_status_id', 'amount', 'frequency_unit', 'frequency_interval', 'installments', 'payment_processor_id', 'payment_token_id', 'processor_id', 'is_test', 'failure_count', 'failure_retry_date', 'next_sched_contribution_date'];
const TRANSACTION_FIELDS = ['contribution_id', 'payment_processor_id', 'access_code', 'failed_message', 'status', 'tries', 'is_email_receipt'];

// ---------------------------------------------------------------------------
// Settings an administrator may have saved (UP-05).
// ---------------------------------------------------------------------------
$settings = [
  'eway_recurring_contribution_max_retry' => 3,
  'eway_recurring_contribution_retry_delay' => 6,
  'eway_recurring_keep_sending_receipts' => 1,
  'eway_settlement_window_days' => 21,
];
foreach ($settings as $name => $value) {
  Civi::settings()->set($name, $value);
}

// ---------------------------------------------------------------------------
// Payment processors, live and test (UP-02).
// ---------------------------------------------------------------------------
$processorIds = [];
foreach (['live' => 0, 'test' => 1] as $mode => $isTest) {
  $result = civicrm_api3('PaymentProcessor', 'create', [
    'payment_processor_type_id' => 'eWay_Recurring',
    'name' => 'Upgrade test eWAY',
    'title' => 'Upgrade test eWAY',
    'description' => 'Created by the upgrade test',
    'user_name' => "upgrade-test-key-$mode",
    'password' => "upgrade-test-password-$mode",
    'is_test' => $isTest,
    'is_active' => 1,
    'is_recur' => 1,
    'domain_id' => CRM_Core_Config::domainID(),
    'class_name' => 'Payment_eWAYRecurring',
    'billing_mode' => 4,
    'payment_type' => 1,
  ]);
  $processorIds[$mode] = (int) $result['id'];
  $state['processors'][$mode] = ['id' => $processorIds[$mode], 'fields' => snapshot('PaymentProcessor', $processorIds[$mode], PROCESSOR_FIELDS)];
}
Civi::settings()->set('eway_settlement_processors', [$processorIds['live']]);
$settings['eway_settlement_processors'] = [$processorIds['live']];
$state['settings'] = $settings;

// ---------------------------------------------------------------------------
// A contact, and saved cards with every field filled so that the metadata job
// has nothing to look up (no call to eWAY) (UP-03).
// ---------------------------------------------------------------------------
$contactId = (int) civicrm_api3('Contact', 'create', [
  'contact_type' => 'Individual',
  'first_name' => 'Upgrade',
  'last_name' => 'Tester',
])['id'];

$tokenId = (int) civicrm_api3('PaymentToken', 'create', [
  'contact_id' => $contactId,
  'payment_processor_id' => $processorIds['live'],
  'token' => '912345678901',
  'expiry_date' => date('Y-m-t', strtotime('+2 years')),
  'masked_account_number' => '444433XXXXXX1111',
  'created_date' => 'now',
])['id'];
$state['tokens'][] = ['id' => $tokenId, 'fields' => snapshot('PaymentToken', $tokenId, TOKEN_FIELDS)];

$legacyTokenId = (int) civicrm_api3('PaymentToken', 'create', [
  'contact_id' => $contactId,
  'payment_processor_id' => $processorIds['live'],
  'token' => '912345678902',
  'expiry_date' => date('Y-m-t', strtotime('+1 year')),
  'masked_account_number' => '510510XXXXXX5100',
  'created_date' => 'now',
])['id'];
$state['tokens'][] = ['id' => $legacyTokenId, 'fields' => snapshot('PaymentToken', $legacyTokenId, TOKEN_FIELDS)];

// ---------------------------------------------------------------------------
// Recurring contributions in each state (UP-03), including the two stuck
// states left by the old retry behaviour (UP-10, UP-11).
//
// Each gets 'expect': 'unchanged', or 'Failed' for the records the upgrade
// step must mark. Next scheduled dates are in the future (or the record cannot
// be charged), so the job checks in verify-v3.php find nothing due and make no
// call to eWAY.
// ---------------------------------------------------------------------------
$cases = [
  // label                    status         live/test  failures  retry date   expect
  'healthy'                 => ['In Progress', 0, 0, NULL,       'unchanged'],
  'completed'               => ['Completed',   0, 0, NULL,       'unchanged'],
  'cancelled'               => ['Cancelled',   0, 3, '-2 days',  'unchanged'],
  'pending'                 => ['Pending',     0, 3, '-2 days',  'unchanged'],
  'already failed'          => ['Failed',      0, 3, '-2 days',  'unchanged'],
  'being retried'           => ['In Progress', 0, 1, '+2 days',  'unchanged'],
  'exhausted, no retry date' => ['In Progress', 0, 3, NULL,       'unchanged'],
  'test mode, exhausted'    => ['In Progress', 1, 3, '-2 days',  'unchanged'],
  // The stuck states the upgrade step repairs.
  'exhausted'               => ['In Progress', 0, 3, '-2 days',  'Failed'],
  'exhausted, over limit'   => ['In Progress', 0, 5, '-2 days',  'Failed'],
  'retry date, no failures' => ['In Progress', 0, 0, '-2 days',  'Failed'],
];
foreach ($cases as $label => [$status, $isTest, $failures, $retryDate, $expect]) {
  $params = [
    'contact_id' => $contactId,
    'amount' => 10,
    'frequency_unit' => 'month',
    'frequency_interval' => 1,
    'installments' => 0,
    'contribution_status_id' => $status,
    'financial_type_id' => 1,
    'payment_processor_id' => $processorIds['live'],
    'payment_token_id' => $tokenId,
    'processor_id' => '912345678901',
    'is_test' => $isTest,
    'failure_count' => $failures,
    'start_date' => date('Y-m-d H:i:s', strtotime('-3 months')),
    'create_date' => date('Y-m-d H:i:s', strtotime('-3 months')),
    'next_sched_contribution_date' => date('Y-m-d H:i:s', strtotime('+10 days')),
  ];
  if ($retryDate !== NULL) {
    $params['failure_retry_date'] = date('Y-m-d H:i:s', strtotime($retryDate));
  }
  $recurId = (int) civicrm_api3('ContributionRecur', 'create', $params)['id'];
  $state['recurring'][$label] = [
    'id' => $recurId,
    'expect' => $expect,
    'fields' => snapshot('ContributionRecur', $recurId, RECUR_FIELDS),
  ];
}

// ---------------------------------------------------------------------------
// Verification queue rows with every column filled in (UP-03). They are final
// (success and failed), so the verification job has nothing to check.
// ---------------------------------------------------------------------------
foreach ([['status' => 1, 'tries' => 2, 'failed_message' => NULL, 'is_email_receipt' => 0], ['status' => 2, 'tries' => 4, 'failed_message' => 'Declined by the issuer (upgrade test)', 'is_email_receipt' => 1]] as $i => $row) {
  $contributionId = (int) civicrm_api3('Contribution', 'create', [
    'contact_id' => $contactId,
    'financial_type_id' => 1,
    'total_amount' => 10,
    'contribution_status_id' => 'Pending',
    'receive_date' => date('Y-m-d H:i:s'),
    'invoice_id' => 'upgradetest' . $i . bin2hex(random_bytes(4)),
    'is_email_receipt' => 0,
  ])['id'];
  $params = $row + [
    'contribution_id' => $contributionId,
    'payment_processor_id' => $processorIds['live'],
    'access_code' => "UPGRADETESTACCESSCODE$i",
  ];
  $id = (int) civicrm_api3('EwayContributionTransactions', 'create', array_filter($params, function ($v) {
    return $v !== NULL;
  }))['id'];
  $state['transactions'][] = ['id' => $id, 'fields' => snapshot('EwayContributionTransactions', $id, TRANSACTION_FIELDS)];
}

// ---------------------------------------------------------------------------
// Scheduled jobs an administrator changed (UP-04). These three are managed with
// "update: never", so their changes must survive the upgrade.
// ---------------------------------------------------------------------------
$jobChanges = [
  'eWay Transaction Verifications' => ['run_frequency' => 'Daily', 'is_active' => 0],
  'eWay Recurring: fill missing tokens metadata' => ['run_frequency' => 'Daily'],
  'eWay Settlement Sync' => ['run_frequency' => 'Hourly'],
];
foreach ($jobChanges as $name => $changes) {
  $job = civicrm_api3('Job', 'getsingle', ['name' => $name]);
  civicrm_api3('Job', 'create', ['id' => $job['id']] + $changes);
  $after = civicrm_api3('Job', 'getsingle', ['id' => $job['id']]);
  $state['jobs'][$name] = [
    'id' => (int) $job['id'],
    'run_frequency' => (string) $after['run_frequency'],
    'is_active' => (string) $after['is_active'],
  ];
}

// ---------------------------------------------------------------------------
// A managed Search Kit item an administrator edited locally (UP-06).
// ---------------------------------------------------------------------------
$search = civicrm_api4('SavedSearch', 'get', ['where' => [['name', '=', 'Failed_Recurring_Contributions']], 'checkPermissions' => FALSE])->first();
if ($search) {
  civicrm_api4('SavedSearch', 'update', ['where' => [['id', '=', $search['id']]], 'values' => ['label' => 'Failed Recurring Contributions (edited locally)'], 'checkPermissions' => FALSE]);
  $state['search'] = ['id' => (int) $search['id'], 'label' => 'Failed Recurring Contributions (edited locally)'];
}

file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
fwrite(STDERR, sprintf("Seeded: %d processors, %d tokens, %d recurring contributions, %d queue rows. State in %s\n",
  count($state['processors']), count($state['tokens']), count($state['recurring']), count($state['transactions']), $stateFile));
