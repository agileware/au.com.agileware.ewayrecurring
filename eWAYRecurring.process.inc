<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM                                                            |
 +--------------------------------------------------------------------+
 | Copyright Henare Degan (C) 2012, Agileware (C) 2012                |
 +--------------------------------------------------------------------+
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007.                                       |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License along with this program; if not, contact CiviCRM LLC       |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

// Contribution status IDs.
define('COMPLETED_CONTRIBUTION_STATUS_ID', 1);
define('PENDING_CONTRIBUTION_STATUS_ID', 2);
define('CANCELLED_CONTRIBUTION_STATUS_ID', 3);
define('FAILED_CONTRIBUTION_STATUS_ID', 4);
define('IN_PROGRESS_CONTRIBUTION_STATUS_ID', 5);

define('CONTRIBUTION_FAILURE_THRESHOLD', 3);

// TODO:  Variable receipt title based on interval and unit?
define('RECEIPT_SUBJECT_TITLE', 'Recurring Donation');

function process_recurring_payments($payment_processor) {
  // Create log handler
  $log = CRM_Core_Error::createDebugLogger();

  // Get pending contributions
  $pending_contributions = get_pending_recurring_contributions($log, $payment_processor);

  // Create eWay token client
  $token_client = eway_token_client(
				    $payment_processor['url_recur'],
				    $payment_processor['subject'],
				    $payment_processor['user_name'],
				    $payment_processor['password']
				    );

  $log->log("Processing " . count($pending_contributions) . " pending contributions for processor " . $payment_processor['id'], PEAR_LOG_INFO);
  foreach ($pending_contributions as $pending_contribution) {
    if ($pending_contribution['contribution_recur']->contribution_status_id == CANCELLED_CONTRIBUTION_STATUS_ID) {
      complete_contribution($pending_contribution['contribution']['id'],
			    '',
			    CANCELLED_CONTRIBUTION_STATUS_ID,
			    strtotime($pending_contribution['contribution_recur']->cancel_date));
      continue;
    }
    // Process payment
    // $log->log("Processing payment for pending contribution ID: " . $pending_contribution['contribution']['id'] . "\n", PEAR_LOG_DEBUG);
    $amount_in_cents = preg_replace('/\.([0-9]{0,2}).*$/', '$1',
				    $pending_contribution['contribution']['total_amount']);


    $financial_type = civicrm_api3(
      'FinancialType', 'getsingle', array(
        'sequential' => 1,
        'return' => "name",
        'id' => $pending_contribution['contribution']['financial_type_id'],
      ));

    $result = process_eway_payment(
				   $token_client,
				   $pending_contribution['contribution_recur']->processor_id,
				   $amount_in_cents,
				   $pending_contribution['contribution']['invoice_id'],
				   $financial_type['name'] . ($pending_contribution['contribution']['contribution_source']?
                                     ":\n" . $pending_contribution['contribution']['contribution_source'] : '')
				   );

    if ($result->ewayTrxnStatus != 'True') {
      // $log->log('ERROR: Failed to process transaction for managed customer: ' . $pending_contribution['contribution_recur']->processor_id . "\n", PEAR_LOG_DEBUG);
      // $log->log('eWay response: ' . $result->ewayTrxnError . "\n", PEAR_LOG_DEBUG);
      // Mark the transaction as failed and move on.
      complete_contribution($pending_contribution['contribution']['id'],
			    $result->ewayTrxnNumber,
			    FAILED_CONTRIBUTION_STATUS_ID);
      $pending_contribution['contribution_recur']->failure_count++;
    } else {
      // $log->log("Successfully processed payment for pending contribution ID: " . $pending_contribution['contribution']['id'] . "\n", PEAR_LOG_DEBUG);

      // $log->log("Marking contribution as complete\n", PEAR_LOG_DEBUG);

      complete_contribution($pending_contribution['contribution']['id'],
			    $result->ewayTrxnNumber);

      // $log->log("Sending receipt\n", PEAR_LOG_DEBUG);
      send_receipt_email($pending_contribution['contribution']['id']);
    }

    // $log->log("Updating recurring contribution\n", PEAR_LOG_DEBUG);


    /* Schedule next contribution */

    $c_recur =& $pending_contribution['contribution_recur'];

    if (($cd = $pending_contribution['contribution_recur']->cycle_day) > 0 &&
	$pending_contribution['contribution_recur']->frequency_unit == 'month'){
      $d_now = new DateTime();
      $d_next = new DateTime(date("Y-m-$cd 00:00:00"));
      $d_next->modify("+{$c_recur->frequency_interval} " .
		      "{$c_recur->frequency_unit}s");
      $next_m = ($d_now->format('m') + $c_recur->frequency_interval - 1) % 12 + 1;
      if ($next_m != $d_next->format('m')) {
	$daysover = $d_next->format('d');
	$d_next->modify("-{$daysover} days");
      }
      $next_sched = $d_next->format('Y-m-d 00:00:00');
    } else {
      $next_sched = date('Y-m-d 00:00:00',
			 strtotime("+{$c_recur->frequency_interval} " .
				   "{$c_recur->frequency_unit}s"));
    }

    CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
				 $pending_contribution['contribution_recur']->id,
				 'next_sched_contribution_date',
				 CRM_Utils_Date::isoToMysql ($next_sched) );

    /* Update status for reporting */
    CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
				 $pending_contribution['contribution_recur']->id,
				 'contribution_status_id',
				 IN_PROGRESS_CONTRIBUTION_STATUS_ID );

    // $log->log("Finished processing contribution ID: " . $pending_contribution['contribution']['id'] . "\n", PEAR_LOG_DEBUG);
  }

  // Process today's scheduled contributions.
  $scheduled_contributions = get_scheduled_contributions($payment_processor);

  $log->log("Processing " . count($scheduled_contributions) . " scheduled contributions for processor " . $payment_processor['id'], PEAR_LOG_INFO);
  foreach ($scheduled_contributions as $contribution) {
    if ($contribution->payment_processor_id != $payment_processor['id'])
      continue;

    // Re-check schedule time, in case contribution already processed.
    $next_sched = CRM_Core_DAO::getFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
					       $contribution->id,
					       'next_sched_contribution_date',
					       'id',
					       TRUE);

    if (strtotime($next_sched) > time()) {
      $log->log("Detected processing race for scheduled payments, aborting", PEAR_LOG_WARNING);
      break;  // Assume parallel cron job called, exit loop.
    }

    /* Schedule next contribution */
    if ($contribution->installments != 1){
      if(($cd = $contribution->cycle_day) && ($contribution->frequency_unit == 'month' || $contribution->frequency_unit == 'year')){
        $d_now = new DateTime();
        $d_next = new DateTime(date("Y-m-$cd 00:00:00"));
        $d_next->modify("+{$contribution->frequency_interval} " .
          "{$contribution->frequency_unit}s");
        $next_m = ($d_now->format('m') + $contribution->frequency_interval - 1) % 12 + 1;
        if ($next_m != $d_next->format('m')) {
          $daysover = $d_next->format('d');
          $d_next->modify("-{$daysover} days");
        }
        $next_sched = $d_next->format('Y-m-d 00:00:00');
      }
      else {
        $next_sched = date('Y-m-d 00:00:00',
                      strtotime("+{$contribution->frequency_interval} " .
                        "{$contribution->frequency_unit}s"));
      }
    } else {
      $next_sched = NULL;
    }

    // Process payment
    // $log->log("Processing payment for scheduled recurring contribution ID: " . $contribution->id . "\n", PEAR_LOG_DEBUG);
    $amount_in_cents = preg_replace('/\.([0-9]{0,2}).*$/', '$1',
				    $contribution->amount);

    $addresses = civicrm_api('Address', 'get',
			     array('version' => '3',
				   'contact_id' => $contribution->contact_id));

    $billing_address = array_shift($addresses['values']);

    $invoice_id = md5(uniqid(rand(), TRUE));

    try {
      if($next_sched){
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution->id,
          'next_sched_contribution_date',
          CRM_Utils_Date::isoToMysql ($next_sched) );
      } else {
        CRM_Core_DAO::setFieldValue( 'CRM_Contribution_DAO_ContributionRecur',
          $contribution->id,
          'contribution_status_id',
          COMPLETED_CONTRIBUTION_STATUS_ID);
      }

      if ($contribution->installments >= 1) {
        CRM_Core_DAO::setFieldValue( 'CRM_Contribution_DAO_ContributionRecur',
          $contribution->id,
          'installments',
          ($contribution->installments - 1)
        );
      }

      $contributions = civicrm_api3(
        'Contribution', 'get', array(
          'sequential' => 1,
          'contribution_recur_id' => $contribution->id,
          'options' => array('sort' => "id ASC"),
        )
      );

      $precedent = reset($contributions['values']);

      if(empty($precedent)) {
        $precedent = array ('contribution_source' => '');
      }

      $financial_type = civicrm_api3(
        'FinancialType', 'getsingle', array(
          'sequential' => 1,
          'return' => "name",
          'id' => $contribution->financial_type_id,
        ));

      if (!isset($financial_type['name'])) {
        throw new Exception (
          "Financial type could not be loaded for {$contribution->id}";
        );
      }

      $result = process_eway_payment(
				     $token_client,
				     $contribution->processor_id,
				     $amount_in_cents,
				     substr($invoice_id, 0, 16),
				     $financial_type['name'] . ($precedent['contribution_source']?
                                       ":\n" . $precedent['contribution_source'] : '')
				     );

      CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
        $contribution->id, 'failure_count', 0);

      // $log->log("Creating contribution record\n", PEAR_LOG_DEBUG);
      $new_contribution_record = new CRM_Contribute_BAO_Contribution();
      $new_contribution_record->contact_id = $contribution->contact_id;
      $new_contribution_record->receive_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
      $new_contribution_record->total_amount = $contribution->amount;
      $new_contribution_record->contribution_recur_id = $contribution->id;
      $new_contribution_record->contribution_status_id = COMPLETED_CONTRIBUTION_STATUS_ID; // TODO: Remove hardcoded hack
      $new_contribution_record->contribution_type_id = $contribution->contribution_type_id;
      $new_contribution_record->payment_instrument_id = $contribution->payment_instrument_id;
      $new_contribution_record->source = 'eWAY Recurring Payment';
      $new_contribution_record->trxn_id = $result->ewayTrxnNumber;
      $new_contribution_record->address_id = $billing_address['id'];
      $new_contribution_record->invoice_id = $invoice_id;
      $new_contribution_record->campaign_id = $contribution->campaign_id;
      $new_contribution_record->financial_type_id = $contribution->financial_type_id;

      if ($result->ewayTrxnStatus != 'True') {
        // Mark transaction as failed
        $new_contribution_record->contribution_status_id = FAILED_CONTRIBUTION_STATUS_ID;
      }
      else {
        // $log->log("Successfully processed payment for scheduled recurring contribution ID: " . $contribution->id . "\n", PEAR_LOG_DEBUG);

        // $log->log("Sending receipt\n", PEAR_LOG_DEBUG);
        // send_receipt_email($new_contribution_record->id);
      }

      $new_contribution_record->save();

      if(isset($result->ewayTrxnError)){
        $note = new CRM_Core_BAO_Note();

        $note->entity_table = 'civicrm_contribution';
        $note->contact_id = $contribution->contact_id;
        $note->entity_id = $new_contribution_record->id;
        $note->subject = ts('Transaction Error');
        $note->note = $result->ewayTrxnError;

        $note->save();
      }


    // $log->log("Save contribution with trxn_id {$new_contribution_record->trxn_id}", PEAR_LOG_DEBUG);

    }
    catch(Exception $e) {
      $log->log("Processing payment {$contribution->id} for {$contribution->contact_id}: " . $e->getMessage(), PEAR_LOG_WARNING);

      $contribution->failure_count++;

      if ($contribution->failure_count < CONTRIBUTION_FAILURE_THRESHOLD){
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution->id,
          'failure_count',
          $contribution->failure_count);
      } else {
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution->id,
          'contribution_status_id',
          FAILED_CONTRIBUTION_STATUS_ID
        );

        $log->log("Marked payment {$contribution->id} as failed");
      }
    }

    unset($result);

  }

}

