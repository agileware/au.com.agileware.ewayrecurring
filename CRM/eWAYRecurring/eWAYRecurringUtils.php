<?php

class CRM_eWAYRecurring_eWAYRecurringUtils {

  public static $TRANSACTION_IN_QUEUE_STATUS = 0;
  public static $TRANSACTION_SUCCESS_STATUS = 1;
  public static $TRANSACTION_FAILED_STATUS = 2;

  public static $TRANSACTION_MAX_TRIES = 3;

  /**
   * Validate pending transactions.
   *
   * @param array $params
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public function validatePendingTransactions($params = array()) {
    // Fetch all transactions to validate
    $transactionsToValidate = civicrm_api3('EwayContributionTransactions', 'get', array(
      'status'     => self::$TRANSACTION_IN_QUEUE_STATUS,
      'tries'      => ['<' => self::$TRANSACTION_MAX_TRIES],
      'sequantial' => TRUE,
    ));

    $transactionsToValidate = $transactionsToValidate['values'];

    // Include eWay SDK.
    require_once 'vendor/autoload.php';

    $apiResponse = array(
      'failed'        => 0,
      'success'       => 0,
      'not_processed' => 0,
      'deleted'       => 0,
    );

    foreach ($transactionsToValidate as $transactionToValidate) {
      $contributionId = $transactionToValidate['contribution_id'];
      try {

        // Fetch contribution
        $contribution = civicrm_api3('Contribution', 'getsingle', array(
          'id' => $contributionId,
        ));

        // Fetch payment processor
        $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', array(
          'id' => $transactionToValidate['payment_processor_id'],
        ));

        // Validate the transaction
        $response = self::validateContribution($transactionToValidate['access_code'], $contribution, $paymentProcessor);
        $transactionToValidate['tries']++;

        if ($response['hasTransactionFailed']) {
          $transactionToValidate['status'] = self::$TRANSACTION_FAILED_STATUS;
          $transactionToValidate['failed_message'] = $response['transactionResponseError'];
          $apiResponse['failed']++;
        }
        else if (!$response['transactionNotProcessedYet']) {
          $transactionToValidate['status'] = self::$TRANSACTION_SUCCESS_STATUS;
          $apiResponse['success']++;
        }
        else{
          $apiResponse['not_processed']++;
        }

        // Update the transaction
        civicrm_api3('EwayContributionTransactions', 'create', $transactionToValidate);

      }
      catch (CiviCRM_API3_Exception $e) {
        // Contribution/Payment Processor not found, delete the transaction.
        civicrm_api3('EwayContributionTransactions', 'delete', array(
          'id' => $transactionToValidate['id'],
        ));
        $apiResponse['deleted']++;
      }
    }
    return $apiResponse;

  }

  /**
   * Create eWay client using credentials from payment processor.
   *
   * @return \Eway\Rapid\Contract\Client
   */
  public static function getEWayClient($paymentProcessor) {
    $eWayApiKey = $paymentProcessor['user_name'];   // eWAY Api Key
    $eWayApiPassword = $paymentProcessor['password']; // eWAY Api Password
    $eWayEndPoint = ($paymentProcessor['is_test']) ? \Eway\Rapid\Client::MODE_SANDBOX : \Eway\Rapid\Client::MODE_PRODUCTION;

    $eWayClient = \Eway\Rapid::createClient($eWayApiKey, $eWayApiPassword, $eWayEndPoint);

    return $eWayClient;
  }

