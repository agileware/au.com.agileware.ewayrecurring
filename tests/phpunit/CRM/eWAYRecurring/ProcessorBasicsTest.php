<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;

/**
 * Basic behaviour of the payment processor class that needs no gateway.
 *
 * Test plan suite PU-A: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_ProcessorBasicsTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // Forget payment processor objects cached by earlier tests.
    FakeHttp::uninstall();
  }

  public function tearDown(): void {
    FakeHttp::uninstall();
    parent::tearDown();
  }

  /**
   * @testPlan PU-A5
   */
  public function testGetInstanceUsesTheModeOfTheProcessor(): void {
    $live = Fixture::processor(FALSE);
    $test = Fixture::processor(TRUE);

    $this->assertSame('live', CRM_Core_Payment_eWAYRecurring::getInstance($live)->_mode);
    $this->assertSame('test', CRM_Core_Payment_eWAYRecurring::getInstance($test)->_mode);
  }

  /**
   * @testPlan PU-A5
   */
  public function testGetInstanceReturnsTheSameObjectForTheSameProcessor(): void {
    $live = Fixture::processor(FALSE);
    $other = Fixture::processor(FALSE);

    $first = CRM_Core_Payment_eWAYRecurring::getInstance($live);

    $this->assertSame($first, CRM_Core_Payment_eWAYRecurring::getInstance($live));
    $this->assertNotSame($first, CRM_Core_Payment_eWAYRecurring::getInstance($other));
  }

}
