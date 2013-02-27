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

class org_civicrm_ewayrecurring extends CRM_Core_Payment
{
  // const CHARSET  = 'UTF-8'; # (not used, implicit in the API, might need to convert?)

    /**
     * We only need one instance of this object. So we use the singleton
     * pattern and cache the instance in this variable
     *
     * @var object
     * @static
     */
    static private $_singleton = null;

    /**********************************************************
     * Constructor
     *
     * @param string $mode the mode of operation: live or test
     *
        * @return void
    **********************************************************/
    function __construct( $mode, &$paymentProcessor )
    {
        // As this handles recurring and non-recurring, we also need to include original api libraries
        require_once 'packages/eWAY/eWAY_GatewayRequest.php';
        require_once 'packages/eWAY/eWAY_GatewayResponse.php';

        $this->_mode             = $mode;             // live or test
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('eWay Recurring');
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
    static function &singleton( $mode, &$paymentProcessor )
    {
        $processorName = $paymentProcessor['name'];
        if (self::$_singleton[$processorName] === null ) {
            self::$_singleton[$processorName] = new org_civicrm_ewayrecurring( $mode, $paymentProcessor );
        }
        return self::$_singleton[$processorName];
    }

    function createCustomerToken( &$customerinfo ) {
      $gateway_URL = $this->_paymentProcessor['url_recur'];    // eWAY Gateway URL

      $soap_client = new SoapClient($gateway_URL);

      // Set up SOAP headers
      $headers = array(
        'eWAYCustomerID' => $this->_paymentProcessor['subject'],   // eWAY Client ID
        'Username'       => $this->_paymentProcessor['user_name'],
        'Password'       => $this->_paymentProcessor['password']
      );

      $header = new SoapHeader('https://www.eway.com.au/gateway/managedpayment', 'eWAYHeader', $headers);
      $soap_client->__setSoapHeaders($header);

      // Hook to allow customer info to be changed before submitting it
      CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $customerinfo );

      // Create the customer via the API
      $result = $soap_client->CreateCustomer($customerinfo);

      // We've created the customer successfully
      return $result->CreateCustomerResult;
    }