/**
 * get_pending_recurring_contributions
 *
 * Gets recurring contributions that are in a pending state.
 * These are for newly created recurring contributions and should
 * generally be processed the same day they're created. These do not
 * include the regularly processed recurring transactions.
 *
 * @return array An array of associative arrays containing contribution arrays & contribtion_recur objects
 */
function get_pending_recurring_contributions($log, $payment_processor)
{
    // Get pending contributions
    // TODO: Stop using the API. For consistency we should use BAOs so we get objects back
    $params = array(
        'version' => 3,
        'contribution_status_id' => PENDING_CONTRIBUTION_STATUS_ID,
        'rowCount' => 100,
        'offset' => 0,
    );

    $result = array();

    $pending_contributions = civicrm_api('Contribution', 'get', $params);

    while($pending_contributions['count'] > 0) {
      foreach ($pending_contributions['values'] as $contribution) {
        // Only process those with recurring contribution records
        if ($contribution['contribution_recur_id']) {
          // Find the recurring contribution record for this contribution
          $recurring = new CRM_Contribute_BAO_ContributionRecur();
          $recurring->id = $contribution['contribution_recur_id'];

          // Only process records that have a recurring record with
          // a processor ID, i.e. an eWay token
          if ($recurring->find(true) && $recurring->processor_id
	      && ($recurring->payment_processor_id == $payment_processor['id'])) {
            // TODO: Return the same type of results
            // This is a bit nasty, contribution is an array and
            // contribution_recur is an object
            $result[$contribution['contribution_recur_id']] = array(
              'contribution' => $contribution,
              'contribution_recur' => $recurring
            );
          }
        }
      }

      $params['offset'] += $params['rowCount'];
      $pending_contributions = civicrm_api('Contribution', 'get', $params);
    }
    return $result;
}

