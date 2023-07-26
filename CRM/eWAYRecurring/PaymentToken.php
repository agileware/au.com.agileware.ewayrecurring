<?php

use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use CRM_eWAYRecurring_ExtensionUtil as E;

require_once E::path('vendor/autoload.php');

class CRM_eWAYRecurring_PaymentToken {

  /**
   * civicrm/ewayrecurring/createtoken
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function createToken() {
    $store = NULL;

    $contact_id = CRM_Utils_Request::retrieve('contact_id', 'String', $store, TRUE);
    $pp_id = CRM_Utils_Request::retrieve('pp_id', 'String', $store, TRUE);

    // get information from post data
    $billingDetails = [];
    $billingDetails['first_name'] = CRM_Utils_Request::retrieve('billing_first_name', 'String');
    $billingDetails['middle_name'] = CRM_Utils_Request::retrieve('billing_middle_name', 'String');
    $billingDetails['last_name'] = CRM_Utils_Request::retrieve('billing_last_name', 'String');
    foreach (array_keys($_POST) as $key) {
      $data = CRM_Utils_Request::retrieve($key, 'String');
      if (strpos($key, 'billing_street_address') !== FALSE) {
        $billingDetails['billing_street_address'] = $data;
      }
      elseif (strpos($key, 'billing_city') !== FALSE) {
        $billingDetails['billing_city'] = $data;
      }
      elseif (strpos($key, 'billing_postal_code') !== FALSE) {
        $billingDetails['billing_postal_code'] = $data;
      }
      elseif (strpos($key, 'billing_country_id') !== FALSE) {
        $country = civicrm_api3('Country', 'get', [
          'id' => $data,
          'return' => ["iso_code"],
        ]);
        $country = array_shift($country['values']);
        $billingDetails['billing_country'] = $country['iso_code'];
      }
      elseif (strpos($key, 'billing_state_province_id') !== FALSE) {
        $country = civicrm_api3('StateProvince', 'get', [
          'id' => $data,
        ]);
        $country = array_shift($country['values']);
        $billingDetails['billing_state_province'] = $country['name'];
      }
    }
    $requiredBillingFields = [
      'first_name',
      'last_name',
      'billing_street_address',
      'billing_city',
      'billing_postal_code',
      'billing_country',
      'billing_state_province',
    ];

    foreach ($requiredBillingFields as $field) {
      if (empty($billingDetails[$field])) {
        CRM_Utils_JSON::output([
          'is_error' => 1,
          'message' => 'Missing field: ' . $field,
        ]);
      }
    }
    $paymentProcessor = self::getPaymentProcessorById($pp_id);
    if (!$paymentProcessor) {
      // TODO error header
      CRM_Utils_JSON::output([
        'is_error' => 1,
        'message' => 'no payment processor found.',
      ]);
    }

    // get contact info
    $contact = civicrm_api3('Contact', 'getsingle', [
      'return' => [
        "formal_title",
        "first_name",
        "last_name",
        "country",
        "street_address",
        "city",
        "state_province",
        "postal_code",
        "email",
        'phone',
      ],
      'id' => $contact_id,
      'api.Country.getsingle' => [
        'id' => "\$value.country_id",
        'return' => ["iso_code"],
      ],
    ]);
    if ($contact['is_error']) {
      // TODO add error header
      CRM_Utils_JSON::output([
        'is_error' => 1,
        'message' => 'no contact found.',
      ]);
    }

    $client = CRM_eWAYRecurring_Utils::getEWayClient($paymentProcessor);
    $redirectUrl = CRM_Utils_System::url(
      "civicrm/ewayrecurring/savetoken",
      [
        'cid' => $contact_id,
        'pp_id' => $pp_id,
      ],
      TRUE,
      NULL,
      FALSE,
      TRUE
    );

    $ewayParams = [
      'RedirectUrl' => $redirectUrl,
      'CancelUrl' => CRM_Utils_System::url('', NULL, TRUE, NULL, FALSE),
      'Title' => '',
      'FirstName' => substr($billingDetails['first_name'],0,50),
      'LastName' => substr($billingDetails['last_name'],0,50),
      'Country' => $billingDetails['billing_country'],
      'Reference' => substr('civi-' . $contact_id,0,64),
      'Street1' => substr($billingDetails['billing_street_address'],0,50),
      'City' => substr($billingDetails['billing_city'],0,50),
      'State' => substr($billingDetails['billing_state_province'],0,50),
      'PostalCode' => substr($billingDetails['billing_postal_code'],0,30),
      'Email' => substr($contact['email'],0,50),
      'Phone' => substr($contact['phone'],0,32),
      'CustomerReadOnly' => TRUE,
    ];
    $response = $client->createCustomer(\Eway\Rapid\Enum\ApiMethod::RESPONSIVE_SHARED, $ewayParams);
    // store access code to session
    CRM_Core_Session::singleton()
      ->set('eway_accesscode', $response->AccessCode);
    $errorMessage = implode(', ', array_map(
        '\Eway\Rapid::getMessage',
        $response->getErrors())
    );
    if (!empty($errorMessage)) {
      CRM_Utils_JSON::output([
        'is_error' => 1,
        'message' => $errorMessage,
      ]);
    }

    if (!$response->SharedPaymentUrl) {
      $errorMessage = 'No eWay URL returned. Please check the civi log for more details.';
      Civi::log()
        ->info($errorMessage . ' eway response: ' . print_r($response, TRUE));
      CRM_Utils_JSON::output([
        'is_error' => 1,
        'message' => $errorMessage,
      ]);
    }

    CRM_Utils_JSON::output([
      'is_error' => 0,
      'url' => $response->SharedPaymentUrl,
    ]);
  }

  /**
   * civicrm/ewayrecurring/savetoken
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function saveToken() {
    $pp_id = CRM_Utils_Request::retrieve('pp_id', 'String');
    if (!$pp_id) {
      echo "<script>window.close();</script>";
      die();
    }

    // eway will have access code in url
    $isFromeWay = TRUE;
    $accessCode = CRM_Utils_Request::retrieve('AccessCode', 'String');
    if (!$accessCode) {
      $accessCode = CRM_Core_Session::singleton()->get('eway_accesscode');
      $isFromeWay = FALSE;
    }

    if (!$accessCode) {
      CRM_Utils_JSON::output([
        'is_error' => 1,
        'message' => 'no access code.',
      ]);
      die();
    }

    $paymentProcessor = self::getPaymentProcessorById($pp_id);
    if (!$paymentProcessor) {
      CRM_Utils_JSON::output([
        'is_error' => 1,
        'message' => 'no payment processor.',
      ]);
      // TODO error header
      die();
    }

    $client = CRM_eWAYRecurring_Utils::getEWayClient($paymentProcessor);
    $response = $client->queryTransaction($accessCode)->Transactions[0];
    $token = $response->TokenCustomerID;
    if (empty($token)) {
      CRM_Utils_JSON::output([
        'is_error' => 1,
        'message' => 'no token.',
      ]);
      // TODO handle error
      die();
    }
    $response = $client->queryCustomer($token)->Customers[0];
    // contact id from eWay reference field
    preg_match('/civi-(.*)/m', $response->Reference, $matches);
    $cid = $matches[1];

    $expiryDate = new DateTime();
    $expiryDate->setDate($response->CardDetails->ExpiryYear, $response->CardDetails->ExpiryMonth, 1);
    $expiryDate = $expiryDate->format("Y-m-t");

    $paymentToken = [
      'contact_id' => $cid,
      'payment_processor_id' => $pp_id,
      'token' => $token,
      'billing_first_name' => $response->FirstName,
      'billing_last_name' => $response->LastName,
      'masked_account_number' => $response->CardDetails->Number,
      'expiry_date' => $expiryDate,
      'created_date' => "now",
    ];

    /**
     * update the record if exist
     * Dedupe rule:
     *  Same contact with
     *    same token or
     *    same masked card number
     */
    $result = civicrm_api3('PaymentToken', 'get', [
      'sequential' => 1,
      'contact_id' => $cid,
      'token' => $token,
      'masked_account_number' => $response->CardDetails->Number,
      'options' => ['or' => [["masked_account_number", "token"]]],
    ]);

