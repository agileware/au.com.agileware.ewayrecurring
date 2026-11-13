<?php

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorType;
use Civi\Api4\PaymentToken;

/**
 * Data set-up shared by the extension's headless tests.
 *
 * Everything runs inside the test's database transaction (TransactionalInterface),
 * so nothing here needs cleaning up. Contributions use APIv3 because it creates
 * the line items that Contribution.repeattransaction and completetransaction
 * rely on.
 *
 * Test plan: developer/TEST_PLAN.md section 2.2.
 */
class CRM_eWAYRecurring_Test_EwayFixture {

  /**
   * Create an eWAY payment processor and return its row, as APIv4 returns it
   * (which is also the shape the extension's own code passes around).
   */
  public static function processor(bool $isTest = FALSE, array $values = []): array {
    static $counter = 0;
    $counter++;

    $typeId = PaymentProcessorType::get(FALSE)
      ->addWhere('name', '=', 'eWay_Recurring')
      ->addSelect('id')
      ->execute()
      ->first()['id'];

    $id = PaymentProcessor::create(FALSE)
      ->setValues($values + [
        'payment_processor_type_id' => $typeId,
        'name' => 'Test eWAY Processor ' . $counter,
        'title' => 'Test eWAY Processor ' . $counter,
        'user_name' => 'test-api-key',
        'password' => 'test-api-password',
        'is_test' => $isTest ? 1 : 0,
        'is_active' => 1,
        'domain_id' => CRM_Core_Config::domainID(),
      ])
      ->execute()
      ->first()['id'];

    return self::processorRow($id);
  }

  public static function processorRow(int $id): array {
    return PaymentProcessor::get(FALSE)
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
  }

  /**
   * A contact. By default it has a billing address, as every donor who paid
   * through a contribution page does; pass $withAddress = FALSE for none.
   */
  public static function contact(string $first = 'Test', string $last = 'Donor', bool $withAddress = TRUE): int {
    $id = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', $first)
      ->addValue('last_name', $last)
      ->execute()
      ->first()['id'];

    if ($withAddress) {
      Address::create(FALSE)
        ->addValue('contact_id', $id)
        ->addValue('location_type_id:name', 'Billing')
        ->addValue('is_billing', TRUE)
        ->addValue('street_address', '1 Test Street')
        ->addValue('city', 'Canberra')
        ->addValue('postal_code', '2600')
        ->execute();
    }
    return $id;
  }

  /**
   * A contribution page. Contributions made through a page carry its id, which
   * the extension reads when it validates a recurring payment.
   */
  public static function contributionPage(): int {
    $result = civicrm_api3('ContributionPage', 'create', [
      'title' => 'Test donation page ' . bin2hex(random_bytes(3)),
      'financial_type_id' => 1,
      'is_active' => 1,
    ]);
    return (int) $result['id'];
  }

  /**
   * A payment token (a saved eWAY card) for a contact.
   */
  public static function token(int $contactId, int $processorId, array $values = []): array {
    return PaymentToken::create(FALSE)
      ->setValues($values + [
        'contact_id' => $contactId,
        'payment_processor_id' => $processorId,
        'token' => '912345678901',
        'expiry_date' => date('Y-m-t', strtotime('+2 years')),
        'masked_account_number' => '444433XXXXXX1111',
        'created_date' => 'now',
      ])
      ->execute()
      ->first();
  }

  /**
   * A contribution, through APIv3 so line items exist.
   *
   * @param array $values
   *   Overrides. 'contribution_status_id' accepts a name such as "Pending".
   */
  public static function contribution(int $contactId, array $values = []): array {
    static $counter = 0;
    $counter++;

    $result = civicrm_api3('Contribution', 'create', $values + [
      'contact_id' => $contactId,
      'financial_type_id' => 1,
      'total_amount' => 10,
      'contribution_status_id' => 'Pending',
      'receive_date' => date('Y-m-d H:i:s'),
      'invoice_id' => 'testinvoice' . $counter . bin2hex(random_bytes(6)),
      'is_email_receipt' => 0,
    ]);
    return reset($result['values']);
  }

  /**
   * A recurring contribution that is due to be charged: In Progress, schedule
   * date yesterday, monthly, no installment limit, with a saved card token.
   *
   * Pass 'token' => FALSE for no payment token. Dates accept anything strtotime
   * understands.
   *
   * @return array The ContributionRecur row.
   */
  public static function recurring(int $contactId, array $processor, array $values = []): array {
    $dates = [];
    foreach (['start_date', 'next_sched_contribution_date', 'failure_retry_date', 'end_date'] as $field) {
      if (isset($values[$field]) && $values[$field] !== '') {
        $dates[$field] = date('Y-m-d H:i:s', strtotime($values[$field]));
        unset($values[$field]);
      }
    }
    $token = $values['token'] ?? NULL;
    unset($values['token']);

    $recur = $values + [
      'contact_id' => $contactId,
      'amount' => 10,
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'installments' => 0,
      'contribution_status_id:name' => 'In Progress',
      'financial_type_id' => 1,
      'payment_processor_id' => $processor['id'],
      'processor_id' => 'LEGACYTOKEN01',
      'is_test' => 0,
      'failure_count' => 0,
    ];
    $recur += $dates + [
      'start_date' => date('Y-m-d H:i:s', strtotime('-2 months')),
      'next_sched_contribution_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
    ];

    if ($token !== FALSE && !isset($recur['payment_token_id'])) {
      $recur['payment_token_id'] = ($token ?? self::token($contactId, $processor['id']))['id'];
    }

    return ContributionRecur::create(FALSE)->setValues($recur)->execute()->first();
  }

  /**
   * A Completed contribution that belongs to a recurring contribution. The
   * cron only charges recurring contributions that already have one.
   */
  public static function completedContributionFor(array $recur, string $receiveDate = '-2 months'): array {
    return self::contribution($recur['contact_id'], [
      'total_amount' => $recur['amount'],
      'financial_type_id' => $recur['financial_type_id'],
      'contribution_status_id' => 'Completed',
      'contribution_recur_id' => $recur['id'],
      'receive_date' => date('Y-m-d H:i:s', strtotime($receiveDate)),
      'payment_instrument_id' => 1,
    ]);
  }

  /**
   * A row in the verification queue.
   */
  public static function queued(int $contributionId, int $processorId, string $accessCode, array $values = []): array {
    $result = civicrm_api3('EwayContributionTransactions', 'create', $values + [
      'contribution_id' => $contributionId,
      'payment_processor_id' => $processorId,
      'access_code' => $accessCode,
      'is_email_receipt' => 0,
    ]);
    return reset($result['values']);
  }

  /**
   * Read one contribution back with the fields the tests assert on.
   */
  public static function reloadContribution(int $id): array {
    return civicrm_api3('Contribution', 'getsingle', ['id' => $id]);
  }

  public static function reloadRecurring(int $id): array {
    return ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
  }

}