    /**********************************************************
    * This function sends request and receives response from
    * eWAY payment process
    **********************************************************/
    function doDirectPayment( &$params )
    {
        if ( ! defined( 'CURLOPT_SSLCERT' ) ) {
            CRM_Core_Error::fatal( ts( 'eWAY - Gateway requires curl with SSL support' ) );
        }

        $ewayCustomerID = $this->_paymentProcessor['subject'];   // eWAY Client ID

        /*
        //-------------------------------------------------------------
        // NOTE: eWAY Doesn't use the following at the moment:
        //-------------------------------------------------------------
        $creditCardType = $params['credit_card_type'];
        $currentcyID    = $params['currencyID'];
        $country        = $params['country'];
        */

        //-------------------------------------------------------------
        // Prepare some composite data from _paymentProcessor fields, data that is shared across one off and recurring payments.
        //-------------------------------------------------------------
        $expireYear    = substr ($params['year'], 2, 2);
        $expireMonth   = sprintf('%02d', (int) $params['month']); // Pad month with zeros
        $txtOptions    = "";
        $amountInCents = round(((float) $params['amount']) * 100);
        $credit_card_name  = $params['first_name'] . " ";
        if (strlen($params['middle_name']) > 0 ) {
            $credit_card_name .= $params['middle_name'] . " ";
        }
        $credit_card_name .= $params['last_name'];
        $currDate = date('d/m/Y') ; // Get the current date

        //----------------------------------------------------------------------------------------------------
        // OPTIONAL: If TEST Card Number force an Override of URL and CutomerID.
        // During testing CiviCRM once used the LIVE URL.
        // This code can be uncommented to override the LIVE URL that if CiviCRM does that again.
        //----------------------------------------------------------------------------------------------------
        //        if ( ( $gateway_URL == "https://www.eway.com.au/gateway_cvn/xmlpayment.asp")
        //             && ( $params['credit_card_number'] == "4444333322221111" ) ) {
        //$ewayCustomerID = "87654321";
        //$gateway_URL    = "https://www.eway.com.au/gateway/rebill/test/Upload_test.aspx";
        //        }

        //----------------------------------------------------------------------------------------------------
        // Now set the payment details - see http://www.eway.com.au/Support/Developer/PaymentsRealTime.aspx
        //----------------------------------------------------------------------------------------------------

        // Was the recurring payment check box checked?
        if ($params['is_recur'] == true) {
            // Add eWay customer
            $customerinfo = array(
                'Title' => 'Mr.', // Crazily eWay makes this a mandatory field with fixed values
                'FirstName' => $params['first_name'],
                'LastName' => $params['last_name'],
                'Address' => $params['street_address'],
                'Suburb' => $params['city'],
                'State' => $params['state_province'],
                'Company' => '',
                'PostCode' => $params['postal_code'],
                'Country' => strtolower($params['country']),
                'Email' => $params['email'],
                'Fax' => '',
                'Phone' => '',
                'Mobile' => '',
                'CustomerRef' => '',
                'JobDesc' => $params['description'],
                'Comments' => '',
                'URL' => '',
                'CCNumber' => $params['credit_card_number'],
                'CCNameOnCard' => $credit_card_name,
                'CCExpiryMonth' => $expireMonth,
                'CCExpiryYear' => $expireYear

            );

            try {
              $managed_customer_id = $this->createCustomerToken( $customerinfo );
            }
            catch(Exception $e) {
              return self::errorExit(9010, $e->getMessage());
            }

            // Save the eWay customer token in the recurring contribution's processor_id field
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

	    // For monthly payments, set the cycle day according to the submitting page or processor default
	    $cycle_day = 0;

	    if(!empty($params['contributionPageID']) &&
	       CRM_Utils_Type::validate($params['contributionPageID'],
					'Int', FALSE, ts('Contribution Page'))){
	      $cd_sql = 'SELECT cycle_day FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';

	      $cycle_day = CRM_Core_DAO::singleValueQuery
		($cd_sql,
		 array(1 => array($params['contributionPageID'], 'Int')));
	    } else {
	      $cd_sql = 'SELECT cycle_day FROM civicrm_ewayrecurring WHERE processor_id = %1';

	      $cycle_day = CRM_Core_DAO::singleValueQuery
		($cd_sql,
		 array(1 => array($this->_paymentProcessor['id'], 'Int')));
	    }

	    if(!$cycle_day)
	      $cycle_day = 0;

	    CRM_Core_DAO::setFieldValue(
		'CRM_Contribute_DAO_ContributionRecur',
		$params['contributionRecurID'],
		'cycle_day',
		$cycle_day
	    );

            /* AND we're done - this payment will staying in a pending state until it's processed
             * by the cronjob
             */
        }
        // This is a one off payment, most of this is lifted straight from the original code, so I wont document it.
        else
        {
            $gateway_URL    = $this->_paymentProcessor['url_site'];    // eWAY Gateway URL
            $eWAYRequest  = new GatewayRequest;

            if ( ($eWAYRequest == null) || ( ! ($eWAYRequest instanceof GatewayRequest)) ) {
                return self::errorExit( 9001, "Error: Unable to create eWAY Request object.");
            }

            $eWAYResponse = new GatewayResponse;

            if ( ($eWAYResponse == null) || ( ! ($eWAYResponse instanceof GatewayResponse) ) ) {
                return self::errorExit( 9002, "Error: Unable to create eWAY Response object.");
            }

            //----------------------------------------------------------------------------------------------------
            // We use CiviCRM's param's 'invoiceID' as the unique transaction token to feed to eWAY
            // Trouble is that eWAY only accepts 16 chars for the token, while CiviCRM's invoiceID is an 32.
            // As its made from a "$invoiceID = md5(uniqid(rand(), true));" then using the fierst 16 chars
            // should be alright
            //----------------------------------------------------------------------------------------------------
            $uniqueTrnxNum = substr($params['invoiceID'], 0, 16);

            //----------------------------------------------------------------------------------------------------
            // OPTIONAL: If TEST Card Number force an Override of URL and CutomerID.
            // During testing CiviCRM once used the LIVE URL.
            // This code can be uncommented to override the LIVE URL that if CiviCRM does that again.
            //----------------------------------------------------------------------------------------------------
            //        if ( ( $gateway_URL == "https://www.eway.com.au/gateway_cvn/xmlpayment.asp")
            //             && ( $params['credit_card_number'] == "4444333322221111" ) ) {
            //            $ewayCustomerID = "87654321";
            //            $gateway_URL    = "https://www.eway.com.au/gateway_cvn/xmltest/testpage.asp";
            //        }

            //----------------------------------------------------------------------------------------------------
            // Now set the payment details - see http://www.eway.com.au/Support/Developer/PaymentsRealTime.aspx
            //----------------------------------------------------------------------------------------------------
            $eWAYRequest->EwayCustomerID($ewayCustomerID);  //    8 Chars - ewayCustomerID                 - Required
            $eWAYRequest->InvoiceAmount($amountInCents);  //   12 Chars - ewayTotalAmount  (in cents)    - Required
            $eWAYRequest->PurchaserFirstName($params['first_name']);  //   50 Chars - ewayCustomerFirstName
            $eWAYRequest->PurchaserLastName($params['last_name']);  //   50 Chars - ewayCustomerLastName
            $eWAYRequest->PurchaserEmailAddress($params['email']);  //   50 Chars - ewayCustomerEmail
            $eWAYRequest->PurchaserAddress($fullAddress);  //  255 Chars - ewayCustomerAddress
            $eWAYRequest->PurchaserPostalCode($params['postal_code']);  //    6 Chars - ewayCustomerPostcode
            $eWAYRequest->InvoiceDescription($params['description']);  // 1000 Chars - ewayCustomerInvoiceDescription
            $eWAYRequest->InvoiceReference($params['invoiceID']);  //   50 Chars - ewayCustomerInvoiceRef
            $eWAYRequest->CardHolderName($credit_card_name);  //   50 Chars - ewayCardHoldersName            - Required
            $eWAYRequest->CardNumber($params['credit_card_number']);  //   20 Chars - ewayCardNumber                 - Required
            $eWAYRequest->CardExpiryMonth($expireMonth);  //    2 Chars - ewayCardExpiryMonth            - Required
            $eWAYRequest->CardExpiryYear($expireYear);  //    2 Chars - ewayCardExpiryYear             - Required
            $eWAYRequest->CVN($params['cvv2']);  //    4 Chars - ewayCVN                        - Required if CVN Gateway used
            $eWAYRequest->TransactionNumber($uniqueTrnxNum);  //   16 Chars - ewayTrxnNumber
            $eWAYRequest->EwayOption1($txtOptions);  //  255 Chars - ewayOption1
            $eWAYRequest->EwayOption2($txtOptions);  //  255 Chars - ewayOption2
            $eWAYRequest->EwayOption3($txtOptions);  //  255 Chars - ewayOption3

            $eWAYRequest->CustomerIPAddress ($params['ip_address']);
            $eWAYRequest->CustomerBillingCountry($params['country']);

            // Allow further manipulation of the arguments via custom hooks ..
            CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $eWAYRequest );

            //----------------------------------------------------------------------------------------------------
            // Check to see if we have a duplicate before we send
            //----------------------------------------------------------------------------------------------------
            if ( $this->_checkDupe( $params['invoiceID'] ) ) {
                return self::errorExit(9003, 'It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt from eWAY.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.' );
            }

            //----------------------------------------------------------------------------------------------------
            // Convert to XML and send the payment information
            //----------------------------------------------------------------------------------------------------
            $requestxml = $eWAYRequest->ToXML();

            $submit = curl_init( $gateway_URL );

            if ( ! $submit ) {
                return self::errorExit(9004, 'Could not initiate connection to payment gateway');
            }

            curl_setopt($submit, CURLOPT_POST,           true        );
            curl_setopt($submit, CURLOPT_RETURNTRANSFER, true        );  // return the result on success, FALSE on failure
            curl_setopt($submit, CURLOPT_POSTFIELDS,     $requestxml );
            curl_setopt($submit, CURLOPT_TIMEOUT,        36000       );
            // if open_basedir or safe_mode are enabled in PHP settings CURLOPT_FOLLOWLOCATION won't work so don't apply it
            // it's not really required CRM-5841
            if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off')) {
                curl_setopt($submit, CURLOPT_FOLLOWLOCATION, 1           );  // ensures any Location headers are followed
            }

