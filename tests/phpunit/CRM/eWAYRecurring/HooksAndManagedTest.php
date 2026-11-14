<?php

use Civi\Api4\PaymentProcessorType;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * The extension's CiviCRM hooks and managed entities.
 *
 * Test plan suite PU-H: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_HooksAndManagedTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function tearDown(): void {
    // Back to "everything is allowed", which is what the test framework starts with.
    CRM_Core_Config::singleton()->userPermissionClass->permissions = NULL;
    parent::tearDown();
  }

  private function processorTypeIsActive(): bool {
    return (bool) PaymentProcessorType::get(FALSE)
      ->addSelect('is_active')
      ->addWhere('name', '=', 'eWay_Recurring')
      ->execute()
      ->first()['is_active'];
  }

  private function deactivateProcessorType(): void {
    PaymentProcessorType::update(FALSE)
      ->addValue('is_active', FALSE)
      ->addWhere('name', '=', 'eWay_Recurring')
      ->execute();
    $this->assertFalse($this->processorTypeIsActive());
  }

  /**
   * @testPlan PU-H3
   */
  public function testEnablingReactivatesTheProcessorType(): void {
    $this->deactivateProcessorType();

    ewayrecurring_civicrm_enable();

    $this->assertTrue($this->processorTypeIsActive());
  }

  /**
   * The extension is enabled from the command line (cv), by an installer or by
   * a deployment script, where nobody is logged in and so there are no
   * permissions. The enable hook used to ask APIv4 to check them, and the
   * install failed with "Authorization failed" (DEF-15).
   *
   * @testPlan PU-H13
   */
  public function testEnablingWorksWhenNobodyIsLoggedIn(): void {
    $this->deactivateProcessorType();
    CRM_Core_Config::singleton()->userPermissionClass->permissions = [];

    ewayrecurring_civicrm_enable();

    $this->assertTrue($this->processorTypeIsActive());
  }

}
