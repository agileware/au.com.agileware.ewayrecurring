<?php

/**
 * Thrown by Utils::validateContribution() when eWAY has approved a payment but
 * completing the contribution in CiviCRM then failed. The donor has been
 * charged, so the verification job keeps the queue row and tries again (DEF-78)
 * instead of treating it like a payment that was never made.
 *
 * It extends CRM_Core_Exception so every caller that already catches that
 * keeps working, with the same message.
 */
class CRM_eWAYRecurring_ApprovedPaymentNotCompletedException extends CRM_Core_Exception {

  /**
   * @var string
   */
  private $transactionId;

  public function __construct(string $message, $transactionId = '', ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, [], $previous);
    $this->transactionId = (string) $transactionId;
  }

  /**
   * The eWAY transaction id of the approved payment (may be empty).
   */
  public function getTransactionId(): string {
    return $this->transactionId;
  }

}