/**
 * get_scheduled_contributions
 *
 * Gets recurring contributions that are scheduled to be processed today
 *
 * @return array An array of contribtion_recur objects
 */
function get_scheduled_contributions($payment_processor)
{
    $scheduled_today = new CRM_Contribute_BAO_ContributionRecur();
    $scheduled_today->whereAdd("`next_sched_contribution_date` <= now()");
    // Don't get cancelled or failed contributions
    $scheduled_today->whereAdd("`contribution_status_id` IN (2, 5)");
    // Or test transactions
    $scheduled_today->whereAdd("`is_test` != 1");
    $scheduled_today->find();

    $scheduled_contributions = array();

    while ($scheduled_today->fetch()) {
      if($scheduled_today->payment_processor_id != $payment_processor['id']){
	continue;
      }
      // Check that there's no existing contribution record for today
      $contribution = new CRM_Contribute_BAO_Contribution();
      $contribution->contribution_recur_id = $scheduled_today->id;
      $contribution->whereAdd
	("`receive_date` >= '{$scheduled_today->next_sched_contribution_date}'");

      if (!($contribution->find())) {
	$scheduled_contributions[] = clone $scheduled_today;
      }
    }

    return $scheduled_contributions;
}

/**
 * eway_token_client
 *
 * Creates an eWay SOAP client to the eWay token API
 *
 * @param string $gateway_url URL of the gateway to connect to (could be the test or live gateway)
 * @param string $eway_customer_id Your eWay customer ID
 * @param string $username Your eWay business centre username
 * @param string $password Your eWay business centre password
 * @return object A SOAP client to the eWay token API
 */
