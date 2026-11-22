<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;

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
    FakeHttp::uninstall();
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
    $params = $this->formParams();
    return $this->updateBillingWith($processor, $recur, $tokenId, $params);
  }

  /**
   * As updateBilling(), with the form parameters passed in and by reference, because the method adds
   * the card's number and expiry to them for the confirmation email.
   */
  private function updateBillingWith(array $processor, array $recur, int $tokenId, array &$params) {
    $_REQUEST['crid'] = $recur['id'];
    $_REQUEST['contact_payment_token'] = $tokenId;
    $row = $processor;
    $message = '';
    return (new CRM_Core_Payment_eWAYRecurring('live', $row))->updateSubscriptionBillingInfo($message, $params);
  }

  private function paymentFor(array $processor): CRM_Core_Payment_eWAYRecurring {
    $row = $processor;
    return new CRM_Core_Payment_eWAYRecurring('live', $row);
  }

  /**
   * All the contact's billing addresses.
   */
  private function billingAddresses(int $contactId): array {
    return \Civi\Api4\Address::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_billing', '=', TRUE)
      ->execute()
      ->getArrayCopy();
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

  // ---------------------------------------------------------------------------
  // The schedule date and cancelling (PU-N1, PU-N2)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-N1
   */
  public function testChangingTheScheduleWritesTheNextDateFromItsDateAndTimeParts(): void {
    $processor = Fixture::processor();
    $recur = Fixture::recurring(Fixture::contact(), $processor, ['amount' => 25]);
    $message = '';

    $result = $this->paymentFor($processor)->changeSubscriptionAmount($message, [
      'id' => $recur['id'],
      'next_scheduled_date' => '2031-03-15',
      'next_scheduled_date_time' => '14:30:00',
    ]);

    $this->assertTrue($result);
    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame(strtotime('2031-03-15 14:30:00'), strtotime($after['next_sched_contribution_date']));
    $this->assertEquals(25, $after['amount'], 'Nothing else on the record changes');
    $this->assertSame('In Progress', $this->statusName($after));
  }

  /**
   * @testPlan PU-N1
   */
  public function testChangingTheScheduleWithABlankDateChangesNothing(): void {
    $processor = Fixture::processor();
    $recur = Fixture::recurring(Fixture::contact(), $processor);
    $message = '';

    $result = $this->paymentFor($processor)->changeSubscriptionAmount($message, [
      'id' => $recur['id'],
      'next_scheduled_date' => '',
      'next_scheduled_date_time' => '',
    ]);

    $this->assertTrue($result);
    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame(strtotime($recur['next_sched_contribution_date']), strtotime($after['next_sched_contribution_date']));
  }

  /**
   * With no date parts at all the method must still change nothing, and must not raise a PHP warning
   * for the missing keys.
   *
   * @testPlan PU-N1
   */
  public function testChangingTheScheduleWithNoDatePartsChangesNothingAndRaisesNoWarning(): void {
    $processor = Fixture::processor();
    $recur = Fixture::recurring(Fixture::contact(), $processor);
    $message = '';

    $problems = [];
    set_error_handler(function ($level, $text) use (&$problems) {
      $problems[] = $text;
      return TRUE;
    });
    try {
      $result = $this->paymentFor($processor)->changeSubscriptionAmount($message, ['id' => $recur['id']]);
    }
    finally {
      restore_error_handler();
    }

    $this->assertTrue($result);
    $this->assertSame([], $problems);
    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame(strtotime($recur['next_sched_contribution_date']), strtotime($after['next_sched_contribution_date']));
  }

  /**
   * @testPlan PU-N2
   */
  public function testCancellingASubscriptionReturnsTrueWithoutCallingEway(): void {
    $http = (new FakeHttp())->install();
    $processor = Fixture::processor();
    $recur = Fixture::recurring(Fixture::contact(), $processor);
    $message = '';

    $result = $this->paymentFor($processor)->cancelSubscription($message, ['subscriptionId' => $recur['processor_id']]);

    $this->assertTrue($result);
    $this->assertSame([], $http->requests, 'No request goes to eWAY');
    $this->assertSame('', $message);
  }

  // ---------------------------------------------------------------------------
  // What is refused before anything is saved (PU-N3)
  // ---------------------------------------------------------------------------

  /**
   * Run the update with only some of the request values present.
   */
  private function updateBillingFromRequest(array $processor, array $request): void {
    unset($_REQUEST['crid'], $_REQUEST['contact_payment_token']);
    foreach ($request as $key => $value) {
      $_REQUEST[$key] = $value;
    }
    $message = '';
    $params = $this->formParams();
    $this->paymentFor($processor)->updateSubscriptionBillingInfo($message, $params);
  }

  /**
   * @testPlan PU-N3
   */
  public function testUpdatingBillingWithoutTheRecurringIdOrTheCardIsRefused(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring();
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'MISSING00001']);
    $requests = [
      'no recurring id' => ['contact_payment_token' => $token['id']],
      'no card' => ['crid' => $recur['id']],
      'neither' => [],
    ];

    foreach ($requests as $label => $request) {
      try {
        $this->updateBillingFromRequest($processor, $request);
        $this->fail("$label: the update should have been refused");
      }
      catch (PaymentProcessorException $e) {
        $this->assertSame(9000, $e->getErrorCode(), $label);
        $this->assertSame('Missing contribution id and token id.', $e->getMessage(), $label);
      }
    }

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertSame('Failed', $this->statusName($after), 'Nothing was changed');
    $this->assertEquals($recur['payment_token_id'], $after['payment_token_id']);
  }

  /**
   * @testPlan PU-N3
   */
  public function testUpdatingBillingOnACompletedRecurringContributionIsRefused(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring(['contribution_status_id:name' => 'Completed']);
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'COMPLETED0001']);
    $streetBefore = $this->billingStreet($contactId);

    try {
      $this->updateBilling($processor, $recur, $token['id']);
      $this->fail('A completed recurring contribution cannot be updated');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame(9010, $e->getErrorCode());
      $this->assertSame('Attempted to update billing details for a completed contribution.', $e->getMessage());
    }

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals($recur['payment_token_id'], $after['payment_token_id'], 'The card is not changed');
    $this->assertSame('Completed', $this->statusName($after));
    $this->assertSame($streetBefore, $this->billingStreet($contactId), 'The address is not changed');
  }

  /**
   * @testPlan PU-N3
   */
  public function testUpdatingBillingOnACancelledRecurringContributionIsRefused(): void {
    [$processor, $recur, $contactId] = $this->failedRecurring(['contribution_status_id:name' => 'Cancelled']);
    $token = Fixture::token($contactId, $processor['id'], ['token' => 'CANCELLED0001']);
    $streetBefore = $this->billingStreet($contactId);

    try {
      $this->updateBilling($processor, $recur, $token['id']);
      $this->fail('A cancelled recurring contribution cannot be updated');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame(9010, $e->getErrorCode());
      $this->assertSame('Attempted to update billing details for a cancelled contribution.', $e->getMessage());
    }

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals($recur['payment_token_id'], $after['payment_token_id']);
    $this->assertSame('Cancelled', $this->statusName($after));
    $this->assertSame($streetBefore, $this->billingStreet($contactId));
  }

  // ---------------------------------------------------------------------------
  // A successful update (PU-N4)
  // ---------------------------------------------------------------------------

  /**
   * A recurring contribution that is running normally, with an old card, and a new card to switch to.
   *
   * @return array [processor, recur, contactId, newToken]
   */
  private function runningRecurringWithANewCard(bool $withAddress = TRUE): array {
    $processor = Fixture::processor();
    $contactId = Fixture::contact('Test', 'Donor', $withAddress);
    $recur = Fixture::recurring($contactId, $processor);
    $newToken = Fixture::token($contactId, $processor['id'], [
      'token' => 'NEWCARD00001',
      'masked_account_number' => '510510XXXXXX5100',
      'expiry_date' => date('Y-m-t', strtotime('+3 years')),
    ]);
    return [$processor, $recur, $contactId, $newToken];
  }

  /**
   * @testPlan PU-N4
   */
  public function testUpdatingBillingSwitchesTheCardAndPassesItsDetailsToTheEmail(): void {
    [$processor, $recur, , $newToken] = $this->runningRecurringWithANewCard();
    $params = $this->formParams();

    $this->assertTrue($this->updateBillingWith($processor, $recur, $newToken['id'], $params));

    $after = Fixture::reloadRecurring($recur['id']);
    $this->assertEquals($newToken['id'], $after['payment_token_id']);
    $this->assertSame('NEWCARD00001', $after['processor_id'], 'The legacy token field follows the card');
    $this->assertSame('In Progress', $this->statusName($after), 'A running record stays running');

    $expiry = strtotime($newToken['expiry_date']);
    $this->assertSame('510510XXXXXX5100', $params['credit_card_number']);
    $this->assertSame(['Y' => date('Y', $expiry), 'M' => date('m', $expiry)], $params['credit_card_exp_date']);
  }

  /**
   * @testPlan PU-N4
   */
  public function testUpdatingBillingChangesTheExistingBillingAddressInPlace(): void {
    [$processor, $recur, $contactId, $newToken] = $this->runningRecurringWithANewCard();
    $before = $this->billingAddresses($contactId);
    $this->assertCount(1, $before, 'Set-up: one billing address');

    $this->updateBilling($processor, $recur, $newToken['id']);

    $after = $this->billingAddresses($contactId);
    $this->assertCount(1, $after, 'The address is updated, not added');
    $this->assertSame($before[0]['id'], $after[0]['id']);
    $this->assertSame('99 New Street', $after[0]['street_address']);
    $this->assertSame('Sydney', $after[0]['city']);
    $this->assertSame('2000', $after[0]['postal_code']);
    $this->assertEquals(1013, $after[0]['country_id']);
  }

  /**
   * @testPlan PU-N4
   */
  public function testUpdatingBillingCreatesABillingAddressWhenTheContactHasNone(): void {
    [$processor, $recur, $contactId, $newToken] = $this->runningRecurringWithANewCard(FALSE);
    $this->assertSame([], $this->billingAddresses($contactId), 'Set-up: no address');

    $this->updateBilling($processor, $recur, $newToken['id']);

    $after = $this->billingAddresses($contactId);
    $this->assertCount(1, $after);
    $this->assertSame('99 New Street', $after[0]['street_address']);
    $this->assertSame('Sydney', $after[0]['city']);
    $this->assertEquals(1013, $after[0]['country_id']);
  }

  /**
   * The postcode and state are optional: when the form sends neither, an existing value stays.
   *
   * @testPlan PU-N4
   */
  public function testUpdatingBillingWithNoPostcodeOrStateKeepsTheExistingOnes(): void {
    [$processor, $recur, $contactId, $newToken] = $this->runningRecurringWithANewCard();
    $params = $this->formParams();
    unset($params['postal_code'], $params['state_province_id']);

    $this->updateBillingWith($processor, $recur, $newToken['id'], $params);

    $after = $this->billingAddresses($contactId);
    $this->assertCount(1, $after);
    $this->assertSame('99 New Street', $after[0]['street_address'], 'The street is still updated');
    $this->assertSame('2600', $after[0]['postal_code'], 'The existing postcode stays');
  }

  /**
   * @testPlan PU-N4
   */
  public function testUpdatingBillingSavesTheStateWhenTheFormSendsOne(): void {
    $state = \Civi\Api4\StateProvince::get(FALSE)
      ->addWhere('country_id', '=', 1013)
      ->setLimit(1)
      ->execute()
      ->first();
    if (!$state) {
      $this->markTestSkipped('The test database has no states for country 1013.');
    }
    [$processor, $recur, $contactId, $newToken] = $this->runningRecurringWithANewCard(FALSE);
    $params = $this->formParams();
    $params['state_province_id'] = $state['id'];

    $this->updateBillingWith($processor, $recur, $newToken['id'], $params);

    $after = $this->billingAddresses($contactId);
    $this->assertCount(1, $after);
    $this->assertEquals($state['id'], $after[0]['state_province_id']);
  }

}
