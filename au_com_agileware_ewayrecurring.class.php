<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM                                                            |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/*
 +--------------------------------------------------------------------+
 | eWay Recurring Payment Processor Extension                         |
 +--------------------------------------------------------------------+
 | Licensed to CiviCRM under the Academic Free License version 3.0    |
 |                                                                    |
 | Originally written & contributed by Dolphin Software P/L - March   |
 | 2008                                                               |
 |                                                                    |
 | This is a combination of the original eWay payment processor, with |
 | customisations to handle recurring payments as well. Originally    |
 | started by Chris Ward at Community Builders in 2012.               |
 |                                                                    |
 +--------------------------------------------------------------------+
 |                                                                    |
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | This code was initially based on the recent PayJunction module     |
 | contributed by Phase2 Technology, and then plundered bits from     |
 | the AuthorizeNet module contributed by Ideal Solution, and         |
 | referenced the eWAY code in Drupal 5.7's ecommerce-5.x-3.4 and     |
 | ecommerce-5.x-4.x-dev modules.                                     |
 |                                                                    |
 | Plus a bit of our own code of course - Peter Barwell               |
 | contact PB@DolphinSoftware.com.au if required.                     |
 |                                                                    |
 | NOTE: The eWAY gateway only allows a single currency per account   |
 |       (per eWAY CustomerID) ie you can only have one currency per  |
 |       added Payment Processor.                                     |
 |       The only way to add multi-currency is to code it so that a   |
 |       different CustomerID is used per currency.                   |
 |                                                                    |
 +--------------------------------------------------------------------+
*/

/*
 -----------------------------------------------------------------------------------------------
 From the eWay web-site - http://www.eway.com.au/Support/Developer/PaymentsRealTime.aspx
 -----------------------------------------------------------------------------------------------
   The test Customer ID is 87654321 - this is the only ID that will work on the test gateway.
   The test Credit Card number is 4444333322221111
   - this is the only credit card number that will work on the test gateway.
   The test Total Amount should end in 00 or 08 to get a successful response (e.g. $10.00 or $10.08)
   ie - all other amounts will return a failed response.

 -----------------------------------------------------------------------------------------------
*/

require_once 'CRM/Core/Payment.php';
require_once 'eWAYRecurring.process.inc';