function eway_token_client($gateway_url, $eway_customer_id, $username, $password)
{

    $soap_client = new SoapClient($gateway_url);

    // Set up SOAP headers
    $headers = array(
        'eWAYCustomerID' => $eway_customer_id,
        'Username'       => $username,
        'Password'       => $password
    );
    $header = new SoapHeader('https://www.eway.com.au/gateway/managedpayment', 'eWAYHeader', $headers);
    $soap_client->__setSoapHeaders($header);

    return $soap_client;
}

/**
 * process_eway_payment
 *
 * Processes an eWay token payment
 *
 * @param object $soap_client An eWay SOAP client set up and ready to go
 * @param string $managed_customer_id The eWay token ID for the credit card you want to process
 * @param string $amount_in_cents The amount in cents to charge the customer
 * @param string $invoice_reference InvoiceReference to send to eWay
 * @param string $invoice_description InvoiceDescription to send to eWay
 * @throws SoapFault exceptions
 * @return object eWay response object
 */
function process_eway_payment($soap_client, $managed_customer_id, $amount_in_cents, $invoice_reference, $invoice_description) {

  static $prev_response = NULL;

  $paymentinfo = array(
    'managedCustomerID' => $managed_customer_id,
    'amount' => $amount_in_cents,
    'InvoiceReference' => $invoice_reference,
    'InvoiceDescription' => $invoice_description
  );

  $result = $soap_client->ProcessPayment($paymentinfo);
  $eway_response = & $result->ewayResponse;

  if ($prev_response->ewayTrxnNumber == $eway_response->ewayTrxnNumber) {
    throw new Exception (
      'eWAY ProcessPayment returned duplicate transaction number: ' .
      $prev_response->ewayTrxnNumber . ' vs ' . $eway_response->ewayTrxnNumber
    );
  }

  $prev_response = & $eway_response;

  return $eway_response;
}

