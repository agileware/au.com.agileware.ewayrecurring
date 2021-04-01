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

// TODO:  Variable receipt title based on interval and unit?
define('RECEIPT_SUBJECT_TITLE', 'Recurring Donation');

use CRM_eWAYRecurring_ExtensionUtil as E;

function process_recurring_payments($payment_processor, $paymentObject) {
  // If an ewayrecurring job is already running, we want to exit as soon as possible.
  $lock = \Civi\Core\Container::singleton()
    ->get('lockManager')
    ->create('worker.ewayrecurring');
  if (!$lock->isFree() || !$lock->acquire()) {
    Civi::log()->warning("Detected processing race for scheduled payments, aborting");
    return FALSE;
  }

  // Create eWay token client
  $eWayClient = $paymentObject->getEWayClient();

  // Process today's scheduled contributions.
  $scheduled_contributions = get_scheduled_contributions($payment_processor);
  $scheduled_failed_contributions = get_scheduled_failed_contributions($payment_processor);

  $scheduled_contributions = array_merge($scheduled_failed_contributions, $scheduled_contributions);

  foreach ($scheduled_contributions as $contribution) {
    if ($contribution->payment_processor_id != $payment_processor['id']) {
      continue;
    }

    // Re-check schedule time, in case contribution already processed.
    $next_sched = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionRecur',
      $contribution->id,
      'next_sched_contribution_date',
      'id',
      TRUE);

    /* Get the number of Contributions already recorded for this Schedule. */
    $mainContributions = civicrm_api3('Contribution', 'get', [
      'options' => ['limit' => 0],
      'sequential' => 1,
      'return' => ['total_amount', 'tax_amount'],
      'contribution_recur_id' => $contribution->id,
    ]);

    $mainContributions = $mainContributions['values'];
    $ccount = count($mainContributions);

    /* Schedule next contribution */
    if (($contribution->installments <= 0) || ($contribution->installments > $ccount + 1)) {
      $next_sched = date('Y-m-d 00:00:00', strtotime("+{$contribution->frequency_interval} {$contribution->frequency_unit}s"));
    }
    else {
      $next_sched = NULL;
      /* Mark recurring contribution as complteted*/
      civicrm_api(
        'ContributionRecur', 'create',
        [
          'version' => '3',
          'id' => $contribution->id,
          'contribution_recur_status_id' => _contribution_status_id('Completed'),
        ]
      );
    }

    // Process payment
    // Civi::log()->debug("Processing payment for scheduled recurring contribution ID: " . $contribution->id . "\n");
    $amount_in_cents = preg_replace('/\.([0-9]{0,2}).*$/', '$1',
      $contribution->amount);

    $addresses = civicrm_api('Address', 'get',
      [
        'version' => '3',
        'contact_id' => $contribution->contact_id,
      ]);

    $billing_address = array_shift($addresses['values']);

    $invoice_id = md5(uniqid(rand(), TRUE));
    $eWayResponse = NULL;

    try {
      if ($contribution->contribution_status_id != _contribution_status_id('Failed')) {
        _eWayRecurring_update_contribution_status($next_sched, $contribution);
      }

      $mainContributions = $mainContributions[0];
      $new_contribution_record = [];
      if (empty($mainContributions['tax_amount'])) {
        $mainContributions['tax_amount'] = 0;
      }

      $repeat_params = [
        'contribution_recur_id'  => $contribution->id,
        'contribution_status_id' => _contribution_status_id('Pending'),
        'total_amount'           => $contribution->amount,
        'is_email_receipt'       => 0,
      ];

      $repeated = civicrm_api3('Contribution', 'repeattransaction', $repeat_params);

      $new_contribution_record = $repeated;

      // Civi::log()->debug("Creating contribution record\n");
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
      $new_contribution_record['payment_processor_id'] = $contribution->payment_processor_id;

      $contributions = civicrm_api3(
        'Contribution', 'get', [
          'sequential' => 1,
          'contribution_recur_id' => $contribution->id,
          'options' => ['sort' => "id ASC"],
        ]
      );

      $precedent = new CRM_Contribute_BAO_Contribution();
      $precedent->contribution_recur_id = $contribution->id;

      $contributionSource = '';
      $contributionPageId = '';
      $contributionIsTest = 0;

      if ($precedent->find(TRUE)) {
        $contributionSource = $precedent->source;
        $contributionPageId = $precedent->contribution_page_id;
        $contributionIsTest = $precedent->is_test;
      }

      try {
        $financial_type = civicrm_api3(
          'FinancialType', 'getsingle', [
          'sequential' => 1,
          'return' => "name",
          'id' => $contribution->financial_type_id,
        ]);
      } catch (CiviCRM_API3_Exception $e) { // Most likely due to FinancialType API not being available in < 4.5 - try DAO directly
        $ft_bao = new CRM_Financial_BAO_FinancialType();
        $ft_bao->id = $contribution->financial_type_id;
        $found = $ft_bao->find(TRUE);

        $financial_type = (array) $ft_bao;
      }


      if (!isset($financial_type['name'])) {
        throw new Exception (
          "Financial type could not be loaded for {$contribution->id}"
        );
      }

      $new_contribution_record['source'] = "eWay Recurring {$financial_type['name']}:\n{$contributionSource}";
      $new_contribution_record['contribution_page_id'] = $contributionPageId;
      $new_contribution_record['is_test'] = $contributionIsTest;

      // Retrieve the eWAY token

      if (!empty($contribution->payment_token_id)) {
        try {
          $token = civicrm_api3('PaymentToken', 'getvalue', [
                     'return' => 'token',
                     'id'     => $contribution->payment_token_id,
                   ]);
        } catch (CiviCRM_API3_Exception $e) {
          $token = $contribution->processor_id;
        }
      }
      else {
        $token = $contribution->processor_id;
      }

      if (!$token) {
        throw new CRM_Core_Exception(E::ts('No eWAY token found for Recurring Contribution %1', [1 => $contribution->id]));
      }

      $eWayResponse = process_eway_payment(
        $eWayClient,
        $token,
        $amount_in_cents,
        substr($invoice_id, 0, 16),
        $financial_type['name'] . ($contributionSource ?
          ":\n" . $contributionSource : '')
      );

      $new_contribution_record['trxn_id'] = $eWayResponse->getAttribute('TransactionID');

      $responseErrors = $paymentObject->getEWayResponseErrors($eWayResponse);

      if (!$eWayResponse->TransactionStatus) {
        $responseMessages = array_map('\Eway\Rapid::getMessage', explode(', ', $eWayResponse->ResponseMessage));
        $responseErrors = array_merge($responseMessages, $responseErrors);
      }

      if (count($responseErrors)) {
        // Mark transaction as failed
        $new_contribution_record['contribution_status_id'] = _contribution_status_id('Failed');
        _eWAYRecurring_mark_recurring_contribution_Failed($contribution);
      }
      else {
        // send_receipt_email($new_contribution_record->id);
        $new_contribution_record['contribution_status_id'] = _contribution_status_id('Completed');

        $new_contribution_record['is_email_receipt'] = 0;

        if ($contribution->failure_count > 0 && $contribution->contribution_status_id == _contribution_status_id('Failed')) {
          // Failed recurring contribution completed successfuly after several retry.
          _eWayRecurring_update_contribution_status($next_sched, $contribution);
          CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
            $contribution->id,
            'contribution_status_id',
            _contribution_status_id('In Progress'));
          civicrm_api3('Activity', 'create', [
            'source_contact_id' => $contribution->contact_id,
            'activity_type_id' => "eWay Transaction Succeed",
            'source_record' => $contribution->id,
            'details' => 'Transaction Succeed after ' . $contribution->failure_count . ' retries',
          ]);
        }

        CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
          $contribution->id, 'failure_count', 0);

        CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
          $contribution->id, 'failure_retry_date', '');
      }

      $api_action = (
        $new_contribution_record['contribution_status_id'] == _contribution_status_id('Completed')
        ? 'completetransaction'
        : 'create'
      );

      $updated = civicrm_api3('Contribution', $api_action, $new_contribution_record);

      $new_contribution_record = reset($updated['values']);

      if (count($responseErrors)) {
        $note = new CRM_Core_BAO_Note();

        $note->entity_table = 'civicrm_contribution';
        $note->contact_id = $contribution->contact_id;
        $note->entity_id = $new_contribution_record['id'];
        $note->subject = ts('Transaction Error');
        $note->note = implode("\n", $responseErrors);

        $note->save();
      }

      // Civi::log()->debug("Save contribution with trxn_id {$new_contribution_record->trxn_id}");

    } catch (Exception $e) {
      Civi::log()->warning("Processing payment {$contribution->id} for {$contribution->contact_id}: " . $e->getMessage());

      // already talk to eway? then we need to check the payment status
      if ($eWayResponse) {
        $new_contribution_record['contribution_status_id'] = _contribution_status_id('Pending');
      } else {
        $new_contribution_record['contribution_status_id'] = _contribution_status_id('Failed');
      }

      $updated = civicrm_api3('Contribution', 'create', $new_contribution_record);
      $new_contribution_record = reset($updated['values']);
      // CIVIEWAY-147 there is an unknown system error that happen after civi talks to eway
      // It might be a cache cleaning task happening at the same time that break this task
      // Defer the query later to update the contribution status
      if ($eWayResponse) {
        $ewayParams = [
          'access_code' => $eWayResponse->TransactionID,
          'contribution_id' => $new_contribution_record['id'],
          'payment_processor_id' => $contribution->payment_processor_id,
        ];
        civicrm_api3('EwayContributionTransactions', 'create', $ewayParams);
      } else {
        // Just mark it failed when eWay have no info about this at all
        _eWAYRecurring_mark_recurring_contribution_Failed($contribution);
      }

      $note = new CRM_Core_BAO_Note();

      $note->entity_table = 'civicrm_contribution';
      $note->contact_id = $contribution->contact_id;
      $note->entity_id = $new_contribution_record['id'];
      $note->subject = ts('Contribution Error');
      $note->note = $e->getMessage();

      $note->save();
    }

    unset($eWayResponse);

  }

  $lock->release();
}