  /**
   * Validate contribution on successful response.
   *
   * @param $eWayAccessCode
   * @param $contributionID
   */
  public static function validateContribution($eWayAccessCode, $contribution, $paymentProcessor) {
    $contributionID = $contribution['id'];
    $isRecurring = (isset($contribution['contribution_recur_id']) && $contribution['contribution_recur_id'] != '') ? TRUE: FALSE;

    $eWayClient = self::getEWayClient($paymentProcessor);
    $transactionResponse = $eWayClient->queryTransaction($eWayAccessCode);

    $hasTransactionFailed = FALSE;
    $transactionNotProcessedYet = FALSE;
    $transactionResponseError = "";

    if ($transactionResponse) {
      $responseErrors = $transactionResponse->getErrors();
      if (count($responseErrors)) {
        $transactionErrors = array();
        foreach ($responseErrors as $responseError) {
          $errorMessage = \Eway\Rapid::getMessage($responseError);
          $transactionErrors[] = $errorMessage;
        }

        $hasTransactionFailed = TRUE;
        $transactionResponseError = implode(",", $transactionErrors);
      }
      else {
        $transactionResponse = $transactionResponse->Transactions[0];
        if ($transactionResponse->TransactionID == '') {
          $transactionNotProcessedYet = TRUE;
        }
        else if(!$transactionResponse->TransactionStatus) {
          $hasTransactionFailed = TRUE;

          $transactionMessages = implode(', ', array_map('\Eway\Rapid::getMessage', explode(', ', $transactionResponse->ResponseMessage)));

          $transactionResponseError = 'Your payment was declined: ' . $transactionMessages;
        }
      }

      if (!$hasTransactionFailed) {
        if ($isRecurring) {
          $customerTokenID = $transactionResponse->TokenCustomerID;
          self::updateRecurringContribution($contribution, $customerTokenID, $paymentProcessor['id']);
        }

        try {
          civicrm_api3('Contribution', 'completetransaction', array(
            'id'                   => $contributionID,
            'payment_processor_id' => $paymentProcessor['id'],
          ));
        }
        catch (CiviCRM_API3_Exception $e) {

        }
      }
    }
    else {
      $hasTransactionFailed = TRUE;
      $transactionResponseError = 'Sorry, Your payment was declined. Extension error code: 1001';
    }

    return array(
      'hasTransactionFailed' => $hasTransactionFailed,
      'transactionNotProcessedYet' => $transactionNotProcessedYet,
      'transactionResponseError'     => $transactionResponseError,
    );

  }

  /**
   * Update recurring contribution with status and token.
   *
   * @param $contribution
   * @param $customerTokenId
   * @throws CRM_Core_Exception
   */
  public static function updateRecurringContribution($contribution, $customerTokenId, $paymentProcessorId){
    //----------------------------------------------------------------------------------------------------
    // Save the eWay customer token in the recurring contribution's processor_id field
    //----------------------------------------------------------------------------------------------------

    $contributionRecurringId = $contribution['contribution_recur_id'];
    $contributionPageId = $contribution['contribution_page_id'];

    try {

      $recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', [
        'id' => $contributionRecurringId,
      ]);

      //----------------------------------------------------------------------------------------------------
      // For monthly payments, set the cycle day according to the submitting page or processor default
      //----------------------------------------------------------------------------------------------------

      $cycle_day = 0;

      if(!empty($contributionPageId) &&
        CRM_Utils_Type::validate($contributionPageId, 'Int', FALSE, ts('Contribution Page')))
      {
        $cd_sql = 'SELECT cycle_day FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
        $cycle_day = CRM_Core_DAO::singleValueQuery($cd_sql, array(1 => array($contributionPageId, 'Int')));
      } else {
        $cd_sql = 'SELECT cycle_day FROM civicrm_ewayrecurring WHERE processor_id = %1';
        $cycle_day = CRM_Core_DAO::singleValueQuery($cd_sql, array(1 => array($paymentProcessorId, 'Int')));
      }

      if(!$cycle_day)
        $cycle_day = 0;

      if (($cd = $cycle_day) > 0 &&
        $recurringContribution['frequency_unit'] == 'month'){
        $d_now = new DateTime();
        $d_next = new DateTime(date("Y-m-$cd 00:00:00"));
        $d_next->modify("+{$recurringContribution['frequency_interval']} " .
          "{$recurringContribution['frequency_unit']}s");
        $next_m = ($d_now->format('m') + $recurringContribution['frequency_interval'] - 1) % 12 + 1;
        if ($next_m != $d_next->format('m')) {
          $daysover = $d_next->format('d');
          $d_next->modify("-{$daysover} days");
        }
        $next_sched = $d_next->format('Y-m-d 00:00:00');
      } else {
        $next_sched = date('Y-m-d 00:00:00',
          strtotime("+{$recurringContribution['frequency_interval']} " .
            "{$recurringContribution['frequency_unit']}s"));
      }

      $recurringContribution['next_sched_contribution_date'] = CRM_Utils_Date::isoToMysql ($next_sched);
      $recurringContribution['cycle_day'] = $cycle_day;
      $recurringContribution['processor_id'] = $customerTokenId;
      $recurringContribution['create_date'] = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
      $recurringContribution['contribution_status_id'] = _contribution_status_id('In Progress');

      civicrm_api3('ContributionRecur', 'create', $recurringContribution);

    } catch (CiviCRM_API3_Exception $e) {

    }
  }

}
