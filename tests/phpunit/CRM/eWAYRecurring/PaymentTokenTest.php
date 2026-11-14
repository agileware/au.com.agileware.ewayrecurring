<?php

use Civi\Api4\Country;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use Civi\Api4\StateProvince;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;
use CRM_eWAYRecurring_Test_FakeEwayResponses as Responses;

/**
 * Saved cards: CRM_eWAYRecurring_PaymentToken.
 *
 * The page callbacks end with CRM_Utils_JSON::output(), which throws a
 * PrematureExitException under the test framework carrying what it would have
 * printed, so the tests read the answer from that exception.
 *
 * Test plan suite PU-G: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_PaymentTokenTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var \CRM_eWAYRecurring_Test_FakeEwayHttp
   */
  private $http;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->http = (new FakeHttp())->install();
  }

  public function tearDown(): void {
    FakeHttp::uninstall();
    unset($_REQUEST['pp_id'], $_REQUEST['AccessCode']);
    if ($this->savedRequest !== NULL) {
      $_REQUEST = $this->savedRequest;
      $_POST = $this->savedPost;
      $this->savedRequest = $this->savedPost = NULL;
    }
    CRM_Core_Session::singleton()->set('eway_accesscode', NULL);
    parent::tearDown();
  }

  /**
   * @var array|null The request as it was before a test set up a form post.
   */
  private $savedRequest;

  /**
   * @var array|null
   */
  private $savedPost;

  /**
   * Call savetoken the way the "Add Credit Card" button does: with the access
   * code kept in the session, answering in JSON.
   *
   * @return array What it would have printed.
   */
  private function saveToken(array $processor): array {
    $_REQUEST['pp_id'] = $processor['id'];
    CRM_Core_Session::singleton()->set('eway_accesscode', 'AC-TOKEN');
    try {
      CRM_eWAYRecurring_PaymentToken::saveToken();
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      return $e->errorData;
    }
    $this->fail('saveToken() was expected to answer and exit.');
  }

  /**
   * Call savetoken with the request as given and return what it printed.
   *
   * @param string|null $processorId The pp_id parameter.
   * @param string|null $sessionAccessCode The code held in the session.
   *
   * @return array [what it printed as JSON (or NULL), anything else it echoed]
   */
  private function callSaveToken(?string $processorId, ?string $sessionAccessCode): array {
    unset($_REQUEST['pp_id'], $_REQUEST['AccessCode']);
    if ($processorId !== NULL) {
      $_REQUEST['pp_id'] = $processorId;
    }
    CRM_Core_Session::singleton()->set('eway_accesscode', $sessionAccessCode);
    $level = ob_get_level();
    ob_start();
    try {
      CRM_eWAYRecurring_PaymentToken::saveToken();
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      return [$e->errorData ?: NULL, ob_get_clean()];
    }
    finally {
      // Close only the buffer opened here.
      while (ob_get_level() > $level) {
        ob_end_clean();
      }
    }
    $this->fail('saveToken() was expected to answer and exit.');
  }

  /**
   * Without pp_id the page only closes its own window.
   *
   * @testPlan PU-G9
   */
  public function testSavingATokenWithNoProcessorJustClosesTheWindow(): void {
    [$json, $echoed] = $this->callSaveToken(NULL, 'AC-TOKEN');

    $this->assertArrayNotHasKey('is_error', $json ?? [], 'It does not answer with an error.');
    $this->assertSame('<script>window.close();</script>', $echoed);
    $this->assertSame([], $this->http->requests, 'eWAY is not called.');
  }

  /**
   * @testPlan PU-G9
   */
  public function testSavingATokenWithNoAccessCodeIsRefused(): void {
    [$json] = $this->callSaveToken((string) Fixture::processor()['id'], NULL);

    $this->assertSame(1, $json['is_error']);
    $this->assertSame('no access code.', $json['message']);
    $this->assertSame([], $this->http->requests, 'eWAY is not called.');
  }

  /**
   * @testPlan PU-G9
   */
  public function testSavingATokenForAnUnknownProcessorIsRefused(): void {
    [$json] = $this->callSaveToken('99999999', 'AC-TOKEN');

    $this->assertSame(1, $json['is_error']);
    $this->assertSame('no payment processor.', $json['message']);
    $this->assertSame([], $this->http->requests, 'eWAY is not called.');
  }

  /**
   * @testPlan PU-G9
   */
  public function testSavingATokenForAnAccessCodeWithNoTransactionIsRefused(): void {
    $this->http->respond('getTransaction', ['Transactions' => [], 'Errors' => NULL]);

    $answer = $this->saveToken(Fixture::processor());

    $this->assertSame(1, $answer['is_error']);
    $this->assertSame('no transaction.', $answer['message']);
  }

  /**
   * @testPlan PU-G9
   */
  public function testSavingATokenForATransactionWithoutATokenIsRefused(): void {
    $this->http->respond('getTransaction', Responses::transactionFound(['TokenCustomerID' => '']));

    $answer = $this->saveToken(Fixture::processor());

    $this->assertSame(1, $answer['is_error']);
    $this->assertSame('no token.', $answer['message']);
  }

  /**
   * @testPlan PU-G9
   */
  public function testSavingATokenForAnUnknownCustomerIsRefused(): void {
    $this->http->respond('getTransaction', Responses::transactionFound());
    $this->http->respond('getCustomer', ['Customers' => [], 'Errors' => NULL]);

    $answer = $this->saveToken(Fixture::processor());

    $this->assertSame(1, $answer['is_error']);
    $this->assertSame('no customer.', $answer['message']);
  }

  public function badReferenceProvider(): array {
    return [
      'not our format' => ['Order 1234'],
      'empty' => [''],
      'letters instead of an id' => ['civi-abc'],
      'extra text' => ['civi-12 and more'],
      'no such contact' => ['civi-999999999'],
    ];
  }

  /**
   * The contact is taken from the customer's eWAY reference, "civi-<id>". A
   * reference that does not name an existing contact must not create a token.
   *
   * @dataProvider badReferenceProvider
   * @testPlan PU-G9
   */
  public function testSavingATokenForACustomerWithoutAValidContactReferenceIsRefused(string $reference): void {
    $this->http->respond('getTransaction', Responses::transactionFound());
    $this->http->respond('getCustomer', Responses::customerFound('912345678901', [], $reference));

    $answer = $this->saveToken(Fixture::processor());

    $this->assertSame(1, $answer['is_error']);
    $this->assertSame('no contact.', $answer['message']);
    $this->assertSame(0, PaymentToken::get(FALSE)->addWhere('token', '=', '912345678901')->execute()->count());
  }

  /**
   * @testPlan PU-G9
   */
  public function testSavingATokenForACustomerWithoutCardDetailsIsRefused(): void {
    $contactId = Fixture::contact();
    $this->http->respond('getTransaction', Responses::transactionFound());
    $this->http->respond('getCustomer', ['Customers' => [['TokenCustomerID' => '912345678901', 'Reference' => 'civi-' . $contactId]], 'Errors' => NULL]);

    $answer = $this->saveToken(Fixture::processor());

    $this->assertSame(1, $answer['is_error']);
    $this->assertSame('no card details.', $answer['message']);
  }

  /**
   * The guards do not get in the way of a good card.
   *
   * @testPlan PU-G12
   */
  public function testASavedCardIsStoredForTheContactInTheReference(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $this->http->respond('getTransaction', Responses::transactionFound(['TokenCustomerID' => 'TOKEN-OK']));
    $this->http->respond('getCustomer', Responses::customerFound('TOKEN-OK', [], 'civi-' . $contactId));

    $answer = $this->saveToken($processor);

    $this->assertSame(0, $answer['is_error']);
    $token = PaymentToken::get(FALSE)->addWhere('id', '=', $answer['token_id'])->execute()->first();
    $this->assertSame('TOKEN-OK', $token['token']);
    $this->assertEquals($contactId, $token['contact_id']);
    $this->assertEquals($processor['id'], $token['payment_processor_id']);
    $this->assertSame('444433XXXXXX1111', $token['masked_account_number']);
  }

  // ---------------------------------------------------------------------------
  // Helpers for the cases below
  // ---------------------------------------------------------------------------

  /**
   * A payment processor of another type (not eWAY).
   */
  private function otherProcessor(): array {
    $id = PaymentProcessor::create(FALSE)
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

  /**
   * A token that has neither a masked card number nor an expiry date, as one
   * created from a bare token id is, unless $values says otherwise.
   */
  private function bareToken(int $contactId, int $processorId, array $values = []): array {
    return Fixture::token($contactId, $processorId, $values + [
      'masked_account_number' => NULL,
      'expiry_date' => NULL,
    ]);
  }

  private function reloadToken(int $id): array {
    return PaymentToken::get(FALSE)->addWhere('id', '=', $id)->execute()->first();
  }

  private function countTokens(int $contactId): int {
    return PaymentToken::get(FALSE)->addWhere('contact_id', '=', $contactId)->execute()->count();
  }

  /**
   * eWAY answers every getCustomer call with a saved card, whatever the token.
   */
  private function everyCustomerHasACard(array $card = []): void {
    $this->http->respond('getCustomer', Responses::customerFound('ANY', $card), TRUE);
  }

  // ---------------------------------------------------------------------------
  // fillTokensMeta
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-G1
   */
  public function testFillTokensMetaFillsMissingDetailsForEwayTokensOnly(): void {
    $processor = Fixture::processor();
    $other = $this->otherProcessor();
    $contactId = Fixture::contact();
    $ewayToken = $this->bareToken($contactId, $processor['id'], ['token' => 'TOKEN-EWAY']);
    $otherToken = $this->bareToken($contactId, $other['id'], ['token' => 'TOKEN-OTHER']);
    $this->everyCustomerHasACard(['Number' => '555555XXXXXX4444', 'ExpiryMonth' => '03', 'ExpiryYear' => '33']);

    $results = CRM_eWAYRecurring_PaymentToken::fillTokensMeta();

    $this->assertSame(1, $results['count']);
    $filled = $this->reloadToken($ewayToken['id']);
    $this->assertSame('555555XXXXXX4444', $filled['masked_account_number']);
    $this->assertStringStartsWith('2033-03-31', $filled['expiry_date']);

    $untouched = $this->reloadToken($otherToken['id']);
    $this->assertNull($untouched['masked_account_number']);
    $this->assertNull($untouched['expiry_date']);
    $this->assertSame([['TOKEN-EWAY']], array_column($this->http->callsTo('getCustomer'), 'args'), 'Only the eWAY token is looked up.');
  }

  /**
   * A token that is missing just one of the two details is still looked up,
   * and both are written.
   *
   * @testPlan PU-G1
   */
  public function testFillTokensMetaRefillsATokenMissingOnlyTheExpiry(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $token = $this->bareToken($contactId, $processor['id'], ['masked_account_number' => '400000XXXXXX0002']);
    $this->everyCustomerHasACard(['Number' => '555555XXXXXX4444', 'ExpiryMonth' => '06', 'ExpiryYear' => '30']);

    $results = CRM_eWAYRecurring_PaymentToken::fillTokensMeta();

    $this->assertSame(1, $results['count']);
    $reloaded = $this->reloadToken($token['id']);
    $this->assertSame('555555XXXXXX4444', $reloaded['masked_account_number']);
    $this->assertStringStartsWith('2030-06-30', $reloaded['expiry_date']);
  }

  public function expiryProvider(): array {
    return [
      'january' => ['01', '25', '2025-01-31'],
      'a 30 day month' => ['04', '30', '2030-04-30'],
      'february in a common year' => ['02', '27', '2027-02-28'],
      'february in a leap year' => ['02', '28', '2028-02-29'],
      'december' => ['12', '31', '2031-12-31'],
    ];
  }

  /**
   * eWAY gives a two digit year. The card is valid to the end of its month.
   *
   * @dataProvider expiryProvider
   * @testPlan PU-G2
   */
  public function testFillTokensMetaSetsTheExpiryToTheLastDayOfTheMonth(string $month, string $year, string $expected): void {
    $processor = Fixture::processor();
    $token = $this->bareToken(Fixture::contact(), $processor['id']);
    $this->everyCustomerHasACard(['ExpiryMonth' => $month, 'ExpiryYear' => $year]);

    CRM_eWAYRecurring_PaymentToken::fillTokensMeta();

    $this->assertStringStartsWith($expected, $this->reloadToken($token['id'])['expiry_date']);
  }

  /**
   * @testPlan PU-G3
   */
  public function testFillTokensMetaDoesNotQueryTokensThatAreComplete(): void {
    $processor = Fixture::processor();
    $token = Fixture::token(Fixture::contact(), $processor['id'], [
      'masked_account_number' => '400000XXXXXX0002',
      'expiry_date' => '2040-01-31',
    ]);

    $results = CRM_eWAYRecurring_PaymentToken::fillTokensMeta();

    $this->assertSame(0, $results['count']);
    $this->assertSame([], $this->http->requests, 'eWAY is not called.');
    $reloaded = $this->reloadToken($token['id']);
    $this->assertSame('400000XXXXXX0002', $reloaded['masked_account_number']);
    $this->assertStringStartsWith('2040-01-31', $reloaded['expiry_date']);
  }

  /**
   * A processor whose credentials eWAY cannot use (here none are saved) has
   * its tokens skipped. That is not an error, and the other processors carry on.
   *
   * @testPlan PU-G4
   */
  public function testFillTokensMetaSkipsTheTokensOfAProcessorThatCannotLogIn(): void {
    $broken = Fixture::processor(FALSE, ['user_name' => '', 'password' => '']);
    $working = Fixture::processor();
    $contactId = Fixture::contact();
    $brokenToken = $this->bareToken($contactId, $broken['id'], ['token' => 'TOKEN-BROKEN']);
    $workingToken = $this->bareToken($contactId, $working['id'], ['token' => 'TOKEN-WORKING']);
    $this->everyCustomerHasACard();

    $results = CRM_eWAYRecurring_PaymentToken::fillTokensMeta();

    $this->assertSame(1, $results['count']);
    $this->assertArrayNotHasKey('errors', $results);
    $this->assertArrayNotHasKey('is_error', $results);
    $this->assertNull($this->reloadToken($brokenToken['id'])['masked_account_number']);
    $this->assertNotNull($this->reloadToken($workingToken['id'])['masked_account_number']);
    $this->assertSame([['TOKEN-WORKING']], array_column($this->http->callsTo('getCustomer'), 'args'));
  }

  /**
   * One token eWAY will not answer for is reported with its id, and the rest
   * of the batch is still filled. (The errors used to be collected in a
   * variable that was never returned.)
   *
   * @testPlan PU-G5
   */
  public function testFillTokensMetaReportsATokenThatFailsAndCarriesOn(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $bad = $this->bareToken($contactId, $processor['id'], ['token' => 'TOKEN-BAD']);
    $good = $this->bareToken($contactId, $processor['id'], ['token' => 'TOKEN-GOOD']);
    $this->http->respond('getCustomer', function ($tokenCustomerId) {
      return $tokenCustomerId === 'TOKEN-BAD'
        ? Responses::errors('V6040')
        : Responses::customerFound($tokenCustomerId);
    }, TRUE);

    $results = CRM_eWAYRecurring_PaymentToken::fillTokensMeta();

    $this->assertSame(1, $results['count'], 'The good token is filled.');
    $this->assertNotNull($this->reloadToken($good['id'])['masked_account_number']);
    $this->assertNull($this->reloadToken($bad['id'])['masked_account_number']);
    $this->assertTrue($results['is_error']);
    $this->assertCount(1, $results['errors']);
    $this->assertStringContainsString('token id ' . $bad['id'], $results['errors'][0]);
    $this->assertStringContainsString('V6040', $results['errors'][0]);
  }

  /**
   * @testPlan PU-G6
   */
  public function testFillTokensMetaLooksEachProcessorUpOnce(): void {
    $first = Fixture::processor();
    $second = Fixture::processor();
    $contactId = Fixture::contact();
    foreach ([$first, $first, $first, $second, $second] as $i => $processor) {
      $this->bareToken($contactId, $processor['id'], ['token' => 'TOKEN-' . $i]);
    }
    $this->everyCustomerHasACard();

    $lookups = 0;
    $listener = function ($event) use (&$lookups) {
      $request = $event->getApiRequest();
      if ($request['entity'] === 'PaymentProcessor' && $request['action'] === 'get') {
        $lookups++;
      }
    };
    Civi::dispatcher()->addListener('civi.api.prepare', $listener);
    try {
      $results = CRM_eWAYRecurring_PaymentToken::fillTokensMeta();
    }
    finally {
      Civi::dispatcher()->removeListener('civi.api.prepare', $listener);
    }

    $this->assertSame(5, $results['count'], 'Every token is filled.');
    $this->assertSame(2, $lookups, 'One PaymentProcessor.get per processor, not per token.');
  }

  /**
   * A token that was changed in place (a new token id, with the card details
   * cleared because they belonged to the old card) is found again and filled
   * from eWAY using the new token value.
   *
   * @testPlan PU-G11
   */
  public function testFillTokensMetaRefillsATokenThatWasUpdatedInPlace(): void {
    $processor = Fixture::processor();
    $token = Fixture::token(Fixture::contact(), $processor['id'], [
      'token' => 'TOKEN-OLD',
      'masked_account_number' => '400000XXXXXX0002',
      'expiry_date' => '2040-01-31',
    ]);
    $this->assertSame(0, CRM_eWAYRecurring_PaymentToken::fillTokensMeta()['count'], 'Nothing to do while it is complete.');
    $this->assertSame([], $this->http->requests);

    PaymentToken::update(FALSE)
      ->addWhere('id', '=', $token['id'])
      ->addValue('token', 'TOKEN-NEW')
      ->addValue('masked_account_number', NULL)
      ->addValue('expiry_date', NULL)
      ->execute();
    $this->http->respond('getCustomer', Responses::customerFound('TOKEN-NEW', ['Number' => '555555XXXXXX4444', 'ExpiryMonth' => '09', 'ExpiryYear' => '34']));

    $results = CRM_eWAYRecurring_PaymentToken::fillTokensMeta();

    $this->assertSame(1, $results['count']);
    $this->assertSame(['TOKEN-NEW'], $this->http->lastArgs('getCustomer'));
    $reloaded = $this->reloadToken($token['id']);
    $this->assertSame('TOKEN-NEW', $reloaded['token']);
    $this->assertSame('555555XXXXXX4444', $reloaded['masked_account_number']);
    $this->assertStringStartsWith('2034-09-30', $reloaded['expiry_date']);
  }

  // ---------------------------------------------------------------------------
  // getPaymentProcessorById
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-G7
   */
  public function testGetPaymentProcessorByIdFindsAProcessor(): void {
    $processor = Fixture::processor();

    $found = CRM_eWAYRecurring_PaymentToken::getPaymentProcessorById($processor['id']);

    $this->assertIsArray($found);
    $this->assertEquals($processor['id'], $found['id']);
    $this->assertSame('test-api-key', $found['user_name']);
  }

  /**
   * @testPlan PU-G7
   */
  public function testGetPaymentProcessorByIdReturnsNullForAnUnknownId(): void {
    $this->assertNull(CRM_eWAYRecurring_PaymentToken::getPaymentProcessorById(99999999));
  }

  // ---------------------------------------------------------------------------
  // saveToken: updating or creating
  // ---------------------------------------------------------------------------

  /**
   * Save the card eWAY holds under $token, as the return from the hosted page does.
   */
  private function saveCard(array $processor, int $contactId, string $token, string $number): array {
    $this->http->respond('getTransaction', Responses::transactionFound(['TokenCustomerID' => $token]));
    $this->http->respond('getCustomer', Responses::customerFound($token, ['Number' => $number], 'civi-' . $contactId));
    return $this->saveToken($processor);
  }

  /**
   * @testPlan PU-G8
   */
  public function testSavingTheSameTokenUpdatesTheExistingRow(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $existing = Fixture::token($contactId, $processor['id'], ['token' => 'TOKEN-A', 'masked_account_number' => '411111XXXXXX1111']);

    $answer = $this->saveCard($processor, $contactId, 'TOKEN-A', '555555XXXXXX4444');

    $this->assertSame(0, $answer['is_error']);
    $this->assertEquals($existing['id'], $answer['token_id']);
    $this->assertSame(1, $this->countTokens($contactId));
    $this->assertSame('555555XXXXXX4444', $this->reloadToken($existing['id'])['masked_account_number']);
  }

  /**
   * @testPlan PU-G8
   */
  public function testSavingTheSameCardNumberUpdatesTheExistingRow(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $existing = Fixture::token($contactId, $processor['id'], ['token' => 'TOKEN-A', 'masked_account_number' => '411111XXXXXX1111']);

    $answer = $this->saveCard($processor, $contactId, 'TOKEN-B', '411111XXXXXX1111');

    $this->assertSame(0, $answer['is_error']);
    $this->assertEquals($existing['id'], $answer['token_id']);
    $this->assertSame(1, $this->countTokens($contactId));
    $this->assertSame('TOKEN-B', $this->reloadToken($existing['id'])['token']);
  }

  /**
   * @testPlan PU-G8
   */
  public function testSavingADifferentTokenAndCardCreatesANewRow(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $existing = Fixture::token($contactId, $processor['id'], ['token' => 'TOKEN-A', 'masked_account_number' => '411111XXXXXX1111']);

    $answer = $this->saveCard($processor, $contactId, 'TOKEN-B', '555555XXXXXX4444');

    $this->assertSame(0, $answer['is_error']);
    $this->assertNotEquals($existing['id'], $answer['token_id']);
    $this->assertSame(2, $this->countTokens($contactId));
    $this->assertSame('TOKEN-A', $this->reloadToken($existing['id'])['token'], 'The first card is left alone.');
  }

  /**
   * @testPlan PU-G8
   */
  public function testTheSameCardForAnotherContactCreatesANewRow(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $otherContactId = Fixture::contact('Other', 'Donor');
    $existing = Fixture::token($otherContactId, $processor['id'], ['token' => 'TOKEN-A', 'masked_account_number' => '411111XXXXXX1111']);

    $answer = $this->saveCard($processor, $contactId, 'TOKEN-A', '411111XXXXXX1111');

    $this->assertNotEquals($existing['id'], $answer['token_id']);
    $this->assertSame(1, $this->countTokens($contactId));
    $this->assertSame(1, $this->countTokens($otherContactId));
  }

  /**
   * @testPlan PU-G8
   */
  public function testTheSameCardOnAnotherProcessorCreatesANewRow(): void {
    $processor = Fixture::processor();
    $otherProcessor = Fixture::processor();
    $contactId = Fixture::contact();
    $existing = Fixture::token($contactId, $otherProcessor['id'], ['token' => 'TOKEN-A', 'masked_account_number' => '411111XXXXXX1111']);

    $answer = $this->saveCard($processor, $contactId, 'TOKEN-A', '411111XXXXXX1111');

    $this->assertNotEquals($existing['id'], $answer['token_id']);
    $this->assertSame(2, $this->countTokens($contactId));
    $this->assertEquals($otherProcessor['id'], $this->reloadToken($existing['id'])['payment_processor_id']);
  }

  // ---------------------------------------------------------------------------
  // createToken: refusals
  // ---------------------------------------------------------------------------

  /**
   * The billing fields as the "Add Credit Card" form posts them (the keys end
   * in the location type id, as CiviCRM's billing fields do).
   */
  private function billingPost(int $contactId, int $processorId): array {
    $countryId = Country::get(FALSE)->addWhere('iso_code', '=', 'AU')->execute()->first()['id'];
    $stateId = StateProvince::get(FALSE)->addWhere('country_id', '=', $countryId)->setLimit(1)->execute()->first()['id'];
    return [
      'contact_id' => $contactId,
      'pp_id' => $processorId,
      'billing_first_name' => 'Test',
      'billing_last_name' => 'Donor',
      'billing_street_address-5' => '1 Test Street',
      'billing_city-5' => 'Canberra',
      'billing_postal_code-5' => '2600',
      'billing_country_id-5' => $countryId,
      'billing_state_province_id-5' => $stateId,
    ];
  }

  /**
   * Call createtoken with this form post and return what it printed.
   */
  private function createToken(array $post): array {
    $this->savedRequest = $_REQUEST;
    $this->savedPost = $_POST;
    $_POST = $post;
    $_REQUEST = array_merge($_REQUEST, $post);
    try {
      CRM_eWAYRecurring_PaymentToken::createToken();
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      return $e->errorData;
    }
    $this->fail('createToken() was expected to answer and exit.');
  }

  public function missingBillingFieldProvider(): array {
    return [
      'first name' => ['first_name', 'billing_first_name'],
      'last name' => ['last_name', 'billing_last_name'],
      'street' => ['billing_street_address', 'billing_street_address-5'],
      'city' => ['billing_city', 'billing_city-5'],
      'postcode' => ['billing_postal_code', 'billing_postal_code-5'],
      'country' => ['billing_country', 'billing_country_id-5'],
      'state' => ['billing_state_province', 'billing_state_province_id-5'],
    ];
  }

  /**
   * @dataProvider missingBillingFieldProvider
   * @testPlan PU-G10
   */
  public function testCreatingATokenWithAMissingBillingFieldIsRefused(string $field, string $postKey): void {
    $post = $this->billingPost(Fixture::contact(), Fixture::processor()['id']);
    unset($post[$postKey]);

    $answer = $this->createToken($post);

    $this->assertSame(1, $answer['is_error']);
    $this->assertSame('Missing field: ' . $field, $answer['message']);
    $this->assertSame([], $this->http->requests, 'eWAY is not called.');
  }

  /**
   * @testPlan PU-G10
   */
  public function testCreatingATokenForAnUnknownProcessorIsRefused(): void {
    $answer = $this->createToken($this->billingPost(Fixture::contact(), 99999999));

    $this->assertSame(1, $answer['is_error']);
    $this->assertSame('no payment processor found.', $answer['message']);
    $this->assertSame([], $this->http->requests, 'eWAY is not called.');
  }

  /**
   * Looking an unknown contact up used to throw out of the page instead of
   * answering.
   *
   * @testPlan PU-G10
   */
  public function testCreatingATokenForAnUnknownContactIsRefused(): void {
    $answer = $this->createToken($this->billingPost(99999999, Fixture::processor()['id']));

    $this->assertSame(1, $answer['is_error']);
    $this->assertSame('no contact found.', $answer['message']);
    $this->assertSame([], $this->http->requests, 'eWAY is not called.');
  }

}
