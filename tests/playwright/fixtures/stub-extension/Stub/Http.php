<?php

namespace Civi\EwayRecurringStub;

use Eway\Rapid\Service\Http as EwayHttp;
use Eway\Rapid\Service\Http\Response as EwayResponse;

/**
 * A scripted stand-in for the eWAY SDK's HTTP service.
 *
 * The queue is a list of ['method' => ..., 'response' => [...], 'repeat' => bool] kept in the setting
 * ewayrecurring_stub_queue. A call takes the first entry for its method and removes it unless it repeats. Every call
 * is appended to ewayrecurring_stub_log. A call with nothing queued is logged as unexpected and throws, so a
 * test fails loudly when the code makes a gateway call it did not expect.
 *
 * In a response, the string {{hosted_url}} becomes the address of the fake hosted payment page for that request,
 * carrying the RedirectUrl, CancelUrl and display details so the page served by Playwright can act on them.
 */
class Http extends EwayHttp {

  private const QUEUE = 'ewayrecurring_stub_queue';

  private const LOG = 'ewayrecurring_stub_log';

  private const HOST = 'eway-stub.test';

  /**
   * The AccessCode of the response being built.
   *
   * @var string
   */
  private $lastAccessCode = '';

  public function __construct() {
    // The real constructor builds a curl wrapper. It is never used here.
    parent::__construct('stub-key', 'stub-password', 'https://' . self::HOST . '/api/');
  }

  private function answer(string $method, array $args): EwayResponse {
    $queue = \Civi::settings()->get(self::QUEUE) ?: [];
    $log = \Civi::settings()->get(self::LOG) ?: [];

    $found = NULL;
    foreach ($queue as $index => $entry) {
      if (($entry['method'] ?? NULL) === $method) {
        $found = $entry;
        if (empty($entry['repeat'])) {
          unset($queue[$index]);
        }
        break;
      }
    }

    $log[] = ['method' => $method, 'args' => $args, 'time' => date('c'), 'unexpected' => $found === NULL];
    \Civi::settings()->set(self::LOG, $log);
    \Civi::settings()->set(self::QUEUE, array_values($queue));

    if ($found === NULL) {
      throw new \LogicException("Unexpected eWAY call to $method. Queue a response with stub.respond('$method', ...).");
    }
    // The hosted page address carries the access code the response hands out.
    $this->lastAccessCode = $found['response']['AccessCode'] ?? '';
    $body = $this->expand($found['response'], $args[0] ?? NULL);
    return new EwayResponse(200, json_encode($body));
  }

  /**
   * Replace {{hosted_url}} anywhere in a response.
   *
   * @param mixed $value
   * @param mixed $request The request data the extension sent.
   *
   * @return mixed
   */
  private function expand($value, $request) {
    if (is_array($value)) {
      return array_map(function ($item) use ($request) {
        return $this->expand($item, $request);
      }, $value);
    }
    if ($value === '{{hosted_url}}') {
      return $this->hostedUrl($request);
    }
    return $value;
  }

  private function hostedUrl($request): string {
    $data = json_decode(json_encode($request), TRUE) ?: [];
    $customer = $data['Customer'] ?? [];
    $name = trim(($customer['FirstName'] ?? '') . ' ' . ($customer['LastName'] ?? ''));
    $cents = $data['Payment']['TotalAmount'] ?? NULL;
    return 'https://' . self::HOST . '/pay?' . http_build_query([
      'AccessCode' => $this->lastAccessCode,
      'RedirectUrl' => $data['RedirectUrl'] ?? '',
      'CancelUrl' => $data['CancelUrl'] ?? '',
      'Amount' => $cents === NULL ? '' : number_format($cents / 100, 2, '.', ''),
      'InvoiceReference' => $data['Payment']['InvoiceReference'] ?? '',
      'Customer' => $name,
    ]);
  }

  public function getTransaction($reference) {
    return $this->answer(__FUNCTION__, [$reference]);
  }

  public function getTransactionInvoiceNumber($invoiceNumber) {
    return $this->answer(__FUNCTION__, [$invoiceNumber]);
  }

  public function getTransactionInvoiceReference($invoiceReference) {
    return $this->answer(__FUNCTION__, [$invoiceReference]);
  }

  public function postTransaction($data) {
    return $this->answer(__FUNCTION__, [$data]);
  }

  public function postTransactionRefund($transactionId, $data) {
    return $this->answer(__FUNCTION__, [$transactionId, $data]);
  }

  public function postAccessCodeShared($data) {
    return $this->answer(__FUNCTION__, [$data]);
  }

  public function postAccessCode($data) {
    return $this->answer(__FUNCTION__, [$data]);
  }

  public function getAccessCode($accessCode) {
    return $this->answer(__FUNCTION__, [$accessCode]);
  }

  public function getCustomer($tokenCustomerId) {
    return $this->answer(__FUNCTION__, [$tokenCustomerId]);
  }

  public function getSettlementSearch($query) {
    return $this->answer(__FUNCTION__, [$query]);
  }

}