class au_com_agileware_ewayrecurring extends CRM_Core_Payment
{
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
  */
  function __construct( $mode, &$paymentProcessor )
  {
    $this->_mode             = $mode;             // live or test
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('eWay Recurring');

    // Include eWay SDK.
    require_once 'vendor/autoload.php';
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
  static function &singleton( $mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE )
  {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null ) {
        self::$_singleton[$processorName] = new au_com_agileware_ewayrecurring( $mode, $paymentProcessor );
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Create eWay client using credentials from payment processor.
   *
   * @return \Eway\Rapid\Contract\Client
   */
  function getEWayClient() {
    $eWayApiKey = $this->_paymentProcessor['user_name'];   // eWAY Api Key
    $eWayApiPassword = $this->_paymentProcessor['password']; // eWAY Api Password
    $eWayEndPoint = ($this->_paymentProcessor['is_test']) ? \Eway\Rapid\Client::MODE_SANDBOX : \Eway\Rapid\Client::MODE_PRODUCTION;

    $eWayClient = \Eway\Rapid::createClient($eWayApiKey, $eWayApiPassword, $eWayEndPoint);

    return $eWayClient;
  }

  /**
   * Form customer details array from given params.
   *
   * @param $params array
   * @return array
   */
  function getEWayClientDetailsArray($params) {
    $expireYear    = substr ($params['year'], 2, 2);
    $expireMonth   = sprintf('%02d', (int) $params['month']); // Pad month with zeros
    $credit_card_name  = $params['first_name'] . " ";
    if (strlen($params['middle_name']) > 0 ) {
      $credit_card_name .= $params['middle_name'] . " ";
    }
    $credit_card_name .= $params['last_name'];

    $eWayCustomer = array(
      'Reference' => (isset($params['contactID'])) ? 'Civi-' . $params['contactID'] : '', // Referencing eWay customer with CiviCRM id if we have.
      'FirstName' => $params['first_name'],
      'LastName' => $params['last_name'],
      'Street1' => $params['street_address'],
      'City' => $params['city'],
      'State' => $params['state_province'],
      'PostalCode' => $params['postal_code'],
      'Country' => $params['country'],
      'Email' => (isset($params['email']) ? $params['email'] : ''), // Email is not accessible for updateSubscriptionBillingInfo method.
      'CardDetails' => [
          'Name' => $credit_card_name,
          'Number' => $params['credit_card_number'],
          'ExpiryMonth' => $expireMonth,
          'ExpiryYear' => $expireYear,
          'CVN' => $params['cvv2'],
      ]
    );

    if(isset($params['subscriptionId']) && !empty($params['subscriptionId'])) {
        $eWayCustomer['TokenCustomerID'] = $params['subscriptionId']; //Include cutomer token for updateSubscriptionBillingInfo.
    }

    if(strlen($eWayCustomer['Country']) > 2) {
        // Replace country value if given country is the name of the country instead of Country code.
        $isoCountryCode = CRM_Core_PseudoConstant::getName('CRM_Core_BAO_Address', 'country_id', $params['country_id']);
        $eWayCustomer['Country'] = $isoCountryCode;
    }

    return $eWayCustomer;
  }

  /**
   * Check if eWayResponse has any errors. Return array of errors if transaction was unsuccessful.
   *
   * @param Eway\Rapid\Model\Response\AbstractResponse $eWAYResponse
   * @return array
   */
  function getEWayResponseErrors($eWAYResponse, $createCustomerRequest = FALSE) {
    $transactionErrors = array();
    if ( !$eWAYResponse->TransactionStatus ) {
      if (count($eWAYResponse->getErrors())) {
          foreach ($eWAYResponse->getErrors() as $error) {
              $errorMessage = \Eway\Rapid::getMessage($error);
              CRM_Core_Error::debug_var('eWay Error', $errorMessage, TRUE, TRUE);
              $transactionErrors[] = $errorMessage;
          }

      } else if(!$createCustomerRequest) {
          $transactionErrors[] = 'Sorry, Your payment was declined.';
      }
    }

    return $transactionErrors;
  }

    /**
     * This function sends request and receives response from
     * eWAY payment process
     */
    function doDirectPayment( &$params )
    {
        if ( ! defined( 'CURLOPT_SSLCERT' ) ) {
            CRM_Core_Error::fatal( ts( 'eWAY - Gateway requires curl with SSL support' ) );
        }

        $eWayClient = $this->getEWayClient();

        //-------------------------------------------------------------
        // Prepare some composite data from _paymentProcessor fields, data that is shared across one off and recurring payments.
        //-------------------------------------------------------------

        $amountInCents = round(((float) preg_replace('/[\s,]/', '', $params['amount'])) * 100);
        $eWayCustomer = $this->getEWayClientDetailsArray($params);

        //----------------------------------------------------------------------------------------------------
        // Throw error if there are some errors while creating eWAY Client.
        // This could be due to incorrect Api Username or Api Password.
        //----------------------------------------------------------------------------------------------------

        if(is_null($eWayClient) || count($eWayClient->getErrors())) {
            return self::errorExit( 9001, "Error: Unable to create eWAY Client object.");
        }

        //----------------------------------------------------------------------------------------------------
        // Now set the payment details - see https://eway.io/api-v3/#direct-connection
        //----------------------------------------------------------------------------------------------------

        // Was the recurring payment check box checked?
        if (CRM_Utils_Array::value('is_recur', $params, false)) {

            //----------------------------------------------------------------------------------------------------
            // Hook to allow customer info to be changed before submitting it
            //----------------------------------------------------------------------------------------------------

            CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $eWayCustomer );

            try {

              //----------------------------------------------------------------------------------------------------
              // Create eWay Customer.
              //----------------------------------------------------------------------------------------------------

              $eWayCustomerResponse = $eWayClient->createCustomer(\Eway\Rapid\Enum\ApiMethod::DIRECT, $eWayCustomer);

              //----------------------------------------------------------------------------------------------------
              // See if we got an OK result - if not tell 'em and bail out
              //----------------------------------------------------------------------------------------------------

              $transactionErrors = $this->getEWayResponseErrors($eWayCustomerResponse, TRUE);
              if(count($transactionErrors)) {
                return self::errorExit( 9008, implode("<br>", $transactionErrors));
              }

              $managed_customer_id = $eWayCustomerResponse->getAttribute('Customer')->TokenCustomerID;
            }
            catch(Exception $e) {
              return self::errorExit(9010, $e->getMessage());
            }

            //----------------------------------------------------------------------------------------------------
            // Force the contribution to Pending.
            //----------------------------------------------------------------------------------------------------

            CRM_Core_DAO::setFieldValue(
                'CRM_Contribute_DAO_Contribution',
                $params['contributionID'],
                'contribution_status_id',
                _contribution_status_id('Pending')
            );

            //----------------------------------------------------------------------------------------------------
            // Save the eWay customer token in the recurring contribution's processor_id field
            //----------------------------------------------------------------------------------------------------

            CRM_Core_DAO::setFieldValue(
              'CRM_Contribute_DAO_ContributionRecur',
              $params['contributionRecurID'],
              'processor_id',
              $managed_customer_id
            );

            CRM_Core_DAO::setFieldValue(
              'CRM_Contribute_DAO_ContributionRecur',
              $params['contributionRecurID'],
              'create_date',
              CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'))
            );

            //----------------------------------------------------------------------------------------------------
            // For monthly payments, set the cycle day according to the submitting page or processor default
            //----------------------------------------------------------------------------------------------------

	        $cycle_day = 0;

	        if(!empty($params['contributionPageID']) &&
	          CRM_Utils_Type::validate($params['contributionPageID'], 'Int', FALSE, ts('Contribution Page')))
	        {
	          $cd_sql = 'SELECT cycle_day FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
	          $cycle_day = CRM_Core_DAO::singleValueQuery($cd_sql, array(1 => array($params['contributionPageID'], 'Int')));
	        } else {
	          $cd_sql = 'SELECT cycle_day FROM civicrm_ewayrecurring WHERE processor_id = %1';
	          $cycle_day = CRM_Core_DAO::singleValueQuery($cd_sql, array(1 => array($this->_paymentProcessor['id'], 'Int')));
	        }

            if(!$cycle_day)
                $cycle_day = 0;

            CRM_Core_DAO::setFieldValue(
              'CRM_Contribute_DAO_ContributionRecur',
              $params['contributionRecurID'],
              'cycle_day',
              $cycle_day
            );

            //----------------------------------------------------------------------------------------------------
            // AND we're done - this payment will staying in a pending state until it's processed
            // by the cronjob
            //----------------------------------------------------------------------------------------------------

        }
        // This is a one off payment, most of this is lifted straight from the original code, so I wont document it.
        else
        {
            //----------------------------------------------------------------------------------------------------
            // We use CiviCRM's param's 'invoiceID' as the unique transaction token to feed to eWAY
            // Trouble is that eWAY only accepts 16 chars for the token, while CiviCRM's invoiceID is an 32.
            // As its made from a "$invoiceID = md5(uniqid(rand(), true));" then using the first 12 chars
            // should be alright
            //----------------------------------------------------------------------------------------------------

            $uniqueTrnxNum = substr($params['invoiceID'], 0, 12);

            $eWayTransaction = array(
                'Customer' => $eWayCustomer,
                'Payment' => [
                    'TotalAmount' => $amountInCents,
                    'InvoiceNumber' => $uniqueTrnxNum,
                    'InvoiceDescription' => $params['description'],
                    'InvoiceReference' => $params['invoiceID'],
                ],
                'CustomerIP' => $params['ip_address'],
                'TransactionType' => \Eway\Rapid\Enum\TransactionType::PURCHASE,
                'Capture' => true,
            );

            //----------------------------------------------------------------------------------------------------
            // Allow further manipulation of the arguments via custom hooks ..
            //----------------------------------------------------------------------------------------------------

            CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $eWayTransaction );

            //----------------------------------------------------------------------------------------------------
            // Check to see if we have a duplicate before we send request.
            //----------------------------------------------------------------------------------------------------

            if (method_exists($this, 'checkDupe') ?
                $this->checkDupe($params['invoiceID'], CRM_Utils_Array::value('contributionID', $params)) :
                $this->_checkDupe($params['invoiceID'])
            ) {
                return self::errorExit(9003, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt from eWAY.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.' );
            }

            $eWAYResponse = $eWayClient->createTransaction(\Eway\Rapid\Enum\ApiMethod::DIRECT, $eWayTransaction);

            //----------------------------------------------------------------------------------------------------
            // If null data returned - tell 'em and bail out
            //----------------------------------------------------------------------------------------------------

            if ( is_null($eWAYResponse) ) {
                return self::errorExit( 9006, "Error: Connection to payment gateway failed - no data returned.");
            }

            //----------------------------------------------------------------------------------------------------
            // See if we got an OK result - if not tell 'em and bail out
            //----------------------------------------------------------------------------------------------------

            $transactionErrors = $this->getEWayResponseErrors($eWAYResponse);
            if(count($transactionErrors)) {
                return self::errorExit( 9008, implode("<br>", $transactionErrors));
            }

            //-----------------------------------------------------------------------------------------------------
            // Cross-Check - the unique 'TrxnReference' we sent out should match the just received 'TrxnReference'
            //
            // PLEASE NOTE: If this occurs (which is highly unlikely) its a serious error as it would mean we have
            //              received an OK status from eWAY, but their Gateway has not returned the correct unique
            //              token - ie something is broken, BUT money has been taken from the client's account,
            //              so we can't very well error-out as CiviCRM will then not process the registration.
            //              There is an error message commented out here but my prefered response to this unlikley
            //              possibility is to email 'support@eWAY.com.au'
            //-----------------------------------------------------------------------------------------------------

            $eWayTrxnReference_OUT = $uniqueTrnxNum;
            $eWayTrxnReference_IN  = $eWAYResponse->getAttribute('Payment')->InvoiceNumber;

            if ($eWayTrxnReference_IN != $eWayTrxnReference_OUT) {
                // return self::errorExit( 9009, "Error: Unique Trxn code was not returned by eWAY Gateway. This is extremely unusual! Please contact the administrator of this site immediately with details of this transaction.");
                self::send_alert_email( $eWayTrxnReference_IN, $eWayTrxnReference_OUT, $eWayTrxnReference_IN, json_encode($eWayTransaction), json_encode($eWAYResponse));
            }

            //----------------------------------------------------------------------------------------------------
            // Success !
            //----------------------------------------------------------------------------------------------------

            $beaglestatus = $eWAYResponse->getAttribute('BeagleScore');
            if ( !empty( $beaglestatus ) ) {
                $beaglestatus = ": ". $beaglestatus;
            }
            $params['trxn_result_code'] = $eWAYResponse->TransactionStatus . $beaglestatus;
            $params['gross_amount']     = $eWAYResponse->getAttribute('Payment')->TotalAmount;
            $params['trxn_id']          = $eWAYResponse->getAttribute('TransactionID');

        }

        return $params;
    }

    /**
     * Checks to see if invoice_id already exists in db
     * @param  int     $invoiceId   The ID to check
     * @return bool                 True if ID exists, else false
     */
    function _checkDupe( $invoiceId )
    {
        require_once 'CRM/Contribute/DAO/Contribution.php';
        $contribution = new CRM_Contribute_DAO_Contribution( );
        $contribution->invoice_id = $invoiceId;
        return $contribution->find( );
    }

    /**
     * This function checks the eWAY response status - returning a boolean false if status != 'true'
     *
     * @param $response
     * @return bool
     */
    function isError( &$response)
    {
        $errors = $response->getErrors();

        if ( count($errors) ) {
            return true;
        }
        return false;
    }

    /**
     * Produces error message and returns from class
     *
     * @param null $errorCode
     * @param null $errorMessage
     * @return object
     */
    function &errorExit ( $errorCode = null, $errorMessage = null )
    {
        $e =& CRM_Core_Error::singleton( );

        if ( $errorCode ) {
            $e->push( $errorCode, 0, null, $errorMessage );
        } else {
            $e->push( 9000, 0, null, 'Unknown System Error.' );
        }
        return $e;
    }

    /**
     * NOTE: 'doTransferCheckout' not implemented
     *
     * @param $params
     * @param $component
     * @throws Exception
     */
    function doTransferCheckout( &$params, $component )
    {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
    }

    /**
     * This public function checks to see if we have the right processor config values set
     *
     * NOTE: Called by Events and Contribute to check config params are set prior to trying
     *       register any credit card details
     *
     * @param string $mode the mode we are operating in (live or test) - not used but could be
     * to check that the 'test' mode CustomerID was equal to '87654321' and that the URL was
     * set to https://www.eway.com.au/gateway_cvn/xmltest/TestPage.asp
     *
     * returns string $errorMsg if any errors found - null if OK
     *
     * @return null|string
     */
    function checkConfig( )
    {
        $errorMsg = array();

        if ( empty( $this->_paymentProcessor['user_name'] ) ) {
            $errorMsg[] = ts( 'eWAY API Key is not set for this payment processor' );
        }

        if ( empty( $this->_paymentProcessor['password'] ) ) {
            $errorMsg[] = ts( 'eWAY API Password is not set for this payment processor' );
        }

        // TODO: Check that recurring config values have been set

        if ( ! empty( $errorMsg ) ) {
            return implode( '<p>', $errorMsg );
        } else {
            return null;
        }
    }

    /**
     * Sends an alert email.
     *
     * @param $p_eWAY_tran_num - eWay transaction number
     * @param $p_trxn_out  - Transaction number which we sent to eWay
     * @param $p_trxn_back - Transaction number which returned from the eWay
     * @param $p_request  -  Requested data
     * @param $p_response  - Response from eWay
     * @throws Exception
     */
    function send_alert_email($p_eWAY_tran_num, $p_trxn_out, $p_trxn_back, $p_request, $p_response)
    {
        //----------------------------------------------------------------------------------------------------
        // Initialization call is required to use CiviCRM APIs.
        //----------------------------------------------------------------------------------------------------

        civicrm_initialize( true );

        require_once 'CRM/Utils/Mail.php';
        require_once 'CRM/Core/BAO/Domain.php';

        list( $fromName, $fromEmail ) = CRM_Core_BAO_Domain::getNameAndEmail( );
        $from      = "$fromName <$fromEmail>";

        $toName    = 'Support at eWAY';
        $toEmail   = 'Support@eWAY.com.au';

        $subject   = "ALERT: Unique Trxn Number Failure : eWAY Transaction # = [". $p_eWAY_tran_num . "]";

        $message   = "
                    TRXN sent out with request   = '$p_trxn_out'.
                    TRXN sent back with response = '$p_trxn_back'.
                    
                    This is a ['$this->_mode'] transaction.
                    
                    
                    Request JSON =
                    ---------------------------------------------------------------------------
                    $p_request
                    ---------------------------------------------------------------------------
                    
                    
                    Response JSON =
                    ---------------------------------------------------------------------------
                    $p_response
                    ---------------------------------------------------------------------------
                    
                    
                    Regards
                    
                    The CiviCRM eWAY Payment Processor Module
        ";

        //----------------------------------------------------------------------------------------------------
        // create the params array
        //----------------------------------------------------------------------------------------------------

        $params                = array( );

        $params['groupName'  ] = 'eWay Email Sender';
        $params['from'       ] = $from;
        $params['toName'     ] = $toName;
        $params['toEmail'    ] = $toEmail;
        $params['subject'    ] = $subject;
        $params['text'       ] = $message;

        CRM_Utils_Mail::send( $params );
    }

    /**
     * Function handles eWAY Recurring Payments cron job.
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
     * @return bool
     */
    function changeSubscriptionAmount(&$message = '', $params = array()) {
      // Process Schedule updates here.
      if($params['next_scheduled_date']){
          $submitted_nsd = strtotime($params['next_scheduled_date'] . ' ' . $params['next_scheduled_date_time']);
          CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
				     $params['id'],
				     'next_sched_contribution_date',
				     date('YmdHis', $submitted_nsd) );
      }
      return TRUE;
    }

