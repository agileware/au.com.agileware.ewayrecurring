<?php

use Civi\Api4\PaymentToken;
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
    CRM_Core_Session::singleton()->set('eway_accesscode', NULL);
    parent::tearDown();
  }

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

}
