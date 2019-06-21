<?php

use CRM_eWAYRecurring_ExtensionUtil as E;
use Civi\Test\EndToEndInterface;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - The global variable $_CV has some properties which may be useful, such
 * as:
 *    CMS_URL, ADMIN_USER, ADMIN_PASS, ADMIN_EMAIL, DEMO_USER, DEMO_PASS,
 * DEMO_EMAIL.
 *  - To spawn a new CiviCRM thread and execute an API call or PHP code, use
 * cv(), e.g. cv('api system.flush');
 *      $data = cv('eval "return Civi::settings()->get(\'foobar\')"');
 *      $dashboardUrl = cv('url civicrm/dashboard');
 *  - This template uses the most generic base-class, but you may want to use a
 * more powerful base class, such as \PHPUnit_Extensions_SeleniumTestCase or
 *    \PHPUnit_Extensions_Selenium2TestCase.
 *    See also: https://phpunit.de/manual/4.8/en/selenium.html
 *
 * @group e2e
 * @see cv
 */
class CRM_EwayRecurring_E2ETest extends CRM_EwayRecurring_TestCase implements EndToEndInterface {

  protected $_contactID;

  /**
   * @var object
   */
  protected $contact;

  protected $paymentProcessor;

  public static function setUpBeforeClass() {
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest

    // Example: Install this extension. Don't care about anything else.
    Civi\Test::e2e()->installMe(__DIR__)->apply();

    // Example: Uninstall all extensions except this one.
    // \Civi\Test::e2e()->uninstall('*')->installMe(__DIR__)->apply();

    // Example: Install only core civicrm extensions.
    // \Civi\Test::e2e()->uninstall('*')->install('org.civicrm.*')->apply();
  }

  public function setUp() {
    //    $this->useTransaction(TRUE);
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  public function testEventRegistration() {
    $ppid = $this->createPaymentProcessor();
    civicrm_api3('Event', 'create', [
      'id' => 3,
      'payment_processor' => $ppid,
      'allow_same_participant_emails' => 1,
    ]);

    $url = cv("url -d '[cms.root]/civicrm/?page=CiviCRM&q=civicrm%2Fevent%2Fregister&reset=1&id=3'");
    list($header, $body) = $this->httpRequest($url);

    // Get the cookie
    $cookie = [];
    preg_match_all('/Set-Cookie: (.*);/', $header, $cookie);
    $cookie = $cookie[1][0];

    $result = civicrm_api3('PriceFieldValue', 'get', [
      'sequential' => 1,
      'name' => "Price_Field",
    ]);
    $pfvid = array_pop($result['values'])['id'];

    $hidden = [];
    $params = [
      'additional_participants' => '',
      'first_name' => 'unit',
      'last_name' => 'tester',
      'email-Primary' => 'unit-tester@test.com',
      'price_7' => "13",
      'payment_processor_id' => $ppid,
      'billing_first_name' => 'unit',
      'billing_middle_name' => '',
      'billing_last_name' => 'tester',
      'billing_street_address-5' => 'box 1',
      'billing_city-5' => 'Belconnen',
      'billing_country_id-5' => '1013',
      'billing_state_province_id-5' => '1638',
      'billing_postal_code-5' => '2600',
      '_qf_Register_upload' => 'Continue',
    ];

    // Get hidden fields in form and add it the the post data
    preg_match_all('/name="(.*)" type="hidden" value="(.*)"/', $body, $hidden);
    foreach ($hidden[1] as $index => $value) {
      $params[$value] = $hidden[2][$index];
    }

    // Check qfKey
    $this->assertTrue(array_key_exists('qfKey', $params));

    // Post form
    $url = cv("url -d '[cms.root]/civicrm/?page=CiviCRM&q=civicrm/event/register'");
    $options = [
      'http' => [
        'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
          "User-Agent: PHP\r\n" .
          "Accept: */*\r\n" .
          "Cookie: {$cookie}\r\n",
        'method' => 'POST',
        'content' => http_build_query($params),
      ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, FALSE, $context);

    // Confirm
    $confirm_params = [
      'qfKey' => $params['qfKey'],
      'entryURL' => $params['entryURL'],
      '_qf_default' => 'Confirm:next',
    ];
    $options['http']['content'] = http_build_query($confirm_params);
    $context = stream_context_create($options);
    $result = file_get_contents($url, FALSE, $context);
    // Pay on eWay page and get url from
    list($access_code, $url) = $this->ewayPage($result);
    $this->assertTrue(strpos($url, cv("url -d '[cms.root]/civicrm/?page=CiviCRM&q=civicrm%2Fewayrecurring%2Fverifypayment'")) !== FALSE, 'eWay payment failed.');
    list($header, $body) = $this->httpRequest($url, NULL, $cookie);

    // Check redirect
    $url = cv("url -d '[cms.root]/civicrm/?page=CiviCRM&q=civicrm%2Fevent%2Fregister'");
    $url .= "&qfKey={$params['qfKey']}&_qf_ThankYou_display=true";
    $this->assertTrue(strpos($header, $url) !== FALSE, 'Failed to redirect to thank you page.');

    // check contribution and event participant
    $result = civicrm_api3('EwayContributionTransactions', 'get', [
      'sequential' => 1,
      'access_code' => $access_code,
    ]);
    $result = array_pop($result['values']);
    $this->assertEquals(1, $result['status'], 'eWay transaction not completed.');
    $pp_result = civicrm_api3('ParticipantPayment', 'get', [
      'sequential' => 1,
      "contribution_id" => $result['contribution_id'],
      'api.Participant.getsingle' => ['sequential' => 1, 'id' => "\$value.id"],
    ]);
    $c_result = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'id' => $result['contribution_id'],
      'contribution_status_id' => "Completed",
    ]);
    $this->assertEquals(1, $c_result['count'], 'Contribution not completed.');
    $this->assertEquals('Registered', $pp_result['values'][0]['api.Participant.getsingle']['participant_status']);
  }

