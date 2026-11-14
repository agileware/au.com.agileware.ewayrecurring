<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;
use CRM_eWAYRecurring_Test_FakeEwayResponses as Responses;

/**
 * The page eWAY sends the donor back to, tested through resolve(), which
 * decides the outcome without sending headers or exiting.
 *
 * Test plan suite PU-K: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_PagesTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

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
   * A live processor and a Pending contribution waiting for an access code.
   */
  private function pendingPayment(string $accessCode = 'AC0001'): array {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $contribution = Fixture::contribution($contactId);
    Fixture::queued($contribution['id'], $processor['id'], $accessCode, ['is_email_receipt' => 0]);
    $_REQUEST['AccessCode'] = $accessCode;
    return [$processor, $contactId, $contribution];
  }

  private function page(): CRM_eWAYRecurring_Page_VerifyPayment {
    return new CRM_eWAYRecurring_Page_VerifyPayment();
  }

  /**
   * @return array[] Each is [invoice id, processor id, component].
   */
  public function missingParameterProvider(): array {
    return [
      'no invoice id' => ['', '1', 'contribute'],
      'no processor id' => ['inv1', '', 'contribute'],
      'processor id zero' => ['inv1', '0', 'contribute'],
      'no component' => ['inv1', '1', ''],
      'nothing' => ['', '', ''],
    ];
  }

  /**
   * @dataProvider missingParameterProvider
   * @testPlan PU-K1
   */
  public function testAMissingParameterIsABadRequest(string $invoice, string $processorId, string $component): void {
    $this->assertSame(['status' => 400], $this->page()->resolve($invoice, $processorId, 'qf', $component));
    $this->assertSame([], $this->http->requests, 'eWAY is not asked.');
  }

  /**
   * @testPlan PU-K2
   */
  public function testAnUnknownProcessorIsABadRequest(): void {
    [, , $contribution] = $this->pendingPayment();

    $outcome = $this->page()->resolve($contribution['invoice_id'], '99999999', 'qf', 'contribute');

    $this->assertSame(['status' => 400], $outcome);
    $this->assertSame([], $this->http->requests, 'eWAY is not asked.');
  }

  /**
   * An invoice that no contribution has gives neither success nor failure.
   *
   * @testPlan PU-K2
   */
  public function testAnUnknownInvoiceIsABadRequest(): void {
    $processor = Fixture::processor();
    $_REQUEST['AccessCode'] = 'AC0001';

    $this->assertSame(['status' => 400], $this->page()->resolve('no-such-invoice', (string) $processor['id'], 'qf', 'contribute'));
    $this->assertSame([], $this->http->requests);
  }

  /**
   * @testPlan PU-K3
   */
  public function testAnApprovedEventPaymentGoesToTheThankYouPageWithTheQfKey(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 41001]));

    $outcome = $this->page()->resolve($contribution['invoice_id'], (string) $processor['id'], 'qf-key-1', 'event');

    $this->assertArrayHasKey('redirect', $outcome);
    $this->assertStringContainsString('civicrm/event/register', $outcome['redirect']);
    parse_str((string) parse_url(html_entity_decode($outcome['redirect']), PHP_URL_QUERY), $query);
    $this->assertSame('qf-key-1', $query['qfKey'] ?? NULL);
    $this->assertSame('true', $query['_qf_ThankYou_display'] ?? NULL);
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
  }

  /**
   * @testPlan PU-K3
   */
  public function testAnApprovedContributionPaymentGoesToTheContactSummary(): void {
    [$processor, $contactId, $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 41002]));

    $outcome = $this->page()->resolve($contribution['invoice_id'], (string) $processor['id'], 'qf-key-2', 'contribute');

    $this->assertArrayHasKey('redirect', $outcome);
    $this->assertStringContainsString('civicrm/contact/view', $outcome['redirect']);
    parse_str((string) parse_url(html_entity_decode($outcome['redirect']), PHP_URL_QUERY), $query);
    $this->assertEquals($contactId, $query['cid'] ?? NULL);
  }

  /**
   * A declined payment is sent to the failure page by the call inside
   * resolve(), which redirects and exits. The test framework turns the exit
   * into an exception, so nothing is returned and the contribution is Failed.
   *
   * @testPlan PU-K3
   */
  public function testADeclinedPaymentRedirectsToTheFailurePageAndFailsTheContribution(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', Responses::transactionFound([
      'TransactionStatus' => FALSE,
      'ResponseMessage' => 'D4401',
      'TransactionID' => 41003,
    ]));

    $redirectedTo = NULL;
    try {
      $this->page()->resolve($contribution['invoice_id'], (string) $processor['id'], 'qf-key-3', 'contribute');
      $this->fail('A declined payment must redirect and exit.');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      $redirectedTo = $e->errorData['url'] ?? NULL;
    }

    $this->assertNotEmpty($redirectedTo);
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Failed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
  }

  /**
   * An exception from the validation is neither success nor failure: the
   * request is rejected, and nothing reads an unset response (DEF-5).
   *
   * @testPlan PU-K3
   */
  public function testAnExceptionWhileValidatingIsABadRequest(): void {
    [$processor, , $contribution] = $this->pendingPayment();
    $this->http->respond('getTransaction', function () {
      throw new CRM_Core_Exception('eWAY is not reachable');
    });

    $outcome = $this->page()->resolve($contribution['invoice_id'], (string) $processor['id'], 'qf-key-4', 'contribute');

    $this->assertSame(['status' => 400], $outcome);
  }

}
