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

define('CONTRIBUTION_FAILURE_THRESHOLD', 3);

// TODO:  Variable receipt title based on interval and unit?
define('RECEIPT_SUBJECT_TITLE', 'Recurring Donation');

function process_recurring_payments($payment_processor, $paymentObject) {
  // Create log handler
  $log = CRM_Core_Error::createDebugLogger();

  // If an ewayrecurring job is already running, we want to exit as soon as possible.
  $lock = \Civi\Core\Container::singleton()->get('lockManager')->create('worker.ewayrecurring');
  if(!$lock->isFree() || !$lock->acquire()){
    return FALSE;
  }

  // Create eWay token client
  $eWayClient = $paymentObject->getEWayClient();

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

    /* Get the number of Contributions already recorded for this Schedule. */
    $mainContributions = civicrm_api3('Contribution', 'get', array(
                'options' => array('limit' => 0),
                'sequential' => 1,
                'return'     => array('total_amount', 'tax_amount'),
                'contribution_recur_id' => $contribution->id,
              ));

    $mainContributions = $mainContributions['values'];
    $ccount = count($mainContributions);

    /* Schedule next contribution */
    if (($contribution->installments <= 0) || ($contribution->installments > $ccount + 1)) {
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
      /* Mark recurring contribution as complteted*/
      civicrm_api(
        'ContributionRecur', 'create',
        array(
          'version' => '3',
          'id' => $contribution->id,
          'contribution_recur_status_id' => _contribution_status_id('Completed'))
      );
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
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution->id,
          'contribution_status_id',
          _contribution_status_id('Completed'));
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution->id,
          'end_date',
          CRM_Utils_Date::isoToMysql ($d_now) );
      }

      $mainContributions = $mainContributions[0];
      $new_contribution_record = array();

      // $log->log("Creating contribution record\n", PEAR_LOG_DEBUG);
      $new_contribution_record['contact_id'] = $contribution->contact_id;
      $new_contribution_record['receive_date'] = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
      $new_contribution_record['total_amount'] = ($contribution->amount - $mainContributions['tax_amount']);
      $new_contribution_record['contribution_recur_id'] = $contribution->id;
      $new_contribution_record['payment_instrument_id'] = $contribution->payment_instrument_id;
      $new_contribution_record['address_id'] = $billing_address['id'];
      $new_contribution_record['invoice_id'] = $invoice_id;
      $new_contribution_record['campaign_id'] = $contribution->campaign_id;
      $new_contribution_record['financial_type_id'] = $contribution->financial_type_id;
      $new_contribution_record['payment_processor'] = $contribution->payment_processor_id;

      $contributions = civicrm_api3(
        'Contribution', 'get', array(
          'sequential' => 1,
          'contribution_recur_id' => $contribution->id,
          'options' => array('sort' => "id ASC"),
        )
      );

      $precedent = new CRM_Contribute_BAO_Contribution();
      $precedent->contribution_recur_id = $contribution->id;

      $contributionSource = '';
      $contributionPageId = '';
      $contributionIsTest = 0;

      if($precedent->find(TRUE)) {
        $contributionSource = $precedent->source;
        $contributionPageId = $precedent->contribution_page_id;
        $contributionIsTest = $precedent->is_test;
      }

      try {
        $financial_type = civicrm_api3(
          'FinancialType', 'getsingle', array(
            'sequential' => 1,
            'return' => "name",
            'id' => $contribution->financial_type_id,
          ));
      }
      catch(CiviCRM_API3_Exception $e) { // Most likely due to FinancialType API not being available in < 4.5 - try DAO directly
        $ft_bao = new CRM_Financial_BAO_FinancialType();
        $ft_bao->id = $contribution->financial_type_id;
        $found = $ft_bao->find(true);

        $financial_type = (array) $ft_bao;
      }


      if (!isset($financial_type['name'])) {
        throw new Exception (
          "Financial type could not be loaded for {$contribution->id}"
        );
      }

      $new_contribution_record['source'] = "eWAY Recurring {$financial_type['name']}:\n{$contributionSource}";
      $new_contribution_record['contribution_page_id'] = $contributionPageId;
      $new_contribution_record['is_test'] = $contributionIsTest;

      $eWayResponse = process_eway_payment(
                     $eWayClient,
				     $contribution->processor_id,
				     $amount_in_cents,
				     substr($invoice_id, 0, 16),
				     $financial_type['name'] . ($contributionSource?
                                       ":\n" . $contributionSource : '')
				     );

      CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
        $contribution->id, 'failure_count', 0);

      $new_contribution_record['trxn_id'] = $eWayResponse->getAttribute('TransactionID');

      $responseErrors = $paymentObject->getEWayResponseErrors($eWayResponse);
      if (count($responseErrors)) {
        // Mark transaction as failed
        $new_contribution_record['contribution_status_id'] = _contribution_status_id('Failed');
      }
      else {
        // $log->log("Successfully processed payment for scheduled recurring contribution ID: " . $contribution->id . "\n", PEAR_LOG_DEBUG);

        // $log->log("Sending receipt\n", PEAR_LOG_DEBUG);
        // send_receipt_email($new_contribution_record->id);
      }

      $created = civicrm_api3('Contribution', 'create', $new_contribution_record);

      $new_contribution_record = reset($created['values']);

      if(count($responseErrors)){
        $note = new CRM_Core_BAO_Note();

        $note->entity_table = 'civicrm_contribution';
        $note->contact_id = $contribution->contact_id;
        $note->entity_id = $new_contribution_record['id'];
        $note->subject = ts('Transaction Error');
        $note->note = implode("\n", $responseErrors);

        $note->save();
      }


    // $log->log("Save contribution with trxn_id {$new_contribution_record->trxn_id}", PEAR_LOG_DEBUG);

    }
    catch(Exception $e) {
      $log->log("Processing payment {$contribution->id} for {$contribution->contact_id}: " . $e->getMessage(), PEAR_LOG_WARNING);

      $contribution->failure_count++;

      // Record a failed contribution in this case.
      $new_contribution_record['contribution_status_id'] = _contribution_status_id('Failed');
      $created = civicrm_api3('Contribution', 'create', $new_contribution_record);

      $new_contribution_record = reset($created['values']);

      if(isset($responseErrors) && count($responseErrors)){
        $note = new CRM_Core_BAO_Note();

        $note->entity_table = 'civicrm_contribution';
        $note->contact_id = $contribution->contact_id;
        $note->entity_id = $new_contribution_record['id'];
        $note->subject = ts('Contribution Error');
        $note->note = $e->getMessage();

        $note->save();
      }

      if ($contribution->failure_count < CONTRIBUTION_FAILURE_THRESHOLD){
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution->id,
          'failure_count',
          $contribution->failure_count);
      } else {
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
          $contribution->id,
          'contribution_status_id',
          _contribution_status_id('Failed')
        );

        $log->log("Marked payment {$contribution->id} as failed");
      }
    }

    unset($eWayResponse);

  }

  $lock->release();
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
    // $scheduled_today->whereAdd("`is_test` != 1");
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

      if (!($contribution->find(TRUE))) {
	$scheduled_contributions[] = clone $scheduled_today;
      }
    }

    return $scheduled_contributions;
}

