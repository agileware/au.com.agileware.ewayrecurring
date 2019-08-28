<?php

require_once 'CRM/Core/Payment.php';
require_once 'eWAYRecurring.process.inc';

// Include eWay SDK.
require_once extensionPath('vendor/autoload.php');

class au_com_agileware_ewayrecurring extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  private $jsEmbeded = FALSE;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;             // live or test
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('eWay Recurring');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new au_com_agileware_ewayrecurring($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Create eWay client using credentials from payment processor.
   *
   * @return \Eway\Rapid\Contract\Client
   */
  function getEWayClient() {
    $eWayApiKey = $this->_paymentProcessor['user_name'];   // eWay Api Key
    $eWayApiPassword = $this->_paymentProcessor['password']; // eWay Api Password
    $eWayEndPoint = ($this->_paymentProcessor['is_test']) ? \Eway\Rapid\Client::MODE_SANDBOX : \Eway\Rapid\Client::MODE_PRODUCTION;

    $eWayClient = \Eway\Rapid::createClient($eWayApiKey, $eWayApiPassword, $eWayEndPoint);

    return $eWayClient;
  }

  /**
   * Validate contribution on successful response.
   *
   * @param $eWayAccessCode
   * @param $contributionID
   */
  function validateContribution($eWayAccessCode, $contribution, $qfKey, $paymentProcessor) {
    $this->_is_test = $contribution['is_test'];

    $response = CRM_eWAYRecurring_eWAYRecurringUtils::validateContribution($eWayAccessCode, $contribution, $paymentProcessor);
    CRM_eWAYRecurring_eWAYRecurringUtils::completeEWayTransaction($eWayAccessCode);

    $hasTransactionFailed = $response['hasTransactionFailed'];
    $transactionResponseError = $response['transactionResponseError'];
    $contributionID = $response['contributionId'];
    $transactionID = $response['transactionId'];

    if ($hasTransactionFailed) {
      civicrm_api3('Contribution', 'create', [
        'id' => $contributionID,
        'contribution_status_id' => _contribution_status_id('Failed'),
        'trxn_id' => $transactionID,
      ]);

      if ($transactionResponseError != '') {
        CRM_Core_Session::setStatus($transactionResponseError, ts('Error'), 'error');
      }
      $failUrl = $this->getReturnFailUrl($qfKey);
      CRM_Utils_System::redirect($failUrl);
    }

  }

  /**
   * Remove Credit card fields from the form.
   *
   * @return array
   */
  function getPaymentFormFields() {
    if ($this->backOffice) {
      return [
        'contact_payment_token',
        'add_credit_card'
      ];
    }
    return [
    ];
  }

  function getPaymentFormFieldsMetadata() {
    if (!$this->jsEmbeded && $this->backOffice) {
      CRM_Core_Resources::singleton()->addScript('CRM.eway.paymentTokenInitialize();');
      $this->jsEmbeded = TRUE;
    }
    return [
      'contact_payment_token' => [
        'htmlType' => 'select',
        'name' => 'contact_payment_token',
        'title' => ts('Stored Credit Card'),
        'attributes' => [],
        'is_required' => TRUE,
        'description' => ts(
          '<div><span class="description">Credit card details are not entered directly into CiviCRM and are processed directly by eWay, <a href="https://www.eway.com.au/about-eway/technology-security/pci-dss/" target="_blank">learn more on this page</a>.</span></div>'
        )
      ],
      'add_credit_card' => [
        'htmlType' => 'button',
        'name' => 'add_credit_card',
        'title' => ts('Add Credit Card'),
        'attributes' => [
          'onclick' => 'CRM.eway.addCreditCard();'
        ],
        'description' => ts(
          '<span class="description">Make sure to click the button <b>RETURN TO MERCHANT</b> after adding a credit card.</span>'
        )
      ]
  ];
  }

  function getBillingAddressFields($billingLocationID = NULL) {
    return [
      'first_name' => 'billing_first_name',
      'middle_name' => 'billing_middle_name',
      'last_name' => 'billing_last_name',
      'street_address' => "billing_street_address-{$billingLocationID}",
      'city' => "billing_city-{$billingLocationID}",
      'country' => "billing_country_id-{$billingLocationID}",
      'state_province' => "billing_state_province_id-{$billingLocationID}",
      'postal_code' => "billing_postal_code-{$billingLocationID}",
    ];
  }

  function supportsBackOffice() {
    return TRUE;
  }

  /**
   * Set customer's country based on given params
   *
   * @param $params
   */
  private function setCustomerCountry(&$params) {
    if (!isset($params['country']) || empty($params['country'])) {
      $countryId = 0;
      if (isset($params['country_id']) && !empty($params['country_id'])) {
        $countryId = $params['country_id'];
      }
      if ($countryId == 0) {
        try {
          $billingLocationTypeId = civicrm_api3('LocationType', 'getsingle', [
            'return' => ["id"],
            'name' => "Billing",
          ]);
          $billingLocationTypeId = $billingLocationTypeId['id'];
        } catch (CiviCRM_API3_Exception $e) {
        }
        $billingCountryIdKey = "billing_country_id-" . $billingLocationTypeId;
        if (isset($params[$billingCountryIdKey]) && !empty($params[$billingCountryIdKey])) {
          $countryId = $params[$billingCountryIdKey];
        }
      }
      $isoCountryCode = CRM_Core_PseudoConstant::getName('CRM_Core_BAO_Address', 'country_id', $countryId);
      $params['country'] = $isoCountryCode;
    }
  }

  /**
   * Form customer details array from given params.
   *
   * @param $params array
   *
   * @return array
   */
  function getEWayClientDetailsArray($params) {
    $this->setCustomerCountry($params);
    if (empty($params['country'])) {
      return self::errorExit(9007, 'Not able to retrieve customer\'s country.');
    }
    $eWayCustomer = [
      'Reference' => (isset($params['contactID'])) ? 'Civi-' . $params['contactID'] : '',
      // Referencing eWay customer with CiviCRM id if we have.
      'FirstName' => $params['first_name'],
      'LastName' => $params['last_name'],
      'Street1' => $params['street_address'],
      'City' => $params['city'],
      'State' => $params['state_province'],
      'PostalCode' => $params['postal_code'],
      'Country' => $params['country'],
      'Email' => (isset($params['email']) ? $params['email'] : ''),
      // Email is not accessible for updateSubscriptionBillingInfo method.
    ];

    if (isset($params['subscriptionId']) && !empty($params['subscriptionId'])) {
      $eWayCustomer['TokenCustomerID'] = $params['subscriptionId']; //Include cutomer token for updateSubscriptionBillingInfo.
    }

    if (strlen($eWayCustomer['Country']) > 2) {
      // Replace country value if given country is the name of the country instead of Country code.
      $isoCountryCode = CRM_Core_PseudoConstant::getName('CRM_Core_BAO_Address', 'country_id', $params['country_id']);
      $eWayCustomer['Country'] = $isoCountryCode;
    }

    return $eWayCustomer;
  }

  /**
   * Check if eWayResponse has any errors. Return array of errors if
   * transaction was unsuccessful.
   *
   * @param Eway\Rapid\Model\Response\AbstractResponse $eWAYResponse
   *
   * @return array
   */
  function getEWayResponseErrors($eWAYResponse) {
    $transactionErrors = [];

    $eway_errors = $eWAYResponse->getErrors();
    foreach ($eway_errors as $error) {
      $errorMessage = \Eway\Rapid::getMessage($error);
      $transactionErrors[] = $errorMessage;
    }

    return $transactionErrors;
  }

  /**
   * This function sends request and receives response from
   * eWay payment process
   */
  public function doPayment(&$params, $component = 'contribute') {
    if (!defined('CURLOPT_SSLCERT')) {
      CRM_Core_Error::fatal(ts('eWay - Gateway requires curl with SSL support'));
    }

    $eWayClient = $this->getEWayClient();

    //-------------------------------------------------------------
    // Prepare some composite data from _paymentProcessor fields, data that is shared across one off and recurring payments.
    //-------------------------------------------------------------

    $amountInCents = round(((float) preg_replace('/[\s,]/', '', $params['amount'])) * 100);
    $eWayCustomer = $this->getEWayClientDetailsArray($params);

    //----------------------------------------------------------------------------------------------------
    // Throw error if there are some errors while creating eWay Client.
    // This could be due to incorrect Api Username or Api Password.
    //----------------------------------------------------------------------------------------------------

    if (is_null($eWayClient) || count($eWayClient->getErrors())) {
      return self::errorExit(9001, "Error: Unable to create eWay Client object.");
    }

    //----------------------------------------------------------------------------------------------------
    // Now set the payment details - see https://eway.io/api-v3/#direct-connection
    //----------------------------------------------------------------------------------------------------


    //----------------------------------------------------------------------------------------------------
    // We use CiviCRM's param's 'invoiceID' as the unique transaction token to feed to eWay
    // Trouble is that eWay only accepts 16 chars for the token, while CiviCRM's invoiceID is an 32.
    // As its made from a "$invoiceID = md5(uniqid(rand(), true));" then using the first 12 chars
    // should be alright
    //----------------------------------------------------------------------------------------------------

    $uniqueTrnxNum = substr($params['invoiceID'], 0, 12);
    $invoiceDescription = $params['description'];
    if ($invoiceDescription == '') {
      $invoiceDescription = 'Invoice ID: ' . $params['invoiceID'];
    }

    if ($this->backOffice) {
      $result = civicrm_api3('PaymentToken', 'getsingle', [
        'sequential' => 1,
        'id' => $params['contact_payment_token'],
      ]);
      if ($result['is_error']) {
        return self::errorExit();
      }
      $token = $result['token'];
      $eWayTransaction = [
        'Customer' => [
          'TokenCustomerID' =>$token
        ],
        'Payment' => [
          'TotalAmount' => $amountInCents,
          'InvoiceNumber' => $uniqueTrnxNum,
          'InvoiceDescription' => substr(trim($invoiceDescription), 0, 64),
          'InvoiceReference' => $params['invoiceID'],
        ],
        'TransactionType' => \Eway\Rapid\Enum\TransactionType::MOTO
      ];
    } else {
      $eWayTransaction = [
        'Customer' => $eWayCustomer,
        'RedirectUrl' => $this->getSuccessfulPaymentReturnUrl($params, $component),
        'CancelUrl' => $this->getCancelPaymentReturnUrl($params, $component),
        'TransactionType' => (($this->isBackOffice() && !Civi::settings()
            ->get('cvv_backoffice_required'))
          ? \Eway\Rapid\Enum\TransactionType::MOTO
          : \Eway\Rapid\Enum\TransactionType::PURCHASE),
        'Payment' => [
          'TotalAmount' => $amountInCents,
          'InvoiceNumber' => $uniqueTrnxNum,
          'InvoiceDescription' => substr(trim($invoiceDescription), 0, 64),
          'InvoiceReference' => $params['invoiceID'],
        ],
        'CustomerIP' => (isset($params['ip_address'])) ? $params['ip_address'] : '',
        'Capture' => TRUE,
        'SaveCustomer' => TRUE,
        'Options' => [
          'ContributionID' => $params['contributionID'],
        ],
        'CustomerReadOnly' => TRUE,
      ];
    }

    // Was the recurring payment check box checked?
    if (CRM_Utils_Array::value('is_recur', $params, FALSE)) {

      //----------------------------------------------------------------------------------------------------
      // Force the contribution to Pending.
      //----------------------------------------------------------------------------------------------------

      CRM_Core_DAO::setFieldValue(
        'CRM_Contribute_DAO_Contribution',
        $params['contributionID'],
        'contribution_status_id',
        _contribution_status_id('Pending')
      );
    }

    //----------------------------------------------------------------------------------------------------
    // Allow further manipulation of the arguments via custom hooks ..
    //----------------------------------------------------------------------------------------------------

    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $eWayTransaction);

    //----------------------------------------------------------------------------------------------------
    // Check to see if we have a duplicate before we send request.
    //----------------------------------------------------------------------------------------------------

    if (method_exists($this, 'checkDupe') ?
      $this->checkDupe($params['invoiceID'], CRM_Utils_Array::value('contributionID', $params)) :
      $this->_checkDupe($params['invoiceID'])
    ) {
      return self::errorExit(9003, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt from eWay.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.');
    }

    if ($this->backOffice) {
      $eWAYResponse = $eWayClient->createTransaction(\Eway\Rapid\Enum\ApiMethod::DIRECT, $eWayTransaction);
    } else {
      $eWAYResponse = $eWayClient->createTransaction(\Eway\Rapid\Enum\ApiMethod::RESPONSIVE_SHARED, $eWayTransaction);
    }

    //----------------------------------------------------------------------------------------------------
    // If null data returned - tell 'em and bail out
    //----------------------------------------------------------------------------------------------------

    if (is_null($eWAYResponse)) {
      return self::errorExit(9006, "Error: Connection to payment gateway failed - no data returned.");
    }

    //----------------------------------------------------------------------------------------------------
    // See if we got an OK result - if not tell 'em and bail out
    //----------------------------------------------------------------------------------------------------
    $transactionErrors = $this->getEWayResponseErrors($eWAYResponse);
    if (count($transactionErrors)) {
      return self::errorExit(9008, implode("<br>", $transactionErrors));
    }

    $paymentProcessor = $this->getPaymentProcessor();

    if (!isset($params['is_email_receipt'])) {
      $params['is_email_receipt'] = 1;
    }

    $ewayParams = [
      'access_code' => $eWAYResponse->AccessCode,
      'contribution_id' => $params['contributionID'],
      'payment_processor_id' => $paymentProcessor['id'],
      'is_email_receipt' => $params['is_email_receipt'],
    ];

    // FIXME financial type, amount
    if ($component == 'event') {
      //$ewayParams['amount'] = $params['amount'];
    }

    if ($this->backOffice) {
      $statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate');
      if ($eWAYResponse->TransactionStatus) {
        $params['payment_status_id'] = array_search('Completed', $statuses);;
        $params['trxn_id'] = $eWAYResponse->TransactionID;
      } else {
        $params['payment_status_id'] = array_search('Pending', $statuses);;
        $errorMessage = implode(', ', array_map(
            '\Eway\Rapid::getMessage',
            explode(', ', $eWAYResponse->ResponseMessage))
        );
        CRM_Core_Session::setStatus(
          ts($errorMessage),
          ts('eWay Payment Error'),
          'error'
          );
        self::errorExit(9008, $errorMessage);
      }
    } else {
      civicrm_api3('EwayContributionTransactions', 'create', $ewayParams);
      CRM_Utils_System::redirect($eWAYResponse->SharedPaymentUrl);
    }

    return $params;
  }

  /**
   * Check if submitting the payment from contribution page.
   *
   * @param $params
   *
   * @return bool
   */
  function isFromContributionPage($params) {
    return (isset($params['contributionPageID']) && !empty($params['contributionPageID']));
  }

  /**
   * Get cancel payment return URL.
   *
   * @param $params
   *
   * @return string
   */
  function getCancelPaymentReturnUrl($params, $component = 'contribute', $recurringContribution = NULL) {
    if ($this->cancelUrl) {
      return $this->cancelUrl;
    }
    else if ($this->isFromContributionPage($params)) {
      return $this->getCancelUrl($params['qfKey'], '');
    }

    if (Civi::$statics['openedeWayForm'] == 'CRM_Contribute_Form_UpdateBilling' && $recurringContribution != NULL) {
      return CRM_Utils_System::url('civicrm/contribute/updatebilling', [
        'cid' => $recurringContribution['contact_id'],
        'context' => 'contribution',
        'crid' => $recurringContribution['id'],
      ], TRUE, NULL, FALSE);
    }

    if ($component == 'event') {
      return CRM_Utils_System::url('civicrm/event/register', [
        '_qf_Confirm_display' => 'true',
        'qfKey' => $params['qfKey'],
      ], TRUE, NULL, FALSE);
    }

    return CRM_Utils_System::url('civicrm/contact/view/contribution', [
      'action' => 'add',
      'cid' => $params['contactID'],
      'context' => 'contribution',
      'mode' => 'live',
    ], TRUE, NULL, FALSE);
  }

  /**
   * Get successful payment return URL.
   *
   * @param $params
   *
   * @return string
   */

  function getSuccessfulPaymentReturnUrl($params, $component, $recurringContribution = NULL) {
    if ($this->successUrl) {
      return $this->successUrl;
    }
    else if ($this->isFromContributionPage($params)) {
      return $this->getReturnSuccessUrl($params['qfKey']);
    }

    if (Civi::$statics['openedeWayForm'] == 'CRM_Contribute_Form_UpdateBilling' && $recurringContribution != NULL) {
      return CRM_Utils_System::url('civicrm/ewayrecurring/verifyupdatetoken', [
        'recurringContributionID' => $recurringContribution['id'],
        'qfKey' => $params['qfKey'],
        'paymentProcessorID' => ($this->getPaymentProcessor())['id'],
      ], TRUE, NULL, FALSE);
    }

    return CRM_Utils_System::url('civicrm/ewayrecurring/verifypayment', [
      'contributionInvoiceID' => $params['invoiceID'],
      'qfKey' => $params['qfKey'],
      'paymentProcessorID' => ($this->getPaymentProcessor())['id'],
      'component' => $component,
    ], TRUE, NULL, FALSE);
  }

  /**
   * Checks to see if invoice_id already exists in db
   *
   * @param int $invoiceId The ID to check
   *
   * @return bool                 True if ID exists, else false
   */
  function _checkDupe($invoiceId) {
    require_once 'CRM/Contribute/DAO/Contribution.php';
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->invoice_id = $invoiceId;
    return $contribution->find();
  }

  /**
   * This function checks the eWay response status - returning a boolean false
   * if status != 'true'
   *
   * @param $response
   *
   * @return bool
   */
  function isError(&$response) {
    $errors = $response->getErrors();

    if (count($errors)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Produces error message and returns from class
   *
   * @param null $errorCode
   * @param null $errorMessage
   *
   * @return object
   */
  function &errorExit($errorCode = NULL, $errorMessage = NULL) {
    $e =& CRM_Core_Error::singleton();

    if ($errorCode) {
      $e->push($errorCode, 0, NULL, $errorMessage);
    }
    else {
      $e->push(9000, 0, NULL, 'Unknown System Error.');
    }
    return $e;
  }

  /**
   * This public function checks to see if we have the right processor config
   * values set
   *
   * NOTE: Called by Events and Contribute to check config params are set prior
   * to trying register any credit card details
   *
   * @param string $mode the mode we are operating in (live or test) - not used
   *   but could be to check that the 'test' mode CustomerID was equal to
   *   '87654321' and that the URL was set to
   *   https://www.eway.com.au/gateway_cvn/xmltest/TestPage.asp
   *
   * returns string $errorMsg if any errors found - null if OK
   *
   * @return null|string
   */
  function checkConfig() {
    $errorMsg = [];

    if (empty($this->_paymentProcessor['user_name'])) {
      $errorMsg[] = ts('eWay API Key is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $errorMsg[] = ts('eWay API Password is not set for this payment processor');
    }

    // TODO: Check that recurring config values have been set

    if (!empty($errorMsg)) {
      return implode('<p>', $errorMsg);
    }
    else {
      return NULL;
    }
  }

  /**
   * Function handles eWay Recurring Payments cron job.
   *
   * @return bool
   */
  function handlePaymentCron() {
    return process_recurring_payments($this->_paymentProcessor, $this);
  }

  /**
   * Function to update the subscription amount of recurring payments.
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   */
  function changeSubscriptionAmount(&$message = '', $params = []) {
    // Process Schedule updates here.
    if ($params['next_scheduled_date']) {
      $submitted_nsd = strtotime($params['next_scheduled_date'] . ' ' . $params['next_scheduled_date_time']);
      CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
        $params['id'],
        'next_sched_contribution_date',
        date('YmdHis', $submitted_nsd));
    }
    return TRUE;
  }

  /**
   * Function to cancel the recurring payment subscription.
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   */
  function cancelSubscription(&$message = '', $params = []) {
    // TODO: Implement this - request token deletion from eWay?
    return TRUE;
  }

  /**
   * Function to update billing subscription details of the contact and it
   * updates customer details in eWay using UpdateCustomer method.
   *
   * @param string $message
   * @param array $params
   *
   * @return \Eway\Rapid\Model\Response\CreateCustomerResponse|object
   */
  function updateSubscriptionBillingInfo(&$message = '', $params = []) {
    //----------------------------------------------------------------------------------------------------
    // Something happens to the PseudoConstant cache so it stores the country label in place of its ISO 3166 code.
    // Flush to cache to work around this.
    //----------------------------------------------------------------------------------------------------

    CRM_Core_PseudoConstant::flush();

    //----------------------------------------------------------------------------------------------------
    // Build the customer info for eWay
    //----------------------------------------------------------------------------------------------------

    $eWayCustomer = $this->getEWayClientDetailsArray($params);

    try {
      //----------------------------------------------------------------------------------------------------
      // Get the payment.  Why isn't this provided to the function.
      //----------------------------------------------------------------------------------------------------

      $contribution = civicrm_api3('ContributionRecur', 'getsingle', [
        'payment_processor_id' => $this->_paymentProcessor['id'],
        'processor_id' => $params['subscriptionId'],
      ]);

      //----------------------------------------------------------------------------------------------------
      // We shouldn't be allowed to update the details for completed or cancelled payments
      //----------------------------------------------------------------------------------------------------

      switch ($contribution['contribution_status_id']) {
        case _contribution_status_id('Completed'):
          throw new Exception(ts('Attempted to update billing details for a completed contribution.'));
          break;
        case _contribution_status_id('Cancelled'):
          throw new Exception(ts('Attempted to update billing details for a cancelled contribution.'));
          break;
        default:
          break;
      }

      //----------------------------------------------------------------------------------------------------
      // Now set the payment details - see https://eway.io/api-v3/#direct-connection
      //----------------------------------------------------------------------------------------------------

      $eWayCustomer['RedirectUrl'] = $this->getSuccessfulPaymentReturnUrl($params, $contribution);
      $eWayCustomer['CancelUrl'] = $this->getCancelPaymentReturnUrl($params, $contribution);
      $eWayCustomer['CustomerIP'] = (isset($params['ip_address'])) ? $params['ip_address'] : '';
      $eWayCustomer['CustomerReadOnly'] = TRUE;
      $eWayCustomer['SaveCustomer'] = TRUE;
      $eWayCustomer['Capture'] = FALSE;

      $eWayClient = $this->getEWayClient();

      //----------------------------------------------------------------------------------------------------
      // Throw error if there are some errors while creating eWay Client.
      // This could be due to incorrect Api Username or Api Password.
      //----------------------------------------------------------------------------------------------------

      if (is_null($eWayClient) || count($eWayClient->getErrors())) {
        return self::errorExit(9001, "Error: Unable to create eWay Client object.");
      }

      //----------------------------------------------------------------------------------------------------
      // Allow further manipulation of the arguments via custom hooks ..
      //----------------------------------------------------------------------------------------------------

      CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $eWayTransaction);

      $eWayCustomerResponse = $eWayClient->updateCustomer(\Eway\Rapid\Enum\ApiMethod::RESPONSIVE_SHARED, $eWayCustomer);

      //----------------------------------------------------------------------------------------------------
      // If null data returned - tell 'em and bail out
      //----------------------------------------------------------------------------------------------------

      if (is_null($eWayCustomerResponse)) {
        return self::errorExit(9006, "Error: Connection to payment gateway failed - no data returned.");
      }

      //----------------------------------------------------------------------------------------------------
      // See if we got an OK result - if not tell 'em and bail out
      //----------------------------------------------------------------------------------------------------
      $transactionErrors = $this->getEWayResponseErrors($eWayCustomerResponse);
      if (count($transactionErrors)) {
        return self::errorExit(9008, implode("<br>", $transactionErrors));
      }

      $this->updateContactBillingAddress($params, $contribution);

      CRM_Utils_System::redirect($eWayCustomerResponse->SharedPaymentUrl);

      return $eWayCustomerResponse;
    } catch (Exception $e) {
      return self::errorExit(9010, $e->getMessage());
    }
  }

  private function updateContactBillingAddress($params, $contribution) {
    $billingAddress = civicrm_api3('Address', 'get', [
      'sequential' => 1,
      'contact_id' => $contribution['contact_id'],
      'is_billing' => 1,
    ]);
    $billingAddress = $billingAddress['values'];

    if (count($billingAddress)) {
      $billingAddress = $billingAddress[0];
    }

    $billingAddress['contact_id'] = $contribution['contact_id'];
    $billingAddress['location_type_id'] = 'Billing';

    $billingAddress['is_primary'] = 0;
    $billingAddress['is_billing'] = 1;

    $billingAddress['street_address'] = $params['street_address'];
    $billingAddress['city'] = $params['city'];

    if (isset($params['state_province_id']) && !empty($params['state_province_id'])) {
      $billingAddress['state_province_id'] = $params['state_province_id'];
    }

    if (isset($params['postal_code']) && !empty($params['postal_code'])) {
      $billingAddress['postal_code'] = $params['postal_code'];
    }

    if (isset($params['country_id']) && !empty($params['country_id'])) {
      $billingAddress['country_id'] = $params['country_id'];
    }

    civicrm_api3('Address', 'create', $billingAddress);
  }

}