            // Send the data out over the wire
            //--------------------------------
            $responseData = curl_exec($submit);

            //----------------------------------------------------------------------------------------------------
            // See if we had a curl error - if so tell 'em and bail out
            //
            // NOTE: curl_error does not return a logical value (see its documentation), but
            //       a string, which is empty when there was no error.
            //----------------------------------------------------------------------------------------------------
            if ( (curl_errno($submit) > 0) || (strlen(curl_error($submit)) > 0) ) {
                $errorNum  = curl_errno($submit);
                $errorDesc = curl_error($submit);

                if ($errorNum == 0) { $errorNum = 9005; } // Paranoia - in the unlikley event that 'curl' errno fails

                if (strlen($errorDesc) == 0) { // Paranoia - in the unlikley event that 'curl' error fails
                    $errorDesc = "Connection to eWAY payment gateway failed";
                }

                return self::errorExit( $errorNum, $errorDesc );
            }

            //----------------------------------------------------------------------------------------------------
            // If null data returned - tell 'em and bail out
            //
            // NOTE: You will not necessarily get a string back, if the request failed for
            //       any reason, the return value will be the boolean false.
            //----------------------------------------------------------------------------------------------------
            if ( ( $responseData === false )  || (strlen($responseData) == 0) ) {
                return self::errorExit( 9006, "Error: Connection to payment gateway failed - no data returned.");
            }

