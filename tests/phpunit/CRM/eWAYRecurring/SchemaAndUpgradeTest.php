<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Install and upgrade bookkeeping.
 *
 * Test plan suite PU-L: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_SchemaAndUpgradeTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const KEY = 'au.com.agileware.ewayrecurring';

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  private function latestUpgradeStep(): int {
    return (int) max((new CRM_eWAYRecurring_Upgrader())->getRevisions());
  }

  /**
   * @testPlan PU-L2
   */
  public function testAFreshInstallStoresTheNewestUpgradeStep(): void {
    $this->assertSame($this->latestUpgradeStep(), (int) CRM_Core_BAO_Extension::getSchemaVersion(self::KEY));
  }

  /**
   * The hook that runs after install used to store a fixed 20200, below the
   * newest step, and relied on something else correcting it afterwards (DEF-8).
   *
   * @testPlan PU-L2
   */
  public function testThePostInstallHookStoresTheNewestUpgradeStep(): void {
    CRM_Core_BAO_Extension::setSchemaVersion(self::KEY, 1);

    ewayrecurring_civicrm_postInstall();

    $this->assertSame($this->latestUpgradeStep(), (int) CRM_Core_BAO_Extension::getSchemaVersion(self::KEY));
  }

}