  public function testContributionPage() {
    $ppid = $this->createPaymentProcessor();
    civicrm_api3('ContributionPage', 'create', [
      'sequential' => 1,
      'id' => 1,
      'payment_processor' => $ppid,
    ]);

    $url = cv("url -d '[cms.root]/civicrm/?page=CiviCRM&q=civicrm%2Fcontribute%2Ftransact&reset=1&id=1'");
    list($header, $body) = $this->httpRequest($url);

    // Get the cookie
    $cookie = [];
    preg_match_all('/Set-Cookie: (.*);/', $header, $cookie);
    $cookie = $cookie[1][0];

    // What we have when submitting the form
    $params = [
      "qfKey" => "0fc87d040aa3e00f56f047a231d5622c_2332",
      "entryURL" => "http://localhost:8080/civicrm/?page=CiviCRM&amp;q=civicrm/contribute/transact&amp;page=CiviCRM&amp;reset=1&amp;id=1",
      "hidden_processor" => "1",
      "payment_processor_id" => "3",
      "priceSetId" => "3",
      "selectProduct" => "no_thanks",
      "pledge_frequency_interval" => "1",
      "_qf_default" => "Main:upload",
      "MAX_FILE_SIZE" => "2097152",
      "price_2" => "2",
      "price_3" => "",
      "is_pledge" => "0",
      "pledge_frequency_unit" => "week",
      "pledge_installments" => "",
      "email-5" => "unit-tester@test.com",
      "options_1" => "White",
      "billing_first_name" => "unit",
      "billing_middle_name" => "",
      "billing_last_name" => "tester",
      "billing_street_address-5" => "Box",
      "billing_city-5" => "Belconnen",
      "billing_country_id-5" => "1013",
      "billing_state_province_id-5" => "1641",
      "billing_postal_code-5" => "4444",
      "_qf_Main_upload" => "Confirm Contribution",
    ];

    // Get hidden fields in form and add it the the post data
    preg_match_all('/name="(.*)" type="hidden" value="(.*)"/', $body, $hidden);
    foreach ($hidden[1] as $index => $value) {
      $params[$value] = $hidden[2][$index];
    }

    $url = cv("url -d '[cms.root]/civicrm/?page=CiviCRM&q=civicrm%2Fcontribute%2Ftransact'");

    $options = [
      'http' => [
        'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
          "User-Agent: PHP\r\n" .
          "Accept: */*\r\n" .
          "Cookie: {$cookie}\r\n",
        'method' => 'POST',
        'content' => http_build_query($params),
      ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, FALSE, $context);

    // Confirm
    $confirm_params = [
      'qfKey' => $params['qfKey'],
      'entryURL' => $params['entryURL'],
      '_qf_default' => 'Confirm:next',
    ];
    $options['http']['content'] = http_build_query($confirm_params);
    $context = stream_context_create($options);
    $result = file_get_contents($url, FALSE, $context);

    // Pay on eWay page and get url from
    list($access_code, $url) = $this->ewayPage($result);
    $this->assertTrue(strpos($url, cv("url -d '[cms.root]/civicrm/?page=CiviCRM&q=civicrm%2Fcontribute%2Ftransact'")) !== FALSE, 'eWay payment failed.');
    list($header, $body) = $this->httpRequest($url, NULL, $cookie);

    // check contribution
    $result = civicrm_api3('EwayContributionTransactions', 'get', [
      'sequential' => 1,
      'access_code' => $access_code,
    ]);
    $result = array_pop($result['values']);
    $this->assertEquals(1, $result['status'], 'eWay transaction not completed.');
    $c_result = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'id' => $result['contribution_id'],
      'contribution_status_id' => "Completed",
    ]);
    $this->assertEquals(1, $c_result['count'], 'Contribution not completed.');
  }

  function assertRedirect($header, $dest, $message) {
    // Check redirect
    $this->assertTrue(strpos($header, $dest) !== FALSE, $message);
  }

  /**
   * @param $page
   *
   * @return array
   */
  function ewayPage($page) {
    // Get hidden and pre-filled fields
    $params = [];
    $re = '/(<input .*name="(.*)" .*value="(.*)".*\/>|<select .*name="(.*)".*value="(.*)"|type="hidden" .*value="(.*)" .*name="(.*)")/mU';
    preg_match_all($re, $page, $matches, PREG_SET_ORDER, 0);

    foreach ($matches as $match) {
      if (!empty($match[2])) {
        $params[$match[2]] = $match[3];
      }
      elseif (!empty($match[4])) {
        $params[$match[4]] = $match[5];
      }
      elseif (!empty($match[7])) {
        $params[$match[7]] = $match[6];
      }
    }

    // Input card details
    $params['EWAY_CARDNUMBER'] = '4444 3333 2222 1111';
    $params['EWAY_CARDNAME'] = 'unit tester';
    $params['EWAY_CARDEXPIRYMONTH'] = '12';
    $params['EWAY_CARDEXPIRYYEAR'] = date('Y', strtotime('+1 year'));
    $params['EWAY_CARDCVN'] = '333';

    // Submit payment
    $url = 'https://secure-au.sandbox.ewaypayments.com/sharedpage/SharedPayment/ProcessPayment';
    $options = [
      'http' => [
        'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
          "User-Agent: PHP\r\n" .
          "Accept: */*\r\n",
        'method' => 'POST',
        'content' => http_build_query($params),
      ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, FALSE, $context);

    // Return the url
    $re = '/id="EWAYFinaliseButton".*href="(.*)"/mU';
    preg_match_all($re, $result, $matches, PREG_SET_ORDER, 0);
    return [$params['EWAY_ACCESSCODE'], html_entity_decode($matches[0][1])];
  }

  /**
   * @param string $url
   * @param array $data
   *
   * @return array
   */
  function httpRequest($url, $data = NULL, $cookie = '') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

    if (isset($data)) {
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_POST, count($data));
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    if (!empty($cookie)) {
      curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }

    $response = curl_exec($ch);

    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    return [$header, $body];
  }

  function createPaymentProcessor() {
    $result = civicrm_api3('PaymentProcessorType', 'get', [
      'sequential' => 1,
      'name' => "eWay_Recurring",
    ]);
    $pptid = $result['id'];
    $result = civicrm_api3('PaymentProcessor', 'get', [
      'sequential' => 1,
      'name' => "eWay auto test",
    ]);
    if ($result['count'] <= 0) {
      $result = civicrm_api3('PaymentProcessor', 'create', [
        'domain_id' => '1',
        'name' => 'eWay auto test',
        'description' => 'A eWay payment processor',
        'payment_processor_type_id' => $pptid,
        'is_active' => '1',
        'is_test' => '0',
        'user_name' => '44DD7CiHkbfKFv1z6nM0BK+N1vGdL2xycHMP93vpF8TucTuzjbIcCaOX0cbkL/STiT09zv',
        'password' => '4iCMrFyI',
        'class_name' => 'au.com.agileware.ewayrecurring',
        'billing_mode' => '4',
        'is_recur' => '1',
        'payment_type' => '1',
        'payment_instrument_id' => '1',
      ]);
    }
    $this->paymentProcessor = array_pop($result['values']);
    return $this->paymentProcessor['id'];
  }
}