function _eWayRecurring_update_contribution_status($next_sched, $contribution) {
  $d_now = new DateTime();
  if ($next_sched) {
    CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
      $contribution->id,
      'next_sched_contribution_date',
      CRM_Utils_Date::isoToMysql($next_sched));
  }
  else {
    CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
      $contribution->id,
      'contribution_status_id',
      _contribution_status_id('Completed'));
    CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
      $contribution->id,
      'end_date',
      CRM_Utils_Date::isoToMysql($d_now));
  }
}

function _eWAYRecurring_mark_recurring_contribution_Failed($contribution) {
  $today = new DateTime();
  $retryDelayInDays = Civi::settings()
    ->get('eway_recurring_contribution_retry_delay');
  $today->modify("+" . $retryDelayInDays . " days");
  $today->setTime(0, 0, 0);

  civicrm_api3('Activity', 'create', [
    'source_contact_id' => $contribution->contact_id,
    'activity_type_id' => "eWay Transaction Failed",
    'source_record' => $contribution->id,
  ]);

  civicrm_api3('ContributionRecur', 'create', [
    'id' => $contribution->id,
    'failure_count' => (++$contribution->failure_count),
    'failure_retry_date' => $today->format("Y-m-d H:i:s"),
    // CIVIEWAY-125: Don't actually mark as failed, because that causes the UI
    // to melt down.
    // 'contribution_status_id' => _contribution_status_id('Failed'),
  ]);
}

