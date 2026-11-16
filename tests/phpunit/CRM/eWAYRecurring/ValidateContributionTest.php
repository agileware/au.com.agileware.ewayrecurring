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

  use CRM_eWAYRecurring_Test_LogCapture;

  /**
   * Listeners a test added to the pre hook, removed in tearDown().
   *
   * @var callable[]
   */
  private $preHookListeners = [];

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
    foreach ($this->preHookListeners as $listener) {
      \Civi::dispatcher()->removeListener('hook_civicrm_pre', $listener);
    }
    $this->preHookListeners = [];
    $this->releaseLog();
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
   * The message of a declined payment is eWAY's and is shown as HTML, so markup in it must come out as text (DEF-74).
   *
   * @testPlan PU-D15
   */
  public function testMarkupInADeclineMessageIsShownAsText(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionID' => 998,
      'TransactionStatus' => FALSE,
      'ResponseMessage' => '<img src=x onerror=alert(1)>',
    ]));
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $processor);
    CRM_Core_Session::singleton()->getStatus(TRUE);

    try {
      $payment->validateContribution('AC0001', ['id' => $contribution['id'], 'is_test' => 0], 'qf', $processor);
      $this->fail('A declined payment redirects to the failure page.');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // The redirect.
    }

    $shown = implode(' ', array_column(CRM_Core_Session::singleton()->getStatus(TRUE), 'text'));
    $this->assertStringStartsWith('Your payment was declined: ', $shown);
    $this->assertStringNotContainsString('<img', $shown);
    $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $shown);
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

  /**
   * The access code comes from the address the visitor's browser returned with, so the transaction must be the one
   * made for this contribution: its InvoiceReference is the contribution's invoice id (DEF-47).
   *
   * @testPlan PU-D7
   */
  public function testATransactionForAnotherInvoiceIsRefusedAndChangesNothing(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionID' => 777,
      'TotalAmount' => 1000,
      'InvoiceReference' => 'some-other-invoice',
    ]));

    try {
      CRM_eWAYRecurring_Utils::validateContribution('AC0001', $contribution, $processor);
      $this->fail('Expected the transaction to be refused.');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('does not belong to this contribution', $e->getMessage());
    }

    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Pending', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
    $this->assertEmpty($reloaded['trxn_id']);
  }

  /**
   * @testPlan PU-D7
   */
  public function testADeclinedTransactionForAnotherInvoiceDoesNotFailThisContribution(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionID' => 778,
      'TransactionStatus' => FALSE,
      'ResponseMessage' => 'D4401',
      'InvoiceReference' => 'some-other-invoice',
    ]));

    $this->expectException(CRM_Core_Exception::class);
    try {
      CRM_eWAYRecurring_Utils::validateContribution('AC0001', $contribution, $processor);
    }
    finally {
      $reloaded = Fixture::reloadContribution($contribution['id']);
      $this->assertSame('Pending', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
    }
  }

  /**
   * @testPlan PU-D7
   */
  public function testATransactionForTheRightInvoiceButAnotherAmountIsRefused(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    // The contribution is for 10.00 (1000 cents): eWAY approved one dollar against its invoice.
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionID' => 779,
      'TotalAmount' => 100,
      'InvoiceReference' => $contribution['invoice_id'],
    ]));

    try {
      CRM_eWAYRecurring_Utils::validateContribution('AC0001', $contribution, $processor);
      $this->fail('Expected the transaction to be refused.');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('does not match the amount', $e->getMessage());
    }
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Pending', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
  }

  /**
   * @testPlan PU-D7
   */
  public function testATransactionForThisInvoiceAndAmountCompletesTheContribution(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionID' => 780,
      'TotalAmount' => 1000,
      'InvoiceReference' => $contribution['invoice_id'],
      'InvoiceNumber' => substr($contribution['invoice_id'], 0, 12),
    ]));

    CRM_eWAYRecurring_Utils::validateContribution('AC0001', $contribution, $processor);

    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
  }

  /**
   * The return page leaves the verification queue row waiting when eWAY has no result yet, so the verification job
   * still settles it later (DEF-50).
   *
   * @testPlan PU-D11
   */
  public function testTheReturnPageLeavesTheQueueRowWaitingWhenEwayHasNoResultYet(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionNotProcessedYet());
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $processor);

    $payment->validateContribution('AC0001', ['id' => $contribution['id'], 'is_test' => 0], 'qf', $processor);

    $row = civicrm_api3('EwayContributionTransactions', 'getsingle', ['access_code' => 'AC0001']);
    $this->assertEquals(CRM_eWAYRecurring_Utils::STATUS_IN_QUEUE, $row['status']);
  }

  /**
   * Only this extension's payment processors are eWAY processors (DEF-48).
   *
   * @testPlan PU-D12
   */
  public function testOnlyAnEwayProcessorIsAnEwayProcessor(): void {
    $eway = Fixture::processor();
    $other = \Civi\Api4\PaymentProcessor::create(FALSE)
      ->setValues([
        'payment_processor_type_id:name' => 'Dummy',
        'name' => 'Other processor ' . bin2hex(random_bytes(3)),
        'domain_id' => CRM_Core_Config::domainID(),
        'is_active' => 1,
        'is_test' => 0,
      ])
      ->execute()
      ->first()['id'];

    $this->assertTrue(CRM_eWAYRecurring_Utils::isEwayProcessor((int) $eway['id']));
    $this->assertFalse(CRM_eWAYRecurring_Utils::isEwayProcessor((int) $other));
    $this->assertFalse(CRM_eWAYRecurring_Utils::isEwayProcessor(99999999));
    $this->assertNull(CRM_eWAYRecurring_PaymentToken::getPaymentProcessorById($other));
  }

  /**
   * The invoice id is read from the address, and APIv3 treats a "%" in a plain string as a wildcard. A visitor must not be
   * able to name a contribution by the start (or end) of its invoice id: with an access code eWAY answers with an error
   * for, that fails the first Pending contribution the pattern matches, whoever's it is (DEF-70).
   *
   * @testPlan PU-D13
   */
  public function testAnInvoiceIdWithAWildcardMatchesNoContribution(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $processor);
    // eWAY has no transaction for a made-up access code and says so with an error, which fails a contribution it matched.
    $this->http->respond('getTransaction', ['Transactions' => [], 'Errors' => 'V6110'], TRUE);
    $_REQUEST['AccessCode'] = 'MADE-UP';
    $invoice = $contribution['invoice_id'];

    foreach ([substr($invoice, 0, 8) . '%', '%' . substr($invoice, -6), '%', substr($invoice, 0, 4) . '%' . substr($invoice, -4)] as $pattern) {
      $this->assertNull(CRM_eWAYRecurring_Utils::validateEwayContribution($payment, $pattern), "Pattern $pattern");
    }
    // The database ignores case when it compares text, the id must not.
    $this->assertNull(CRM_eWAYRecurring_Utils::validateEwayContribution($payment, strtoupper($invoice)));
    $this->assertSame([], $this->http->requests, 'eWAY is not asked.');
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Pending', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));

    // The exact id is still found (eWAY has no result yet, so nothing changes).
    $this->http->respond('getTransaction', Responses::transactionNotProcessedYet());
    $found = CRM_eWAYRecurring_Utils::validateEwayContribution($payment, $invoice);
    $this->assertSame($contribution['id'], $found['contribution']['id']);
  }

  /**
   * An access code eWAY has nothing for, with no error either (a visitor can make one up), has no result yet. It used to
   * read the first transaction of an empty list and print PHP warnings (DEF-71).
   *
   * @testPlan PU-D14
   */
  public function testAnAccessCodeWithNoTransactionAndNoErrorIsNotProcessedYet(): void {
    $processor = Fixture::processor();
    $this->http->respond('getTransaction', ['Transactions' => [], 'Errors' => NULL]);

    $result = CRM_eWAYRecurring_Utils::validateEwayAccessCode('MADE-UP', $processor);

    $this->assertFalse($result['hasTransactionFailed']);
    $this->assertTrue($result['transactionNotProcessedYet']);
    $this->assertSame('', $result['transactionResponseError']);
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
   * Make saving one kind of record fail with a CiviCRM exception, as a hook
   * or a database constraint would.
   *
   * @param callable|null $when
   *   Given the values being saved, says whether to refuse this save.
   */
  private function failSavingA(string $objectName, string $op, ?callable $when = NULL): void {
    $listener = function ($event) use ($objectName, $op, $when) {
      [$hookOp, $hookObjectName, , $values] = $event->getHookValues();
      if ($hookObjectName === $objectName && $hookOp === $op && ($when === NULL || $when((array) $values))) {
        throw new CRM_Core_Exception("Saving the $objectName was refused by the test");
      }
    };
    \Civi::dispatcher()->addListener('hook_civicrm_pre', $listener);
    $this->preHookListeners[] = $listener;
  }

  /**
   * A Pending recurring record, its Pending first contribution, and the
   * answers eWAY gives for an approved first payment.
   *
   * @return array [processor, recurring row, contribution row as the job reads it]
   */
  private function approvedFirstRecurringPayment(): array {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $processor, [
      'contribution_status_id:name' => 'Pending',
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
    return [$processor, $recur, $this->contributionRowAsTheJobReadsIt($contribution['id'])];
  }

  /**
   * The first payment is taken by eWAY, but the recurring record cannot be
   * loaded to be started. This used to leave no trace at all (DEF-77). The
   * donor has paid, so the contribution is still completed and nothing is
   * thrown; the failure is logged with the ids a person needs to repair it.
   *
   * @testPlan PU-D16
   */
  public function testARecurringRecordThatCannotBeLoadedIsLoggedAndThePaymentStillCompletes(): void {
    [$processor, , $row] = $this->approvedFirstRecurringPayment();
    // The row names a recurring record that is not there.
    $row['contribution_recur_id'] = 99999999;
    $this->captureLog();

    CRM_eWAYRecurring_Utils::validateContribution('AC0001', $row, $processor);

    $errors = $this->logged('error', 'eWAY Recurring: the first payment was taken');
    $this->assertCount(1, $errors, 'The failure is logged once, at error level.');
    $this->assertCount(1, $this->loggedErrors());
    $this->assertSame(99999999, (int) $errors[0]['context']['recur_id']);
    $this->assertSame((int) $row['id'], (int) $errors[0]['context']['contribution_id']);
    $this->assertSame((int) $processor['id'], (int) $errors[0]['context']['processor_id']);
    $this->assertNotSame('', (string) $errors[0]['context']['msg']);
    $this->assertSame('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', Fixture::reloadContribution($row['id'])['contribution_status_id']));
  }

  /**
   * The recurring record loads but cannot be saved (here a hook refuses it).
   * It is left without its token, card and schedule date, the contribution is
   * still completed, and the failure is logged without any card data (DEF-77).
   *
   * @testPlan PU-D17
   */
  public function testARecurringRecordThatCannotBeSavedIsLoggedAndThePaymentStillCompletes(): void {
    [$processor, $recur, $row] = $this->approvedFirstRecurringPayment();
    // Only the save that starts the schedule (it carries the eWAY customer token): core also edits the recurring
    // record when the contribution is completed.
    $this->failSavingA('ContributionRecur', 'edit', function (array $values) {
      return ($values['processor_id'] ?? NULL) === 'TOKEN-A';
    });
    $this->captureLog();

    CRM_eWAYRecurring_Utils::validateContribution('AC0001', $row, $processor);

    $errors = $this->logged('error', 'eWAY Recurring: the first payment was taken');
    $this->assertCount(1, $errors);
    $context = $errors[0]['context'];
    $this->assertSame((int) $recur['id'], (int) $context['recur_id']);
    $this->assertSame((int) $row['id'], (int) $context['contribution_id']);
    $this->assertSame((int) $processor['id'], (int) $context['processor_id']);
    $this->assertStringContainsString('Saving the ContributionRecur was refused by the test', $context['msg']);
    $everythingLogged = json_encode($this->logRecords);
    $this->assertStringNotContainsString('TOKEN-A', $everythingLogged, 'The customer token is not logged.');

    $reloaded = Fixture::reloadRecurring($recur['id']);
    // Nothing the extension saves reached the record: no eWAY token, no card, and the schedule date is as it was.
    $this->assertEmpty($reloaded['processor_id']);
    $this->assertEmpty($reloaded['payment_token_id']);
    $this->assertGreaterThan(strtotime('+9 years'), strtotime($reloaded['next_sched_contribution_date']));
    $this->assertSame('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', Fixture::reloadContribution($row['id'])['contribution_status_id']));
  }

  /**
   * The normal path writes nothing at error level, so the log line above
   * means something. (This one passes with or without the fix: it guards
   * against logging a failure that did not happen.)
   *
   * @testPlan PU-D18
   */
  public function testAnApprovedFirstRecurringPaymentLogsNoError(): void {
    [$processor, $recur, $row] = $this->approvedFirstRecurringPayment();
    $this->captureLog();

    CRM_eWAYRecurring_Utils::validateContribution('AC0001', $row, $processor);

    $this->assertSame([], $this->loggedErrors());
    $this->assertSame([], $this->logged('warning', 'eWAY Recurring'));
    $this->assertSame('In Progress', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', Fixture::reloadRecurring($recur['id'])['contribution_status_id']));
  }

  /**
   * The verification job asks eWAY, eWAY approves, but completing the
   * contribution fails. That is not a missing contribution, yet the job
   * removed the queue row with no trace of why (DEF-78).
   *
   * @testPlan PU-D19
   */
  public function testTheVerificationJobLogsAFailureAfterEwayWasAsked(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 777]));
    $this->failSavingA('Contribution', 'edit');
    $this->captureLog();

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(1, $result['deleted']);
    $errors = $this->logged('error', 'eWAY Recurring: the verification job could not finish checking the payment');
    $this->assertCount(1, $errors);
    $this->assertSame((int) $contribution['id'], (int) $errors[0]['context']['contribution_id']);
    $this->assertSame((int) $processor['id'], (int) $errors[0]['context']['processor_id']);
    $this->assertStringContainsString('Saving the Contribution was refused by the test', $errors[0]['context']['msg']);
    $this->assertSame('Pending', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', Fixture::reloadContribution($contribution['id'])['contribution_status_id']));
  }

  /**
   * A contribution that is no longer Pending is the ordinary reason for the
   * job to drop a row, and is not worth an error in the log.
   *
   * @testPlan PU-D19
   */
  public function testTheVerificationJobDoesNotLogAnErrorForAContributionThatIsNoLongerPending(): void {
    [$processor, $contactId] = [Fixture::processor(), Fixture::contact()];
    $contribution = Fixture::contribution($contactId, ['contribution_status_id' => 'Completed']);
    Fixture::queued($contribution['id'], $processor['id'], 'AC-DONE');
    $this->captureLog();

    $result = (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $this->assertSame(1, $result['deleted']);
    $this->assertSame([], $this->loggedErrors());
    $this->assertSame([], $this->http->requests, 'eWAY is not asked.');
  }

  /**
   * Giving up on a row that is out of tries marks the contribution Failed.
   * When that cannot be saved the contribution stays Pending and the row stays
   * in the queue, and the job said nothing (DEF-78).
   *
   * @testPlan PU-D20
   */
  public function testTheVerificationJobLogsWhenItCannotMarkAContributionFailed(): void {
    [, , $contribution] = $this->pendingPayment();
    civicrm_api3('EwayContributionTransactions', 'create', [
      'id' => civicrm_api3('EwayContributionTransactions', 'getsingle', ['contribution_id' => $contribution['id']])['id'],
      'tries' => CRM_eWAYRecurring_Utils::MAX_TRIES,
    ]);
    $this->failSavingA('Contribution', 'edit');
    $this->captureLog();

    (new CRM_eWAYRecurring_Utils())->validatePendingTransactions();

    $errors = $this->logged('error', 'eWAY Recurring: the verification job could not mark contribution');
    $this->assertCount(1, $errors);
    $this->assertSame((int) $contribution['id'], (int) $errors[0]['context']['contribution_id']);
    $this->assertEquals(CRM_eWAYRecurring_Utils::MAX_TRIES, $errors[0]['context']['tries']);
    $this->assertSame('Pending', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', Fixture::reloadContribution($contribution['id'])['contribution_status_id']));
  }

  /**
   * The return page rejects a request when validating it throws, which is
   * right, but a donor who paid then has no trace of why in the log (DEF-79).
   * The invoice id names the payment, so it is not logged.
   *
   * @testPlan PU-D21
   */
  public function testTheReturnPageLogsWhyAPaymentCouldNotBeVerified(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $_REQUEST['AccessCode'] = 'AC0001';
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 778]));
    $this->failSavingA('Contribution', 'edit');
    $this->captureLog();

    $outcome = (new CRM_eWAYRecurring_Page_VerifyPayment())->resolve($contribution['invoice_id'], (string) $processor['id'], 'qf', 'contribute');

    $this->assertSame(['status' => 400], $outcome);
    $warnings = $this->logged('warning', 'eWAY return page: the payment could not be verified');
    $this->assertCount(1, $warnings);
    $this->assertSame((int) $processor['id'], (int) $warnings[0]['context']['processor_id']);
    $this->assertStringContainsString('Saving the Contribution was refused by the test', $warnings[0]['context']['msg']);
    $this->assertStringNotContainsString($contribution['invoice_id'], json_encode($this->logRecords));
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