/**
 * process_eway_payment
 *
 * Processes an eWay token payment
 *
 * @param object $eWayClient An eWay client set up and ready to go
 * @param string $managed_customer_id The eWay token ID for the credit card you want to process
 * @param string $amount_in_cents The amount in cents to charge the customer
 * @param string $invoice_reference InvoiceReference to send to eWay
 * @param string $invoice_description InvoiceDescription to send to eWay
 * @throws SoapFault exceptions
 * @return object eWay response object
 */
function process_eway_payment($eWayClient, $managed_customer_id, $amount_in_cents, $invoice_reference, $invoice_description) {

  static $prev_response = NULL;

  $paymentTransaction = array(
    'Customer' => [
      'TokenCustomerID' => $managed_customer_id,
    ],
    'Payment' => [
      'TotalAmount' => $amount_in_cents,
      'InvoiceDescription' => substr(trim($invoice_description), 0, 64),
      'InvoiceReference' => $invoice_reference,
    ],
    'TransactionType' => \Eway\Rapid\Enum\TransactionType::RECURRING,
  );

  $eWayResponse = $eWayClient->createTransaction(\Eway\Rapid\Enum\ApiMethod::DIRECT, $paymentTransaction);

  if (isset($prev_response) && $prev_response->getAttribute('TransactionID') == $eWayResponse->getAttribute('TransactionID')) {
    throw new Exception (
      'eWAY ProcessPayment returned duplicate transaction number: ' .
      $prev_response->getAttribute('TransactionID') . ' vs ' . $eWayResponse->getAttribute('TransactionID')
    );
  }

  $prev_response = & $eWayResponse;

  return $eWayResponse;
}

/**
 * send_receipt_email
 *
 * Sends a receipt for a contribution
 *
 * @param string $contribution_id The ID of the contribution to mark as complete
 * @param CRM_Core_Payment $paymentObject CRM_Core_Payment object
 * @return bool Success or failure
 */
function send_receipt_email($contribution_id, $paymentObject)
{
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(true);

    $is_email_receipt = civicrm_api3('ContributionPage', 'getvalue', array(
      'id' => $contribution->contribution_page_id,
      'return' => 'is_email_receipt'
    ));

    if(!$is_email_receipt) {
      return NULL;
    }

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
            'updateSubscriptionBillingUrl' => $paymentObject->subscriptionURL($contribution_id, 'contribution', 'billing'),
        ),
        'from' => $receiptFrom,
        'toName' => $name,
        'toEmail' => $email,
        'isTest' => $contribution->is_test
    );

    list($sent, $subject, $message, $html) = CRM_Core_BAO_MessageTemplate::sendTemplate($params);

    return $sent;
}