/**
 * get_scheduled_contributions
 *
 * Gets recurring contributions that are scheduled to be processed today
 *
 * @return array An array of contribtion_recur objects
 */
function get_scheduled_contributions($payment_processor) {
  $scheduled_today = new CRM_Contribute_BAO_ContributionRecur();

  // Only get contributions for the current processor
  $scheduled_today->payment_processor_id = $payment_processor['id'];

  // Only get contribution that are on or past schedule
  $scheduled_today->whereAdd("`next_sched_contribution_date` <= now()");

  // Don't get cancelled or failed contributions
  $status_ids = implode(', ', [ _contribution_status_id('In Progress'), _contribution_status_id('Pending') ]);
  $scheduled_today->whereAdd("`contribution_status_id` IN ({$status_ids})");

  // CIVIEWAY-124: Exclude contributions that never completed
  $t = $scheduled_today->tableName();
  $ct = CRM_Contribute_BAO_Contribution::getTableName();
  $scheduled_today->whereAdd("EXISTS (SELECT 1 FROM `{$ct}` WHERE `contribution_status_id` = 1 AND `{$t}`.id = `{$ct}`.`contribution_recur_id`)");

  // Exclude contributions that have already been processed
  $scheduled_today->whereAdd("NOT EXISTS (SELECT 1 FROM `{$ct}` WHERE `{$ct}`.`receive_date` >= `{$t}`.`next_sched_contribution_date` AND `{$t}`.id = `{$ct}`.`contribution_recur_id`)");

  $scheduled_today->find();

  $scheduled_contributions = [];

  while ($scheduled_today->fetch()) {
    $scheduled_contributions[] = clone $scheduled_today;
  }

  return $scheduled_contributions;
}

/**
 * get_scheduled_failed_contributions
 *
 * Gets recurring contributions that are failed and to be processed today
 *
 * @return array An array of contribtion_recur objects
 */
