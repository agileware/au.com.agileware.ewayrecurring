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
    unset($_REQUEST['contact_payment_token']);
    // Forget status messages left by earlier tests.
    CRM_Core_Session::singleton()->getStatus(TRUE);
  }

  public function tearDown(): void {
    FakeHttp::uninstall();
    CRM_Core_Session::singleton()->set('eWAYResponse', NULL, self::QF_KEY);
    CRM_Core_Session::singleton()->getStatus(TRUE);
    unset($_REQUEST['contact_payment_token']);
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

  private function newPayment(array $processor, bool $backOffice = FALSE): CRM_Core_Payment_eWAYRecurring {
    $row = $processor;
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $row);
    if ($backOffice) {
      $payment->setBackOffice(TRUE);
    }
    return $payment;
  }

  /**
   * Run doPayment(). The payment page redirects to eWAY's hosted page and
   * exits, which the test framework turns into a PrematureExitException.
   *
   * @param array $params
   *   Passed by reference, as doPayment() takes it, so a test can read what it
   *   changed (for example payment_status_id).
   * @param string|null $redirectedTo
   *   Set to the URL of the redirect, or NULL if there was none.
   *
   * @return array|null The params returned, or NULL if it redirected.
   */
  private function attempt(CRM_Core_Payment_eWAYRecurring $payment, array &$params, ?string &$redirectedTo = NULL): ?array {
    $redirectedTo = NULL;
    try {
      return $payment->doPayment($params);
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      $redirectedTo = $e->errorData['url'] ?? NULL;
      return NULL;
    }
  }

  /**
   * Run doPayment() for a contribution page payment.
   *
   * @return array|null The params returned, or NULL if it redirected.
   */
  private function pay(array $processor, array $params): ?array {
    return $this->attempt($this->newPayment($processor), $params);
  }

  /**
   * Run doPayment() expecting it to refuse the payment.
   *
   * @return \Civi\Payment\Exception\PaymentProcessorException
   */
  private function refused(CRM_Core_Payment_eWAYRecurring $payment, array &$params): PaymentProcessorException {
    try {
      $this->attempt($payment, $params);
    }
    catch (PaymentProcessorException $e) {
      return $e;
    }
    $this->fail('Expected the payment to be refused.');
  }

  /**
   * Params for a back office payment with a saved card. Pass NULL for no card.
   * The back office path reads contributionRecurID and is_recur directly, so they
   * are always present.
   */
  private function backOfficeParams(array $contribution, $tokenId, array $overrides = []): array {
    $params = $this->params($contribution, $overrides + ['contributionRecurID' => NULL, 'is_recur' => 0]);
    if ($tokenId !== NULL) {
      $params['contact_payment_token'] = $tokenId;
    }
    return $params;
  }

  private function statusId(string $name): int {
    $statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate');
    return (int) array_search($name, $statuses);
  }

  private function queueRows(array $contribution): int {
    return (int) civicrm_api3('EwayContributionTransactions', 'get', ['contribution_id' => $contribution['id']])['count'];
  }

  /**
   * The request data of the transaction a contribution page payment sent to eWAY.
   */
  private function sharedPageRequest(): array {
    $calls = $this->http->callsTo('postAccessCodeShared');
    $this->assertCount(1, $calls, 'One hosted page was requested.');
    return $calls[0]['args'][0];
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

  // ---------------------------------------------------------------------------
  // PU-B1 to PU-B11: contribution page payments
  // ---------------------------------------------------------------------------

  public function zeroAmountProvider(): array {
    return [
      'zero' => ['0'],
      'zero with decimals' => ['0.00'],
      'zero with one decimal' => ['0.0'],
    ];
  }

  /**
   * @dataProvider zeroAmountProvider
   * @testPlan PU-B1
   */
  public function testZeroAmountIsCompletedWithoutCallingEway(string $amount): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());

    $result = $this->pay($processor, $this->params($contribution, ['amount' => $amount]));

    $this->assertSame(['payment_status_id' => $this->statusId('Completed'), 'payment_status' => 'Completed'], $result);
    $this->assertSame([], $this->http->requests);
    $this->assertSame(0, $this->queueRows($contribution), 'Nothing is queued for verification.');
  }

  public function amountProvider(): array {
    return [
      'whole dollars' => ['10', '1000'],
      'two decimals' => ['10.00', '1000'],
      'under a dollar' => ['0.05', '5'],
      'thousands' => ['1234.50', '123450'],
      'floating point rounding' => ['19.99', '1999'],
      'large' => ['1234567.89', '123456789'],
    ];
  }

  /**
   * @dataProvider amountProvider
   * @testPlan PU-B2
   */
  public function testAmountIsSentToEwayInWholeCents(string $amount, string $expectedCents): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated());

    $this->pay($processor, $this->params($contribution, ['amount' => $amount]));

    $this->assertSame($expectedCents, (string) $this->sharedPageRequest()['Payment']['TotalAmount']);
  }

  /**
   * doPayment() strips spaces and commas from the amount before it converts it, but CiviCRM's PropertyBag, which
   * doPayment() builds first, rejects an amount that is not numeric. So an amount written "1,234.50" never reaches the
   * conversion: the contribution page form hands over a plain number, and this pins that anything else is refused
   * before eWAY is called.
   *
   * @testPlan PU-B2
   */
  public function testAnAmountWithAThousandsSeparatorIsRefusedBeforeEwayIsCalled(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $params = $this->params($contribution, ['amount' => '1,234.50']);

    try {
      $this->attempt($this->newPayment($processor), $params);
      $this->fail('Expected the amount to be refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('numeric', $e->getMessage());
    }
    $this->assertSame([], $this->http->requests);
  }

  /**
   * @testPlan PU-B3
   */
  public function testTheContributionPagePayloadAsksForASharedPageThatSavesTheCard(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $contribution = Fixture::contribution($contactId);
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated());

    $this->pay($processor, $this->params($contribution));

    $sent = $this->sharedPageRequest();
    $this->assertSame([], $this->http->callsTo('postTransaction'), 'A hosted page, not a direct charge.');
    $this->assertSame('Purchase', $sent['TransactionType']);
    $this->assertSame('TokenPayment', $sent['Method'], 'Capture with SaveCustomer becomes a token payment.');
    $this->assertTrue($sent['Capture']);
    $this->assertTrue($sent['SaveCustomer']);
    $this->assertTrue($sent['CustomerReadOnly']);
    $this->assertEquals(['ContributionID' => $contribution['id']], $sent['Options']);
    $this->assertSame('127.0.0.1', $sent['CustomerIP']);
    $this->assertSame('Test', $sent['Customer']['FirstName']);
    $this->assertSame('AU', $sent['Customer']['Country']);
    $this->assertSame('Civi-' . $contactId, $sent['Customer']['Reference']);
    $this->assertStringContainsString(self::QF_KEY, $sent['RedirectUrl']);
    $this->assertStringContainsString(self::QF_KEY, $sent['CancelUrl']);
  }

  /**
   * Back office payments are always MOTO and contribution page payments always Purchase. The cvv_backoffice_required
   * setting that doPayment() consults is only read on the contribution page branch, where the payment is never a back
   * office one, and the extension does not register the setting, so it has no effect either way.
   *
   * @testPlan PU-B4
   */
  public function testBackOfficePaymentsAreMotoAndContributionPagePaymentsArePurchase(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $token = Fixture::token($contactId, $processor['id']);

    $backOffice = Fixture::contribution($contactId);
    $this->http->respond('postTransaction', Responses::paymentApproved(70000004));
    $params = $this->backOfficeParams($backOffice, $token['id']);
    $this->attempt($this->newPayment($processor, TRUE), $params);
    $this->assertSame('MOTO', $this->http->lastArgs('postTransaction')[0]['TransactionType']);

    $page = Fixture::contribution($contactId);
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated());
    $this->pay($processor, $this->params($page));
    $this->assertSame('Purchase', $this->sharedPageRequest()['TransactionType']);
  }

  /**
   * @testPlan PU-B5
   */
  public function testInvoiceNumberReferenceAndDescriptionAreCutToEwaysLengths(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $invoiceId = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated());

    $this->pay($processor, $this->params($contribution, ['invoiceID' => $invoiceId, 'description' => str_repeat('D', 100)]));

    $payment = $this->sharedPageRequest()['Payment'];
    $this->assertSame('ABCDEFGHIJKL', $payment['InvoiceNumber'], 'The first 12 characters of the invoice id.');
    $this->assertSame(substr($invoiceId, 0, 50), $payment['InvoiceReference']);
    $this->assertSame(str_repeat('D', 64), $payment['InvoiceDescription']);
  }

  public function blankDescriptionProvider(): array {
    return [
      'empty' => [''],
      'spaces' => ['   '],
    ];
  }

  /**
   * @dataProvider blankDescriptionProvider
   * @testPlan PU-B5
   */
  public function testABlankDescriptionFallsBackToTheInvoiceId(string $description): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated());

    $this->pay($processor, $this->params($contribution, ['description' => $description]));

    $this->assertSame('Invoice ID: ' . $contribution['invoice_id'], $this->sharedPageRequest()['Payment']['InvoiceDescription']);
  }

  /**
   * How an accented letter is transliterated depends on the C library, so only the ASCII around it and the absence of
   * anything else are asserted.
   *
   * @testPlan PU-B5
   */
  public function testNonAsciiCharactersAreRemovedFromTheTextSentToEway(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated());

    $this->pay($processor, $this->params($contribution, [
      'description' => "Caf\u{e9} donation \u{1F600}",
      'first_name' => "Jos\u{e9}",
    ]));

    $sent = $this->sharedPageRequest();
    $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $sent['Payment']['InvoiceDescription']);
    $this->assertStringStartsWith('Caf', $sent['Payment']['InvoiceDescription']);
    $this->assertStringContainsString(' donation', $sent['Payment']['InvoiceDescription']);
    $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $sent['Customer']['FirstName']);
    $this->assertStringStartsWith('Jos', $sent['Customer']['FirstName']);
  }

  /**
   * @testPlan PU-B6
   */
  public function testTheAlterPaymentProcessorParamsHookRunsAndCanChangeThePayload(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated());
    $seen = [];
    $listener = function ($event) use (&$seen) {
      $seen[] = [
        'class' => get_class($event->paymentObj),
        'contributionID' => $event->rawParams['contributionID'],
        'description' => $event->cookedParams['Payment']['InvoiceDescription'],
      ];
      $cooked = &$event->cookedParams;
      $cooked['Payment']['InvoiceDescription'] = 'Changed by the hook';
      unset($cooked);
    };
    \Civi::dispatcher()->addListener('hook_civicrm_alterPaymentProcessorParams', $listener);

    try {
      $this->pay($processor, $this->params($contribution));
    }
    finally {
      \Civi::dispatcher()->removeListener('hook_civicrm_alterPaymentProcessorParams', $listener);
    }

    $this->assertCount(1, $seen);
    $this->assertSame(CRM_Core_Payment_eWAYRecurring::class, $seen[0]['class']);
    $this->assertEquals($contribution['id'], $seen[0]['contributionID']);
    $this->assertSame('Test donation', $seen[0]['description'], 'The hook sees the payload before eWAY does.');
    $this->assertSame('Changed by the hook', $this->sharedPageRequest()['Payment']['InvoiceDescription']);
  }

  /**
   * An invoice id that already belongs to another contribution is refused before eWAY is called. (CiviCRM keeps
   * invoice ids unique, so the duplicate is another contribution's id.)
   *
   * @testPlan PU-B7
   */
  public function testAnInvoiceIdUsedByAnotherContributionIsRefusedAsADuplicate(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $other = Fixture::contribution($contactId);
    $contribution = Fixture::contribution($contactId);
    $params = $this->params($contribution, ['invoiceID' => $other['invoice_id']]);

    $e = $this->refused($this->newPayment($processor), $params);

    $this->assertStringContainsString('It appears that this transaction is a duplicate.', $e->getMessage());
    $this->assertSame($this->statusId('Failed'), $params['payment_status_id']);
    $this->assertSame([], $this->http->requests);
    $this->assertSame(0, $this->queueRows($contribution));
  }

  /**
   * @testPlan PU-B8
   */
  public function testErrorsFromEwayAreTranslatedAndRefuseThePayment(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->http->respond('postAccessCodeShared', Responses::errors('V6010,V6011'));
    $params = $this->params($contribution);

    $e = $this->refused($this->newPayment($processor), $params);

    $first = \Eway\Rapid::getMessage('V6010');
    $second = \Eway\Rapid::getMessage('V6011');
    $this->assertNotSame('V6010', $first, 'The code has a message in the SDK\'s language file.');
    $this->assertStringContainsString($first, $e->getMessage());
    $this->assertStringContainsString($second, $e->getMessage());
    $this->assertSame($this->statusId('Failed'), $params['payment_status_id']);
    $this->assertSame(0, $this->queueRows($contribution));
  }

  /**
   * The SDK always returns a response object, so a NULL answer needs a stand-in client.
   *
   * @testPlan PU-B9
   */
  public function testANullAnswerFromTheClientFailsThePaymentWithoutQueueingIt(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $client = $this->createMock(\Eway\Rapid\Contract\Client::class);
    $client->method('getErrors')->willReturn([]);
    $client->method('createTransaction')->willReturn(NULL);
    $payment = $this->newPayment($processor);
    $property = new ReflectionProperty(CRM_Core_Payment_eWAYRecurring::class, 'eWayClient');
    $property->setAccessible(TRUE);
    $property->setValue($payment, $client);
    $params = $this->params($contribution);

    $e = $this->refused($payment, $params);

    $this->assertStringContainsString('Connection to payment gateway failed - no data returned.', $e->getMessage());
    $this->assertSame($this->statusId('Failed'), $params['payment_status_id']);
    $this->assertSame(0, $this->queueRows($contribution));
  }

  public function receiptFlagProvider(): array {
    return [
      'not given, so receipts are on' => [NULL, 1],
      'off' => [0, 0],
      'on' => [1, 1],
    ];
  }

  /**
   * @dataProvider receiptFlagProvider
   * @testPlan PU-B10
   */
  public function testSuccessQueuesTheAccessCodeAndRedirectsToTheHostedPage(?int $isEmailReceipt, int $expectedFlag): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated('ACCESSCODE0010'));
    $params = $this->params($contribution, ['is_email_receipt' => $isEmailReceipt]);

    $result = $this->attempt($this->newPayment($processor), $params, $redirectedTo);

    $this->assertNull($result, 'doPayment redirects rather than returning.');
    $this->assertStringContainsString('eway-stub.test/pay', (string) $redirectedTo);
    $this->assertStringContainsString('AccessCode=ACCESSCODE0010', (string) $redirectedTo);
    $this->assertSame(1, $this->queueRows($contribution), 'Exactly one queue row.');
    $row = civicrm_api3('EwayContributionTransactions', 'getsingle', ['contribution_id' => $contribution['id']]);
    $this->assertSame('ACCESSCODE0010', $row['access_code']);
    $this->assertEquals($processor['id'], $row['payment_processor_id']);
    $this->assertEquals($expectedFlag, $row['is_email_receipt']);
  }

  /**
   * @testPlan PU-B11
   */
  public function testEwaysAnswerIsKeptInTheSessionUnderTheFormKey(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $this->http->respond('postAccessCodeShared', Responses::sharedPageCreated('ACCESSCODE0011', 'https://eway-stub.test/pay?AccessCode=ACCESSCODE0011'));

    $this->pay($processor, $this->params($contribution));

    $stored = CRM_Core_Session::singleton()->get('eWAYResponse', self::QF_KEY);
    $this->assertIsString($stored);
    $response = unserialize($stored);
    $this->assertInstanceOf(\Eway\Rapid\Model\Response\CreateTransactionResponse::class, $response);
    $this->assertSame('ACCESSCODE0011', $response->AccessCode);
    $this->assertSame('https://eway-stub.test/pay?AccessCode=ACCESSCODE0011', $response->SharedPaymentUrl);
  }

  // ---------------------------------------------------------------------------
  // PU-B13 to PU-B17: back office payments with a saved card
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-B13
   */
  public function testBackOfficePaymentSendsTheFirst16CharactersOfTheCardToken(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $contribution = Fixture::contribution($contactId);
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'ABCDEFGHIJKLMNOPQRSTUV']);
    $this->http->respond('postTransaction', Responses::paymentApproved(70000013));
    $params = $this->backOfficeParams($contribution, $token['id']);

    $result = $this->attempt($this->newPayment($processor, TRUE), $params, $redirectedTo);

    $this->assertIsArray($result);
    $this->assertNull($redirectedTo);
    $this->assertSame([], $this->http->callsTo('postAccessCodeShared'), 'No hosted page for the back office.');
    $sent = $this->http->lastArgs('postTransaction')[0];
    $this->assertSame('ABCDEFGHIJKLMNOP', $sent['Customer']['TokenCustomerID']);
    $this->assertSame('MOTO', $sent['TransactionType']);
    $this->assertSame('ProcessPayment', $sent['Method']);
    $this->assertSame('1000', (string) $sent['Payment']['TotalAmount']);
  }

  /**
   * @testPlan PU-B14
   */
  public function testBackOfficePaymentWithAnUnknownCardIsRefused(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $params = $this->backOfficeParams($contribution, 99999999);

    $e = $this->refused($this->newPayment($processor, TRUE), $params);

    $this->assertStringContainsString('valid credit card token', $e->getMessage());
    $this->assertSame($this->statusId('Failed'), $params['payment_status_id']);
    $this->assertSame([], $this->http->requests);
  }

  /**
   * @testPlan PU-B14
   */
  public function testBackOfficePaymentWithNoCardSelectedIsRefused(): void {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    $params = $this->backOfficeParams($contribution, NULL);

    $e = $this->refused($this->newPayment($processor, TRUE), $params);

    $this->assertStringContainsString('valid credit card token', $e->getMessage());
    $this->assertSame([], $this->http->requests);
  }

  /**
   * With no card selected the lookup is PaymentToken.getsingle with id NULL, and the API ignores a NULL filter, so
   * when exactly one token exists on the whole site that token is found and charged. This asserts the payment is
   * refused and no card is charged (it should fail until doPayment() checks the selection is not empty).
   *
   * @testPlan PU-B14
   */
  public function testBackOfficePaymentWithNoCardSelectedDoesNotUseTheOnlyCardOnTheSite(): void {
    $processor = Fixture::processor();
    $someoneElse = Fixture::contact('Someone', 'Else');
    Fixture::token($someoneElse, $processor['id']);
    $contribution = Fixture::contribution(Fixture::contact());
    $params = $this->backOfficeParams($contribution, NULL);

    $e = $this->refused($this->newPayment($processor, TRUE), $params);

    $this->assertStringContainsString('valid credit card token', $e->getMessage());
    $this->assertSame([], $this->http->callsTo('postTransaction'), 'No card is charged.');
  }

  /**
   * @testPlan PU-B15
   */
  public function testBackOfficePaymentWithARecurringRecordSavesTheCardOnIt(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $contribution = Fixture::contribution($contactId);
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'NEWCARDTOKEN1']);
    $recur = Fixture::recurring($contactId, $processor, ['token' => FALSE, 'processor_id' => 'OLDLEGACY']);
    $this->http->respond('postTransaction', Responses::paymentApproved(70000015));
    $params = $this->backOfficeParams($contribution, $token['id'], ['contributionRecurID' => $recur['id'], 'is_recur' => 1]);

    $this->attempt($this->newPayment($processor, TRUE), $params);

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals($token['id'], $after['payment_token_id']);
    $this->assertSame('NEWCARDTOKEN1', $after['processor_id']);
    $this->assertEquals($processor['id'], $after['payment_processor_id']);
    $this->assertNotContains('Please update the credit card manually.', array_column(CRM_Core_Session::singleton()->getStatus(), 'text'), 'No reminder to update the card by hand.');
  }

  /**
   * @testPlan PU-B16
   */
  public function testBackOfficeRecurringPaymentWithoutARecurringRecordAsksForTheCardToBeUpdatedByHand(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $contribution = Fixture::contribution($contactId);
    $token = Fixture::token($contactId, $processor['id']);
    $this->http->respond('postTransaction', Responses::paymentApproved(70000016));
    $params = $this->backOfficeParams($contribution, $token['id'], ['is_recur' => 1]);

    $result = $this->attempt($this->newPayment($processor, TRUE), $params);

    $this->assertIsArray($result, 'The payment itself goes ahead.');
    $texts = array_column(CRM_Core_Session::singleton()->getStatus(), 'text');
    $this->assertContains('Please update the credit card manually.', $texts);
  }

  /**
   * @testPlan PU-B17
   */
  public function testBackOfficeSuccessCompletesThePaymentWithTheTransactionId(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $contribution = Fixture::contribution($contactId);
    $token = Fixture::token($contactId, $processor['id']);
    $this->http->respond('postTransaction', Responses::paymentApproved(70000017));
    $params = $this->backOfficeParams($contribution, $token['id']);

    $result = $this->attempt($this->newPayment($processor, TRUE), $params);

    $this->assertSame($this->statusId('Completed'), $params['payment_status_id']);
    $this->assertEquals(70000017, $params['trxn_id']);
    $this->assertSame($params, $result);
  }

  public function declineProvider(): array {
    return [
      'one message' => ['D4401'],
      'two messages' => ['D4401, D4402'],
    ];
  }

  /**
   * @dataProvider declineProvider
   * @testPlan PU-B17
   */
  public function testBackOfficeDeclineFailsWithTheTranslatedMessages(string $responseMessage): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $contribution = Fixture::contribution($contactId);
    $token = Fixture::token($contactId, $processor['id']);
    $this->http->respond('postTransaction', Responses::paymentDeclined($responseMessage));
    $params = $this->backOfficeParams($contribution, $token['id']);

    $e = $this->refused($this->newPayment($processor, TRUE), $params);

    $expected = implode(', ', array_map('\Eway\Rapid::getMessage', explode(', ', $responseMessage)));
    $this->assertSame($expected, $e->getMessage());
    $this->assertStringContainsString('Refer to Issuer', $e->getMessage());
    $this->assertSame($this->statusId('Failed'), $params['payment_status_id']);
    $this->assertArrayNotHasKey('trxn_id', $params);
  }

}
