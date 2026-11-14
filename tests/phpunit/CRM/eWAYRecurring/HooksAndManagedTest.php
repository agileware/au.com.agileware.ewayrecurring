<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\Managed;
use Civi\Api4\OptionValue;
use Civi\Api4\PaymentProcessorType;
use Civi\Api4\StatusPreference;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_ExtensionUtil as E;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_FakeEwayHttp as FakeHttp;
use CRM_eWAYRecurring_Test_FakeEwayResponses as Responses;

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

  /**
   * @var \CRM_eWAYRecurring_Test_FakeEwayHttp
   */
  private $http;

  public function setUp(): void {
    parent::setUp();
    $this->http = (new FakeHttp())->install();
    // Start each test with no status messages from an earlier one.
    CRM_Core_Session::singleton()->getStatus(TRUE);
  }

  public function tearDown(): void {
    FakeHttp::uninstall();
    // Back to "everything is allowed", which is what the test framework starts with.
    CRM_Core_Config::singleton()->userPermissionClass->permissions = NULL;
    unset($_REQUEST['AccessCode'], $_REQUEST['qfKey']);
    unset(Civi::$statics['ewayrecurring_civicrm_preProcess::validated']);
    CRM_Core_Session::singleton()->getStatus(TRUE);
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

  // ---------------------------------------------------------------------------
  // Managed entities (PU-H1, PU-H2)
  // ---------------------------------------------------------------------------

  private function job(string $name): array {
    return civicrm_api3('Job', 'getsingle', ['name' => $name]);
  }

  private function managedRow(string $name): array {
    return Managed::get(FALSE)
      ->addWhere('module', '=', 'au.com.agileware.ewayrecurring')
      ->addWhere('name', '=', $name)
      ->execute()
      ->first();
  }

  /**
   * @testPlan PU-H1
   */
  public function testTheProcessorTypeIsManaged(): void {
    $type = PaymentProcessorType::get(FALSE)
      ->addWhere('name', '=', 'eWay_Recurring')
      ->execute()
      ->first();

    $this->assertNotNull($type, 'The processor type exists.');
    $this->assertSame('Payment_eWAYRecurring', $type['class_name']);
    $this->assertSame('API Key', $type['user_name_label']);
    $this->assertSame('API Password', $type['password_label']);
    $this->assertTrue((bool) $type['is_recur']);
    $this->assertTrue((bool) $type['is_active']);
    $managed = $this->managedRow('eWay_Recurring');
    $this->assertSame('PaymentProcessorType', $managed['entity_type']);
    $this->assertEquals($type['id'], $managed['entity_id']);
  }

  /**
   * @testPlan PU-H1
   */
  public function testTheActivityTypesAreManaged(): void {
    foreach ([
      'eWay Transaction Failed' => 'eWay_Transaction_Failed',
      'eWay Transaction Succeeded' => 'eWay_Transaction_Succeeded',
    ] as $label => $name) {
      $type = OptionValue::get(FALSE)
        ->addWhere('option_group_id:name', '=', 'activity_type')
        ->addWhere('label', '=', $label)
        ->execute()
        ->first();
      $this->assertNotNull($type, "Activity type '$label' exists.");
      $this->assertSame($name, $type['name']);
      $this->assertTrue((bool) $type['is_active']);
      $this->assertTrue((bool) $type['is_reserved']);
    }
    $this->assertSame('OptionValue', $this->managedRow('eWay_Failed_Transaction_ActivityType')['entity_type']);
    $this->assertSame('OptionValue', $this->managedRow('eWay_Succeed_Transaction_ActivityType')['entity_type']);
  }

  public function jobProvider(): array {
    return [
      'recurring payments' => ['eWay Recurring Payments', 'eWay_Recurring_cron', 'Always', 'Job', 'run_payment_cron', 1],
      'verifications' => ['eWay Transaction Verifications', 'eWay_Transaction_Verification_cron', 'Always', 'EwayContributionTransactions', 'validate', 1],
      'fill token details' => ['eWay Recurring: fill missing tokens metadata', 'eWay_fillTokensMeta_cron', 'Hourly', 'EwayRecurring', 'fillTokensMeta', 1],
    ];
  }

  /**
   * @dataProvider jobProvider
   * @testPlan PU-H1
   */
  public function testTheScheduledJobsAreManaged(string $jobName, string $managedName, string $frequency, string $apiEntity, string $apiAction, int $active): void {
    $job = $this->job($jobName);

    $this->assertSame($frequency, $job['run_frequency']);
    $this->assertSame($apiEntity, $job['api_entity']);
    $this->assertSame($apiAction, $job['api_action']);
    $this->assertEquals($active, $job['is_active']);
    $this->assertSame('Job', $this->managedRow($managedName)['entity_type']);
    $this->assertEquals($job['id'], $this->managedRow($managedName)['entity_id']);
  }

  /**
   * @testPlan PU-H1
   */
  public function testTheRecurringJobRunsTheEwayProcessor(): void {
    $this->assertSame('processor_name=eWay_Recurring', trim($this->job('eWay Recurring Payments')['parameters']));
  }

  /**
   * @testPlan PU-H2
   */
  public function testTheSettlementSyncJobExistsAndIsDisabled(): void {
    $job = $this->job('eWay Settlement Sync');

    $this->assertSame('EwaySettlement', $job['api_entity']);
    $this->assertSame('Sync', $job['api_action']);
    $this->assertSame('Daily', $job['run_frequency']);
    $this->assertEquals(0, $job['is_active'], 'It is off until an administrator turns it on.');
    $this->assertSame('Job', $this->managedRow('Cron:EwaySettlement.Sync')['entity_type']);
  }

  // ---------------------------------------------------------------------------
  // hook_buildForm (PU-H4)
  // ---------------------------------------------------------------------------

  private function cancelSubscriptionForm(string $processorType): CRM_Contribute_Form_CancelSubscription {
    $form = new CRM_Contribute_Form_CancelSubscription();
    $form->_paymentProcessor = ['payment_processor_type' => $processorType];
    $form->addElement('checkbox', 'send_cancel_request', 'Send cancellation request?');
    return $form;
  }

  /**
   * eWAY has no cancel request to send, so the donor is not asked.
   *
   * @testPlan PU-H4
   */
  public function testCancelSubscriptionDropsTheCancelRequestOptionForEway(): void {
    $form = $this->cancelSubscriptionForm('eWay_Recurring');

    ewayrecurring_civicrm_buildForm('CRM_Contribute_Form_CancelSubscription', $form);

    $this->assertFalse($form->elementExists('send_cancel_request'));
  }

  /**
   * @testPlan PU-H4
   */
  public function testCancelSubscriptionKeepsTheCancelRequestOptionForOtherProcessors(): void {
    $form = $this->cancelSubscriptionForm('Dummy');

    ewayrecurring_civicrm_buildForm('CRM_Contribute_Form_CancelSubscription', $form);

    $this->assertTrue($form->elementExists('send_cancel_request'));
  }

  // ---------------------------------------------------------------------------
  // hook_validateForm (PU-H5)
  // ---------------------------------------------------------------------------

  private function validateForm(string $formName, array $fields): array {
    $errors = [];
    $files = [];
    $form = NULL;
    ewayrecurring_civicrm_validateForm($formName, $fields, $files, $form, $errors);
    return $errors;
  }

  private function processorTypeId(string $name): int {
    return (int) PaymentProcessorType::get(FALSE)
      ->addWhere('name', '=', $name)
      ->execute()
      ->first()['id'];
  }

  /**
   * @testPlan PU-H5
   */
  public function testTheEwayProcessorFormRequiresTheApiKeyAndPassword(): void {
    $errors = $this->validateForm('CRM_Admin_Form_PaymentProcessor', [
      'payment_processor_type_id' => (string) $this->processorTypeId('eWay_Recurring'),
      'user_name' => '',
      'password' => '',
    ]);

    $this->assertSame('API Key is a required field.', $errors['user_name']);
    $this->assertSame('API Password is a required field.', $errors['password']);
  }

  /**
   * @testPlan PU-H5
   */
  public function testTheEwayProcessorFormReportsOnlyTheMissingField(): void {
    $typeId = (string) $this->processorTypeId('eWay_Recurring');

    $errors = $this->validateForm('CRM_Admin_Form_PaymentProcessor', ['payment_processor_type_id' => $typeId, 'user_name' => 'key', 'password' => '']);
    $this->assertSame(['password'], array_keys($errors));

    $errors = $this->validateForm('CRM_Admin_Form_PaymentProcessor', ['payment_processor_type_id' => $typeId, 'user_name' => '', 'password' => 'secret']);
    $this->assertSame(['user_name'], array_keys($errors));

    $errors = $this->validateForm('CRM_Admin_Form_PaymentProcessor', ['payment_processor_type_id' => $typeId, 'user_name' => 'key', 'password' => 'secret']);
    $this->assertSame([], $errors);
  }

  /**
   * A site whose processor type pre-dates managed entities has no managed record for it. The hook used to read
   * that record on every form and print a warning when it was missing (DEF-35).
   *
   * @testPlan PU-H5
   */
  public function testTheProcessorFormStillChecksWhenThereIsNoManagedRecordForTheType(): void {
    \Civi\Api4\Managed::delete(FALSE)
      ->addWhere('module', '=', 'au.com.agileware.ewayrecurring')
      ->addWhere('name', '=', 'eWay_Recurring')
      ->execute();

    $errors = $this->validateForm('CRM_Admin_Form_PaymentProcessor', [
      'payment_processor_type_id' => (string) $this->processorTypeId('eWay_Recurring'),
      'user_name' => '',
      'password' => '',
    ]);

    $this->assertSame('API Key is a required field.', $errors['user_name']);
    $this->assertSame('API Password is a required field.', $errors['password']);
    $this->assertSame([], $this->validateForm('CRM_Contribute_Form_Contribution_Main', ['user_name' => '']), 'Other forms are not touched.');
  }

  /**
   * @testPlan PU-H5
   */
  public function testOtherProcessorTypesDoNotNeedAnApiKeyAndPassword(): void {
    $errors = $this->validateForm('CRM_Admin_Form_PaymentProcessor', [
      'payment_processor_type_id' => (string) $this->processorTypeId('Dummy'),
      'user_name' => '',
      'password' => '',
    ]);

    $this->assertSame([], $errors);
  }

  /**
   * @testPlan PU-H5
   */
  public function testOtherFormsAreNotValidated(): void {
    $errors = $this->validateForm('CRM_Contribute_Form_Contribution', [
      'payment_processor_type_id' => (string) $this->processorTypeId('eWay_Recurring'),
      'user_name' => '',
      'password' => '',
    ]);

    $this->assertSame([], $errors);
  }

  // ---------------------------------------------------------------------------
  // hook_permission (PU-H6)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-H6
   */
  public function testBothTokenPermissionsAreDeclaredWithLabels(): void {
    $permissions = [];

    ewayrecurring_civicrm_permission($permissions);

    $this->assertSame(['view payment tokens', 'edit payment tokens'], array_keys($permissions));
    $this->assertSame('CiviContribute: view payment tokens', $permissions['view payment tokens']['label']);
    $this->assertSame('CiviContribute: edit payment tokens', $permissions['edit payment tokens']['label']);
  }

  // ---------------------------------------------------------------------------
  // hook_navigationMenu (PU-H7)
  // ---------------------------------------------------------------------------

  private function menuWithAdminister(): array {
    return [
      1 => ['attributes' => ['name' => 'Contacts', 'label' => 'Contacts', 'navID' => 1, 'active' => 1], 'child' => []],
      2 => ['attributes' => ['name' => 'Administer', 'label' => 'Administer', 'navID' => 2, 'active' => 1], 'child' => []],
    ];
  }

  /**
   * @testPlan PU-H7
   */
  public function testTheSettingsPageIsAddedUnderAdminister(): void {
    $menu = $this->menuWithAdminister();

    ewayrecurring_civicrm_navigationMenu($menu);

    $this->assertSame([], $menu[1]['child'], 'Other menus are not touched.');
    $this->assertCount(1, $menu[2]['child']);
    $item = reset($menu[2]['child'])['attributes'];
    $this->assertSame('eWay Recurring Settings', $item['label']);
    $this->assertSame('eWayRecurringSettings', $item['name']);
    $this->assertSame('civicrm/ewayrecurring/settings', $item['url']);
    $this->assertSame('administer CiviCRM', $item['permission']);
  }

  /**
   * @testPlan PU-H7
   */
  public function testNothingIsAddedWithoutAnAdministerMenu(): void {
    $menu = [1 => ['attributes' => ['name' => 'Contacts', 'label' => 'Contacts', 'navID' => 1, 'active' => 1], 'child' => []]];
    $before = $menu;

    ewayrecurring_civicrm_navigationMenu($menu);

    $this->assertSame($before, $menu);
  }

  // ---------------------------------------------------------------------------
  // hook_searchKitTasks (PU-H8)
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-H8
   */
  public function testTheReactivateTaskIsRegisteredForRecurringContributions(): void {
    $tasks = ['ContributionRecur' => ['update' => ['title' => 'Update']]];

    ewayrecurring_civicrm_searchKitTasks($tasks, TRUE, 0);

    $task = $tasks['ContributionRecur']['reset_recur_status'];
    $this->assertSame('Reactivate', $task['title']);
    $this->assertSame('ewayrecurring', $task['module']);
    $this->assertSame('~/ewayrecurring/crmSearchTaskResetRecur.html', $task['uiDialog']['templateUrl']);
    $this->assertArrayHasKey('update', $tasks['ContributionRecur'], 'Existing tasks are kept.');
    // The module and the dialog template the task names are shipped.
    $this->assertFileExists(E::path('ang/ewayrecurring.ang.php'));
    $this->assertFileExists(E::path('ang/ewayrecurring/crmSearchTaskResetRecur.html'));
  }

  // ---------------------------------------------------------------------------
  // hook_coreResourceList (PU-H9)
  // ---------------------------------------------------------------------------

  /**
   * Run $callback with an empty html-header region, so the script and
   * variables the hook adds are the only ones there, then put back the region
   * the test run had.
   */
  private function inEmptyHeaderRegion(callable $callback): void {
    $had = isset(Civi::$statics['CRM_Core_Region']['html-header']);
    $saved = Civi::$statics['CRM_Core_Region']['html-header'] ?? NULL;
    unset(Civi::$statics['CRM_Core_Region']['html-header']);
    try {
      $callback();
    }
    finally {
      if ($had) {
        Civi::$statics['CRM_Core_Region']['html-header'] = $saved;
      }
      else {
        unset(Civi::$statics['CRM_Core_Region']['html-header']);
      }
    }
  }

  private function exportedProcessorIds(): array {
    $settings = CRM_Core_Resources::singleton()->getSettings();
    return $settings['vars']['agilewareEwayExtension']['paymentProcessorId'] ?? [];
  }

  /**
   * @testPlan PU-H9
   */
  public function testTheScriptAndTheEwayProcessorIdsAreAddedToTheHeader(): void {
    $ewayProcessor = Fixture::processor();
    $otherId = \Civi\Api4\PaymentProcessor::create(FALSE)
      ->setValues([
        'payment_processor_type_id:name' => 'Dummy',
        'name' => 'Other processor ' . bin2hex(random_bytes(3)),
        'domain_id' => CRM_Core_Config::domainID(),
        'is_active' => 1,
        'is_test' => 0,
      ])
      ->execute()
      ->first()['id'];

    $this->inEmptyHeaderRegion(function () use ($ewayProcessor, $otherId) {
      $list = [];
      ewayrecurring_civicrm_coreResourceList($list, 'html-header');

      $this->assertNotNull(CRM_Core_Region::instance('html-header')->get('au.com.agileware.ewayrecurring:js/eway.js'), 'The script is added.');
      $ids = array_map('intval', $this->exportedProcessorIds());
      $this->assertContains((int) $ewayProcessor['id'], $ids);
      $this->assertNotContains((int) $otherId, $ids, 'Only eWAY processors are listed.');
    });
  }

  /**
   * @testPlan PU-H9
   */
  public function testNoProcessorIdsAreExportedWhenThereAreNoEwayProcessors(): void {
    $this->inEmptyHeaderRegion(function () {
      $list = [];
      ewayrecurring_civicrm_coreResourceList($list, 'html-header');

      $this->assertSame([], $this->exportedProcessorIds());
    });
  }

  /**
   * @testPlan PU-H9
   */
  public function testOtherRegionsAreLeftAlone(): void {
    Fixture::processor();

    $this->inEmptyHeaderRegion(function () {
      $list = [];
      ewayrecurring_civicrm_coreResourceList($list, 'page-header');

      $this->assertNull(CRM_Core_Region::instance('html-header')->get('au.com.agileware.ewayrecurring:js/eway.js'));
      $this->assertSame([], $this->exportedProcessorIds());
    });
  }

  // ---------------------------------------------------------------------------
  // hook_preProcess: Thank You (PU-H10)
  // ---------------------------------------------------------------------------

  private function priceSet(bool $isQuickConfig): int {
    $priceSet = new CRM_Price_DAO_PriceSet();
    $priceSet->name = 'priceset_' . bin2hex(random_bytes(4));
    $priceSet->title = 'Test price set';
    $priceSet->extends = 2;
    $priceSet->is_active = 1;
    $priceSet->is_quick_config = (int) $isQuickConfig;
    $priceSet->save();
    return (int) $priceSet->id;
  }

  /**
   * A Thank You form as the return from eWAY would build it.
   */
  private function thankYouForm(CRM_Core_Payment_eWAYRecurring $payment, string $invoiceId, ?int $priceSetId = NULL): CRM_Contribute_Form_Contribution_ThankYou {
    $form = new CRM_Contribute_Form_Contribution_ThankYou();
    $form->_paymentProcessor = ['object' => $payment];
    $form->_params = ['invoiceID' => $invoiceId];
    $form->_priceSetId = $priceSetId;
    return $form;
  }

  private function preProcessThankYou(CRM_Contribute_Form_Contribution_ThankYou $form): void {
    ewayrecurring_civicrm_preProcess('CRM_Contribute_Form_Contribution_ThankYou', $form);
  }

  /**
   * A live processor, and a Pending contribution waiting for eWAY's answer.
   */
  private function pendingContribution(string $accessCode): array {
    $processor = Fixture::processor();
    $contribution = Fixture::contribution(Fixture::contact());
    Fixture::queued($contribution['id'], $processor['id'], $accessCode);
    return [new CRM_Core_Payment_eWAYRecurring('live', $processor), $contribution];
  }

  /**
   * The page asks eWAY about an invoice once, however often it is built.
   *
   * @testPlan PU-H10
   */
  public function testTheThankYouPageValidatesAnInvoiceOnce(): void {
    [$payment, $contribution] = $this->pendingContribution('AC-THANKS-1');
    $_REQUEST['AccessCode'] = 'AC-THANKS-1';
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 4242]), TRUE);

    $this->preProcessThankYou($this->thankYouForm($payment, $contribution['invoice_id']));
    $this->preProcessThankYou($this->thankYouForm($payment, $contribution['invoice_id']));

    $this->assertCount(1, $this->http->callsTo('getTransaction'));
    $reloaded = Fixture::reloadContribution($contribution['id']);
    $this->assertSame('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $reloaded['contribution_status_id']));
  }

  /**
   * @testPlan PU-H10
   */
  public function testTheThankYouPageValidatesEachInvoiceSeparately(): void {
    [$payment, $first] = $this->pendingContribution('AC-THANKS-2');
    [, $second] = $this->pendingContribution('AC-THANKS-3');
    $_REQUEST['AccessCode'] = 'AC-THANKS-2';
    // Each invoice gets its own eWAY transaction id, as core refuses a duplicate.
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 20001]));
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionID' => 20002]));

    $this->preProcessThankYou($this->thankYouForm($payment, $first['invoice_id']));
    $this->preProcessThankYou($this->thankYouForm($payment, $second['invoice_id']));

    $this->assertCount(2, $this->http->callsTo('getTransaction'));
  }

  public function lineItemProvider(): array {
    return [
      'no price set' => [NULL, FALSE],
      'a quick config price set' => ['quick', FALSE],
      'a price set with its own fields' => ['full', TRUE],
    ];
  }

  /**
   * The line items are hidden when the donor only picked an amount (no price
   * set, or the hidden quick config one), and kept when they chose price
   * fields.
   *
   * @dataProvider lineItemProvider
   * @testPlan PU-H10
   */
  public function testTheThankYouPageHidesLineItemsForQuickConfigPriceSets(?string $priceSet, bool $keepsLineItems): void {
    $priceSetId = $priceSet === NULL ? NULL : $this->priceSet($priceSet === 'quick');
    $processor = Fixture::processor();
    $payment = new CRM_Core_Payment_eWAYRecurring('live', $processor);
    $sentinel = ['sentinel line item'];
    CRM_Core_Smarty::singleton()->assign('lineItem', $sentinel);

    // No contribution has this invoice, so nothing is sent to eWAY.
    $this->preProcessThankYou($this->thankYouForm($payment, 'no-such-invoice-' . bin2hex(random_bytes(4)), $priceSetId));

    $lineItem = CRM_Core_Smarty::singleton()->getTemplateVars('lineItem');
    if ($keepsLineItems) {
      $this->assertSame($sentinel, $lineItem);
    }
    else {
      $this->assertFalse($lineItem);
    }
    $this->assertSame([], $this->http->requests);
  }

  // ---------------------------------------------------------------------------
  // hook_preProcess: Confirm (PU-H11)
  // ---------------------------------------------------------------------------

  /**
   * @param string $class The Confirm form class.
   * @param string|null $qfKey What the form says its key is.
   * @param mixed $processorEntry What the form holds as its payment processor.
   */
  private function confirmForm(string $class, ?string $qfKey, $processorEntry): object {
    $form = $this->getMockBuilder($class)
      ->disableOriginalConstructor()
      ->onlyMethods(['get', 'getVar'])
      ->getMock();
    $form->method('get')->willReturn($qfKey);
    $form->method('getVar')->willReturn($processorEntry);
    return $form;
  }

  /**
   * The processor as the Confirm form holds it: the row plus the payment object.
   */
  private function confirmProcessor(): array {
    $processor = Fixture::processor();
    $processor['object'] = new CRM_Core_Payment_eWAYRecurring('live', $processor);
    return $processor;
  }

  /**
   * Put the response the donor's earlier attempt stored in the session.
   */
  private function priorAttempt(string $qfKey, string $accessCode = 'AC-PRIOR'): void {
    CRM_Core_Session::singleton()->set('eWAYResponse', serialize((object) ['AccessCode' => $accessCode]), $qfKey);
  }

  private function preProcessConfirm(object $form): void {
    $formName = $form instanceof CRM_Event_Form_Registration_Confirm
      ? 'CRM_Event_Form_Registration_Confirm'
      : 'CRM_Contribute_Form_Contribution_Confirm';
    ewayrecurring_civicrm_preProcess($formName, $form);
  }

  public function confirmFormProvider(): array {
    return [
      'contribution' => [CRM_Contribute_Form_Contribution_Confirm::class, 'Payment already completed for this Contribution'],
      'event registration' => [CRM_Event_Form_Registration_Confirm::class, 'Payment already completed for this Registration'],
    ];
  }

  /**
   * @dataProvider confirmFormProvider
   * @testPlan PU-H11
   */
  public function testConfirmRefusesAPaymentThatWasAlreadyTaken(string $class, string $message): void {
    $qfKey = 'qfkey-paid-' . bin2hex(random_bytes(3));
    $this->priorAttempt($qfKey);
    $this->http->respond('getTransaction', Responses::transactionFound());
    $form = $this->confirmForm($class, $qfKey, $this->confirmProcessor());

    try {
      $this->preProcessConfirm($form);
      $this->fail('A payment that already went through must stop the form.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame($message, $e->getMessage());
    }
    $this->assertSame([['AC-PRIOR']], array_column($this->http->callsTo('getTransaction'), 'args'));
  }

  /**
   * @dataProvider confirmFormProvider
   * @testPlan PU-H11
   */
  public function testConfirmWarnsAboutAFailedEarlierAttemptAndForgetsIt(string $class): void {
    $qfKey = 'qfkey-failed-' . bin2hex(random_bytes(3));
    $this->priorAttempt($qfKey);
    $this->http->respond('getTransaction', Responses::transactionFound(['TransactionStatus' => FALSE, 'ResponseMessage' => 'D4401']));
    $form = $this->confirmForm($class, $qfKey, $this->confirmProcessor());

    $this->preProcessConfirm($form);

    $messages = array_column(CRM_Core_Session::singleton()->getStatus(TRUE), 'text');
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('has already been submitted, but failed', $messages[0]);
    $this->assertNull(CRM_Core_Session::singleton()->get('eWAYResponse', $qfKey), 'The stored response is cleared, so a new transaction is made.');
  }

  /**
   * @dataProvider confirmFormProvider
   * @testPlan PU-H11
   */
  public function testConfirmCarriesOnWhenTheEarlierAttemptWasNotProcessedYet(string $class): void {
    $qfKey = 'qfkey-waiting-' . bin2hex(random_bytes(3));
    $this->priorAttempt($qfKey);
    $this->http->respond('getTransaction', Responses::transactionNotProcessedYet());
    $form = $this->confirmForm($class, $qfKey, $this->confirmProcessor());

    $this->preProcessConfirm($form);

    $this->assertSame([], CRM_Core_Session::singleton()->getStatus(TRUE), 'No warning is shown.');
    $this->assertNotNull(CRM_Core_Session::singleton()->get('eWAYResponse', $qfKey), 'The stored response is kept.');
    $this->assertCount(1, $this->http->callsTo('getTransaction'));
  }

  /**
   * @testPlan PU-H11
   */
  public function testConfirmDoesNotAskEwayWithoutAnEarlierAttempt(): void {
    $form = $this->confirmForm(CRM_Contribute_Form_Contribution_Confirm::class, 'qfkey-none-' . bin2hex(random_bytes(3)), $this->confirmProcessor());
    $this->preProcessConfirm($form);

    $noKey = $this->confirmForm(CRM_Contribute_Form_Contribution_Confirm::class, NULL, $this->confirmProcessor());
    $this->preProcessConfirm($noKey);

    $this->assertSame([], $this->http->requests);
  }

  /**
   * @testPlan PU-H11
   */
  public function testConfirmIgnoresOtherPaymentProcessors(): void {
    $qfKey = 'qfkey-other-' . bin2hex(random_bytes(3));
    $this->priorAttempt($qfKey);
    $form = $this->confirmForm(CRM_Contribute_Form_Contribution_Confirm::class, $qfKey, ['object' => new stdClass()]);

    $this->preProcessConfirm($form);

    $this->assertSame([], $this->http->requests);
  }

  // ---------------------------------------------------------------------------
  // hook_check (PU-H12)
  // ---------------------------------------------------------------------------

  /**
   * A recurring contribution that has run out of retries, with its last retry
   * date this long ago.
   */
  private function failedRecurring(string $retryDate = '-5 hours', array $values = []): array {
    $processor = Fixture::processor();
    return Fixture::recurring(Fixture::contact(), $processor, $values + [
      'contribution_status_id:name' => 'Failed',
      'failure_count' => 3,
      'failure_retry_date' => $retryDate,
      'token' => FALSE,
    ]);
  }

  private function runCheck(array $statusNames = [], bool $includeDisabled = FALSE): array {
    $messages = [];
    ewayrecurring_civicrm_check($messages, $statusNames, $includeDisabled);
    return $messages;
  }

  /**
   * @testPlan PU-H12
   */
  public function testTheCheckSaysNothingWithoutFailedRecurringContributions(): void {
    $this->assertSame([], $this->runCheck());
  }

  /**
   * @testPlan PU-H12
   */
  public function testTheCheckReportsTheCountWithALink(): void {
    $this->failedRecurring();
    $this->failedRecurring('-2 days');

    $messages = $this->runCheck();

    $this->assertCount(1, $messages);
    $this->assertSame('ewayrecurring_failed', $messages[0]->getName());
    $this->assertStringContainsString('Found 2 failed recurring contributions', $messages[0]->getMessage());
    $this->assertMatchesRegularExpression('#civicrm(/|%2F)contribute(/|%2F)failedrecurring#', $messages[0]->getMessage());
  }

  /**
   * Only records that failed more than four hours ago count, so one the cron
   * is about to retry does not raise an alarm.
   *
   * @testPlan PU-H12
   */
  public function testTheCheckIgnoresFailuresInsideTheFourHourThreshold(): void {
    $this->failedRecurring('-1 hour');
    $this->failedRecurring('+1 day');

    $this->assertSame([], $this->runCheck());
  }

  /**
   * @testPlan PU-H12
   */
  public function testTheCheckIgnoresTestRecurringContributions(): void {
    $this->failedRecurring('-5 hours', ['is_test' => 1]);

    $this->assertSame([], $this->runCheck());
  }

  /**
   * Only a record marked Failed is counted (DEF-3): one still being retried
   * is In Progress.
   *
   * @testPlan PU-H12
   */
  public function testTheCheckOnlyCountsRecurringContributionsMarkedFailed(): void {
    $this->failedRecurring('-5 hours', ['contribution_status_id:name' => 'In Progress']);
    $this->assertSame([], $this->runCheck());

    $this->failedRecurring();
    $messages = $this->runCheck();
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('Found 1 failed recurring contributions', $messages[0]->getMessage());
  }

  /**
   * @testPlan PU-H12
   */
  public function testTheCheckHonoursADisabledStatusPreference(): void {
    $this->failedRecurring();
    StatusPreference::create(FALSE)
      ->setValues([
        'name' => 'ewayrecurring_failed',
        'domain_id' => CRM_Core_Config::domainID(),
        'is_active' => FALSE,
      ])
      ->execute();

    $this->assertSame([], $this->runCheck(), 'A disabled check stays quiet.');
    $this->assertCount(1, $this->runCheck([], TRUE), 'It is reported when disabled checks are asked for.');
  }

  /**
   * @testPlan PU-H12
   */
  public function testTheCheckHonoursTheStatusNamesFilter(): void {
    $this->failedRecurring();

    $this->assertSame([], $this->runCheck(['some_other_check']));
    $this->assertCount(1, $this->runCheck(['some_other_check', 'ewayrecurring_failed']));
    $this->assertCount(1, $this->runCheck(['ewayrecurring_failed']));
  }

}