            //----------------------------------------------------------------------------------------------------
            // If gateway returned no data - tell 'em and bail out
            //----------------------------------------------------------------------------------------------------
            if ( empty($responseData) ) {
                return self::errorExit( 9007, "Error: No data returned from payment gateway.");
            }

            //----------------------------------------------------------------------------------------------------
            // Success so far - close the curl and check the data
            //----------------------------------------------------------------------------------------------------
            curl_close( $submit );

            //----------------------------------------------------------------------------------------------------
            // Payment succesfully sent to gateway - process the response now
            //----------------------------------------------------------------------------------------------------
            $eWAYResponse->ProcessResponse($responseData);

            //----------------------------------------------------------------------------------------------------
            // See if we got an OK result - if not tell 'em and bail out
            //----------------------------------------------------------------------------------------------------
            if ( self::isError( $eWAYResponse ) ) {
                $eWayTrxnError = $eWAYResponse->Error();

                if (substr($eWayTrxnError, 0, 6) == "Error:") {
                    return self::errorExit( 9008, $eWayTrxnError);
                }
                $eWayErrorCode = substr($eWayTrxnError, 0, 2);
                $eWayErrorDesc = substr($eWayTrxnError, 3   );

                return self::errorExit( 9008, "Error: [" . $eWayErrorCode . "] - " . $eWayErrorDesc . ".");
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
            $eWayTrxnReference_OUT = $eWAYRequest->GetTransactionNumber();
            $eWayTrxnReference_IN  = $eWAYResponse->InvoiceReference();

            if ($eWayTrxnReference_IN != $eWayTrxnReference_OUT) {
                // return self::errorExit( 9009, "Error: Unique Trxn code was not returned by eWAY Gateway. This is extremely unusual! Please contact the administrator of this site immediately with details of this transaction.");

                self::send_alert_email( $eWAYResponse->TransactionNumber(), $eWayTrxnReference_OUT, $eWayTrxnReference_IN, $requestxml, $responseData);
            }

            /*
            //----------------------------------------------------------------------------------------------------
            // Test mode always returns trxn_id = 0 - so we fix that here
            //
            // NOTE: This code was taken from the AuthorizeNet payment processor, however it now appears
            //       unecessary for the eWAY gateway - Left here in case it proves useful
            //----------------------------------------------------------------------------------------------------
            if ( $this->_mode == 'test' ) {
                $query = "SELECT MAX(trxn_id) FROM civicrm_contribution WHERE trxn_id LIKE 'test%'";
                $p = array( );
                $trxn_id = strval( CRM_Core_Dao::singleValueQuery( $query, $p ) );
                $trxn_id = str_replace( 'test', '', $trxn_id );
                $trxn_id = intval($trxn_id) + 1;
                $params['trxn_id'] = sprintf('test%08d', $trxn_id);
            } else {
                $params['trxn_id'] = $eWAYResponse->TransactionNumber();
            }
            */

            //=============
            // Success !
            //=============
            $beaglestatus = $eWAYResponse->BeagleScore();
            if ( !empty( $beaglestatus ) ) {
                $beaglestatus = ": ". $beaglestatus;
            }
            $params['trxn_result_code'] = $eWAYResponse->Status() . $beaglestatus;
            $params['gross_amount']     = $eWAYResponse->Amount();
            $params['trxn_id']          = $eWAYResponse->TransactionNumber();
        }
        return $params;
    } // end function doDirectPayment

    // None of these functions have been changed, unless mentioned.

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

    /*************************************************************************************************
     * This function checks the eWAY response status - returning a boolean false if status != 'true'
     *************************************************************************************************/
    function isError( &$response)
    {
        $status = $response->Status();

        if ( (stripos($status, "true")) === false ) {
            return true;
        }
        return false;
    }

    /**************************************************
     * Produces error message and returns from class
     **************************************************/
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

    /**************************************************
     * NOTE: 'doTransferCheckout' not implemented
     **************************************************/
    function doTransferCheckout( &$params, $component )
    {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
    }

    /********************************************************************************************
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
     ********************************************************************************************/
    //function checkConfig( $mode )          // CiviCRM V1.9 Declaration
    function checkConfig( )                // CiviCRM V2.0 Declaration
    {
        $errorMsg = array();

        if ( empty( $this->_paymentProcessor['subject'] ) ) {
            $errorMsg[] = ts( 'eWAY CustomerID is not set for this payment processor' );
        }

        if ( empty( $this->_paymentProcessor['url_site'] ) ) {
            $errorMsg[] = ts( 'eWAY Gateway URL is not set for this payment processor' );
        }

        // TODO: Check that recurring config values have been set

        if ( ! empty( $errorMsg ) ) {
            return implode( '<p>', $errorMsg );
        } else {
            return null;
        }
    }

    function send_alert_email($p_eWAY_tran_num, $p_trxn_out, $p_trxn_back, $p_request, $p_response)
    {
        // Initialization call is required to use CiviCRM APIs.
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


Request XML =
---------------------------------------------------------------------------
$p_request
---------------------------------------------------------------------------


Response XML =
---------------------------------------------------------------------------
$p_response
---------------------------------------------------------------------------


Regards

The CiviCRM eWAY Payment Processor Module
";
        //$cc       = 'Name@Domain';

        // create the params array
        $params                = array( );

        $params['groupName'  ] = 'eWay Email Sender';
        $params['from'       ] = $from;
        $params['toName'     ] = $toName;
        $params['toEmail'    ] = $toEmail;
        $params['subject'    ] = $subject;
        $params['cc'         ] = $cc;
        $params['text'       ] = $message;

        CRM_Utils_Mail::send( $params );
    }

    function handlePaymentCron() {

      return process_recurring_payments($this->_paymentProcessor);

    }

    function changeSubscriptionAmount(&$message = '', $params = array()) {
      // Process Schedule updates here.
      if($params['next_scheduled_date']){
	$submitted_nsd = strtotime($params['next_scheduled_date'] . ' ' . $params['next_scheduled_date_time']);
	CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
				     $params['id'],
				     'next_sched_contribution',
				     date('YmdHis', $submitted_nsd) );
      }
      return TRUE;
    }

    function cancelSubscription(&$message = '', $params = array()) {
      // TODO: Implement this - request token deletion from eWAY?
      return TRUE;
    }

} // end class CRM_Core_Payment_eWAYRecurring

