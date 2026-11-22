<?php

use Eway\Rapid\Service\Http\Response as EwayResponse;

/**
 * Builders for the JSON bodies eWAY returns, shaped like the real API, for use
 * with CRM_eWAYRecurring_Test_FakeEwayHttp::respond().
 *
 * The codes follow eWAY's documented response codes: A2000 is "Transaction
 * Approved", D4401 is "Refer to Issuer", V6010 is "Invalid TransactionType".
 */
class CRM_eWAYRecurring_Test_FakeEwayResponses {

  /**
   * postAccessCodeShared: a Responsive Shared Page was created.
   */
  public static function sharedPageCreated(string $accessCode = 'ACCESSCODE0001', ?string $url = NULL): array {
    return [
      'AccessCode' => $accessCode,
      'SharedPaymentUrl' => $url ?? 'https://eway-stub.test/pay?AccessCode=' . $accessCode,
      'FormActionURL' => 'https://eway-stub.test/process',
      'Errors' => NULL,
    ];
  }

  /**
   * postTransaction (Direct, MOTO): the payment was approved.
   */
  public static function paymentApproved(int $transactionId = 10000001, array $extra = []): array {
    return $extra + [
      'AuthorisationCode' => '123456',
      'ResponseCode' => '00',
      'ResponseMessage' => 'A2000',
      'TransactionID' => $transactionId,
      'TransactionStatus' => TRUE,
      'TransactionType' => 'MOTO',
      'Errors' => NULL,
    ];
  }

  /**
   * postTransaction (Direct, MOTO): the card was declined.
   */
  public static function paymentDeclined(string $message = 'D4401', int $transactionId = 10000002): array {
    return [
      'AuthorisationCode' => '',
      'ResponseCode' => '01',
      'ResponseMessage' => $message,
      'TransactionID' => $transactionId,
      'TransactionStatus' => FALSE,
      'TransactionType' => 'MOTO',
      'Errors' => NULL,
    ];
  }

  /**
   * Any call: eWAY rejected the request with one or more error codes.
   *
   * @param string $errors
   *   Comma separated, for example "V6010,V6011".
   */
  public static function errors(string $errors): array {
    return ['Errors' => $errors];
  }

  /**
   * An empty body with a transport level failure, as when eWAY cannot be reached.
   */
  public static function connectionFailure(): EwayResponse {
    return new EwayResponse(0, '', 'Could not connect to eWAY');
  }

  /**
   * getTransaction: the access code has a transaction. Pass overrides for any
   * field, for example ['TransactionStatus' => FALSE, 'ResponseMessage' => 'D4401'].
   */
  public static function transactionFound(array $overrides = []): array {
    $transaction = $overrides + [
      'TransactionID' => 10000001,
      'TransactionStatus' => TRUE,
      'ResponseCode' => '00',
      'ResponseMessage' => 'A2000',
      'TokenCustomerID' => '912345678901',
      'TotalAmount' => 1000,
    ];
    return ['Transactions' => [$transaction], 'Errors' => NULL];
  }

  /**
   * getTransaction: the customer has not been to the hosted page yet, so eWAY
   * has no transaction for the access code.
   */
  public static function transactionNotProcessedYet(): array {
    return self::transactionFound([
      'TransactionID' => '',
      'TransactionStatus' => FALSE,
      'ResponseMessage' => '',
    ]);
  }

  /**
   * getCustomer: a saved card.
   *
   * eWAY returns the expiry year as two digits (for example "31").
   */
  public static function customerFound(string $token = '912345678901', array $card = [], ?string $reference = NULL): array {
    return [
      'Customers' => [
        [
          'TokenCustomerID' => $token,
          'Reference' => $reference ?? 'civi-1',
          'FirstName' => 'Test',
          'LastName' => 'Donor',
          'CardDetails' => $card + [
            'Number' => '444433XXXXXX1111',
            'Name' => 'Test Donor',
            'ExpiryMonth' => '12',
            'ExpiryYear' => '31',
          ],
        ],
      ],
      'Errors' => NULL,
    ];
  }

}