    if (!$result['is_error'] && $result['count'] > 0) {
      Civi::log()->info("existing token: $token");
      $paymentToken['id'] = $result['values'][0]['id'];
    }

    $token = civicrm_api3('PaymentToken', 'create', $paymentToken);
    $token = array_shift($token['values']);

    if ($isFromeWay) {
      echo "<script>window.close();</script>";
    }
    else {
      $result = civicrm_api3('PaymentToken', 'get', [
        'sequential' => 1,
        'contact_id' => $cid,
        'expiry_date' => ['>' => "now"],
        'options' => ['sort' => "created_date DESC"],
      ]);
      CRM_Utils_JSON::output([
        'is_error' => 0,
        'message' => $result,
        'token_id' => $token['id'],
      ]);
    }

    die();
  }

  /**
   * @param $id
   *
   * @return array|null
   * @throws \CiviCRM_API3_Exception
   */
  public static function getPaymentProcessorById($id) {
    $paymentProcessorInfo = civicrm_api3('PaymentProcessor', 'get', [
      'id' => $id,
      'sequential' => 1,
    ]);
    $paymentProcessorInfo = $paymentProcessorInfo['values'];
    if (count($paymentProcessorInfo) <= 0) {
      return NULL;
    }
    $paymentProcessorInfo = $paymentProcessorInfo[0];

    return CRM_Core_Payment_eWAYRecurring::getInstance($paymentProcessorInfo)
      ->getPaymentProcessor();
  }

  public static function fillTokensMeta() {
    // Caches payment processors
    $processors = [];

    $results = ['count' => 0];

    $tokens = PaymentToken::get(FALSE)
      ->addWhere('payment_processor_id.payment_processor_type_id.name', '=', 'eWay_Recurring')
      // Unretrieved tokens can end up with a saved token with id 0
      // ->addWhere('token', '!=', '0')
      ->addClause('OR', [
        'expiry_date',
        'IS NULL'
      ], [
        'masked_account_number',
        'IS NULL'
      ])
      ->execute();

    foreach ($tokens as $token) {
      // Get Login details for eWAY from payment processor
      $processor = $processors[$token['payment_processor_id']] ??=
        PaymentProcessor::get(FALSE)
          ->addWhere('id', '=', $token['payment_processor_id'])
          ->execute()->first();

      $eway_client = $processor['eway_client'] ??= CRM_eWAYRecurring_Utils::getEWayClient($processor);

      // Skip if unable to log in to eWAY
      if($eway_client->getErrors()) {
        continue;
      }

      $token_customer = $eway_client->queryCustomer($token['token']);

      // Skip if custom query fails
      $errors = $token_customer->getErrors();

      if($errors){
        foreach($errors as &$error) {
          $error = E::ts('Error retrieving data for token id %1: %2', [1 => $token['id'], 2 => $error]);
        }

        $result['errors'] = array_merge($result['errors'] ?? [], $errors);
        $result['is_error'] = TRUE;
        continue;
      }

      $card_details = $token_customer->Customers[0]->CardDetails;

      $card_number = $card_details->Number;

      $expiry_date = new DateTime();
      $expiry_date->setDate(2000 + (int) $card_details->ExpiryYear, $card_details->ExpiryMonth, 1);
      $expiry_date->modify('+ 1 month - 1 day');

      $expiry_date = $expiry_date->format('Ymd');

      $update = PaymentToken::update(FALSE)
        ->addWhere('id', '=', $token['id'])
        ->addValue('masked_account_number', $card_number)
        ->addValue('expiry_date', $expiry_date)
        ->execute();

      $results['count']++;
    }
    return $results;
  }
}