function ewayrecurring_civicrm_buildForm ($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {
    if(!($page_id = $form->getVar('_id')))
      return;
    $form->addElement('text', 'recur_cycleday', ts('Recurring Payment Date'));
    $sql = 'SELECT cycle_day FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
    $default_cd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($page_id, 'Int')));
    if($default_cd) {
      $form->setDefaults(array('recur_cycleday' => $default_cd));
    }
  } elseif ($formName == 'CRM_Contribute_Form_UpdateSubscription') {
    $paymentProcessor = $form->getVar('_paymentProcessorObj');
    if(($paymentProcessor instanceof org_civicrm_ewayrecurring)){
      $crid = $form->getVar('_crid');
      $sql = 'SELECT next_sched_contribution FROM civicrm_contribution_recur WHERE id = %1';
      $form->addDateTime('next_scheduled_date', ts('Next Scheduled Date'), FALSE, array('formatType' => 'activityDateTime'));
      if($default_nsd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($crid, 'Int')))){
	list($defaults['next_scheduled_date'],
	     $defaults['next_scheduled_date_time']) = CRM_Utils_Date::setDateDefaults($default_nsd);
	$form->setDefaults($defaults);
      }
    }
  } elseif ($formName == 'CRM_Admin_Form_PaymentProcessor' &&
	    $form->getVar('_ppType') == 'eWay_Recurring' &&
	    ($processor_id = $form->getVar('_id'))) {
    $form->addElement('text', 'recur_cycleday', ts('Recurring Payment Date'));
    $sql = 'SELECT cycle_day FROM civicrm_ewayrecurring WHERE processor_id = %1';
    $default_cd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($processor_id, 'Int')));
    if($default_cd) {
      $form->setDefaults(array('recur_cycleday' => $default_cd));
    }
  }
}

