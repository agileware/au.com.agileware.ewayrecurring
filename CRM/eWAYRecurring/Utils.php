<?php

use CRM_eWAYRecurring_ExtensionUtil as E;

class CRM_eWAYRecurring_Utils {

  public const STATUS_IN_QUEUE = 0;

  public const STATUS_SUCCESS = 1;

  public const STATUS_FAILED = 2;

  public const MAX_TRIES = 7; // Giving up after 7 tries is ~ 2h in a
                              // run-every-15-minutes scenario.
                              // Counter starts from zero.

  /**
   * How many times the job tries a payment that eWAY approved but that could not be completed in CiviCRM
   * (DEF-78): 96 tries is about 24 hours at one run every 15 minutes, long enough to ride out an outage.
   * Unlike MAX_TRIES it never marks the contribution Failed, because the donor has been charged.
   */
  public const MAX_COMPLETION_TRIES = 96;

  /**
   * The start of failed_message on a queue row that holds an approved payment that could not be completed. The row
   * is recognised by it, so no schema change is needed.
   */
  public const APPROVED_NOT_COMPLETED_PREFIX = 'Approved by eWAY but could not be completed: ';

  /**
   * Validate pending transactions.
   *
   * @param array $params
   *
   * @return array
   * @throws CRM_Core_Exception
   */
  public function validatePendingTransactions($params = []) {
    // Fetch all transactions to validate
    $transactionsToValidate = civicrm_api3('EwayContributionTransactions', 'get', [
      'status' => self::STATUS_IN_QUEUE,
      'tries' => ['<' => self::MAX_COMPLETION_TRIES],
      'sequential' => TRUE,
    ]);

    $transactionsToValidate = $transactionsToValidate['values'];

    // Include eWay SDK.
    require_once E::path('vendor/autoload.php');

    $apiResponse = [
      'failed' => 0,
      'success' => 0,
      'not_processed' => 0,
      'deleted' => 0,
      'held' => 0,
    ];

    foreach ($transactionsToValidate as $transactionToValidate) {
      $contributionId = $transactionToValidate['contribution_id'];
      $triesBefore = (int) ($transactionToValidate['tries'] ?? 0);
      $wasHeld = self::isApprovedNotCompleted($transactionToValidate);
      if (!$wasHeld && $triesBefore >= self::MAX_TRIES) {
        // Out of tries: given up on below.
        continue;
      }
      // Set once the contribution and processor are found. A failure after that point is not "not found" (DEF-78).
      $checking = FALSE;
      try {

        // Fetch contribution
        $contribution = civicrm_api3('Contribution', 'getsingle', [
          'id' => $contributionId,
          'contribution_status_id' => 'Pending',
          'return' => [
            'contribution_page_id',
            'contribution_recur_id',
            'is_test',
            'contact_id',
          ],
        ]);

        // Fetch payment processor
        $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', [
          'id' => $transactionToValidate['payment_processor_id'],
        ]);

        // Validate the transaction
        $checking = TRUE;
        $response = self::validateContribution($transactionToValidate['access_code'], $contribution, $paymentProcessor);
        $transactionToValidate['tries']++;

        if ($response['hasTransactionFailed']) {
          // update recurring
          if ($contribution['contribution_recur_id']) {
            $bao = new CRM_Contribute_BAO_ContributionRecur();
            $bao->id = $contribution['contribution_recur_id'];
            $bao->find();
            CRM_Core_Payment_eWAYRecurring::getInstance($paymentProcessor)
              ->mark_recurring_contribution_failed($bao);
          }
          $transactionToValidate['status'] = self::STATUS_FAILED;
          $transactionToValidate['failed_message'] = $response['transactionResponseError'];
          $apiResponse['failed']++;
        }
        else {
          if (!$response['transactionNotProcessedYet']) {
            $transactionToValidate['status'] = self::STATUS_SUCCESS;
            if ($wasHeld) {
              // Completed on a retry: the "could not be completed" message no longer applies.
              $transactionToValidate['failed_message'] = '';
            }
            $apiResponse['success']++;
          }
          else {
            $apiResponse['not_processed']++;
          }
        }

        // Update the transaction
        civicrm_api3('EwayContributionTransactions', 'create', $transactionToValidate);

      } catch (CRM_eWAYRecurring_ApprovedPaymentNotCompletedException $e) {
        // eWAY approved the payment and completing it failed. The donor has been charged, so keep the row and try
        // again on a later run, up to MAX_COMPLETION_TRIES (DEF-78).
        self::holdApprovedPayment($transactionToValidate, $triesBefore, $wasHeld, $contribution ?? [], $e);
        $apiResponse['held']++;
      } catch (CRM_Core_Exception $e) {
        if ($checking) {
          // Not a missing contribution or processor: eWAY was asked and something failed afterwards, for example
          // completing the contribution of an approved payment. The row is still removed below, as before, so say what
          // happened and for which contribution (DEF-78).
          Civi::log()->error('eWAY Recurring: the verification job could not finish checking the payment for contribution {contribution_id} (queue row {id}, payment processor {processor_id}), and removed it from the queue: {msg}', [
            'contribution_id' => $contributionId,
            'id' => $transactionToValidate['id'],
            'processor_id' => $transactionToValidate['payment_processor_id'],
            'msg' => $e->getMessage(),
          ]);
        }
        // Contribution/Payment Processor not found, delete the transaction.
        civicrm_api3('EwayContributionTransactions', 'delete', [
          'id' => $transactionToValidate['id'],
        ]);
        $apiResponse['deleted']++;
      }
    }

    // Mark all pending transactions that have exceeded the retry limit as failed
    $transactionsPendingMaxTries = civicrm_api3('EwayContributionTransactions', 'get', [
      'status' => self::STATUS_IN_QUEUE,
      'tries' => ['>=' => self::MAX_TRIES],
      'sequential' => TRUE,
    ]);

    $transactionsPendingMaxTries = $transactionsPendingMaxTries['values'];

    foreach ($transactionsPendingMaxTries as $transactionPendingMaxTries) {
      $contributionID = $transactionPendingMaxTries['contribution_id'];
      if (self::isApprovedNotCompleted($transactionPendingMaxTries)) {
        // An approved payment that could not be completed has its own, longer limit, and is never marked Failed.
        if ((int) $transactionPendingMaxTries['tries'] >= self::MAX_COMPLETION_TRIES) {
          self::giveUpOnApprovedPayment($transactionPendingMaxTries);
        }
        continue;
      }
      try {
        // Mark contribution as failed
        civicrm_api3('Contribution', 'create', [
          'id' => $contributionID,
          'contribution_status_id' => 'Failed',
        ]);

        // Update queue accordingly
        $transactionPendingMaxTries['status'] = self::STATUS_FAILED;
        civicrm_api3('EwayContributionTransactions', 'create', $transactionPendingMaxTries);

      } catch (CRM_Core_Exception $e) {
        // The contribution comes from the queue row, which cannot outlive it, so this is not "not found": the
        // contribution stays Pending and the row stays in the queue to be tried again every run (DEF-78).
        Civi::log()->error('eWAY Recurring: the verification job could not mark contribution {contribution_id} Failed after {tries} tries (queue row {id}): {msg}', [
          'contribution_id' => $contributionID,
          'tries' => $transactionPendingMaxTries['tries'],
          'id' => $transactionPendingMaxTries['id'],
          'msg' => $e->getMessage(),
        ]);
      }
    }

    return $apiResponse;

  }

  /**
   * Is this queue row one that holds an approved payment that could not be completed?
   */
  public static function isApprovedNotCompleted(array $queueRow): bool {
    return strpos((string) ($queueRow['failed_message'] ?? ''), self::APPROVED_NOT_COMPLETED_PREFIX) === 0;
  }

  /**
   * Keep the queue row of an approved payment that could not be completed, count the try, and say so in the log and
   * (on the first failure) in a note on the contribution (DEF-78).
   */
  private static function holdApprovedPayment(array $row, int $triesBefore, bool $wasHeld, array $contribution, CRM_eWAYRecurring_ApprovedPaymentNotCompletedException $e): void {
    $row['tries'] = $triesBefore + 1;
    $row['status'] = self::STATUS_IN_QUEUE;
    $row['failed_message'] = self::APPROVED_NOT_COMPLETED_PREFIX . $e->getMessage();
    try {
      civicrm_api3('EwayContributionTransactions', 'create', $row);
    }
    catch (CRM_Core_Exception $saveFailure) {
      Civi::log()->error('eWAY Recurring: could not save the retry count of queue row {id}: {msg}', [
        'id' => $row['id'],
        'msg' => $saveFailure->getMessage(),
      ]);
    }
    Civi::log()->error('eWAY Recurring: eWAY approved the payment for contribution {contribution_id} but it could not be completed (queue row {id}, payment processor {processor_id}, try {tries} of {max}), it will be tried again: {msg}', [
      'contribution_id' => $row['contribution_id'],
      'id' => $row['id'],
      'processor_id' => $row['payment_processor_id'],
      'tries' => $row['tries'],
      'max' => self::MAX_COMPLETION_TRIES,
      'msg' => $e->getMessage(),
    ]);
    if (!$wasHeld) {
      self::addContributionNote(
        (int) $row['contribution_id'],
        $contribution['contact_id'] ?? NULL,
        E::ts('eWAY approved this payment but it could not be completed'),
        E::ts('eWAY approved this payment, but CiviCRM could not mark the contribution Completed. The donor has been charged. eWAY transaction ID: %1. Reason: %2. The eWAY Recurring verification job will try again on each run for about a day. If it still fails, see "Payments eWay approved but CiviCRM could not complete" in the eWay Recurring documentation: check in the eWAY Business Centre that the payment was taken, fix the reason shown here, then complete the contribution by hand (set it to Completed and enter the eWAY transaction ID).', [
          1 => $e->getTransactionId() !== '' ? $e->getTransactionId() : E::ts('not known'),
          2 => $e->getMessage(),
        ])
      );
    }
  }

  /**
   * The job has used up MAX_COMPLETION_TRIES on an approved payment: stop trying, leave the contribution Pending
   * (the donor was charged, so it must not be marked Failed), and leave a note for staff (DEF-78).
   */
  private static function giveUpOnApprovedPayment(array $row): void {
    $row['status'] = self::STATUS_FAILED;
    try {
      civicrm_api3('EwayContributionTransactions', 'create', $row);
    }
    catch (CRM_Core_Exception $e) {
      // Left in the queue: the next run tries to give up again.
      Civi::log()->error('eWAY Recurring: could not stop the retries of queue row {id}: {msg}', [
        'id' => $row['id'],
        'msg' => $e->getMessage(),
      ]);
      return;
    }
    Civi::log()->error('eWAY Recurring: gave up completing contribution {contribution_id} after {tries} tries (queue row {id}); eWAY approved the payment, so the contribution was left Pending and needs completing by hand: {msg}', [
      'contribution_id' => $row['contribution_id'],
      'tries' => $row['tries'],
      'id' => $row['id'],
      'msg' => $row['failed_message'],
    ]);
    try {
      $contactId = civicrm_api3('Contribution', 'getvalue', [
        'id' => $row['contribution_id'],
        'return' => 'contact_id',
      ]);
    }
    catch (CRM_Core_Exception $e) {
      return;
    }
    self::addContributionNote(
      (int) $row['contribution_id'],
      $contactId,
      E::ts('eWAY approved this payment but it was never completed: action needed'),
      E::ts('The verification job gave up after %1 tries. eWAY approved this payment, so the donor has been charged, but the contribution is still Pending. Check in the eWAY Business Centre that the payment was taken, fix the reason below, then complete the contribution by hand (set it to Completed and enter the eWAY transaction ID). Last message: %2', [
        1 => $row['tries'],
        2 => $row['failed_message'],
      ])
    );
  }

  /**
   * Add a note to a contribution. A failure is logged, never thrown: the note is a courtesy to staff.
   */
  private static function addContributionNote(int $contributionId, $contactId, string $subject, string $note): void {
    if (!$contactId) {
      return;
    }
    try {
      civicrm_api3('Note', 'create', [
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $contributionId,
        'contact_id' => $contactId,
        'subject' => $subject,
        'note' => $note,
      ]);
    }
    catch (CRM_Core_Exception $e) {
      Civi::log()->warning('eWAY Recurring: could not add a note to contribution {contribution_id}: {msg}', [
        'contribution_id' => $contributionId,
        'msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Optional factory for the SDK's HTTP service, used by automated tests.
   *
   * @var callable|null
   *   function (array $paymentProcessor): \Eway\Rapid\Contract\HttpService
   */
  private static $httpServiceFactory = NULL;

  /**
   * Replace the SDK's HTTP service for every client this extension builds.
   *
   * This is a test seam and is never set in normal operation: with no factory
   * registered the client talks to eWAY exactly as before. Tests register a
   * fake that answers from canned responses so no request leaves the server.
   * Pass NULL to go back to the real service.
   *
   * @param callable|null $factory
   *   Receives the payment processor array and returns an HttpService.
   */
  public static function setHttpServiceFactory(?callable $factory): void {
    self::$httpServiceFactory = $factory;
  }

  /**
   * Create eWay client using credentials from payment processor.
   *
   * This is the only place an eWAY client is built.
   *
   * @return \Eway\Rapid\Contract\Client
   */
  public static function getEWayClient($paymentProcessor) {
    $eWayApiKey = $paymentProcessor['user_name'];   // eWay Api Key
    $eWayApiPassword = $paymentProcessor['password']; // eWay Api Password
    $eWayEndPoint = ($paymentProcessor['is_test']) ? \Eway\Rapid\Client::MODE_SANDBOX : \Eway\Rapid\Client::MODE_PRODUCTION;

    $eWayClient = \Eway\Rapid::createClient($eWayApiKey, $eWayApiPassword, $eWayEndPoint);

    if (self::$httpServiceFactory !== NULL) {
      $eWayClient->setHttpService(call_user_func(self::$httpServiceFactory, $paymentProcessor));
    }

    return $eWayClient;
  }

  /**
   * Complete eWay transaction based on access code.
   *
   * @param $accessCode
   */
  public static function completeEWayTransaction($accessCode) {
    try {
      $eWayTransaction = civicrm_api3('EwayContributionTransactions', 'getsingle', [
        'access_code' => $accessCode,
      ]);
      $eWayTransaction['status'] = self::STATUS_SUCCESS;
      civicrm_api3('EwayContributionTransactions', 'create', $eWayTransaction);
    } catch (CRM_Core_Exception $e) {
      // Transaction not found.
    }
  }

  /**
   * Validating eWay Access code.
   *
   * @param $eWayAccessCode
   * @param $paymentProcessor
   *
   * @return array
   */
  public static function validateEwayAccessCode($eWayAccessCode, $paymentProcessor, $validatingUpdateToken = FALSE) {
    $eWayClient = self::getEWayClient($paymentProcessor);
    $transactionResponse = $eWayClient->queryTransaction($eWayAccessCode);

    $hasTransactionFailed = FALSE;
    $transactionNotProcessedYet = FALSE;
    $transactionResponseError = "";
    $transactionID = "";

    if ($transactionResponse) {

      if (isset($transactionResponse->Transactions) && count($transactionResponse->Transactions) > 0) {
        $transactionID = $transactionResponse->Transactions[0]->TransactionID;
      }

      $responseErrors = $transactionResponse->getErrors();

      if (count($responseErrors)) {
        $transactionErrors = [];
        foreach ($responseErrors as $responseError) {
          $errorMessage = \Eway\Rapid::getMessage($responseError);
          $transactionErrors[] = $errorMessage;
        }

        $hasTransactionFailed = TRUE;
        $transactionResponseError = implode(",", $transactionErrors);
      }
      else {
        if (!$validatingUpdateToken) {
          // An answer with no error and no transaction at all (an access code eWAY has nothing for, which the visitor can
          // make up) is the same as one with an empty transaction: there is no result yet.
          if (empty($transactionResponse->Transactions[0])) {
            $transactionNotProcessedYet = TRUE;
          }
          else {
            $transactionResponse = $transactionResponse->Transactions[0];
            if ($transactionResponse->TransactionID == '') {
              $transactionNotProcessedYet = TRUE;
            } elseif (!$transactionResponse->TransactionStatus) {
              $hasTransactionFailed = TRUE;
              $transactionMessages = implode(', ', array_map('\Eway\Rapid::getMessage', explode(', ', (string) $transactionResponse->ResponseMessage)));
              $transactionResponseError = 'Your payment was declined: ' . $transactionMessages;
            }
          }
        }
      }
    }
    else {
      $hasTransactionFailed = TRUE;
      $transactionResponseError = 'Sorry, Your payment was declined. Extension error code: 1001';
    }

    return [
      'hasTransactionFailed' => $hasTransactionFailed,
      'transactionNotProcessedYet' => $transactionNotProcessedYet,
      'transactionResponseError' => $transactionResponseError,
      'transactionID' => $transactionID,
      'transactionResponse' => $transactionResponse,
      'eWayClient' => $eWayClient,
    ];
  }

  /**
   * Update customer token in DB.
   *
   * @param $response
   * @param $recurringContribution
   *
   * @return |null
   * @throws CRM_Core_Exception
   */
  public static function updateCustomerDetails($response, $recurringContribution) {
    $transactionResponse = $response['transactionResponse'];
    $eWayClient = $response['eWayClient'];

    $customerTokenID = self::getCustomerTokenFromResponse($transactionResponse);
    $customerResponse = $eWayClient->queryCustomer($customerTokenID);

    if (isset($customerResponse->Customers) && count($customerResponse->Customers)) {
      $customerResponse = $customerResponse->Customers[0];
    }
    else {
      $customerResponse = NULL;
    }

    $paymentTokenID = NULL;
    if ($customerResponse && isset($customerResponse->CardDetails)) {
      $ccNumber = $customerResponse->CardDetails->Number;
      $ccName = $customerResponse->CardDetails->Name;
      $ccExpMonth = $customerResponse->CardDetails->ExpiryMonth;
      $ccExpYear = $customerResponse->CardDetails->ExpiryYear;

      $expiryDate = new DateTime();
      $expiryDate->setDate($ccExpYear, $ccExpMonth, 1);
      $expiryDate = $expiryDate->format("Y-m-t");

      $params = [
        'contact_id' => $recurringContribution['contact_id'],
        'payment_processor_id' => $recurringContribution['payment_processor_id'],
        'token' => $customerTokenID,
        'created_date' => date("Y-m-d"),
        'created_id' => CRM_Core_Session::getLoggedInContactID(),
        'expiry_date' => $expiryDate,
        'billing_first_name' => $ccName,
        'masked_account_number' => $ccNumber
      ];

      if (isset($recurringContribution['payment_token_id']) && !empty($recurringContribution['payment_token_id'])) {
        $params['id'] = $recurringContribution['payment_token_id'];
      }
      else {
        // A new recurring record has no card yet. Use the same dedupe rule as saveToken(): the same contact and
        // payment processor with the same token or the same masked card number is the same card, so a returning
        // donor does not collect a duplicate row each time.
        $sameCard = \Civi\Api4\PaymentToken::get(FALSE)
          ->addSelect('id')
          ->addWhere('contact_id', '=', $recurringContribution['contact_id'])
          ->addWhere('payment_processor_id', '=', $recurringContribution['payment_processor_id'])
          ->addOrderBy('id', 'ASC')
          ->setLimit(1);
        if ($customerTokenID !== '') {
          $sameCard->addClause('OR', ['token', '=', $customerTokenID], ['masked_account_number', '=', $ccNumber]);
        }
        else {
          $sameCard->addWhere('masked_account_number', '=', $ccNumber);
        }
        $existing = $sameCard->execute()->first();
        if ($existing) {
          $params['id'] = $existing['id'];
        }
      }
      $paymentToken = civicrm_api3('PaymentToken', 'create', $params);
      $paymentTokenID = $paymentToken['id'];
    }

    return $paymentTokenID;
  }

  /**
   * Get customer token from response.
   *
   * @param $transactionResponse
   *
   * @return string
   */
  private static function getCustomerTokenFromResponse($transactionResponse) {
    if (isset($transactionResponse->TokenCustomerID) && $transactionResponse->TokenCustomerID != "") {
      return $transactionResponse->TokenCustomerID;
    }

    if (isset($transactionResponse->Transactions[0]) && isset($transactionResponse->Transactions[0]->TokenCustomerID) && !empty($transactionResponse->Transactions[0]->TokenCustomerID)) {
      return $transactionResponse->Transactions[0]->TokenCustomerID;
    }

    return "";
  }

  /**
   * Is this payment processor one of this extension's (an eWAY processor)?
   */
  public static function isEwayProcessor(int $paymentProcessorId): bool {
    return \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('id')
      ->addWhere('id', '=', $paymentProcessorId)
      ->addWhere('payment_processor_type_id:name', '=', 'eWay_Recurring')
      ->execute()
      ->count() > 0;
  }

  /**
   * Refuse an eWAY transaction that is not the one made for this contribution.
   *
   * doPayment() sends eWAY the contribution's invoice id as the InvoiceReference and the amount in cents, and eWAY
   * returns both on the transaction. Without this check an approved access code from any other payment (a small one
   * of the visitor's own) would complete any Pending contribution whose invoice id is known. A response with no
   * InvoiceReference (an error answer, or a stub) carries nothing to compare and is left to the other checks.
   *
   * @param object $transaction
   *   The transaction from eWAY, or the whole response when eWAY answered with errors.
   * @param int $contributionID
   *
   * @throws \CRM_Core_Exception When the transaction belongs to another invoice or has another amount.
   */
  private static function assertTransactionIsForContribution($transaction, $contributionID) {
    if (!is_object($transaction) || !isset($transaction->InvoiceReference) || (string) $transaction->InvoiceReference === '') {
      return;
    }

    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contributionID,
      'return' => ['invoice_id', 'total_amount'],
    ]);
    // Built the way doPayment() builds the reference it sends.
    $expectedReference = substr(trim(self::sanitizeToAscii($contribution['invoice_id'] ?? '')), 0, 50);
    if ((string) $transaction->InvoiceReference !== $expectedReference) {
      Civi::log()->warning('eWAY return refused: the transaction is for another invoice (contribution {id})', ['id' => $contributionID]);
      throw new CRM_Core_Exception('The eWAY transaction does not belong to this contribution.');
    }

    if (isset($transaction->TotalAmount) && is_numeric($transaction->TotalAmount)
      && (int) $transaction->TotalAmount !== (int) round(((float) $contribution['total_amount']) * 100)) {
      Civi::log()->warning('eWAY return refused: the transaction amount differs from the contribution (contribution {id})', ['id' => $contributionID]);
      throw new CRM_Core_Exception('The eWAY transaction does not match the amount of this contribution.');
    }
  }

  /**
   * Validate contribution on successful response.
   *
   * @param $eWayAccessCode
   * @param $contributionID
   */
  public static function validateContribution($eWayAccessCode, $contribution, $paymentProcessor) {

    $contributionID = $contribution['id'];
    $isRecurring = (isset($contribution['contribution_recur_id']) && $contribution['contribution_recur_id'] != '') ? TRUE : FALSE;

    $accessCodeResponse = self::validateEwayAccessCode($eWayAccessCode, $paymentProcessor);

    $hasTransactionFailed = $accessCodeResponse['hasTransactionFailed'];
    $transactionNotProcessedYet = $accessCodeResponse['transactionNotProcessedYet'];
    $transactionResponseError = $accessCodeResponse['transactionResponseError'];
    $transactionID = $accessCodeResponse['transactionID'];
    $transactionResponse = $accessCodeResponse['transactionResponse'];
    $eWayClient = $accessCodeResponse['eWayClient'];

    // The access code comes from the address the visitor's browser came back with, so check that eWAY's transaction
    // really is the one for this contribution before anything is completed or failed.
    if (!$transactionNotProcessedYet) {
      self::assertTransactionIsForContribution($transactionResponse, $contributionID);
    }

    if (!$transactionNotProcessedYet) {
      if (!$hasTransactionFailed) {
        try {
          if ($isRecurring) {
            $customerTokenID = self::getCustomerTokenFromResponse($transactionResponse);
            self::updateRecurringContribution($contribution, $customerTokenID, $paymentProcessor['id'], $accessCodeResponse, $transactionID);
          }

          $result = civicrm_api3('Contribution', 'get', [
            'sequential' => 1,
            'contribution_status_id' => 'Pending',
            'return' => ['receipt_date', 'contribution_status_id'],
            'id' => $contributionID,
          ]);

          // Only complete pending transactions
          if($result['count'] > 0) {
            // check if receipt should be sent
            $result = civicrm_api3('EwayContributionTransactions', 'get', [
              'sequential' => 1,
              'return' => ['is_email_receipt'],
              'contribution_id' => $contributionID,
            ]);
            $send_email = $result['values'][0]['is_email_receipt'];

            civicrm_api3('Contribution', 'completetransaction', [
              'id' => $contributionID,
              'trxn_id' => $transactionID,
              'payment_processor_id' => $paymentProcessor['id'],
              'is_email_receipt' => $send_email,
            ]);
          }
        }
        catch (CRM_eWAYRecurring_ApprovedPaymentNotCompletedException $e) {
          throw $e;
        }
        catch (CRM_Core_Exception $e) {
          // eWAY approved the payment, so say so: the verification job keeps the queue row and tries again (DEF-78).
          throw new CRM_eWAYRecurring_ApprovedPaymentNotCompletedException($e->getMessage(), $transactionID, $e);
        }
      }
      else {
        civicrm_api3('Contribution', 'create', [
          'id' => $contributionID,
          'trxn_id' => $transactionID,
          'payment_processor_id' => $paymentProcessor['id'],
          'is_email_receipt' => 0,
          'contribution_status_id' => 'Failed',
        ]);
      }
    }

    return [
      'hasTransactionFailed' => $hasTransactionFailed,
      'contributionId' => $contributionID,
      'transactionId' => $transactionID,
      'transactionNotProcessedYet' => $transactionNotProcessedYet,
      'transactionResponseError' => $transactionResponseError,
    ];
  }

  /**
   * Delete recurring contribution if transaction failed.
   *
   * @param $contribution
   */
  private static function deleteRecurringContribution($contribution) {
    $contributionRecurringId = $contribution['contribution_recur_id'];
    try {
      civicrm_api3('ContributionRecur', 'delete', [
        'id' => $contributionRecurringId,
      ]);
    } catch (CRM_Core_Exception $e) {
      // Recurring contribution not found. Skip!
    }
  }

  /**
   * Update recurring contribution with status and token.
   *
   * @param $contribution
   * @param $customerTokenId
   *
   * @throws CRM_Core_Exception
   */
  private static function updateRecurringContribution($contribution, $customerTokenId, $paymentProcessorId, $accessCodeResponse, $transactionID) {
    //----------------------------------------------------------------------------------------------------
    // Save the eWay customer token in the recurring contribution's processor_id field
    //----------------------------------------------------------------------------------------------------

    $contributionRecurringId = $contribution['contribution_recur_id'];
    $contributionPageId = $contribution['contribution_page_id'];

    try {

      $recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', [
        'id' => $contributionRecurringId,
      ]);

      $next_sched = date('Y-m-d 00:00:00',
        strtotime("+{$recurringContribution['frequency_interval']} " .
          "{$recurringContribution['frequency_unit']}s"));

      $paymentTokenID = self::updateCustomerDetails($accessCodeResponse, $recurringContribution);

      $recurringContribution['next_sched_contribution_date'] = CRM_Utils_Date::isoToMysql($next_sched);
      $recurringContribution['processor_id'] = $customerTokenId;
      $recurringContribution['payment_token_id'] = $paymentTokenID;
      $recurringContribution['create_date'] = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
      $recurringContribution['contribution_status_id'] = self::contribution_status_id('In Progress', TRUE);
      $recurringContribution['trxn_id'] = $transactionID;

      civicrm_api3('ContributionRecur', 'create', $recurringContribution);
    }
    catch (CRM_Core_Exception $e) {
      // The first payment has already been taken by eWAY, so this must not stop the contribution being completed, and
      // an error page now would be worse. But the recurring record is left without its token, card and schedule date
      // (so it can never be charged again), so say so (DEF-77). Only ids and the exception's message are logged, never card or credential data.
      Civi::log()->error('eWAY Recurring: the first payment was taken but the recurring contribution {recur_id} could not be started (contribution {contribution_id}, payment processor {processor_id}): {msg}', [
        'recur_id' => $contributionRecurringId,
        'contribution_id' => $contribution['id'] ?? NULL,
        'processor_id' => $paymentProcessorId,
        'msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Validate eWay contribution by AccessCode, Invoice ID and Payment Processor.
   *
   * @param $paymentProcessor
   * @param $invoiceID
   *
   * @return array|null
   * @throws CRM_Core_Exception
   * @throws CRM_Core_Exception
   */
  public static function validateEwayContribution($paymentProcessor, $invoiceID) {
    if ($paymentProcessor instanceof CRM_Core_Payment_eWAYRecurring) {
      // The invoice id comes from the address. APIv3 reads a "%" in a plain string as a wildcard, so ask for an exact
      // match: otherwise a visitor could name a contribution by the start of its invoice id and have a payment they
      // know nothing about completed or failed (DEF-70).
      $invoiceID = is_string($invoiceID) ? $invoiceID : '';
      $contribution = civicrm_api3('Contribution', 'get', [
        'invoice_id' => ['=' => $invoiceID],
        'sequential' => TRUE,
        'return' => [
          'contribution_page_id',
          'contribution_recur_id',
          'total_amount',
          'is_test',
          'invoice_id',
        ],
        'is_test' => ($paymentProcessor->_mode == 'test') ? 1 : 0,
        'options' => ['limit' => 5],
      ]);
      // The database compares text without regard to case or trailing spaces; the id has to match exactly.
      $contribution['values'] = array_values(array_filter(
        $contribution['values'],
        function ($row) use ($invoiceID) {
          return $invoiceID !== '' && ($row['invoice_id'] ?? NULL) === $invoiceID;
        }
      ));

      if (count($contribution['values']) > 0 && $contribution['values'][0]['total_amount'] > 0) {
        // Include eWay SDK.
        require_once E::path('vendor/autoload.php');
        $store = NULL;

        $contribution = $contribution['values'][0];
        // @TODO $form is an undefined variable
        $eWayAccessCode = CRM_Utils_Request::retrieve('AccessCode', 'String', $store, FALSE, "");
        $qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $store, FALSE, "");

        $paymentProcessor->validateContribution($eWayAccessCode, $contribution, $qfKey, $paymentProcessor->getPaymentProcessor());

        return [
          'contribution' => $contribution,
        ];
      }
    }
    return NULL;
  }

  public static function contribution_status_id($name, $for_recur = FALSE) {
    $baoName = $for_recur ? 'CRM_Contribute_BAO_ContributionRecur' : 'CRM_Contribute_BAO_Contribution';
    return \CRM_Core_PseudoConstant::getKey($baoName, 'contribution_status_id', $name);
  }

  /** 
   * Sanitizer Function to remove non-ASCII characters
   * 
   * @param string $string
   * @return string
   */
  public static function sanitizeToAscii($string) {
    // empty() would also drop the string "0".
    if ($string === NULL || $string === FALSE || $string === '') {
      return '';
    }
    $string = (string) $string;

    if (function_exists('iconv')) {
      $string = iconv('UTF-8', 'ASCII//TRANSLIT', $string);

    }
    return preg_replace('/[^\x20-\x7E]/', '', $string);
  }
}