/**
 * complete_contribution
 *
 * Marks a contribution as complete
 *
 * @param string $contribution_id The ID of the contribution to mark as complete
 * @return object The contribution object
 */
function complete_contribution($contribution_id, $trxn_id = NULL, $status_id = COMPLETED_CONTRIBUTION_STATUS_ID, $c_date = NULL)
{
    // Mark the contribution as complete
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(true);

    if(!$c_date)
      $c_date = time();

    $contribution->contribution_status_id = $status_id;

    if ($status_id == CANCELLED_CONTRIBUTION_STATUS_ID)
      $contribution->cancel_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'), $c_date);
    else
      $contribution->receive_date = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'), $c_date);

    if($trxn_id)
      $contribution->trxn_id = $trxn_id;

    return $contribution->save();
}

/**
 * send_receipt_email
 *
 * Sends a receipt for a contribution
 *
 * @param string $contribution_id The ID of the contribution to mark as complete
 * @return bool Success or failure
 */
function send_receipt_email($contribution_id)
{
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(true);

    list($name, $email) = CRM_Contact_BAO_Contact_Location::getEmailDetails($contribution->contact_id);

    $domainValues     = CRM_Core_BAO_Domain::getNameAndEmail();
    $receiptFrom      = "$domainValues[0] <$domainValues[1]>";
    $receiptFromEmail = $domainValues[1];

    $params = array(
        'groupName' => 'msg_tpl_workflow_contribution',
        'valueName' => 'contribution_online_receipt',
        'contactId' => $contribution->contact_id,
        'tplParams' => array(
            'contributeMode' => 'directIPN', // Tells the person to contact us for cancellations
            'receiptFromEmail' => $receiptFromEmail,
            'amount' => $contribution->total_amount,
            'title' => RECEIPT_SUBJECT_TITLE,
            'is_recur' => true,
	    'is_monetary' => true,
	    'is_pay_later' => false,
            'billingName' => $name,
            'email' => $email,
	    'trxn_id' => $contribution->trxn_id,
            'receive_date' => CRM_Utils_Date::format($contribution->receive_date),
        ),
        'from' => $receiptFrom,
        'toName' => $name,
        'toEmail' => $email,
        'isTest' => $contribution->is_test
    );

    list($sent, $subject, $message, $html) = CRM_Core_BAO_MessageTemplate::sendTemplate($params);

    return $sent;
}
