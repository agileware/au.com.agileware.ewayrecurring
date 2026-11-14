<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;

/**
 * Basic behaviour of the payment processor class that needs no gateway.
 *
 * Test plan suite PU-A: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_ProcessorBasicsTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // Forget payment processor objects cached by earlier tests.
    FakeHttp::uninstall();
  }

  public function tearDown(): void {
    FakeHttp::uninstall();
    parent::tearDown();
  }

  /**
   * A payment object for a processor row that only needs the fields a test cares about.
   */
  private function payment(array $row = ['user_name' => 'test-api-key', 'password' => 'test-api-password']): CRM_Core_Payment_eWAYRecurring {
    return new CRM_Core_Payment_eWAYRecurring('live', $row);
  }

  // ---------------------------------------------------------------------------
  // Configuration check and form definition (PU-A1 to PU-A4, PU-A6)
  // ---------------------------------------------------------------------------

  public function checkConfigProvider(): array {
    return [
      'key and password' => ['key', 'secret', []],
      'no key' => ['', 'secret', ['key']],
      'no password' => ['key', '', ['password']],
      'neither' => ['', '', ['key', 'password']],
      'neither is in the row at all' => [NULL, NULL, ['key', 'password']],
    ];
  }

  /**
   * @dataProvider checkConfigProvider
   * @testPlan PU-A1
   */
  public function testCheckConfigNamesWhatIsMissing(?string $userName, ?string $password, array $missing): void {
    $row = [];
    if ($userName !== NULL) {
      $row['user_name'] = $userName;
    }
    if ($password !== NULL) {
      $row['password'] = $password;
    }
    $messages = [
      'key' => ts('eWay API Key is not set for this payment processor'),
      'password' => ts('eWay API Password is not set for this payment processor'),
    ];
    $expected = $missing ? implode('<p>', array_map(function ($name) use ($messages) {
      return $messages[$name];
    }, $missing)) : NULL;

    $this->assertSame($expected, $this->payment($row)->checkConfig());
  }

  /**
   * @testPlan PU-A2
   */
  public function testPaymentFormFieldsAreEmptyOnTheFrontEndAndTheCardFieldsInTheBackOffice(): void {
    $payment = $this->payment();
    $this->assertSame([], $payment->getPaymentFormFields(), 'The donor enters card details on the eWAY page, so the form has none.');

    $payment->setBackOffice(TRUE);
    $this->assertSame(['contact_payment_token', 'add_credit_card'], $payment->getPaymentFormFields());
  }

  /**
   * @testPlan PU-A3
   */
  public function testPaymentFormFieldsMetadataHasTheCardSelectAndTheAddCardButton(): void {
    $metadata = $this->payment()->getPaymentFormFieldsMetadata();

    $this->assertSame(['contact_payment_token', 'add_credit_card'], array_keys($metadata));

    $select = $metadata['contact_payment_token'];
    $this->assertSame('select', $select['htmlType']);
    $this->assertSame('contact_payment_token', $select['name']);
    $this->assertTrue($select['is_required']);
    $this->assertSame('eway_credit_card_field', $select['extra']['class']);
    $this->assertSame(['' => 'No cards found.'], $select['attributes'], 'The browser script fills in the contact\'s cards.');

    $button = $metadata['add_credit_card'];
    $this->assertSame('button', $button['htmlType']);
    $this->assertSame('add_credit_card', $button['name']);
    $this->assertSame('CRM.eway.addCreditCard();', $button['attributes']['onclick']);
    $this->assertSame('eway_credit_card_field', $button['attributes']['class']);
  }

  /**
   * @testPlan PU-A4
   */
  public function testBillingAddressFieldsCarryTheBillingLocationId(): void {
    $this->assertSame([
      'first_name' => 'billing_first_name',
      'middle_name' => 'billing_middle_name',
      'last_name' => 'billing_last_name',
      'street_address' => 'billing_street_address-5',
      'city' => 'billing_city-5',
      'country' => 'billing_country_id-5',
      'state_province' => 'billing_state_province_id-5',
      'postal_code' => 'billing_postal_code-5',
    ], $this->payment()->getBillingAddressFields(5));
  }

  /**
   * @testPlan PU-A6
   */
  public function testTheProcessorSupportsTheBackOffice(): void {
    $this->assertTrue($this->payment()->supportsBackOffice());
  }

  /**
   * @testPlan PU-A6
   */
  public function testTheEditableRecurringScheduleFieldsAreTheSixDocumentedOnes(): void {
    $this->assertSame(
      ['amount', 'installments', 'frequency_interval', 'frequency_unit', 'next_sched_contribution_date', 'end_date'],
      $this->payment()->getEditableRecurringScheduleFields()
    );
  }

  // ---------------------------------------------------------------------------
  // Serialising the object (PU-A7)
  // ---------------------------------------------------------------------------

  /**
   * The SDK client holds a curl handle, which PHP cannot serialise, and the instance cache can contain the object
   * itself. __serialize() leaves both out, so CiviCRM can serialise the payment object after a client has been built.
   *
   * @testPlan PU-A7
   */
  public function testSerializingLeavesOutTheClientAndTheInstanceCache(): void {
    $payment = $this->payment(Fixture::processor());
    // Build the SDK client, as taking a payment does (no request is sent).
    $getClient = new ReflectionMethod($payment, 'getEWayClient');
    $getClient->setAccessible(TRUE);
    $this->assertNotNull($getClient->invoke($payment), 'A client exists before the object is serialised.');
    $property = new ReflectionProperty(CRM_Core_Payment_eWAYRecurring::class, 'eWayClient');
    $property->setAccessible(TRUE);
    $this->assertNotNull($property->getValue($payment));

    $exported = $payment->__serialize();

    $this->assertArrayNotHasKey('eWayClient', $exported);
    $this->assertArrayNotHasKey('instances', $exported);
    $this->assertArrayHasKey('_mode', $exported);
    $this->assertArrayHasKey('_paymentProcessor', $exported);
    $this->assertIsString(serialize($payment), 'serialize() does not fail on the curl handle.');
  }

  // ---------------------------------------------------------------------------
  // CRM_eWAYRecurring_Utils helpers (PU-A8, PU-A9)
  // ---------------------------------------------------------------------------

  public function sanitizeExactProvider(): array {
    return [
      'empty string' => ['', ''],
      'null' => [NULL, ''],
      'plain ASCII' => ['Plain text, 100% ASCII: ~!@#$%^&*()_+-=[]{};\':",./<>?', 'Plain text, 100% ASCII: ~!@#$%^&*()_+-=[]{};\':",./<>?'],
      'control characters are removed' => ["Line1\x00\x07\r\nLine2\t\x7F", 'Line1Line2'],
      'very long string' => [str_repeat('abcdefghij', 5000), str_repeat('abcdefghij', 5000)],
    ];
  }

  /**
   * @dataProvider sanitizeExactProvider
   * @testPlan PU-A8
   */
  public function testSanitizeToAsciiLeavesPrintableAsciiAndRemovesTheRest(?string $input, string $expected): void {
    $this->assertSame($expected, CRM_eWAYRecurring_Utils::sanitizeToAscii($input));
  }

  /**
   * The transliteration of letters outside ASCII depends on the C library and its locale: "e" with an acute accent can
   * become "e", "'e" or "?". What must hold everywhere is that only printable ASCII is left and the ASCII around it
   * survives.
   */
  public function sanitizeTransliteratedProvider(): array {
    return [
      'accented Latin' => ["Caf\u{e9} cr\u{e8}me", '/^Caf.{1,2} cr.{1,2}me$/'],
      'emoji' => ["Thank you \u{1F600}", '/^Thank you [\x20-\x7E]*$/'],
      'CJK' => ["\u{6771}\u{4EAC} Tokyo", '/^[\x20-\x7E]* Tokyo$/'],
      'mixed' => ["Jos\u{e9} \u{2013} appeal \u{1F600}\n", '/^Jos.{1,2} .*appeal.*$/'],
    ];
  }

  /**
   * @dataProvider sanitizeTransliteratedProvider
   * @testPlan PU-A8
   */
  public function testSanitizeToAsciiReducesOtherCharactersToPrintableAscii(string $input, string $pattern): void {
    $result = CRM_eWAYRecurring_Utils::sanitizeToAscii($input);

    $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $result, 'Only printable ASCII is left.');
    $this->assertMatchesRegularExpression($pattern, $result);
  }

  /**
   * Contributions and recurring contributions have different status lists: "Refunded" exists only for contributions.
   *
   * @testPlan PU-A9
   */
  public function testContributionStatusIdUsesTheContributionOrTheRecurringList(): void {
    $this->assertSame(1, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Completed'));
    $this->assertSame(2, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Pending'));
    $this->assertSame(3, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Cancelled'));
    $this->assertSame(4, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Failed'));
    $this->assertSame(7, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Refunded'));

    $this->assertSame(1, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Completed', TRUE));
    $this->assertSame(2, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Pending', TRUE));
    $this->assertSame(3, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Cancelled', TRUE));
    $this->assertSame(4, (int) CRM_eWAYRecurring_Utils::contribution_status_id('Failed', TRUE));
    $this->assertSame(5, (int) CRM_eWAYRecurring_Utils::contribution_status_id('In Progress', TRUE));
    $this->assertNull(CRM_eWAYRecurring_Utils::contribution_status_id('Refunded', TRUE), 'The recurring list has no Refunded status.');
    $this->assertNull(CRM_eWAYRecurring_Utils::contribution_status_id('No such status'));
  }

  // ---------------------------------------------------------------------------
  // The customer sent to eWAY (PU-A10)
  // ---------------------------------------------------------------------------

  /**
   * What CiviCRM hands the processor for a donor, with every field present.
   */
  private function donorParams(array $overrides = []): array {
    return $overrides + [
      'contactID' => 42,
      'first_name' => 'Test',
      'last_name' => 'Donor',
      'street_address' => '1 Test Street',
      'city' => 'Canberra',
      'state_province' => 'ACT',
      'postal_code' => '2600',
      'email' => 'donor@example.test',
      'country' => 'AU',
    ];
  }

  private function customerDetails(array $params): array {
    return $this->payment()->getEWayClientDetailsArray($params);
  }

  /**
   * @testPlan PU-A10
   */
  public function testCustomerFieldsAreCutToTheLengthsEwayAccepts(): void {
    $customer = $this->customerDetails($this->donorParams([
      'contactID' => 12345678901234567,
      'first_name' => str_repeat('F', 60),
      'last_name' => str_repeat('L', 60),
      'street_address' => str_repeat('S', 80),
      'city' => str_repeat('C', 80),
      'state_province' => str_repeat('T', 80),
      'postal_code' => str_repeat('P', 40),
      'email' => str_repeat('e', 70) . '@example.test',
    ]));

    $this->assertSame('Civi-12345678901', $customer['Reference'], 'Reference is "Civi-" and the contact id, 16 characters at most.');
    $this->assertSame(str_repeat('F', 50), $customer['FirstName']);
    $this->assertSame(str_repeat('L', 50), $customer['LastName']);
    $this->assertSame(str_repeat('S', 50), $customer['Street1']);
    $this->assertSame(str_repeat('C', 50), $customer['City']);
    $this->assertSame(str_repeat('T', 50), $customer['State']);
    $this->assertSame(str_repeat('P', 30), $customer['PostalCode']);
    $this->assertSame(str_repeat('e', 50), $customer['Email']);
  }

  /**
   * @testPlan PU-A10
   */
  public function testCustomerFieldsAreCopiedWhenTheyAreShortAndTheReferenceIsEmptyWithoutAContact(): void {
    $params = $this->donorParams();
    unset($params['contactID']);

    $customer = $this->customerDetails($params);

    $this->assertSame('', $customer['Reference']);
    $this->assertSame('Test', $customer['FirstName']);
    $this->assertSame('1 Test Street', $customer['Street1']);
    $this->assertSame('Canberra', $customer['City']);
    $this->assertSame('ACT', $customer['State']);
    $this->assertSame('2600', $customer['PostalCode']);
    $this->assertSame('donor@example.test', $customer['Email']);
  }

  /**
   * @testPlan PU-A10
   */
  public function testCountryGivenAsAnIsoCodeIsUsedAsIs(): void {
    $this->assertSame('AU', $this->customerDetails($this->donorParams(['country' => 'AU']))['Country']);
  }

  /**
   * @testPlan PU-A10
   */
  public function testCountryGivenAsANameIsReplacedByTheIsoCodeOfItsCountryId(): void {
    CRM_Core_PseudoConstant::flush();

    $customer = $this->customerDetails($this->donorParams(['country' => 'Australia', 'country_id' => 1013]));

    $this->assertSame('AU', $customer['Country']);
  }

  /**
   * @testPlan PU-A10
   */
  public function testCountryIsLookedUpFromTheCountryId(): void {
    CRM_Core_PseudoConstant::flush();
    $params = $this->donorParams(['country_id' => 1013]);
    unset($params['country']);

    $this->assertSame('AU', $this->customerDetails($params)['Country']);
  }

  /**
   * @testPlan PU-A10
   */
  public function testCountryIsLookedUpFromTheBillingLocationField(): void {
    CRM_Core_PseudoConstant::flush();
    $billingLocationId = civicrm_api3('LocationType', 'getvalue', ['return' => 'id', 'name' => 'Billing']);
    $params = $this->donorParams(["billing_country_id-{$billingLocationId}" => 1013]);
    unset($params['country']);

    $this->assertSame('AU', $this->customerDetails($params)['Country']);
  }

  /**
   * @testPlan PU-A10
   */
  public function testMissingCountryIsRefusedWithCode9007(): void {
    $params = $this->donorParams();
    unset($params['country']);

    try {
      $this->customerDetails($params);
      $this->fail('Expected the missing country to be refused.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertEquals(9007, $e->getCode());
      $this->assertStringContainsString('country', $e->getMessage());
    }
  }

  /**
   * @testPlan PU-A10
   */
  public function testTokenCustomerIdIsIncludedOnlyWhenASubscriptionIdIsGiven(): void {
    $with = $this->customerDetails($this->donorParams(['subscriptionId' => '912345678901']));
    $this->assertSame('912345678901', $with['TokenCustomerID']);

    $this->assertArrayNotHasKey('TokenCustomerID', $this->customerDetails($this->donorParams()));
    $this->assertArrayNotHasKey('TokenCustomerID', $this->customerDetails($this->donorParams(['subscriptionId' => ''])));
  }

  /**
   * @testPlan PU-A5
   */
  public function testGetInstanceUsesTheModeOfTheProcessor(): void {
    $live = Fixture::processor(FALSE);
    $test = Fixture::processor(TRUE);

    $this->assertSame('live', CRM_Core_Payment_eWAYRecurring::getInstance($live)->_mode);
    $this->assertSame('test', CRM_Core_Payment_eWAYRecurring::getInstance($test)->_mode);
  }

  /**
   * @testPlan PU-A5
   */
  public function testGetInstanceReturnsTheSameObjectForTheSameProcessor(): void {
    $live = Fixture::processor(FALSE);
    $other = Fixture::processor(FALSE);

    $first = CRM_Core_Payment_eWAYRecurring::getInstance($live);

    $this->assertSame($first, CRM_Core_Payment_eWAYRecurring::getInstance($live));
    $this->assertNotSame($first, CRM_Core_Payment_eWAYRecurring::getInstance($other));
  }

  /**
   * Core reads $this->_is_test when it builds some return addresses, and validateContribution() sets it. The class
   * never declared it, so PHP 8.2 printed "Creation of dynamic property CRM_Core_Payment_eWAYRecurring::$_is_test is
   * deprecated" on the donor's thank you page wherever errors are displayed (DEF-16).
   *
   * @testPlan PU-A11
   */
  public function testTheTestModeFlagIsADeclaredProperty(): void {
    $property = new ReflectionProperty(CRM_Core_Payment_eWAYRecurring::class, '_is_test');

    $this->assertSame(CRM_Core_Payment_eWAYRecurring::class, $property->getDeclaringClass()->getName());
    $row = Fixture::processor();
    $this->assertFalse((bool) (new CRM_Core_Payment_eWAYRecurring('live', $row))->_is_test, 'It starts as not test mode.');
  }

}