function get_scheduled_failed_contributions($payment_processor) {
  $maxFailRetry = Civi::settings()
    ->get('eway_recurring_contribution_max_retry');

  $scheduled_today = new CRM_Contribute_BAO_ContributionRecur();

  // Only get contributions for the current processor
  $scheduled_today->payment_processor_id = $payment_processor['id'];

  $scheduled_today->whereAdd("`failure_retry_date` <= now()");

  $scheduled_today->contribution_status_id = _contribution_status_id('In Progress');
  $scheduled_today->whereAdd("`failure_count` < " . $maxFailRetry);
  $scheduled_today->whereAdd("`failure_count` > 0");
  $scheduled_today->whereAdd("`failure_count` IS NOT NULL");

  // CIVIEWAY-124: Exclude contributions that never completed
  $t = $scheduled_today->tableName();
  $ct = CRM_Contribute_BAO_Contribution::getTableName();
  $scheduled_today->whereAdd("EXISTS (SELECT 1 FROM `{$ct}` WHERE `contribution_status_id` = 1 AND `{$t}`.id = `contribution_recur_id`)");

  // Exclude contributions that have already been processed
  $scheduled_today->whereAdd("NOT EXISTS (SELECT 1 FROM `{$ct}` WHERE `{$ct}`.`receive_date` >= `{$t}`.`failure_retry_date` AND `{$t}`.id = `{$ct}`.`contribution_recur_id`)");

  $scheduled_today->find();

  $scheduled_failed_contributions = [];

  while ($scheduled_today->fetch()) {
    $scheduled_failed_contributions[] = clone $scheduled_today;
  }

  return $scheduled_failed_contributions;
}

/**
 * process_eway_payment
 *
 * Processes an eWay token payment
 *
 * @param object $eWayClient An eWay client set up and ready to go
 * @param string $managed_customer_id The eWay token ID for the credit card you
 *   want to process
 * @param string $amount_in_cents The amount in cents to charge the customer
 * @param string $invoice_reference InvoiceReference to send to eWay
 * @param string $invoice_description InvoiceDescription to send to eWay
 *
 * @return object eWay response object
 * @throws SoapFault exceptions
 */
function process_eway_payment($eWayClient, $managed_customer_id, $amount_in_cents, $invoice_reference, $invoice_description) {

  static $prev_response = NULL;

  $paymentTransaction = [
    'Customer' => [
      'TokenCustomerID' => substr($managed_customer_id,0,16)
    ],
    'Payment' => [
      'TotalAmount' => substr($amount_in_cents,0,10),
      'InvoiceDescription' => substr(trim($invoice_description), 0, 64),
      'InvoiceReference' => substr($invoice_reference,0,64),
    ],
    'TransactionType' => \Eway\Rapid\Enum\TransactionType::MOTO
  ];
  $eWayResponse = $eWayClient->createTransaction(\Eway\Rapid\Enum\ApiMethod::DIRECT, $paymentTransaction);

  if (isset($prev_response) && $prev_response->getAttribute('TransactionID') == $eWayResponse->getAttribute('TransactionID')) {
    throw new Exception (
      'eWay ProcessPayment returned duplicate transaction number: ' .
      $prev_response->getAttribute('TransactionID') . ' vs ' . $eWayResponse->getAttribute('TransactionID')
    );
  }

  $prev_response = &$eWayResponse;

  return $eWayResponse;
}

/**
 * send_receipt_email
 *
 * Sends a receipt for a contribution
 *
 * @param string $contribution_id The ID of the contribution to mark as complete
 * @param CRM_Core_Payment $paymentObject CRM_Core_Payment object
 *
 * @return bool Success or failure
 */
function send_receipt_email($contribution_id, $paymentObject) {
  $contribution = new CRM_Contribute_BAO_Contribution();
  $contribution->id = $contribution_id;
  $contribution->find(TRUE);

  $is_email_receipt = civicrm_api3('ContributionPage', 'getvalue', [
    'id' => $contribution->contribution_page_id,
    'return' => 'is_email_receipt',
  ]);

  if (!$is_email_receipt) {
    return NULL;
  }

  list($name, $email) = CRM_Contact_BAO_Contact_Location::getEmailDetails($contribution->contact_id);

  $domainValues = CRM_Core_BAO_Domain::getNameAndEmail();
  $receiptFrom = "$domainValues[0] <$domainValues[1]>";
  $receiptFromEmail = $domainValues[1];

  $params = [
    'groupName' => 'msg_tpl_workflow_contribution',
    'valueName' => 'contribution_online_receipt',
    'contactId' => $contribution->contact_id,
    'tplParams' => [
      'contributeMode' => 'directIPN',
      // Tells the person to contact us for cancellations
      'receiptFromEmail' => $receiptFromEmail,
      'amount' => $contribution->total_amount,
      'title' => RECEIPT_SUBJECT_TITLE,
      'is_recur' => TRUE,
      'is_monetary' => TRUE,
      'is_pay_later' => FALSE,
      'billingName' => $name,
      'email' => $email,
      'trxn_id' => $contribution->trxn_id,
      'receive_date' => CRM_Utils_Date::format($contribution->receive_date),
      'updateSubscriptionBillingUrl' => $paymentObject->subscriptionURL($contribution_id, 'contribution', 'billing'),
    ],
    'from' => $receiptFrom,
    'toName' => $name,
    'toEmail' => $email,
    'isTest' => $contribution->is_test,
  ];

  list($sent, $subject, $message, $html) = CRM_Core_BAO_MessageTemplate::sendTemplate($params);

  return $sent;
}