function ewayrecurring_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount' ||
      $formName == 'CRM_Admin_Form_PaymentProcessor') {
    $cycle_day = CRM_Utils_Array::value('recur_cycleday', $fields);
    if($cycle_day == '')
      return;
    if (!CRM_Utils_Type::validate($cycle_day, 'Int', FALSE, ts('Cycle day')) || $cycle_day < 1 || $cycle_day > 31) {
      $errors['recur_cycleday'] = ts('Recurring Payment Date must be a number between 1 and 31');
    }
  } elseif ($formName == 'CRM_Contribute_Form_UpdateSubscription') {

    $submitted_nsd = strtotime(CRM_Utils_Array::value('next_scheduled_date', $fields) . ' ' . CRM_Utils_Array::value('next_scheduled_date_time', $fields));

    $crid = $form->getVar('_crid');

    $sql = 'SELECT UNIX_TIMESTAMP(MAX(receive_date)) FROM civicrm_contribution WHERE contribution_recur_id = %1';
    $current_nsd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($crid, 'Int')));
    $form->setVar('_currentNSD', $current_nsd);

    if($submitted_nsd < $current_nsd)
      $errors['next_scheduled_date'] = ts('Cannot schedule next contribution date before latest received date');
    elseif ($submitted_nsd < time())
      $errors['next_scheduled_date'] = ts('Cannot schedule next contribution in the past');
  }
}

