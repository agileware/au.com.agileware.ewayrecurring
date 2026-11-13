<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;
use CRM_eWAYRecurring_Test_FakeEwayResponses as Responses;

/**
 * Taking a payment: CRM_Core_Payment_eWAYRecurring::doPayment().
 *
 * Test plan suite PU-B: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_DoPaymentTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var \CRM_eWAYRecurring_Test_FakeEwayHttp
   */
  private $http;

  private const QF_KEY = 'testqfkey_1234';

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
    CRM_Core_Session::singleton()->set('eWAYResponse', NULL, self::QF_KEY);
    parent::tearDown();
  }

  /**
   * A contribution page payment, as CiviCRM hands it to doPayment() on the
   * confirm page.
   */
  private function params(array $contribution, array $overrides = []): array {
    return $overrides + [
      'amount' => '10.00',
      'invoiceID' => $contribution['invoice_id'],
      'contributionID' => $contribution['id'],
      'contactID' => $contribution['contact_id'],
      'contributionPageID' => 1,
      'qfKey' => self::QF_KEY,
      'description' => 'Test donation',
      'first_name' => 'Test',
      'last_name' => 'Donor',
      'street_address' => '1 Test Street',
      'city' => 'Canberra',
      'postal_code' => '2600',
      'state_province' => 'ACT',
      'country' => 'AU',
      'email' => 'donor@example.test',
      'ip_address' => '127.0.0.1',
      'is_email_receipt' => 0,
    ];
  }

  /**
   * Run doPayment() for a contribution page payment. The payment page redirects
   * to eWAY's hosted page and exits, which the test framework turns into a
   * PrematureExitException.
   *
   * @return array [params returned, or NULL if it redirected]
   */
  private function pay(array $processor, array $params): ?array {
    $row = $processor;
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $row);
    try {
      return $payment->doPayment($params);
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      return NULL;
    }
  }

  /**
   * Pretend the donor was already sent to the hosted page for an access code:
   * the queue row exists and CiviCRM kept eWAY's answer in the session.
   */
  private function alreadySentToTheHostedPage(array $processor, array $contribution, string $accessCode, array $queueValues = []): void {
    Fixture::queued($contribution['id'], $processor['id'], $accessCode, $queueValues);
    CRM_Core_Session::singleton()->set('eWAYResponse', serialize(new \Eway\Rapid\Model\Response\CreateTransactionResponse([
      'AccessCode' => $accessCode,
      'SharedPaymentUrl' => 'https://eway-stub.test/pay?AccessCode=' . $accessCode,
    ])), self::QF_KEY);
  }

  /**
   * Submitting the confirm page again while the donor is still on the hosted
   * page reuses the access code and sends them back to it: no second
   * transaction is created, and no second queue row.
   *
   * @testPlan PU-B12
   */
  public function testResubmittingWhileTheTransactionIsStillOpenReusesTheHostedPage(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->alreadySentToTheHostedPage($processor, $contribution, 'AC-OPEN');

    $result = $this->pay($processor, $this->params($contribution));

    $this->assertNull($result, 'The donor is redirected to the hosted page again.');
    $this->assertSame([], $this->http->requests, 'No new transaction is created with eWAY.');
    $this->assertSame(1, civicrm_api3('EwayContributionTransactions', 'get', ['contribution_id' => $contribution['id']])['count']);
  }

  /**
   * @testPlan PU-B12
   */
  public function testResubmittingAnAlreadySuccessfulPaymentDoesNotChargeAgain(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->alreadySentToTheHostedPage($processor, $contribution, 'AC-PAID', ['status' => CRM_eWAYRecurring_Utils::STATUS_SUCCESS]);

    $result = $this->pay($processor, $this->params($contribution));

    $this->assertIsArray($result, 'doPayment returns without redirecting.');
    $this->assertSame([], $this->http->requests);
  }

  /**
   * @testPlan PU-B12
   */
  public function testResubmittingAnAlreadyFailedPaymentExplainsWhy(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->alreadySentToTheHostedPage($processor, $contribution, 'AC-FAILED', [
      'status' => CRM_eWAYRecurring_Utils::STATUS_FAILED,
      'failed_message' => 'Your payment was declined: Refer to Issuer',
    ]);

    try {
      $this->pay($processor, $this->params($contribution));
      $this->fail('Expected the payment to be refused.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertStringContainsString('A previous attempt to complete this transaction already failed - Your payment was declined: Refer to Issuer', $e->getMessage());
    }
    $this->assertSame([], $this->http->requests);
  }

  /**
   * @testPlan PU-B12
   */
  public function testAnExistingTransactionThatIsNotInThisSessionIsRefused(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->alreadySentToTheHostedPage($processor, $contribution, 'AC-OTHER-SESSION');
    // A different access code is in this session.
    CRM_Core_Session::singleton()->set('eWAYResponse', serialize(new \Eway\Rapid\Model\Response\CreateTransactionResponse(['AccessCode' => 'AC-DIFFERENT'])), self::QF_KEY);

    try {
      $this->pay($processor, $this->params($contribution));
      $this->fail('Expected the payment to be refused.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertStringContainsString('An existing transaction was found that does not match this session', $e->getMessage());
    }
    $this->assertSame([], $this->http->requests);
  }

}
