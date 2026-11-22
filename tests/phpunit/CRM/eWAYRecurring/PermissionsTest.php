<?php

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\PaymentToken;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;

/**
 * Who may read and change saved cards: CRM_eWAYRecurring_APIWrapper.
 *
 * The extension adds two permissions, "view payment tokens" and "edit payment
 * tokens", and lets users who hold them (or "access CiviContribute") use the
 * PaymentToken API, which core would otherwise leave to administrators.
 *
 * Most checks ask the API kernel whether a call would be authorised
 * (runAuthorize), which runs the same permission listeners as a real call
 * without needing the data behind it. The test framework's permission stub
 * is set to exactly the permissions each case names.
 *
 * Test plan suite PU-I: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_PermissionsTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->makeSureTheListenerIsRegistered();
  }

  public function tearDown(): void {
    // Back to "everything is allowed", which is what the test framework starts with.
    CRM_Core_Config::singleton()->userPermissionClass->permissions = NULL;
    parent::tearDown();
  }

  /**
   * The extension registers its listener in hook_config, which runs when
   * CiviCRM's configuration is built. Check it is there, and add it if the
   * test run's event dispatcher was built since.
   */
  private function makeSureTheListenerIsRegistered(): void {
    foreach (Civi::dispatcher()->getListeners('civi.api.authorize') as $listener) {
      if ($listener === ['CRM_eWAYRecurring_APIWrapper', 'authorize']) {
        return;
      }
    }
    ewayrecurring_civicrm_config(CRM_Core_Config::singleton());
    $this->assertTrue(TRUE);
  }

  /**
   * Act as a user who holds exactly these permissions.
   */
  private function holding(string ...$permissions): void {
    CRM_Core_Config::singleton()->userPermissionClass->permissions = $permissions;
  }

  /**
   * Would this call, made with permission checks on, be allowed?
   */
  private function allowed(int $version, string $entity, string $action): bool {
    $params = $version === 3
      ? ['version' => 3, 'check_permissions' => 1]
      : ['version' => 4, 'checkPermissions' => TRUE];
    return Civi::service('civi_api_kernel')->runAuthorize($entity, $action, $params);
  }

  /**
   * Every call this extension decides on: [api version, action].
   */
  public function readCallProvider(): array {
    return [
      'APIv3 get' => [3, 'get'],
      'APIv4 get' => [4, 'get'],
    ];
  }

  public function writeCallProvider(): array {
    return [
      'APIv3 create' => [3, 'create'],
      'APIv3 delete' => [3, 'delete'],
      'APIv4 create' => [4, 'create'],
      'APIv4 update' => [4, 'update'],
      'APIv4 delete' => [4, 'delete'],
    ];
  }

  // ---------------------------------------------------------------------------
  // Reading
  // ---------------------------------------------------------------------------

  /**
   * @dataProvider readCallProvider
   * @testPlan PU-I1
   */
  public function testViewingTokensIsAllowedWithTheViewPermission(int $version, string $action): void {
    $this->holding('view payment tokens');

    $this->assertTrue($this->allowed($version, 'PaymentToken', $action));
  }

  /**
   * @dataProvider readCallProvider
   * @testPlan PU-I1
   */
  public function testViewingTokensIsAllowedWithAccessCiviContribute(int $version, string $action): void {
    $this->holding('access CiviContribute');

    $this->assertTrue($this->allowed($version, 'PaymentToken', $action));
  }

  /**
   * @dataProvider readCallProvider
   * @testPlan PU-I1
   */
  public function testViewingTokensIsDeniedWithoutThosePermissions(int $version, string $action): void {
    $this->holding('access CiviCRM', 'edit payment tokens', 'view all contacts');

    $this->assertFalse($this->allowed($version, 'PaymentToken', $action));
  }

  /**
   * @dataProvider readCallProvider
   * @testPlan PU-I1
   */
  public function testViewingTokensIsDeniedWithNoPermissionsAtAll(int $version, string $action): void {
    $this->holding();

    $this->assertFalse($this->allowed($version, 'PaymentToken', $action));
  }

  // ---------------------------------------------------------------------------
  // Changing
  // ---------------------------------------------------------------------------

  /**
   * @dataProvider writeCallProvider
   * @testPlan PU-I2
   */
  public function testChangingTokensIsAllowedWithTheEditPermission(int $version, string $action): void {
    $this->holding('edit payment tokens');

    $this->assertTrue($this->allowed($version, 'PaymentToken', $action));
  }

  /**
   * @dataProvider writeCallProvider
   * @testPlan PU-I2
   */
  public function testChangingTokensIsAllowedWithAccessCiviContribute(int $version, string $action): void {
    $this->holding('access CiviContribute');

    $this->assertTrue($this->allowed($version, 'PaymentToken', $action));
  }

  /**
   * Being able to see cards is not being able to change them.
   *
   * @dataProvider writeCallProvider
   * @testPlan PU-I2
   */
  public function testChangingTokensIsDeniedWithOnlyTheViewPermission(int $version, string $action): void {
    $this->holding('view payment tokens');

    $this->assertFalse($this->allowed($version, 'PaymentToken', $action));
  }

  /**
   * @dataProvider writeCallProvider
   * @testPlan PU-I2
   */
  public function testChangingTokensIsDeniedWithNoPermissionsAtAll(int $version, string $action): void {
    $this->holding();

    $this->assertFalse($this->allowed($version, 'PaymentToken', $action));
  }

  /**
   * The edit permission does not let a user read cards back.
   *
   * @dataProvider readCallProvider
   * @testPlan PU-I2
   */
  public function testTheEditPermissionDoesNotAllowViewing(int $version, string $action): void {
    $this->holding('edit payment tokens');

    $this->assertFalse($this->allowed($version, 'PaymentToken', $action));
  }

  // ---------------------------------------------------------------------------
  // Other entities
  // ---------------------------------------------------------------------------

  public function otherEntityProvider(): array {
    return [
      'APIv3 PaymentProcessor get' => [3, 'PaymentProcessor', 'get'],
      'APIv3 PaymentProcessor create' => [3, 'PaymentProcessor', 'create'],
      'APIv4 PaymentProcessor get' => [4, 'PaymentProcessor', 'get'],
      'APIv4 PaymentProcessor update' => [4, 'PaymentProcessor', 'update'],
      'APIv3 Job get' => [3, 'Job', 'get'],
      'APIv4 Job get' => [4, 'Job', 'get'],
    ];
  }

  /**
   * The token permissions open up the token API and nothing next to it: other
   * entities that core leaves to administrators stay closed.
   *
   * @dataProvider otherEntityProvider
   * @testPlan PU-I3
   */
  public function testTheTokenPermissionsDoNotOpenUpOtherEntities(int $version, string $entity, string $action): void {
    $this->holding('access CiviContribute', 'view payment tokens', 'edit payment tokens', 'access CiviCRM');

    $this->assertFalse($this->allowed($version, $entity, $action));
  }

  /**
   * And core's own rules still apply to them as before.
   *
   * @dataProvider otherEntityProvider
   * @testPlan PU-I3
   */
  public function testAdministratorsStillReachOtherEntities(int $version, string $entity, string $action): void {
    $this->holding('administer CiviCRM');

    $this->assertTrue($this->allowed($version, $entity, $action));
  }

  // ---------------------------------------------------------------------------
  // Real calls with permission checks on (APIv4 and APIv3)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-I4
   */
  public function testAnApiv4CallWithoutPermissionIsRefused(): void {
    $this->holding('access CiviCRM');

    $this->expectException(UnauthorizedException::class);
    PaymentToken::get(TRUE)->execute();
  }

  /**
   * @testPlan PU-I4
   */
  public function testAnApiv4CallWithThePermissionRuns(): void {
    $processor = Fixture::processor();
    Fixture::token(Fixture::contact(), $processor['id']);
    $this->holding('view payment tokens', 'access CiviCRM', 'view all contacts');

    $result = PaymentToken::get(TRUE)->execute();

    $this->assertInstanceOf(\Civi\Api4\Generic\Result::class, $result);
  }

  /**
   * @testPlan PU-I4
   */
  public function testAnApiv3CallWithoutPermissionIsRefused(): void {
    $this->holding('access CiviCRM');

    $this->expectException(\Exception::class);
    civicrm_api3('PaymentToken', 'get', ['check_permissions' => 1]);
  }

  /**
   * @testPlan PU-I4
   */
  public function testAnApiv3CallWithThePermissionRuns(): void {
    $this->holding('view payment tokens', 'access CiviCRM', 'view all contacts');

    $result = civicrm_api3('PaymentToken', 'get', ['check_permissions' => 1]);

    $this->assertSame(0, $result['is_error']);
  }

}
