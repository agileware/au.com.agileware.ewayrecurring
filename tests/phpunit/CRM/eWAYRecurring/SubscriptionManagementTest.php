<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;

/**
 * Updating the billing details of a recurring contribution:
 * CRM_Core_Payment_eWAYRecurring::updateSubscriptionBillingInfo(), the method
 * CiviCRM's Update Billing Details form calls once a card has been chosen.
 *
 * Test plan suite PU-N: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_SubscriptionManagementTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function tearDown(): void {
    // The method reads the recurring id and chosen card from the request.
    unset($_REQUEST['crid'], $_REQUEST['contact_payment_token']);
    parent::tearDown();
  }

  /**
   * A failed recurring contribution that has used up its retries, with the
   * Completed contribution the cron needs to charge it again.
   *
   * @return array [processor, recur, contactId]
   */
  private function failedRecurring(array $values = []): array {
    $processor = Fixture::processor();
    $contactId = Fixture::contact();
    $recur = Fixture::recurring($contactId, $processor, $values + [
      'contribution_status_id:name' => 'Failed',
      'failure_count' => 3,
      'failure_retry_date' => '-2 days',
    ]);
    Fixture::completedContributionFor($recur);
    return [$processor, $recur, $contactId];
  }

  /**
   * What CiviCRM's form passes in: the billing address the donor typed.
   */
  private function formParams(): array {
    return [
      'first_name' => 'Test',
      'last_name' => 'Donor',
      'street_address' => '99 New Street',
      'city' => 'Sydney',
      'postal_code' => '2000',
      'country_id' => 1013,
      'country' => 'AU',
    ];
  }

  /**
   * Submit the Update Billing Details form for a recurring contribution.
   *
   * @return mixed What updateSubscriptionBillingInfo() returns.
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  private function updateBilling(array $processor, array $recur, int $tokenId) {
    $_REQUEST['crid'] = $recur['id'];
    $_REQUEST['contact_payment_token'] = $tokenId;
    $row = $processor;
    $message = '';
    $params = $this->formParams();
    return (new CRM_Core_Payment_eWAYRecurring('live', $row))->updateSubscriptionBillingInfo($message, $params);
  }

  private function statusName(array $recur): string {
    return CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur['contribution_status_id']);
  }

  /**
   * The ids the cron's normal list would charge.
   */
  private function dueIds(array $processor): array {
    $row = $processor;
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $row);
    $method = new ReflectionMethod($payment, 'get_scheduled_contributions');
    $method->setAccessible(TRUE);
    return array_map(function ($recur) {
      return (int) $recur->id;
    }, $method->invoke($payment));
  }

  private function billingStreet(int $contactId): ?string {
    return \Civi\Api4\Address::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_billing', '=', TRUE)
      ->execute()
      ->first()['street_address'] ?? NULL;
  }

  // ---------------------------------------------------------------------------
  // Recovering a Failed recurring contribution (DEF-13)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-N5
   */
  public function testUpdatingBillingRecoversAFailedRecurringContribution(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring();
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'NEWTOKEN001']);

    $this->assertTrue($this->updateBilling($processor, $recur, $token['id']));

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->statusName($after));
    $this->assertEquals(0, $after['failure_count']);
    $this->assertEmpty($after['failure_retry_date'], 'The retry date is cleared, as Reactivate does');
    $this->assertEquals($token['id'], $after['payment_token_id']);
    $this->assertSame('NEWTOKEN001', $after['processor_id']);
    $this->assertSame(strtotime($recur['next_sched_contribution_date']), strtotime($after['next_sched_contribution_date']), 'The schedule date is left alone');
    $this->assertSame('99 New Street', $this->billingStreet($contactId));
    $this->assertContains((int) $recur['id'], $this->dueIds($processor), 'The next cron run charges the recovered record');
  }

  /**
   * A record that is still being retried keeps its status and its retry
   * state: only a Failed record is recovered. It retries with the new card.
   *
   * @testPlan PU-N5
   */
  public function testUpdatingBillingLeavesARecordThatIsStillBeingRetried(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring([
      'contribution_status_id:name' => 'In Progress',
      'failure_count' => 1,
    ]);
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'NEWTOKEN002']);

    $this->assertTrue($this->updateBilling($processor, $recur, $token['id']));

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('In Progress', $this->statusName($after));
    $this->assertEquals(1, $after['failure_count']);
    $this->assertNotEmpty($after['failure_retry_date']);
    $this->assertEquals($token['id'], $after['payment_token_id']);
  }

  /**
   * A donor with no state or no email address is common. The code that builds the customer for eWAY cut those
   * values with substr(), and PHP 8.1 deprecates passing null to it, so a notice could reach the page (DEF-18).
   *
   * @testPlan PU-N9
   */
  public function testUpdatingBillingWithNoStateOrEmailRaisesNoDeprecation(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring();
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'NODEPREC0001']);

    $deprecations = [];
    set_error_handler(function ($level, $message) use (&$deprecations) {
      if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
        $deprecations[] = $message;
      }
      return TRUE;
    });
    try {
      $this->assertTrue($this->updateBilling($processor, $recur, $token['id']));
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $deprecations);
  }

  // ---------------------------------------------------------------------------
  // Which saved card may be chosen (DEF-14)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-N6
   */
  public function testAnotherContactsCardIsRefused(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring();
    $otherContact = Fixture::contact('Other', 'Person');
    $otherToken = Fixture::token($otherContact, $processor['id'], ['token' => 'OTHERCONTACT01']);

    $this->assertRefused($processor, $recur, $contactId, $otherToken['id']);
  }

  /**
   * @testPlan PU-N7
   */
  public function testACardFromAnotherPaymentProcessorIsRefused(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring();
    $otherProcessor = Fixture::processor();
    $otherToken = Fixture::token($contactId, $otherProcessor['id'], ['token' => 'OTHERPROC0001']);

    $this->assertRefused($processor, $recur, $contactId, $otherToken['id']);
  }

  /**
   * @testPlan PU-N8
   */
  public function testAnExpiredCardIsRefused(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring();
    $expired = Fixture::token($contactId, $processor['id'], [
      'token' => 'EXPIRED000001',
      'expiry_date' => date('Y-m-t', strtotime('-1 month')),
    ]);

    $this->assertRefused($processor, $recur, $contactId, $expired['id']);
  }

  /**
   * @testPlan PU-N6
   */
  public function testANonexistentCardIsRefused(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring();

    $this->assertRefused($processor, $recur, $contactId, 99999999);
  }

  /**
   * A refused card must change nothing: not the token, the status, the retry
   * state or the contact's billing address.
   */
  private function assertRefused(array $processor, array $recur, int $contactId, int $tokenId): void {
    $streetBefore = $this->billingStreet($contactId);
    try {
      $this->updateBilling($processor, $recur, $tokenId);
      $this->fail('The card should have been refused');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame(9010, $e->getErrorCode());
    }

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals($recur['payment_token_id'], $after['payment_token_id']);
    $this->assertSame('Failed', $this->statusName($after));
    $this->assertEquals(3, $after['failure_count']);
    $this->assertNotEmpty($after['failure_retry_date']);
    $this->assertSame($streetBefore, $this->billingStreet($contactId), 'The billing address is not changed either');
  }

}
