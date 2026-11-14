<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;

/**
 * Where eWAY sends the donor back to after the hosted page: the success and
 * cancel URLs built by CRM_Core_Payment_eWAYRecurring.
 *
 * The update billing branches that used to exist were deleted with the
 * VerifyUpdateToken page (DEF-13), so there is nothing to test for them.
 *
 * Test plan suite PU-C: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_ReturnUrlsTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const QF_KEY = 'testqfkey_9876';

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  private function payment(?array $processor = NULL): CRM_Core_Payment_eWAYRecurring {
    $row = $processor ?? Fixture::processor();
    return new CRM_Core_Payment_eWAYRecurring('live', $row);
  }

  /**
   * The query string of a URL as an array. Whether the path is a clean URL or
   * "index.php?q=path" depends on the site, so path checks use
   * assertStringContainsString() on the whole URL instead.
   */
  private function query(string $url): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    return $query;
  }

  // ---------------------------------------------------------------------------
  // Success URL
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-C1
   */
  public function testACustomSuccessUrlIsUsedAsIs(): void {
    $payment = $this->payment();
    $payment->setSuccessUrl('https://example.test/thanks?x=1');

    $params = ['contributionPageID' => 7, 'qfKey' => self::QF_KEY, 'invoiceID' => 'inv123'];

    $this->assertSame('https://example.test/thanks?x=1', $payment->getSuccessfulPaymentReturnUrl($params, 'contribute'));
    unset($params['contributionPageID']);
    $this->assertSame('https://example.test/thanks?x=1', $payment->getSuccessfulPaymentReturnUrl($params, 'contribute'), 'It wins over the default too.');
  }

  /**
   * @testPlan PU-C1
   */
  public function testContributionPagePaymentsReturnToTheThankYouPage(): void {
    $url = $this->payment()->getSuccessfulPaymentReturnUrl(['contributionPageID' => 7, 'qfKey' => self::QF_KEY, 'invoiceID' => 'inv123'], 'contribute');

    $this->assertStringContainsString('civicrm/contribute/transact', $url);
    $query = $this->query($url);
    $this->assertSame('1', $query['_qf_ThankYou_display']);
    $this->assertSame(self::QF_KEY, $query['qfKey']);
  }

  public function componentProvider(): array {
    return [
      'contribution' => ['contribute'],
      'event' => ['event'],
    ];
  }

  /**
   * Payments that do not come from a contribution page (events, the back
   * office) return to the verify payment page, which carries what it needs to
   * check the transaction.
   *
   * @dataProvider componentProvider
   * @testPlan PU-C1
   */
  public function testOtherPaymentsReturnToTheVerifyPaymentPage(string $component): void {
    $processor = Fixture::processor();

    $url = $this->payment($processor)->getSuccessfulPaymentReturnUrl(['qfKey' => self::QF_KEY, 'invoiceID' => 'inv123'], $component);

    $this->assertStringContainsString('civicrm/ewayrecurring/verifypayment', $url);
    $query = $this->query($url);
    $this->assertSame('inv123', $query['contributionInvoiceID']);
    $this->assertSame(self::QF_KEY, $query['qfKey']);
    $this->assertEquals($processor['id'], $query['paymentProcessorID']);
    $this->assertSame($component, $query['component']);
  }

  // ---------------------------------------------------------------------------
  // Cancel URL
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-C2
   */
  public function testACustomCancelUrlIsUsedAsIs(): void {
    $payment = $this->payment();
    $payment->setCancelUrl('https://example.test/cancelled');

    $this->assertSame('https://example.test/cancelled', $payment->getCancelPaymentReturnUrl(['contributionPageID' => 7, 'qfKey' => self::QF_KEY], 'contribute'));
    $this->assertSame('https://example.test/cancelled', $payment->getCancelPaymentReturnUrl(['qfKey' => self::QF_KEY, 'contactID' => 5], 'event'));
  }

  /**
   * @testPlan PU-C2
   */
  public function testContributionPagePaymentsCancelBackToTheForm(): void {
    $url = $this->payment()->getCancelPaymentReturnUrl(['contributionPageID' => 7, 'qfKey' => self::QF_KEY], 'contribute');

    $this->assertStringContainsString('civicrm/contribute/transact', $url);
    $query = $this->query($url);
    $this->assertSame('1', $query['_qf_Main_display']);
    $this->assertSame('1', $query['cancel']);
    $this->assertSame(self::QF_KEY, $query['qfKey']);
  }

  /**
   * @testPlan PU-C2
   */
  public function testEventPaymentsCancelBackToTheConfirmPage(): void {
    $url = $this->payment()->getCancelPaymentReturnUrl(['qfKey' => self::QF_KEY, 'contactID' => 5], 'event');

    $this->assertStringContainsString('civicrm/event/register', $url);
    $query = $this->query($url);
    $this->assertSame('true', $query['_qf_Confirm_display']);
    $this->assertSame(self::QF_KEY, $query['qfKey']);
  }

  /**
   * @testPlan PU-C2
   */
  public function testBackOfficePaymentsCancelBackToTheNewContributionForm(): void {
    $url = $this->payment()->getCancelPaymentReturnUrl(['qfKey' => self::QF_KEY, 'contactID' => 55], 'contribute');

    $this->assertStringContainsString('civicrm/contact/view/contribution', $url);
    $query = $this->query($url);
    $this->assertSame('add', $query['action']);
    $this->assertSame('55', $query['cid']);
    $this->assertSame('contribution', $query['context']);
    $this->assertSame('live', $query['mode']);
  }

  // ---------------------------------------------------------------------------
  // Contribution page detection
  // ---------------------------------------------------------------------------

  public function pageIdProvider(): array {
    return [
      'a page id' => [['contributionPageID' => 7], TRUE],
      'a page id as a string' => [['contributionPageID' => '12'], TRUE],
      'no page id' => [[], FALSE],
      'page id zero' => [['contributionPageID' => 0], FALSE],
      'page id empty' => [['contributionPageID' => ''], FALSE],
      'page id null' => [['contributionPageID' => NULL], FALSE],
    ];
  }

  /**
   * @dataProvider pageIdProvider
   * @testPlan PU-C3
   */
  public function testIsFromContributionPageNeedsAPageId(array $params, bool $expected): void {
    $this->assertSame($expected, $this->payment()->isFromContributionPage($params));
  }

}
