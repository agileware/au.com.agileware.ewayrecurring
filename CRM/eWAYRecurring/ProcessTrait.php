<?php

trait CRM_eWAYRecurring_ProcessTrait {

  /**
   * @return array
   */
  abstract public function getPaymentProcessor();

  /**
   * @param array $processor
   */
  abstract public function setPaymentProcessor($processor);

  abstract protected function getEWayClient();

  public function process_recurring_payments() {
    // If an ewayrecurring job is already running, we want to exit as soon as possible.
    $lock = \Civi\Core\Container::singleton()
      ->get('lockManager')
      ->create('worker.ewayrecurring');
    if (!$lock->isFree() || !$lock->acquire()) {
      Civi::log()
        ->warning("Detected processing race for scheduled payments, aborting");

      return FALSE;
    }

    // Process today's scheduled contributions.
    $scheduled_contributions = $this->get_scheduled_contributions();
    $scheduled_failed_contributions = $this->get_scheduled_failed_contributions();

    $scheduled_contributions = array_merge($scheduled_failed_contributions, $scheduled_contributions);

    foreach ($scheduled_contributions as $contribution) {
      if ($contribution->payment_processor_id != $this->getPaymentProcessor()['id']) {
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
        $next_sched = date('Y-m-d 00:00:00', strtotime($next_sched . " +{$contribution->frequency_interval} {$contribution->frequency_unit}s"));
      }
      else {
        $next_sched = NULL;
        /* Mark recurring contribution as complteted*/
        civicrm_api3(
          'ContributionRecur', 'create',
          [
            'id' => $contribution->id,
            'contribution_recur_status_id' => CRM_eWAYRecurring_Utils::contribution_status_id('Completed', TRUE),
          ]
        );
      }

      // Process payment
      // Whole cents, as a string: 10.50 is "1050" and 0.99 is "99".
      $amount_in_cents = (string) (int) round(((float) $contribution->amount) * 100);

      $addresses = civicrm_api3('Address', 'get', ['contact_id' => $contribution->contact_id,]);

      $billing_address = array_shift($addresses['values']);

      $invoice_id = md5(uniqid(rand(), TRUE));
      $eWayResponse = NULL;

      try {
        if (!$contribution->failure_retry_date) {
          // Only update the next schedule if we're not in a retry state.
          $this->update_contribution_status($next_sched, $contribution);
        }

        $mainContributions = $mainContributions[0];
        $new_contribution_record = [];
        if (empty($mainContributions['tax_amount'])) {
          $mainContributions['tax_amount'] = 0;
        }

        $repeat_params = [
          'contribution_recur_id' => $contribution->id,
          'contribution_status_id' => CRM_eWAYRecurring_Utils::contribution_status_id('Pending'),
          'total_amount' => $contribution->amount,
          'is_email_receipt' => 0,
        ];

        $repeated = civicrm_api3('Contribution', 'repeattransaction', $repeat_params);

        $new_contribution_record = $repeated;

        $new_contribution_record['contact_id'] = $contribution->contact_id;
        $new_contribution_record['receive_date'] = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
        $new_contribution_record['total_amount'] = ($contribution->amount - $mainContributions['tax_amount']);
        $new_contribution_record['contribution_recur_id'] = $contribution->id;
        $new_contribution_record['payment_instrument_id'] = $contribution->payment_instrument_id;
        $new_contribution_record['address_id'] = $billing_address['id'] ?? NULL;
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
        }
        catch (CRM_Core_Exception $e) { // Most likely due to FinancialType API not being available in < 4.5 - try DAO directly
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
              'id' => $contribution->payment_token_id,
            ]);
          }
          catch (CRM_Core_Exception $e) {
            $token = $contribution->processor_id;
          }
        }
        else {
          $token = $contribution->processor_id;
        }

        if (!$token) {
          throw new CRM_Core_Exception(\CRM_eWAYRecurring_ExtensionUtil::ts('No eWAY token found for Recurring Contribution %1', [1 => $contribution->id]));
        }

        $eWayResponse = $this->process_payment(
          $token,
          $amount_in_cents,
          substr($invoice_id, 0, 16),
          $financial_type['name'] . ($contributionSource ?
            ":\n" . $contributionSource : '')
        );

        $new_contribution_record['trxn_id'] = $eWayResponse->getAttribute('TransactionID');

        $responseErrors = $this->getEWayResponseErrors($eWayResponse);

        if (!$eWayResponse->TransactionStatus) {
          $responseMessages = array_map('\Eway\Rapid::getMessage', explode(', ', $eWayResponse->ResponseMessage));
          $responseErrors = array_merge($responseMessages, $responseErrors);
        }

        if (count($responseErrors)) {
          // Mark transaction as failed
          $new_contribution_record['contribution_status_id'] = CRM_eWAYRecurring_Utils::contribution_status_id('Failed');
          $this->mark_recurring_contribution_failed($contribution);
        }
        else {
          $new_contribution_record['contribution_status_id'] = CRM_eWAYRecurring_Utils::contribution_status_id('Completed');

          $new_contribution_record['is_email_receipt'] = Civi::settings()
            ->get('eway_recurring_keep_sending_receipts');

          if ($contribution->failure_count > 0) {
            // A recurring contribution that had failed was charged successfully on a retry. It is still
            // In Progress while it is being retried, so this cannot depend on it being marked Failed.
            $this->update_contribution_status($next_sched, $contribution);
            CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
              $contribution->id,
              'contribution_status_id',
              CRM_eWAYRecurring_Utils::contribution_status_id('In Progress', TRUE));

            try {
              civicrm_api3('Activity', 'create', [
                'source_contact_id' => $contribution->contact_id,
                'activity_type_id' => 'eWay_Transaction_Succeeded',
                'source_record' => $contribution->id,
                'details' => 'Transaction Succeeded after ' . $contribution->failure_count . ' retries',
              ]);
            }
            catch (CRM_Core_Exception $e) {
              \Civi::log()
                ->info('eWAY Recurring: Couldn\'t record success activity: ' . $e->getMessage());
            }
          }

          CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
            $contribution->id, 'failure_count', 0);

          CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
            $contribution->id, 'failure_retry_date', '');
        }

        $api_action = (
        $new_contribution_record['contribution_status_id'] == CRM_eWAYRecurring_Utils::contribution_status_id('Completed')
          ? 'completetransaction'
          : 'create'
        );

        $updated = civicrm_api3('Contribution', $api_action, $new_contribution_record);

        $new_contribution_record = reset($updated['values']);

        // The invoice_id does not seem to be recorded by
        // Contribution.completetransaction, so let's update it directly.
        if ($api_action === 'completetransaction') {
          $updated = civicrm_api3('Contribution', 'create', [
            'id' => $new_contribution_record['id'],
            'invoice_id' => $invoice_id,
          ]);
          $new_contribution_record = reset($updated['values']);
        }

        if (count($responseErrors)) {
          $note = new CRM_Core_BAO_Note();

          $note->entity_table = 'civicrm_contribution';
          $note->contact_id = $contribution->contact_id;
          $note->entity_id = $new_contribution_record['id'];
          $note->subject = ts('Transaction Error');
          $note->note = implode("\n", $responseErrors);

          $note->save();
        }
      }
      catch (Exception $e) {
        Civi::log()
          ->warning("Processing payment {$contribution->id} for {$contribution->contact_id}: " . $e->getMessage());

        // already talk to eway? then we need to check the payment status
        if ($eWayResponse) {
          $new_contribution_record['contribution_status_id'] = CRM_eWAYRecurring_Utils::contribution_status_id('Pending');
        }
        else {
          $new_contribution_record['contribution_status_id'] = CRM_eWAYRecurring_Utils::contribution_status_id('Failed');
        }

        try {
          $updated = civicrm_api3('Contribution', 'create', $new_contribution_record);

          $new_contribution_record = reset($updated['values']);
        }
        catch (CRM_Core_Exception $e) {
          Civi::log()
            ->warning("Recurring contribution - Unable to set status of new contribution: " . $e->getMessage(), $new_contribution_record);
        }

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
        }
        else {
          // Just mark it failed when eWay have no info about this at all
          $this->mark_recurring_contribution_failed($contribution);
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

  protected function update_contribution_status($next_sched, $contribution) {
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
        CRM_eWAYRecurring_Utils::contribution_status_id('Completed', TRUE));
      CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
        $contribution->id,
        'end_date',
        date('YmdHis'));
    }
  }

  public function mark_recurring_contribution_failed($contribution) {
    $today = new DateTime();
    $retryDelayInDays = Civi::settings()
      ->get('eway_recurring_contribution_retry_delay');
    $today->modify("+" . $retryDelayInDays . " days");
    $today->setTime(0, 0, 0);

    try {
      civicrm_api3('Activity', 'create', [
        'source_contact_id' => $contribution->contact_id,
        'activity_type_id' => 'eWay_Transaction_Failed',
        'source_record' => $contribution->id,
      ]);
    }
    catch (CRM_Core_Exception $e) {
      /* Failing to create the failure activity should not prevent the
         ContributionRecur entity from being updated. Log it and move on. */
      \Civi::log()
        ->info('eWAY Recurring: Couldn\'t record failure activity: ' . $e->getMessage());
    }

    $params = [
      'id' => $contribution->id,
      'failure_count' => (++$contribution->failure_count),
      'failure_retry_date' => $today->format("Y-m-d H:i:s"),
      // CIVIEWAY-125: Don't mark it as failed while retries remain, because that causes the UI
      // to melt down.
    ];

    // Once the maximum number of retries is used up nothing will charge it again (the retry list
    // stops at this count), so mark it Failed. That is what puts it on the "Failed Recurring
    // Contributions" list and in the system status check, and what Reactivate resets.
    $maxRetries = (int) Civi::settings()->get('eway_recurring_contribution_max_retry');
    if ($contribution->failure_count >= $maxRetries) {
      $params['contribution_status_id'] = CRM_eWAYRecurring_Utils::contribution_status_id('Failed', TRUE);
    }

    civicrm_api3('ContributionRecur', 'create', $params);
  }

  /**
   * get_scheduled_contributions
   *
   * Gets recurring contributions that are scheduled to be processed today
   *
   * @return array An array of contribtion_recur objects
   */
  protected function get_scheduled_contributions() {
    $payment_processor = $this->getPaymentProcessor();
    $scheduled_today = new CRM_Contribute_BAO_ContributionRecur();

    // Only get contributions for the current processor
    $scheduled_today->payment_processor_id = $payment_processor['id'];

    // Only get contribution that are on or past schedule
    $scheduled_today->whereAdd("`next_sched_contribution_date` <= now()");

    // Don't get cancelled or failed contributions
    $status_ids = implode(', ', [
      CRM_eWAYRecurring_Utils::contribution_status_id('In Progress', TRUE),
      CRM_eWAYRecurring_Utils::contribution_status_id('Pending', TRUE),
    ]);
    $scheduled_today->whereAdd("`contribution_status_id` IN ({$status_ids})");

    // Ignore transactions that have a failure_retry_date, these are subject to different conditions
    $scheduled_today->whereAdd("`failure_retry_date` IS NULL");

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
  public function get_scheduled_failed_contributions() {
    $payment_processor = $this->getPaymentProcessor();

    $maxFailRetry = Civi::settings()
      ->get('eway_recurring_contribution_max_retry');

    $scheduled_today = new CRM_Contribute_BAO_ContributionRecur();

    // Only get contributions for the current processor
    $scheduled_today->payment_processor_id = $payment_processor['id'];

    $scheduled_today->whereAdd("`failure_retry_date` <= now()");

    $scheduled_today->contribution_status_id = CRM_eWAYRecurring_Utils::contribution_status_id('In Progress', TRUE);
    $scheduled_today->whereAdd("`failure_count` < " . $maxFailRetry);
    $scheduled_today->whereAdd("`failure_count` > 0");

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
   * @param string $managed_customer_id The eWay token ID for the credit card
   *   you want to process
   * @param string $amount_in_cents The amount in cents to charge the customer
   * @param string $invoice_reference InvoiceReference to send to eWay
   * @param string $invoice_description InvoiceDescription to send to eWay
   *
   * @return object eWay response object
   * @throws SoapFault exceptions
   */
  public function process_payment($managed_customer_id, $amount_in_cents, $invoice_reference, $invoice_description) {
    // The TransactionID of the previous charge made by this process, to catch eWAY returning one twice.
    static $previousTransactionId = NULL;

    $eWayClient = $this->getEWayClient();

    // Avoid sending non-ASCII characters to eWAY, as that causes the transaction to fail.
    $invoice_reference = CRM_eWAYRecurring_Utils::sanitizeToAscii($invoice_reference);
    $invoice_description = CRM_eWAYRecurring_Utils::sanitizeToAscii($invoice_description);

    $paymentTransaction = [
      'Customer' => [
        'TokenCustomerID' => substr($managed_customer_id, 0, 16),
      ],
      'Payment' => [
        'TotalAmount' => substr($amount_in_cents, 0, 10),
        'InvoiceDescription' => substr(trim($invoice_description), 0, 64),
        'InvoiceReference' => substr($invoice_reference, 0, 64),
      ],
      'TransactionType' => \Eway\Rapid\Enum\TransactionType::MOTO,
    ];
    $eWayResponse = $eWayClient->createTransaction(\Eway\Rapid\Enum\ApiMethod::DIRECT, $paymentTransaction);

    // Only a real transaction id can be a duplicate: a request eWAY refused carries none.
    $transactionId = $eWayResponse->getAttribute('TransactionID');
    if (!empty($transactionId) && (string) $previousTransactionId === (string) $transactionId) {
      throw new Exception (
        'eWay ProcessPayment returned duplicate transaction number: ' .
        $previousTransactionId . ' vs ' . $transactionId
      );
    }

    $previousTransactionId = $transactionId;

    return $eWayResponse;
  }

}
