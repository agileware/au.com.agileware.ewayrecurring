<?php
/**
 * Seed data for the Playwright tests. Run with `cv scr`; safe to run again.
 *
 * It grows as specs are written. Today it creates what the infrastructure checks need: a live and a test eWAY
 * processor with dummy credentials (the stub answers for them), a non-eWAY processor for contrast, a donor with
 * a saved card, and outbound mail redirected to the database so tests can read what would have been sent.
 *
 * Names come from test-data.json so specs and the seed cannot drift.
 */

use Civi\Api4\Contact;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;

$data = json_decode(file_get_contents(__DIR__ . '/test-data.json'), TRUE);
$domainId = CRM_Core_Config::domainID();

function seedProcessor(string $name, string $typeName, bool $isTest, array $extra = []): int {
  $existing = PaymentProcessor::get(FALSE)
    ->addSelect('id')
    ->addWhere('name', '=', $name)
    ->addWhere('is_test', '=', $isTest)
    ->execute()
    ->first();
  if ($existing) {
    return (int) $existing['id'];
  }
  return (int) PaymentProcessor::create(FALSE)
    ->setValues($extra + [
      'name' => $name,
      'title' => $name,
      'payment_processor_type_id:name' => $typeName,
      'is_test' => $isTest,
      'is_active' => TRUE,
      'domain_id' => CRM_Core_Config::domainID(),
    ])
    ->execute()
    ->first()['id'];
}

// eWAY processors: live and test. The credentials are dummy; the stub answers instead of eWAY.
$liveId = 0;
foreach ([FALSE, TRUE] as $isTest) {
  $id = seedProcessor($data['processors']['live'], 'eWay_Recurring', $isTest, [
    'user_name' => 'playwright-key',
    'password' => 'playwright-password',
  ]);
  if (!$isTest) {
    $liveId = $id;
  }
}

// A processor that is not eWAY, for the cases that check eWAY records are told apart.
foreach ([FALSE, TRUE] as $isTest) {
  seedProcessor($data['processors']['dummy'], 'Dummy', $isTest);
}

// A donor with a saved card.
$donor = $data['contacts']['donor'];
$contact = Contact::get(FALSE)
  ->addSelect('id')
  ->addWhere('first_name', '=', $donor['firstName'])
  ->addWhere('last_name', '=', $donor['lastName'])
  ->execute()
  ->first();
$contactId = $contact ? (int) $contact['id'] : (int) Contact::create(FALSE)
  ->addValue('contact_type', 'Individual')
  ->addValue('first_name', $donor['firstName'])
  ->addValue('last_name', $donor['lastName'])
  ->execute()
  ->first()['id'];

$hasToken = PaymentToken::get(FALSE)
  ->addWhere('contact_id', '=', $contactId)
  ->addWhere('token', '=', $data['tokens']['valid']['token'])
  ->selectRowCount()
  ->execute()
  ->count();
if (!$hasToken) {
  PaymentToken::create(FALSE)
    ->setValues([
      'contact_id' => $contactId,
      'payment_processor_id' => $liveId,
      'token' => $data['tokens']['valid']['token'],
      'expiry_date' => date('Y-m-t', strtotime('+2 years')),
      'masked_account_number' => $data['tokens']['valid']['masked'],
      'created_date' => 'now',
    ])
    ->execute();
}

// Outbound mail goes to the database instead of being sent (CiviCRM's "redirect to database" option).
Civi::settings()->set('mailing_backend', ['outBound_option' => CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB]);

fwrite(STDERR, "Playwright seed data is in place.\n");