    /**
     * Function to cancel the recurring payment subscription.
     *
     * @param string $message
     * @param array $params
     * @return bool
     */
    function cancelSubscription(&$message = '', $params = array()) {
      // TODO: Implement this - request token deletion from eWAY?
      return TRUE;
    }

    /**
     * Function to update billing subscription details of the contact and it updates
     * customer details in eWay using UpdateCustomer method.
     *
     * @param string $message
     * @param array $params
     * @return \Eway\Rapid\Model\Response\CreateCustomerResponse|object
     */
    function updateSubscriptionBillingInfo(&$message = '', $params = array()) {
      //----------------------------------------------------------------------------------------------------
      // Something happens to the PseudoConstant cache so it stores the country label in place of its ISO 3166 code.
      // Flush to cache to work around this.
      //----------------------------------------------------------------------------------------------------

      CRM_Core_PseudoConstant::flush();

      //----------------------------------------------------------------------------------------------------
      // Build the customer info for eWAY
      //----------------------------------------------------------------------------------------------------

      $eWayCustomer = $this->getEWayClientDetailsArray($params);

      try {
        //----------------------------------------------------------------------------------------------------
        // Get the payment.  Why isn't this provided to the function.
        //----------------------------------------------------------------------------------------------------

        $contribution = civicrm_api3('ContributionRecur', 'getsingle', array(
                        'payment_processor_id' => $this->_paymentProcessor['id'],
                        'processor_id' => $params['subscriptionId']
                      ));

        //----------------------------------------------------------------------------------------------------
        // We shouldn't be allowed to update the details for completed or cancelled payments
        //----------------------------------------------------------------------------------------------------

        switch($contribution['contribution_status_id']) {
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
        // Hook to allow customer info to be changed before submitting it
        //----------------------------------------------------------------------------------------------------
        CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $eWayCustomer);

        $eWayClient = $this->getEWayClient();

        //----------------------------------------------------------------------------------------------------
        // Create eWay Customer.
        //----------------------------------------------------------------------------------------------------
        $eWayCustomerResponse = $eWayClient->updateCustomer(\Eway\Rapid\Enum\ApiMethod::DIRECT, $eWayCustomer);

        //----------------------------------------------------------------------------------------------------
        // See if we got an OK result - if not tell 'em and bail out
        //----------------------------------------------------------------------------------------------------
        $transactionErrors = $this->getEWayResponseErrors($eWayCustomerResponse, TRUE);
        if(count($transactionErrors)) {
          return self::errorExit( 9008, implode("<br>", $transactionErrors));
        }

        //----------------------------------------------------------------------------------------------------
        // Updating the billing details should fixed failed contributions
        //----------------------------------------------------------------------------------------------------

        if(_contribution_status_id('Failed') == $contribution['contribution_status_id']) {
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution['id'],
          'contribution_status_id',
          _contribution_status_id('In Progress') );
        }

        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution['id'],
          'failure_count',
          0 );

        return $eWayCustomerResponse;
    }
    catch(Exception $e) {
      return self::errorExit(9010, $e->getMessage());
    }
  }

}
