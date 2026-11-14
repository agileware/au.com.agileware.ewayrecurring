<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\FormWrapper;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_eWAYRecurring_ExtensionUtil as E;
use CRM_eWAYRecurring_Test_EwayFixture as Fixture;
use CRM_eWAYRecurring_Test_SettingsFormWrapper as Wrapper;

/**
 * The settings page: CRM_eWAYRecurring_Form_Settings.
 *
 * Driven through core's FormWrapper (as core's own form tests are), so the
 * form is built, validated and submitted the way a browser post would.
 *
 * Test plan suite PU-J: developer/TEST_PLAN.md.
 *
 * @group headless
 */
class CRM_eWAYRecurring_SettingsFormTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const FORM = 'CRM_eWAYRecurring_Form_Settings';

  private const SETTINGS = [
    'eway_recurring_contribution_max_retry',
    'eway_recurring_contribution_retry_delay',
    'eway_recurring_keep_sending_receipts',
    'eway_settlement_window_days',
    'eway_settlement_processors',
  ];

  /**
   * @var array The request globals the form wrapper overwrites, kept to put back.
   */
  private $savedGlobals = [];

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->savedGlobals = [
      'post' => $_POST,
      'get' => $_GET,
      'request' => $_REQUEST,
      'method' => $_SERVER['REQUEST_METHOD'] ?? NULL,
      'uri' => $_SERVER['REQUEST_URI'] ?? NULL,
    ];
    // The form redirects back to the page it was submitted on.
    $_SERVER['REQUEST_URI'] = '/civicrm/ewayrecurring/settings';
    $this->revertSettings();
  }

  public function tearDown(): void {
    $this->revertSettings();
    $_POST = $this->savedGlobals['post'];
    $_GET = $this->savedGlobals['get'];
    $_REQUEST = $this->savedGlobals['request'];
    foreach (['method' => 'REQUEST_METHOD', 'uri' => 'REQUEST_URI'] as $key => $name) {
      if ($this->savedGlobals[$key] === NULL) {
        unset($_SERVER[$name]);
      }
      else {
        $_SERVER[$name] = $this->savedGlobals[$key];
      }
    }
    parent::tearDown();
  }

  /**
   * Database rows roll back after each test but the in-process settings cache
   * does not, so put every setting this form owns back to its default.
   */
  private function revertSettings(): void {
    foreach (self::SETTINGS as $name) {
      \Civi::settings()->revert($name);
    }
  }

  /**
   * A complete, valid submission. Pass overrides, or NULL to leave a field out.
   */
  private function values(array $overrides = []): array {
    $values = $overrides + [
      'eway_recurring_contribution_max_retry' => '3',
      'eway_recurring_contribution_retry_delay' => '4',
      'eway_recurring_keep_sending_receipts' => '0',
      'eway_settlement_window_days' => '10',
    ];
    return array_filter($values, function ($value) {
      return $value !== NULL;
    });
  }

  /**
   * Build and validate the form with these values. Nothing is saved.
   *
   * @return array The validation errors, by field.
   */
  private function errorsFor(array $values): array {
    $wrapper = (new Wrapper(self::FORM, $values))->processForm(FormWrapper::VALIDATED);
    return (array) $wrapper->getValidationOutput();
  }

  /**
   * Submit the form with these values, as the Submit button does.
   */
  private function submit(array $values): Wrapper {
    return (new Wrapper(self::FORM, $values))->processForm(FormWrapper::SUBMITTED);
  }

  // ---------------------------------------------------------------------------
  // Defaults
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-J1
   */
  public function testTheFormStartsWithTheDocumentedDefaults(): void {
    $defaults = (new CRM_eWAYRecurring_Form_Settings())->setDefaultValues();

    $this->assertEquals(3, $defaults['eway_recurring_contribution_max_retry']);
    $this->assertEquals(4, $defaults['eway_recurring_contribution_retry_delay']);
    $this->assertFalse((bool) $defaults['eway_recurring_keep_sending_receipts']);
    $this->assertEquals(10, $defaults['eway_settlement_window_days']);
    $this->assertSame([], $defaults['eway_settlement_processors']);
  }

  /**
   * @testPlan PU-J1
   */
  public function testTheSettingsHoldTheSameDefaultsOutsideTheForm(): void {
    $this->assertEquals(3, \Civi::settings()->get('eway_recurring_contribution_max_retry'));
    $this->assertEquals(4, \Civi::settings()->get('eway_recurring_contribution_retry_delay'));
    $this->assertFalse((bool) \Civi::settings()->get('eway_recurring_keep_sending_receipts'));
    $this->assertEquals(10, \Civi::settings()->get('eway_settlement_window_days'));
    $this->assertSame([], \Civi::settings()->get('eway_settlement_processors'));
  }

  // ---------------------------------------------------------------------------
  // Validation
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-J2
   */
  public function testAValidSubmissionHasNoErrors(): void {
    $this->assertSame([], $this->errorsFor($this->values()));
  }

  public function badNumberProvider(): array {
    return [
      'blank' => [''],
      'letters' => ['abc'],
      'a word and a number' => ['3 times'],
    ];
  }

  /**
   * @dataProvider badNumberProvider
   * @testPlan PU-J2
   */
  public function testRetriesMustBeANumber(string $value): void {
    $errors = $this->errorsFor($this->values(['eway_recurring_contribution_max_retry' => $value]));

    $this->assertSame(['eway_recurring_contribution_max_retry' => 'Retries should be a valid number'], $errors);
  }

  /**
   * @dataProvider badNumberProvider
   * @testPlan PU-J2
   */
  public function testTheRetryDelayMustBeANumber(string $value): void {
    $errors = $this->errorsFor($this->values(['eway_recurring_contribution_retry_delay' => $value]));

    $this->assertSame(['eway_recurring_contribution_retry_delay' => 'Retry delay should be a valid number'], $errors);
  }

  /**
   * A field left out of the post is not an error: the form falls back to the
   * value it was showing (the saved setting, or its default), so the check
   * never sees a missing value.
   *
   * @testPlan PU-J2
   */
  public function testLeavingRetriesAndDelayOutIsNotAnError(): void {
    $errors = $this->errorsFor($this->values([
      'eway_recurring_contribution_max_retry' => NULL,
      'eway_recurring_contribution_retry_delay' => NULL,
    ]));

    $this->assertSame([], $errors);
  }

  public function windowProvider(): array {
    return [
      'zero' => ['0', FALSE],
      'one' => ['1', TRUE],
      'ninety' => ['90', TRUE],
      'ninety one' => ['91', FALSE],
      'negative' => ['-5', FALSE],
      'letters' => ['abc', FALSE],
    ];
  }

  /**
   * The settlement window is from 1 to 90 days.
   *
   * @dataProvider windowProvider
   * @testPlan PU-J2
   */
  public function testTheSettlementWindowMustBeBetweenOneAndNinetyDays(string $days, bool $accepted): void {
    $errors = $this->errorsFor($this->values(['eway_settlement_window_days' => $days]));

    if ($accepted) {
      $this->assertSame([], $errors);
    }
    else {
      $this->assertSame(['eway_settlement_window_days' => 'Settlement sync window must be between 1 and 90 days'], $errors);
    }
  }

  // ---------------------------------------------------------------------------
  // Saving
  // ---------------------------------------------------------------------------

  /**
   * @testPlan PU-J3
   */
  public function testEverySettingIsSavedAndReadBack(): void {
    $processor = Fixture::processor();

    $wrapper = $this->submit($this->values([
      'eway_recurring_contribution_max_retry' => '5',
      'eway_recurring_contribution_retry_delay' => '7',
      'eway_recurring_keep_sending_receipts' => '1',
      'eway_settlement_window_days' => '30',
      'eway_settlement_processors' => [(string) $processor['id']],
    ]));

    $this->assertInstanceOf(CRM_Core_Exception_PrematureExitException::class, $wrapper->getException(), 'The form redirects back to itself once saved.');
    $this->assertEquals(5, \Civi::settings()->get('eway_recurring_contribution_max_retry'));
    $this->assertEquals(7, \Civi::settings()->get('eway_recurring_contribution_retry_delay'));
    $this->assertTrue((bool) \Civi::settings()->get('eway_recurring_keep_sending_receipts'));
    $this->assertEquals(30, \Civi::settings()->get('eway_settlement_window_days'));
    $this->assertSame([(int) $processor['id']], CRM_eWAYRecurring_SettlementSync::normaliseProcessorIds(\Civi::settings()->get('eway_settlement_processors')));
  }

  /**
   * What is saved is what the form shows next time.
   *
   * @testPlan PU-J3
   */
  public function testSavedValuesBecomeTheFormDefaults(): void {
    $processor = Fixture::processor();
    $this->submit($this->values([
      'eway_recurring_contribution_max_retry' => '6',
      'eway_recurring_contribution_retry_delay' => '9',
      'eway_settlement_processors' => [(string) $processor['id']],
    ]));

    $defaults = (new CRM_eWAYRecurring_Form_Settings())->setDefaultValues();

    $this->assertEquals(6, $defaults['eway_recurring_contribution_max_retry']);
    $this->assertEquals(9, $defaults['eway_recurring_contribution_retry_delay']);
    $this->assertSame([(int) $processor['id']], $defaults['eway_settlement_processors']);
  }

  /**
   * A browser sends nothing for a multi-select with every option deselected,
   * and that must clear the saved choice rather than keep it.
   *
   * @testPlan PU-J4
   */
  public function testDeselectingEveryProcessorClearsTheSavedChoice(): void {
    $processor = Fixture::processor();
    \Civi::settings()->set('eway_settlement_processors', [(int) $processor['id']]);
    $this->assertSame([(int) $processor['id']], \Civi::settings()->get('eway_settlement_processors'));

    $this->submit($this->values());

    $this->assertSame([], \Civi::settings()->get('eway_settlement_processors'));
  }

  /**
   * @testPlan PU-J4
   */
  public function testKeepingTheProcessorsSelectedKeepsThem(): void {
    $first = Fixture::processor();
    $second = Fixture::processor();

    $this->submit($this->values(['eway_settlement_processors' => [(string) $first['id'], (string) $second['id']]]));

    $saved = CRM_eWAYRecurring_SettlementSync::normaliseProcessorIds(\Civi::settings()->get('eway_settlement_processors'));
    $this->assertEqualsCanonicalizing([(int) $first['id'], (int) $second['id']], $saved);
  }

  /**
   * The setting used to be written as a delimited string. A site that still
   * has one sees its choice selected on the form.
   *
   * @testPlan PU-J5
   */
  public function testALegacyDelimitedStringStillPrepopulatesTheForm(): void {
    $first = Fixture::processor();
    $second = Fixture::processor();
    $separator = CRM_Core_DAO::VALUE_SEPARATOR;
    \Civi::settings()->set('eway_settlement_processors', $separator . $first['id'] . $separator . $second['id'] . $separator);

    $defaults = (new CRM_eWAYRecurring_Form_Settings())->setDefaultValues();

    $this->assertSame([(int) $first['id'], (int) $second['id']], $defaults['eway_settlement_processors']);
  }

  // ---------------------------------------------------------------------------
  // The processor list
  // ---------------------------------------------------------------------------

  /**
   * The values of a built select element.
   */
  private function optionValues(Wrapper $wrapper, string $elementName): array {
    $element = $wrapper->form()->getElement($elementName);
    $values = [];
    foreach ($element->_options as $option) {
      if ($option['attr']['value'] !== '') {
        $values[] = (int) $option['attr']['value'];
      }
    }
    return $values;
  }

  /**
   * Only live eWAY processors are offered: not test ones, inactive ones or
   * other kinds of processor.
   *
   * @testPlan PU-J6
   */
  public function testTheProcessorListHoldsLiveEwayProcessorsOnly(): void {
    $live = Fixture::processor();
    $alsoLive = Fixture::processor();
    $test = Fixture::processor(TRUE);
    $inactive = Fixture::processor(FALSE, ['is_active' => 0]);
    $otherType = \Civi\Api4\PaymentProcessor::create(FALSE)
      ->setValues([
        'payment_processor_type_id:name' => 'Dummy',
        'name' => 'Other processor ' . bin2hex(random_bytes(3)),
        'domain_id' => CRM_Core_Config::domainID(),
        'is_active' => 1,
        'is_test' => 0,
      ])
      ->execute()
      ->first();

    $wrapper = (new Wrapper(self::FORM))->processForm(FormWrapper::BUILT);
    $offered = $this->optionValues($wrapper, 'eway_settlement_processors');

    $this->assertContains((int) $live['id'], $offered);
    $this->assertContains((int) $alsoLive['id'], $offered);
    $this->assertNotContains((int) $test['id'], $offered, 'A test processor is not offered.');
    $this->assertNotContains((int) $inactive['id'], $offered, 'An inactive processor is not offered.');
    $this->assertNotContains((int) $otherType['id'], $offered, 'Only eWAY processors are offered.');
  }

  /**
   * A processor that is not on the list cannot be saved by posting its id.
   *
   * @testPlan PU-J6
   */
  public function testAProcessorThatIsNotOfferedIsNotSaved(): void {
    $live = Fixture::processor();
    $test = Fixture::processor(TRUE);

    $this->submit($this->values(['eway_settlement_processors' => [(string) $live['id'], (string) $test['id']]]));

    $saved = CRM_eWAYRecurring_SettlementSync::normaliseProcessorIds(\Civi::settings()->get('eway_settlement_processors'));
    $this->assertSame([(int) $live['id']], $saved);
  }

  // ---------------------------------------------------------------------------
  // The retry setting's key
  // ---------------------------------------------------------------------------

  /**
   * The settings file names the retry setting "eway_recurring_contribution_max_retry"
   * as its key but "eway_recurring_contribution_max_fail_attempts" as its name.
   * The retry job reads the key, so the form has to write the key.
   *
   * @testPlan PU-J7
   */
  public function testTheFormWritesTheRetrySettingUnderTheKeyTheJobReads(): void {
    $file = include E::path('settings/eWAYRecurring.setting.php');
    $this->assertSame('eway_recurring_contribution_max_fail_attempts', $file['eway_recurring_contribution_max_retry']['name'], 'The file still has the differing name.');
    $this->assertArrayHasKey('eway_recurring_contribution_max_retry', (new CRM_eWAYRecurring_Form_Settings())->getFormSettings());

    $this->submit($this->values(['eway_recurring_contribution_max_retry' => '8']));

    $this->assertEquals(8, \Civi::settings()->get('eway_recurring_contribution_max_retry'));
  }

  /**
   * The same through the cron: the retry limit it applies is the one saved
   * on the form.
   *
   * @testPlan PU-J7
   */
  public function testTheRetryLimitTheJobAppliesIsTheOneSavedOnTheForm(): void {
    $this->submit($this->values(['eway_recurring_contribution_max_retry' => '2']));

    $this->assertSame(2, (int) \Civi::settings()->get('eway_recurring_contribution_max_retry'));
    $source = file_get_contents(E::path('CRM/eWAYRecurring/ProcessTrait.php'));
    $this->assertStringContainsString("get('eway_recurring_contribution_max_retry')", $source, 'The job reads the key the form writes.');
  }

}
