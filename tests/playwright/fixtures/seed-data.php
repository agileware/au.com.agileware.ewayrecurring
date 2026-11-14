<?php
/**
 * Seed data for the Playwright tests. Run with `cv scr`; safe to run again.
 *
 * It grows as specs are written. It creates a live and a test eWAY processor with dummy credentials (the stub
 * answers for them), a non-eWAY processor for contrast, a donor with a saved card, a once-off contribution page
 * that takes eWAY payments (plus two more pages for the non-ASCII and zero-amount cases), and outbound mail redirected to the database so tests can read what would have been sent.
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

// A once-off contribution page that takes eWAY payments: a price set of fixed amounts, no recurring option,
// the confirm step on, and no receipt email. Created once; the page is found by its title.
$page = $data['pages']['onceOff'];
$existingPage = civicrm_api3('ContributionPage', 'get', ['title' => $page['title'], 'options' => ['limit' => 1]]);
if (empty($existingPage['count'])) {
  $pageId = (int) civicrm_api3('ContributionPage', 'create', [
    'title' => $page['title'],
    'financial_type_id' => 'Donation',
    'is_active' => 1,
    'is_monetary' => 1,
    'currency' => 'AUD',
    'payment_processor' => [$liveId],
    'amount_block_is_active' => 1,
    'is_allow_other_amount' => 0,
    'is_pay_later' => 0,
    'is_confirm_enabled' => 1,
    'is_email_receipt' => 0,
    'is_recur' => 0,
    'intro_text' => '<p>Playwright once-off donation page.</p>',
    'thankyou_title' => 'Thank you for your donation',
    'thankyou_text' => '<p>Your donation has been received.</p>',
  ])['id'];

  $priceSetId = (int) civicrm_api3('PriceSet', 'create', [
    'name' => 'playwright_once_off_amounts',
    'title' => 'Playwright once-off amounts',
    'extends' => 2,
    'financial_type_id' => 'Donation',
    'is_active' => 1,
  ])['id'];
  $fieldId = (int) civicrm_api3('PriceField', 'create', [
    'price_set_id' => $priceSetId,
    'name' => 'amount',
    'label' => 'Donation amount',
    'html_type' => 'Radio',
    'is_display_amounts' => 1,
    'is_required' => 1,
    'is_active' => 1,
  ])['id'];
  foreach ($page['amounts'] as $weight => $amount) {
    civicrm_api3('PriceFieldValue', 'create', [
      'price_field_id' => $fieldId,
      'name' => 'amount_' . $amount,
      'label' => '$' . $amount,
      'amount' => $amount,
      'weight' => $weight + 1,
      'is_active' => 1,
      'financial_type_id' => 'Donation',
    ]);
  }
  CRM_Price_BAO_PriceSet::addTo('civicrm_contribution_page', $pageId, $priceSetId);
}

// More once-off pages for the specs that need a different page. Each one is found by its title and created once:
//  - nonAscii: a title with accented characters, which the extension sends to eWAY inside the invoice description
//    (PW-F1-09);
//  - free: one price option that costs nothing, so the contribution has a zero total (PW-F1-11).
function seedOnceOffPage(array $page, int $processorId, string $priceSetName, array $amounts, array $pageValues = []): int {
  $existing = civicrm_api3('ContributionPage', 'get', ['title' => $page['title'], 'options' => ['limit' => 1]]);
  if (!empty($existing['count'])) {
    return (int) $existing['id'];
  }
  $pageId = (int) civicrm_api3('ContributionPage', 'create', $pageValues + [
    'title' => $page['title'],
    'financial_type_id' => 'Donation',
    'is_active' => 1,
    'is_monetary' => 1,
    'currency' => 'AUD',
    'payment_processor' => [$processorId],
    'amount_block_is_active' => 1,
    'is_allow_other_amount' => 0,
    'is_pay_later' => 0,
    'is_confirm_enabled' => 1,
    'is_email_receipt' => 0,
    'is_recur' => 0,
    'intro_text' => '<p>Playwright donation page.</p>',
    'thankyou_title' => 'Thank you for your donation',
    'thankyou_text' => '<p>Your donation has been received.</p>',
  ])['id'];

  $priceSetId = (int) civicrm_api3('PriceSet', 'create', [
    'name' => $priceSetName,
    'title' => $priceSetName,
    'extends' => 2,
    'financial_type_id' => 'Donation',
    'is_active' => 1,
  ])['id'];
  $fieldId = (int) civicrm_api3('PriceField', 'create', [
    'price_set_id' => $priceSetId,
    'name' => 'amount',
    'label' => 'Donation amount',
    'html_type' => 'Radio',
    'is_display_amounts' => 1,
    'is_required' => 1,
    'is_active' => 1,
  ])['id'];
  $weight = 1;
  foreach ($amounts as $label => $amount) {
    civicrm_api3('PriceFieldValue', 'create', [
      'price_field_id' => $fieldId,
      'name' => 'amount_' . $weight,
      'label' => (string) $label,
      'amount' => $amount,
      'weight' => $weight,
      'is_active' => 1,
      'financial_type_id' => 'Donation',
    ]);
    $weight++;
  }
  CRM_Price_BAO_PriceSet::addTo('civicrm_contribution_page', $pageId, $priceSetId);
  return $pageId;
}

$nonAsciiPage = $data['pages']['nonAscii'];
$nonAsciiAmounts = [];
foreach ($nonAsciiPage['amounts'] as $amount) {
  $nonAsciiAmounts['$' . $amount] = $amount;
}
seedOnceOffPage($nonAsciiPage, $liveId, 'playwright_non_ascii_amounts', $nonAsciiAmounts);
seedOnceOffPage($data['pages']['free'], $liveId, 'playwright_free_amounts', [$data['pages']['free']['optionLabel'] => 0]);

// A recurring-enabled page (PW-F2): the donor can tick "make this recurring", choose the interval and the unit
// (week, month or year) and give a number of installments.
$recurringPage = $data['pages']['recurring'];
$recurringAmounts = [];
foreach ($recurringPage['amounts'] as $amount) {
  $recurringAmounts['$' . $amount] = $amount;
}
seedOnceOffPage($recurringPage, $liveId, 'playwright_recurring_amounts', $recurringAmounts, [
  'is_recur' => 1,
  'is_recur_interval' => 1,
  'is_recur_installments' => 1,
  'recur_frequency_unit' => implode(CRM_Core_DAO::VALUE_SEPARATOR, $recurringPage['units']),
  'intro_text' => '<p>Playwright recurring donation page.</p>',
]);

// Events that take eWAY payments (PW-F3): a paid one that accepts up to two more people on the same registration,
// and a free one. Each is found by its title and created once. Registration uses the core "Your Registration Info"
// profile (email, first and last name) for the first person and for the extra people.
function seedEvent(array $event, ?int $processorId, ?array $fees): int {
  $existing = civicrm_api3('Event', 'get', ['title' => $event['title'], 'options' => ['limit' => 1]]);
  if (!empty($existing['count'])) {
    return (int) $existing['id'];
  }
  $values = [
    'title' => $event['title'],
    'event_type_id' => 1,
    'start_date' => date('Y-m-d 10:00:00', strtotime('+6 months')),
    'end_date' => date('Y-m-d 16:00:00', strtotime('+6 months')),
    'is_active' => 1,
    'is_public' => 1,
    'is_online_registration' => 1,
    'registration_start_date' => date('Y-m-d 00:00:00', strtotime('-1 month')),
    'registration_end_date' => date('Y-m-d 00:00:00', strtotime('+5 months')),
    'is_email_confirm' => 0,
    'is_confirm_enabled' => 1,
    'is_multiple_registrations' => $fees ? 1 : 0,
    'max_additional_participants' => $fees ? 2 : 0,
    'allow_same_participant_emails' => 0,
    'default_role_id' => 1,
    'confirm_title' => 'Confirm your registration',
    'thankyou_title' => 'Thank you for registering',
    'thankyou_text' => '<p>Your registration has been received.</p>',
    'registration_link_text' => 'Register Now',
  ];
  if ($fees) {
    $values += [
      'is_monetary' => 1,
      'currency' => 'AUD',
      'financial_type_id' => 'Event Fee',
      'payment_processor' => [$processorId],
      'is_pay_later' => 0,
      'fee_label' => 'Event Fee(s)',
    ];
  }
  $eventId = (int) civicrm_api3('Event', 'create', $values)['id'];

  $profileId = (int) civicrm_api3('UFGroup', 'getvalue', ['name' => 'event_registration', 'return' => 'id']);
  foreach (['CiviEvent', 'CiviEvent_Additional'] as $module) {
    civicrm_api3('UFJoin', 'create', [
      'module' => $module,
      'entity_table' => 'civicrm_event',
      'entity_id' => $eventId,
      'weight' => 1,
      'uf_group_id' => $profileId,
      'is_active' => 1,
    ]);
  }

  if ($fees) {
    $priceSetId = (int) civicrm_api3('PriceSet', 'create', [
      'name' => 'playwright_event_fees',
      'title' => 'Playwright event fees',
      'extends' => 1,
      'financial_type_id' => 'Event Fee',
      'is_active' => 1,
    ])['id'];
    $fieldId = (int) civicrm_api3('PriceField', 'create', [
      'price_set_id' => $priceSetId,
      'name' => 'ticket',
      'label' => 'Ticket',
      'html_type' => 'Radio',
      'is_display_amounts' => 1,
      'is_required' => 1,
      'is_active' => 1,
    ])['id'];
    $weight = 1;
    foreach ($fees as $label => $amount) {
      civicrm_api3('PriceFieldValue', 'create', [
        'price_field_id' => $fieldId,
        'name' => 'ticket_' . $weight,
        'label' => (string) $label,
        'amount' => $amount,
        'weight' => $weight,
        'is_active' => 1,
        'financial_type_id' => 'Event Fee',
      ]);
      $weight++;
    }
    CRM_Price_BAO_PriceSet::addTo('civicrm_event', $eventId, $priceSetId);
  }
  return $eventId;
}

$paidEvent = $data['events']['paid'];
seedEvent($paidEvent, $liveId, ['$' . $paidEvent['fee'] => $paidEvent['fee']]);
seedEvent($data['events']['free'], NULL, NULL);

// Outbound mail goes to the database instead of being sent (CiviCRM's "redirect to database" option).
Civi::settings()->set('mailing_backend', ['outBound_option' => CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB]);

fwrite(STDERR, "Playwright seed data is in place.\n");
