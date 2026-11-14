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
 * Validation of eWAY transactions: turning eWAY's answer for an access code
 * into a Completed or Failed contribution, and saving the recurring token.
 *
 * Covers Utils::validateEwayAccessCode, validateContribution,
 * updateCustomerDetails, completeEWayTransaction and validateEwayContribution.
 * The gateway is faked (seam S1), nothing reaches eWAY.
 *
 * Test plan suite PU-D: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_ValidateContributionTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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
    unset($_REQUEST['AccessCode'], $_REQUEST['qfKey']);
    parent::tearDown();
  }

  /**
   * A live processor, a contact and a Pending contribution, optionally
   * recurring, with a verification queue row for the access code.
   */
  private function pendingPayment(array $contributionValues = [], string $accessCode = 'AC0001', int $emailReceipt = 0): array {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $contribution = Fixture::contribution($contactId, $contributionValues);
    Fixture::queued($contribution['id'], $processor['id'], $accessCode, ['is_email_receipt' => $emailReceipt]);
    return [$processor, $contactId, $contribution];
  }

  /**
   * The contribution row as Utils::validatePendingTransactions() reads it:
   * only these fields are requested, and the API leaves out any that are empty.
   */
  private function contributionRowAsTheJobReadsIt(int $id): array {
    return civicrm_api3('Contribution', 'getsingle', [
      'id' => $id,
      'return' => ['contribution_page_id', 'contribution_recur_id', 'is_test'],
    ]);
  }

  // ---------------------------------------------------------------------------
  // validateEwayAccessCode
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-D1
   */
  public function testAccessCodeApproved(): void {
    $processor = Fixture::processor();
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 555]));

    $result = CRM_eWAYRecurring_Utils::validateEwayAccessCode('AC0001', $processor);

    $this->assertFalse($result['hasTransactionFailed']);
    $this->assertFalse($result['transactionNotProcessedYet']);
    $this->assertSame('', $result['transactionResponseError']);
    $this->assertEquals(555, $result['transactionID']);
    $this->assertSame([['getTransaction', ['AC0001']]], array_map(function ($call) {
      return [$call['method'], $call['args']];
    }, $this->http->requests));
  }

  /**
   * @testPlan PU-D1
   */
  public function testAccessCodeDeclinedMessageIsPrefixed(): void {
    $processor = Fixture::processor();
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionStatus' => FALSE,
      'ResponseMessage' => 'D4401',
    ]));

    $result = CRM_eWAYRecurring_Utils::validateEwayAccessCode('AC0001', $processor);

    $this->assertTrue($result['hasTransactionFailed']);
    $this->assertStringStartsWith('Your payment was declined: ', $result['transactionResponseError']);
    $this->assertSame('Your payment was declined: ' . \Eway\Rapid::getMessage('D4401'), $result['transactionResponseError']);
  }

  /**
   * @testPlan PU-D1
   */
  public function testAccessCodeEwayErrorsAreJoinedWithComma(): void {
    $processor = Fixture::processor();
    $this->http->respond('getTransaction', Responses::errors('V6010,V6011'));

    $result = CRM_eWAYRecurring_Utils::validateEwayAccessCode('AC0001', $processor);

    $this->assertTrue($result['hasTransactionFailed']);
    $this->assertSame(
      \Eway\Rapid::getMessage('V6010') . ',' . \Eway\Rapid::getMessage('V6011'),
      $result['transactionResponseError']
    );
  }

  /**
   * @testPlan PU-D1
   */
  public function testAccessCodeConnectionFailureCountsAsFailure(): void {
    $processor = Fixture::processor();
    $this->http->respond('getTransaction', Responses::connectionFailure());

    $result = CRM_eWAYRecurring_Utils::validateEwayAccessCode('AC0001', $processor);

    $this->assertTrue($result['hasTransactionFailed']);
    $this->assertNotSame('', $result['transactionResponseError']);
  }

  /**
   * @testPlan PU-D1
   */
  public function testAccessCodeWithNoTransactionYetIsNotProcessedYet(): void {
    $processor = Fixture::processor();
    $this->http->respond('getTransaction', Responses::transactionNotProcessedYet());

    $result = CRM_eWAYRecurring_Utils::validateEwayAccessCode('AC0001', $processor);

    $this->assertFalse($result['hasTransactionFailed']);
    $this->assertTrue($result['transactionNotProcessedYet']);
  }

  /**
   * @testPlan PU-D2
   */
  public function testValidatingUpdateTokenSkipsTheTransactionStatusChecks(): void {
    $processor = Fixture::processor();
    // A declined transaction would normally fail validation.
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionStatus' => FALSE,
      'ResponseMessage' => 'D4401',
    ]));

    $result = CRM_eWAYRecurring_Utils::validateEwayAccessCode('AC0001', $processor, TRUE);

    $this->assertFalse($result['hasTransactionFailed']);
    $this->assertFalse($result['transactionNotProcessedYet']);
  }

  // ---------------------------------------------------------------------------
  // validateContribution
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-D3
   */
  public function testApprovedPendingContributionIsCompleted(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 777]));

    $result = CRM_eWAYRecurring_Utils::validateContribution('AC0001', $contribution, $processor);

    $this->assertFalse($result['hasTransactionFailed']);
    $this->assertEquals($contribution['id'], $result['contributionId']);
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
    $this->assertSame('777', (string) $reloaded['trxn_id']);
  }

  /**
   * @testPlan PU-D4
   */
  public function testAlreadyCompletedContributionIsLeftAlone(): void {
    [$processor, , $contribution] = $this->pendingPayment([
      'contribution_status_id' => 'Completed',
      'trxn_id' => 'ORIGINAL',
    ]);
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 888]));

    CRM_eWAYRecurring_Utils::validateContribution('AC0001', $contribution, $processor);

    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('ORIGINAL', $reloaded['trxn_id']);
  }

  /**
   * @testPlan PU-D5
   */
  public function testReceiptFlagComesFromTheQueueRow(): void {
    $sent = [];
    $listener = function ($event) use (&$sent) {
      if ($event->getApiRequestSig() === '3.contribution.completetransaction') {
        $sent[] = (int) $event->getApiRequest()['params']['is_email_receipt'];
      }
    };
    \Civi::dispatcher()->addListener('civi.api.prepare', $listener);

    try {
      foreach ([0, 1] as $flag) {
        [$processor, , $contribution] = $this->pendingPayment([], 'AC-RECEIPT-' . $flag, $flag);
        $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 600 + $flag]));
        CRM_eWAYRecurring_Utils::validateContribution('AC-RECEIPT-' . $flag, $contribution, $processor);
      }
    }
    finally {
      \Civi::dispatcher()->removeListener('civi.api.prepare', $listener);
    }

    $this->assertSame([0, 1], $sent);
  }

  /**
   * @testPlan PU-D6
   */
  public function testDeclinedPaymentMarksTheContributionFailed(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionID' => 999,
      'TransactionStatus' => FALSE,
      'ResponseMessage' => 'D4401',
    ]));

    $result = CRM_eWAYRecurring_Utils::validateContribution('AC0001', $contribution, $processor);

    $this->assertTrue($result['hasTransactionFailed']);
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Failed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
    $this->assertSame('999', (string) $reloaded['trxn_id']);
  }

  /**
   * @testPlan PU-D7
   */
  public function testNotProcessedYetChangesNothing(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionNotProcessedYet());

    $result = CRM_eWAYRecurring_Utils::validateContribution('AC0001', $contribution, $processor);

    $this->assertTrue($result['transactionNotProcessedYet']);
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Pending', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
    $this->assertEmpty($reloaded['trxn_id']);
  }

  // ---------------------------------------------------------------------------
  // Recurring: token and schedule
  // ---------------------------------------------------------------------------

  /**
   * getCustomerTokenFromResponse is private; it is what decides which token an
   * approved recurring payment saves.
   */
  private function tokenFromResponse($transactionResponse): string {
    $method = new ReflectionMethod(CRM_eWAYRecurring_Utils::class, 'getCustomerTokenFromResponse');
    $method->setAccessible(TRUE);
    return $method->invoke(NULL, $transactionResponse);
  }

  /**
   * @testPlan PU-D8
   */
  public function testTokenIsReadFromEitherLocationInTheResponse(): void {
    $this->assertSame('TOP', $this->tokenFromResponse((object) ['TokenCustomerID' => 'TOP']));
    $this->assertSame('NESTED', $this->tokenFromResponse((object) [
      'TokenCustomerID' => '',
      'Transactions' => [(object) ['TokenCustomerID' => 'NESTED']],
    ]));
    $this->assertSame('', $this->tokenFromResponse((object) ['Transactions' => [(object) ['TokenCustomerID' => '']]]));
  }

  /**
   * @testPlan PU-D9
   */
  public function testApprovedRecurringPaymentStartsTheSchedule(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $processor, [
      'contribution_status_id:name' => 'Pending',
      'frequency_unit' => 'week',
      'frequency_interval' => 2,
      'token' => FALSE,
      'processor_id' => '',
      'next_sched_contribution_date' => '+10 years',
    ]);
    $contribution = Fixture::contribution($contactId, [
      'contribution_recur_id' => $recur['id'],
      'contribution_page_id' => Fixture::contributionPage(),
    ]);
    Fixture::queued($contribution['id'], $processor['id'], 'AC0001');
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 4242, 'TokenCustomerID' => 'TOKEN-A']));
    $this->http->respond('getCustomer', Responses::customerFound('TOKEN-A'));

    CRM_eWAYRecurring_Utils::validateContribution('AC0001', $this->contributionRowAsTheJobReadsIt($contribution['id']), $processor);

    $reloaded = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $reloaded['contribution_status_id']));
    $this->assertSame('TOKEN-A', $reloaded['processor_id']);
    $this->assertNotEmpty($reloaded['payment_token_id']);
    $this->assertSame('4242', (string) $reloaded['trxn_id']);
    $expectedNext = date('Y-m-d 00:00:00', strtotime('+2 weeks'));
    $this->assertSame($expectedNext, date('Y-m-d H:i:s', strtotime($reloaded['next_sched_contribution_date'])));
  }

  /**
   * @testPlan PU-D10
   */
  public function testUpdateCustomerDetailsCreatesANewToken(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $processor, ['token' => FALSE]);
    $this->http->respond('getCustomer', Responses::customerFound('TOKEN-B', ['Name' => 'A Cardholder']));

    $tokenId = CRM_eWAYRecurring_Utils::updateCustomerDetails($this->gatewayResponse($processor, 'TOKEN-B'), $recur);

    $this->assertNotEmpty($tokenId);
    $token = PaymentToken::get(FALSE)->addWhere('id', '=', $tokenId)->execute()->first();
    $this->assertSame('TOKEN-B', $token['token']);
    $this->assertSame('444433XXXXXX1111', $token['masked_account_number']);
    $this->assertSame('A Cardholder', $token['billing_first_name']);
    // eWAY reports the expiry as month 12, year 31: the token expires on the last day of that month.
    $this->assertStringStartsWith('2031-12-31', $token['expiry_date']);
    $this->assertEquals($contactId, $token['contact_id']);
    $this->assertEquals($processor['id'], $token['payment_processor_id']);
  }

  /**
   * @testPlan PU-D10
   */
  public function testUpdateCustomerDetailsUpdatesTheExistingTokenOfTheRecurringContribution(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $existing = Fixture::token($contactId, $processor['id'], ['token' => 'OLD-TOKEN']);
    $recur = Fixture::recurring($contactId, $processor, ['token' => $existing]);
    $this->http->respond('getCustomer', Responses::customerFound('NEW-TOKEN'));

    $tokenId = CRM_eWAYRecurring_Utils::updateCustomerDetails($this->gatewayResponse($processor, 'NEW-TOKEN'), $recur);

    $this->assertEquals($existing['id'], $tokenId);
    $this->assertSame(1, PaymentToken::get(FALSE)->addWhere('contact_id', '=', $contactId)->execute()->count());
    $token = PaymentToken::get(FALSE)->addWhere('id', '=', $tokenId)->execute()->first();
    $this->assertSame('NEW-TOKEN', $token['token']);
  }

  /**
   * A returning donor's new recurring record uses the card they already have (same contact and processor, same
   * token) instead of getting a second identical row (DEF-36).
   *
   * @testPlan PU-D10
   */
  public function testUpdateCustomerDetailsReusesTheSameCardForANewRecurringRecord(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $existing = Fixture::token($contactId, $processor['id'], ['token' => 'SAME-TOKEN']);
    $recur = Fixture::recurring($contactId, $processor, ['token' => FALSE]);
    $this->http->respond('getCustomer', Responses::customerFound('SAME-TOKEN'));

    $tokenId = CRM_eWAYRecurring_Utils::updateCustomerDetails($this->gatewayResponse($processor, 'SAME-TOKEN'), $recur);

    $this->assertEquals($existing['id'], $tokenId);
    $this->assertSame(1, PaymentToken::get(FALSE)->addWhere('contact_id', '=', $contactId)->execute()->count());
  }

  /**
   * The same masked card number is the same card even when eWAY issues a new customer token for it.
   *
   * @testPlan PU-D10
   */
  public function testUpdateCustomerDetailsReusesTheSameMaskedCardUnderANewToken(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $existing = Fixture::token($contactId, $processor['id'], ['token' => 'OLD-CARD-TOKEN', 'masked_account_number' => '444433XXXXXX1111']);
    $recur = Fixture::recurring($contactId, $processor, ['token' => FALSE]);
    $this->http->respond('getCustomer', Responses::customerFound('BRAND-NEW-TOKEN'));

    $tokenId = CRM_eWAYRecurring_Utils::updateCustomerDetails($this->gatewayResponse($processor, 'BRAND-NEW-TOKEN'), $recur);

    $this->assertEquals($existing['id'], $tokenId);
    $this->assertSame(1, PaymentToken::get(FALSE)->addWhere('contact_id', '=', $contactId)->execute()->count());
    $token = PaymentToken::get(FALSE)->addWhere('id', '=', $tokenId)->execute()->first();
    $this->assertSame('BRAND-NEW-TOKEN', $token['token'], 'The row now holds the token eWAY gave this time.');
  }

  /**
   * A card with the same token on another contact, or on another processor, is not the same card.
   *
   * @testPlan PU-D10
   */
  public function testUpdateCustomerDetailsDoesNotReuseACardOfAnotherContactOrProcessor(): void {
    $processor = Fixture::processor();
    $otherProcessor = Fixture::processor(FALSE, ['user_name' => 'other-key', 'password' => 'other-pass']);
    $contactId = Fixture::contact();
    $someoneElse = Fixture::contact('Someone', 'Else');
    $theirs = Fixture::token($someoneElse, $processor['id'], ['token' => 'SHARED-TOKEN']);
    $onOtherProcessor = Fixture::token($contactId, $otherProcessor['id'], ['token' => 'SHARED-TOKEN']);
    $recur = Fixture::recurring($contactId, $processor, ['token' => FALSE]);
    $this->http->respond('getCustomer', Responses::customerFound('SHARED-TOKEN'));

    $tokenId = CRM_eWAYRecurring_Utils::updateCustomerDetails($this->gatewayResponse($processor, 'SHARED-TOKEN'), $recur);

    $this->assertNotEquals($theirs['id'], $tokenId);
    $this->assertNotEquals($onOtherProcessor['id'], $tokenId);
    $token = PaymentToken::get(FALSE)->addWhere('id', '=', $tokenId)->execute()->first();
    $this->assertEquals($contactId, $token['contact_id']);
    $this->assertEquals($processor['id'], $token['payment_processor_id']);
  }

  /**
   * @testPlan PU-D10
   */
  public function testUpdateCustomerDetailsReturnsNullWhenTheCustomerHasNoCard(): void {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $processor, ['token' => FALSE]);
    $this->http->respond('getCustomer', ['Customers' => [['TokenCustomerID' => 'TOKEN-C', 'Reference' => 'civi-1']], 'Errors' => NULL]);

    $this->assertNull(CRM_eWAYRecurring_Utils::updateCustomerDetails($this->gatewayResponse($processor, 'TOKEN-C'), $recur));
  }

  /**
   * The shape validateEwayAccessCode returns, which updateCustomerDetails reads.
   */
  private function gatewayResponse(array $processor, string $token): array {
    return [
      'transactionResponse' => (object) ['TokenCustomerID' => $token],
      'eWayClient' => CRM_eWAYRecurring_Utils::getEWayClient($processor),
    ];
  }

  // ---------------------------------------------------------------------------
  // The verification queue row
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-D11
   */
  public function testCompleteEwayTransactionMarksTheQueueRowSuccessful(): void {
    [, , $contribution] = $this->pendingPayment();

    CRM_eWAYRecurring_Utils::completeEWayTransaction('AC0001');

    $row = civicrm_api3('EwayContributionTransactions', 'getsingle', ['access_code' => 'AC0001']);
    $this->assertEquals(CRM_eWAYRecurring_Utils::STATUS_SUCCESS, $row['status']);
    $this->assertEquals($contribution['id'], $row['contribution_id']);
  }

  /**
   * @testPlan PU-D11
   */
  public function testCompleteEwayTransactionIgnoresAnUnknownAccessCode(): void {
    CRM_eWAYRecurring_Utils::completeEWayTransaction('NO-SUCH-CODE');
    $this->assertSame(0, civicrm_api3('EwayContributionTransactions', 'get', ['access_code' => 'NO-SUCH-CODE'])['count']);
  }

  // ---------------------------------------------------------------------------
  // validateEwayContribution (the entry point from the return page)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-D12
   */
  public function testValidateEwayContributionIgnoresOtherPaymentProcessorTypes(): void {
    $this->assertNull(CRM_eWAYRecurring_Utils::validateEwayContribution(new stdClass(), 'whatever'));
  }

  /**
   * @testPlan PU-D12
   */
  public function testValidateEwayContributionIgnoresUnknownInvoiceAndZeroAmount(): void {
    $processor = Fixture::processor();
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $processor);
    $contactId = Fixture::contact();
    $free = Fixture::contribution($contactId, ['total_amount' => 0, 'invoice_id' => 'free-invoice']);

    $this->assertNull(CRM_eWAYRecurring_Utils::validateEwayContribution($payment, 'no-such-invoice'));
    $this->assertNull(CRM_eWAYRecurring_Utils::validateEwayContribution($payment, 'free-invoice'));
    $this->assertSame('Pending', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', Fixture::reloadContribution($free['id'])['contribution_status_id']));
  }

  /**
   * @testPlan PU-D12
   */
  public function testValidateEwayContributionCompletesALivePayment(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $_REQUEST['AccessCode'] = 'AC0001';
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 31337]));

    $payment = new CRM_Core_Payment_eWAYRecurring('live', $processor);
    $result = CRM_eWAYRecurring_Utils::validateEwayContribution($payment, $contribution['invoice_id']);

    $this->assertEquals($contribution['id'], $result['contribution']['id']);
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
    $row = civicrm_api3('EwayContributionTransactions', 'getsingle', ['access_code' => 'AC0001']);
    $this->assertEquals(CRM_eWAYRecurring_Utils::STATUS_SUCCESS, $row['status']);
  }

  /**
   * A test mode processor only looks at test contributions, so a live
   * contribution is not found and nothing is validated.
   *
   * @testPlan PU-D12
   */
  public function testValidateEwayContributionRespectsLiveAndTestMode(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $_REQUEST['AccessCode'] = 'AC0001';

    $payment = new CRM_Core_Payment_eWAYRecurring('test', $processor);

    $this->assertNull(CRM_eWAYRecurring_Utils::validateEwayContribution($payment, $contribution['invoice_id']));
    $this->assertSame([], $this->http->requests, 'No eWAY call is made for a contribution in the other mode.');
  }

}
