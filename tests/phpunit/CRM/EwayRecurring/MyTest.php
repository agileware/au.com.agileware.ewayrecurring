<?php

use CRM_eWAYRecurring_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test
 * class. Simply create corresponding functions (e.g. "hook_civicrm_post(...)"
 * or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or
 * test****() functions will rollback automatically -- as long as you don't
 * manipulate schema or truncate tables. If this test needs to manipulate
 * schema or truncate tables, then either: a. Do all that using setupHeadless()
 * and Civi\Test. b. Disable TransactionalInterface, and handle all
 * setup/teardown yourself.
 *
 * @group headless
 */
class CRM_EwayRecurring_MyTest extends CiviUnitTestCase {

  protected $paymentProcessorId;

  public static function setUpBeforeClass() {
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    parent::setUpBeforeClass();

    // Example: Install this extension. Don't care about anything else.
    Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();

    // Example: Uninstall all extensions except this one.
    // \Civi\Test::e2e()->uninstall('*')->installMe(__DIR__)->apply();

    // Example: Install only core civicrm extensions.
    // \Civi\Test::e2e()->uninstall('*')->install('org.civicrm.*')->apply();
  }

  public function setUp() {
    $this->useTransaction(TRUE);
    parent::setUp();
  }

  public function testProcessor() {
    $result = civicrm_api3('PaymentProcessorType', 'get', [
      'sequential' => 1,
      'name' => "eWay_Recurring",
    ]);
    $this->paymentProcessorId = $result['values'][0]['id'];
    $this->assertEquals($result['count'], 1, 'Extension not installed./n');
  }

  /**
   * Initial test of submit function for paid event.
   *
   * @param string $thousandSeparator
   *
   * @dataProvider getThousandSeparators
   *
   * @throws \Exception
   */
  public function testPaidSubmit($thousandSeparator) {
    $this->setCurrencySeparators($thousandSeparator);
    $paymentProcessorID = $this->processorCreate([
      'payment_processor_type_id' => $this->paymentProcessorId,
      'financial_account_id' => 12,
      'user_name' => "F9802CH2TbcGPmrhsCLb5MdOp16sfNPUu/esjjEifnwhajDxvYOyIORdG1Cg3dI0t0iIRk",
      'password' => "JlBFHET7",
      'title' => "eWay test",
      'name => "eWay test',
      'billing_mode' => 4,
    ]);
    $params = ['is_monetary' => 1, 'financial_type_id' => 1];
    $event = $this->eventCreate($params);
    $individualID = $this->individualCreate();
    CRM_Event_Form_Registration_Confirm::testSubmit([
      'id' => $event['id'],
      'contributeMode' => 'direct',
      'registerByID' => $individualID,
      'paymentProcessorObj' => CRM_Financial_BAO_PaymentProcessor::getPayment($paymentProcessorID),
      'totalAmount' => $this->formatMoneyInput(8000.67),
      'params' => [
        [
          'qfKey' => 'e6eb2903eae63d4c5c6cc70bfdda8741_2801',
          'entryURL' => 'http://dmaster.local/civicrm/event/register?reset=1&amp;id=3',
          'first_name' => 'k',
          'last_name' => 'p',
          'email-Primary' => 'demo@example.com',
          'hidden_processor' => '1',
          'credit_card_type' => 'Visa',
          'billing_first_name' => 'p',
          'billing_middle_name' => '',
          'billing_last_name' => 'p',
          'billing_street_address-5' => 'p',
          'billing_city-5' => 'p',
          'billing_state_province_id-5' => '1061',
          'billing_postal_code-5' => '7',
          'billing_country_id-5' => '1228',
          'scriptFee' => '',
          'scriptArray' => '',
          'priceSetId' => '6',
          'price_7' => [
            13 => 1,
          ],
          'payment_processor_id' => $paymentProcessorID,
          'bypass_payment' => '',
          'MAX_FILE_SIZE' => '33554432',
          'is_primary' => 1,
          'is_pay_later' => 0,
          'campaign_id' => NULL,
          'defaultRole' => 1,
          'participant_role_id' => '1',
          'currencyID' => 'USD',
          'amount_level' => 'Tiny-tots (ages 5-8) - 1',
          'amount' => $this->formatMoneyInput(8000.67),
          'tax_amount' => NULL,
          'year' => '2019',
          'month' => '1',
          'ip_address' => '127.0.0.1',
          'invoiceID' => '57adc34957a29171948e8643ce906332',
          'button' => '_qf_Register_upload',
          'billing_state_province-5' => 'AP',
          'billing_country-5' => 'US',
        ],
      ],
    ]);
    $this->callAPISuccessGetCount('Participant', [], 1);
    $contribution = $this->callAPISuccessGetSingle('Contribution', []);
    $this->assertEquals(8000.67, $contribution['total_amount']);
    $lastFinancialTrxnId = CRM_Core_BAO_FinancialTrxn::getFinancialTrxnId($contribution['id'], 'DESC');
    $financialTrxn = $this->callAPISuccessGetSingle(
      'FinancialTrxn',
      [
        'id' => $lastFinancialTrxnId['financialTrxnId'],
        'return' => [
          'payment_processor_id',
          'card_type_id.label',
          'pan_truncation',
        ],
      ]
    );
    $this->assertEquals($financialTrxn['payment_processor_id'] ?? NULL, $paymentProcessorID);
    $this->assertEquals($financialTrxn['card_type_id.label'] ?? NULL, 'Visa');
    $this->assertEquals($financialTrxn['pan_truncation'] ?? NULL, 1111);
  }
}
