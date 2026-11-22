<?php

use Eway\Rapid\Service\Http as EwayHttp;
use Eway\Rapid\Service\Http\Response as EwayResponse;

/**
 * A scriptable stand-in for the eWAY SDK's HTTP service.
 *
 * It answers every call from canned responses and records the request data
 * so tests can assert on what the extension sent. No request leaves the
 * server. Activate it with install(), and always call uninstall() in tearDown.
 *
 * Responses are queued per SDK method name (the HttpService method, for
 * example "postTransaction" or "getCustomer"). Each call takes the next
 * queued response. Calling a method with nothing queued throws, so a test
 * fails loudly if the code under test makes a gateway call it did not expect.
 *
 * A queued response can be:
 *  - an array (sent as the JSON body with status 200),
 *  - an Eway\Rapid\Service\Http\Response (used as is, for example a connection failure),
 *  - a Closure receiving the request data and returning either of the above.
 *
 * Test plan: seam S1 (developer/TEST_PLAN.md section 2.1).
 */
class CRM_eWAYRecurring_Test_FakeEwayHttp extends EwayHttp {

  /**
   * Every call made, in order.
   *
   * @var array[] Each: ['method' => string, 'args' => array]
   */
  public $requests = [];

  /**
   * @var array<string, array>
   */
  private $queues = [];

  /**
   * @var array<string, mixed>
   */
  private $repeating = [];

  public function __construct() {
    // The real constructor builds a curl wrapper. It is never used here.
    parent::__construct('fake-key', 'fake-password', 'https://fake.invalid/');
  }

  /**
   * Make the extension use this fake for every client it builds, and drop any
   * payment processor objects (and the clients they cached) from earlier tests.
   */
  public function install(): self {
    CRM_eWAYRecurring_Utils::setHttpServiceFactory(function () {
      return $this;
    });
    self::forgetCachedPaymentProcessors();
    return $this;
  }

  public static function uninstall(): void {
    CRM_eWAYRecurring_Utils::setHttpServiceFactory(NULL);
    self::forgetCachedPaymentProcessors();
  }

  /**
   * CRM_Core_Payment_eWAYRecurring keeps one object (and so one cached SDK
   * client) per processor for the whole process. Forget them so each test
   * builds a client that uses the current fake.
   */
  private static function forgetCachedPaymentProcessors(): void {
    $property = new ReflectionProperty(CRM_Core_Payment_eWAYRecurring::class, 'instances');
    $property->setAccessible(TRUE);
    $property->setValue(NULL, []);
  }

  /**
   * Queue a response for one SDK method.
   *
   * @param string $method
   *   HttpService method, for example "postTransaction".
   * @param array|callable|\Eway\Rapid\Service\Http\Response $response
   * @param bool $repeat
   *   Keep answering with this response for every later call as well.
   */
  public function respond(string $method, $response, bool $repeat = FALSE): self {
    if ($repeat) {
      $this->repeating[$method] = $response;
    }
    else {
      $this->queues[$method][] = $response;
    }
    return $this;
  }

  /**
   * @return array[] The recorded calls to one method.
   */
  public function callsTo(string $method): array {
    return array_values(array_filter($this->requests, function ($call) use ($method) {
      return $call['method'] === $method;
    }));
  }

  /**
   * The request data of the most recent call to a method.
   *
   * @return mixed NULL when the method was never called.
   */
  public function lastArgs(string $method) {
    $calls = $this->callsTo($method);
    return $calls ? end($calls)['args'] : NULL;
  }

  private function answer(string $method, array $args): EwayResponse {
    $this->requests[] = ['method' => $method, 'args' => $args];

    if (!empty($this->queues[$method])) {
      $response = array_shift($this->queues[$method]);
    }
    elseif (array_key_exists($method, $this->repeating)) {
      $response = $this->repeating[$method];
    }
    else {
      throw new LogicException("Unexpected eWAY call to $method. Queue a response with respond('$method', ...).");
    }

    if ($response instanceof Closure) {
      $response = $response(...$args);
    }
    if ($response instanceof EwayResponse) {
      return $response;
    }
    return new EwayResponse(200, json_encode($response));
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
