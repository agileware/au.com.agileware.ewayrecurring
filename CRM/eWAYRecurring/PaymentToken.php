<?php
require_once 'vendor/autoload.php';

class CRM_eWAYRecurring_PaymentToken {

  /**
   * civicrm/ewayrecurring/createtoken
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function createToken() {
    $contact_id = CRM_Utils_Request::retrieve('contact_id', 'String', $store, TRUE);
    $pp_id = CRM_Utils_Request::retrieve('pp_id', 'String', $store, TRUE);

    $paymentProcessor = self::getPaymentProcessorById($pp_id);
    if (!$paymentProcessor) {
      // TODO error header
      die();
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
        'phone'
      ],
      'id' => $contact_id,
      'api.Country.getsingle' => [
        'id' => "\$value.country_id",
        'return' => ["iso_code"],
      ],
    ]);
    if ($contact['is_error'] || $contact['api.Country.getsingle']['is_error']) {
      // TODO add error header
      print_r($contact);
      die();
    }

    $client = CRM_eWAYRecurring_eWAYRecurringUtils::getEWayClient($paymentProcessor);
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
    //Civi::log()->info(print_r($redirectUrl, TRUE));
    $ewayParams = [
      'RedirectUrl' => $redirectUrl,
      'CancelUrl' => CRM_Utils_System::url('', NULL, TRUE, NULL, FALSE),
      'Title' => $contact['formal_title'],
      'FirstName' => $contact['first_name'],
      'LastName' => $contact['last_name'],
      'Country' => $contact['api.Country.getsingle']['iso_code'],
      'Reference' => 'civi-' . $contact_id,
      'Street1' => $contact['street_address'],
      'City' => $contact['city'],
      'State' => $contact['state_province_name'],
      'PostalCode' => $contact['postal_code'],
      'Email' => $contact['email'],
      'Phone' => $contact['phone'],
      'CustomerReadOnly' => TRUE
    ];

    $response = $client->createCustomer(\Eway\Rapid\Enum\ApiMethod::RESPONSIVE_SHARED, $ewayParams);
    //Civi::log()->info(print_r($response, TRUE));
    // store access code to session
    CRM_Core_Session::singleton()
      ->set('eway_accesscode', $response->AccessCode);

    CRM_Utils_System::redirect($response->SharedPaymentUrl);
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

    $client = CRM_eWAYRecurring_eWAYRecurringUtils::getEWayClient($paymentProcessor);
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

    civicrm_api3('PaymentToken', 'create', $paymentToken);

    if ($isFromeWay) {
      echo "<script>window.close();</script>";
    } else {
      $result = civicrm_api3('PaymentToken', 'get', [
        'sequential' => 1,
        'contact_id' => $cid,
        'expiry_date' => ['>' => "now"],
        'options' => ['sort' => "created_date DESC"],
      ]);
      CRM_Utils_JSON::output([
        'is_error' => 0,
        'message' => $result
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
  private static function getPaymentProcessorById($id) {
    $paymentProcessorInfo = civicrm_api3('PaymentProcessor', 'get', [
      'id' => $id,
      'sequential' => 1,
    ]);
    $paymentProcessorInfo = $paymentProcessorInfo['values'];
    if (count($paymentProcessorInfo) <= 0) {
      return NULL;
    }
    $paymentProcessorInfo = $paymentProcessorInfo[0];
    $paymentProcessor = new au_com_agileware_ewayrecurring(($paymentProcessorInfo['is_test']) ? 'test' : 'live', $paymentProcessorInfo);
    return $paymentProcessor->getPaymentProcessor();
  }
}