function ewayrecurring_civicrm_postProcess ($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {
    if(!($page_id = $form->getVar('_id')))
      CRM_Core_Error::fatal("Attempt to process a contribution page form with no id");
    $cycle_day = $form->getSubmitValue('recur_cycleday');
    $is_recur = $form->getSubmitValue('is_recur');
    /* Do not continue if this is not a recurring payment */
    if (!$is_recur)
      return;
    if(!$cycle_day){
      $sql = 'DELETE FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int')));

      /* Update existing recurring contributions for this page */
      $sql = 'UPDATE civicrm_contribution_recur ccr
          INNER JOIN civicrm_contribution cc
                  ON cc.invoice_id = ccr.invoice_id
           LEFT JOIN civicrm_ewayrecurring ceway
                  ON ccr.payment_processor_id = ceway.processor_id
                 SET ccr.cycle_day  = ceway.cycle_day
               WHERE ccr.invoice_id = cc.invoice_id
                 AND cc.contribution_page_id = %1';

      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int')));
    }  else {
      // Relies on a MySQL extension.
      $sql = 'REPLACE INTO civicrm_contribution_page_recur_cycle (page_id, cycle_day) VALUES (%1, %2)';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int'),
					     2 => array($cycle_day, 'Int')));

      /* Update existing recurring contributions for this page */
      $sql = 'UPDATE civicrm_contribution_recur ccr,
                     civicrm_contribution cc
                 SET ccr.cycle_day  = %2
               WHERE ccr.invoice_id = cc.invoice_id
                 AND cc.contribution_page_id = %1';

      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int'),
					     2 => array($cycle_day, 'Int')));
    }
  } elseif ($formName == 'CRM_Admin_Form_PaymentProcessor' &&
	    $form->getVar('_ppType') == 'eWay_Recurring') {
    if(!($processor_id = $form->getVar('_id')))
      CRM_Core_Error::fatal("Attempt to configure a payment processor admin form with no id");

    $cycle_day = $form->getSubmitValue('recur_cycleday');

    if (!$cycle_day){
      $sql = 'DELETE FROM civicrm_ewayrecurring WHERE processor_id = %1';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int')));
      $cycle_day = 0;
    } else {
      // Relies on a MySQL extension.
      $sql = 'REPLACE INTO civicrm_ewayrecurring (processor_id, cycle_day) VALUES (%1, %2)';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int'),
					     2 => array($cycle_day, 'Int')));
    }

    $sql = 'UPDATE civicrm_contribution_recur ccr
        INNER JOIN civicrm_contribution cc
                ON cc.invoice_id = ccr.invoice_id
         LEFT JOIN civicrm_ewayrecurring ceway
                ON ccr.payment_processor_id = ceway.processor_id
         LEFT JOIN civicrm_contribution_page_recur_cycle ccprc
                ON ccprc.page_id = cc.contribution_page_id
               SET ccr.cycle_day = %2
             WHERE ceway.processor_id = %1
               AND ccprc.cycle_day is NULL';

    CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int'),
					   2 => array($cycle_day, 'Int')));
  }
}

/*
 * Implements hook_civicrm_config()
 *
 * Include path for our overloaded templates */
function ewayrecurring_civicrm_config(&$config) {
  $template =& CRM_Core_Smarty::singleton();

  $ewayrecurringRoot =
    dirname(__FILE__) . DIRECTORY_SEPARATOR;

  $ewayrecurringDir = $ewayrecurringRoot . 'templates';

  if (is_array($template->template_dir)) {
    array_unshift($template->template_dir, $ewayrecurringDir);
  }
  else {
    $template->template_dir = array($ewayrecurringDir, $template->template_dir);
  }

  // also fix php include path
  $include_path = $ewayrecurringRoot . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

function ewayrecurring_civicrm_install() {
  ewayrecurring_civicrm_upgrade('enqueue');
}

function ewayrecurring_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  $sql = "SELECT schema_version FROM civicrm_extension WHERE full_name = 'org.civicrm.ewayrecurring'";
  $schemaVersion = intval(CRM_Core_DAO::singleValueQuery($sql, array()));

  if ($op == 'check') {
    if ($schemaVersion < 4) {
      return array(TRUE);
    }
  } elseif ($op == 'enqueue') {
    $upgrades = array("UPDATE civicrm_extension SET schema_version = '4'
		       WHERE full_name='org.civicrm.ewayrecurring'");
    if($schemaVersion < 4) {
      array_unshift($upgrades,
		    "CREATE TABLE `civicrm_ewayrecurring` (
		       `processor_id` int(10) NOT NULL,
		       `cycle_day` int(2) DEFAULT NULL,
		       PRIMARY KEY(`processor_id`)
		       ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }
    if($schemaVersion < 3) {
      array_unshift($upgrades,
		    "CREATE TABLE `civicrm_contribution_page_recur_cycle` (
		       `page_id` int(10) NOT NULL DEFAULT '0',
		       `cycle_day` int(2) DEFAULT NULL,
		       PRIMARY KEY (`page_id`)
		       ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    foreach($upgrades as $st){
      CRM_Core_DAO::executeQuery($st, array());
    }
  }
}
