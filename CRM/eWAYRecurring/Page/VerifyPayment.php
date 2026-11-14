<?php

class CRM_eWAYRecurring_Page_VerifyPayment extends CRM_Core_Page {

  public function run() {
    $store = NULL;

    $contributionInvoiceID = CRM_Utils_Request::retrieve('contributionInvoiceID', 'String', $store, FALSE, "");
    $paymentProcessorID = CRM_Utils_Request::retrieve('paymentProcessorID', 'String', $store, FALSE, "0");
    $qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $store, FALSE, "");
    $component = CRM_Utils_Request::retrieve('component', 'String', $store, FALSE, "");

    $outcome = $this->resolve($contributionInvoiceID, $paymentProcessorID, $qfKey, $component);

    if (!isset($outcome['redirect'])) {
      header("HTTP/1.1 400 Bad Request");
      die();
    }

    CRM_Utils_System::redirect($outcome['redirect']);
  }

  /**
   * Decide what the return page does, without sending anything to the browser.
   *
   * The only thing that can leave this method early is the failure redirect
   * inside CRM_eWAYRecurring_Utils::validateEwayContribution(), which redirects
   * and exits when eWAY declined the payment.
   *
   * @param string $contributionInvoiceID
   * @param string $paymentProcessorID
   * @param string $qfKey
   * @param string $component
   *   "event" or any other value for a contribution page.
   *
   * @return array
   *   ['redirect' => URL] when the payment succeeded, or ['status' => 400]
   *   when the request is not a valid return from eWAY.
   */
  public function resolve($contributionInvoiceID, $paymentProcessorID, $qfKey, $component): array {
    // Missing any parameter should be a bad request
    if (empty($contributionInvoiceID) || empty($paymentProcessorID) || empty($component)) {
      return ['status' => 400];
    }

    $paymentProcessorInfo = civicrm_api3('PaymentProcessor', 'get', [
      'id' => $paymentProcessorID,
      'sequential' => 1,
    ]);
    $paymentProcessorInfo = $paymentProcessorInfo['values'];

    if (count($paymentProcessorInfo) > 0) {
      $paymentProcessorInfo = $paymentProcessorInfo[0];
      $paymentProcessor = new CRM_Core_Payment_eWAYRecurring(($paymentProcessorInfo['is_test']) ? 'test' : 'live', $paymentProcessorInfo);

      $response = NULL;
      try {
        // This function will do redirect if the payment failed
        $response = CRM_eWAYRecurring_Utils::validateEwayContribution($paymentProcessor, $contributionInvoiceID);
      } catch (CRM_Core_Exception $e) {
        // Treated as neither success nor failure: the request is rejected below.
      }

      // payment success
      if ($response) {
        if ($component == 'event') {
          return ['redirect' => CRM_Utils_System::url('civicrm/event/register', [
            'qfKey' => $qfKey,
            '_qf_ThankYou_display' => 'true',
          ])];
        }
        return ['redirect' => CRM_Utils_System::url('civicrm/contact/view', [
          'cid' => $response['contribution']['contact_id'],
        ])];
      }
    }

    // Neither success nor fail. The payment information is wrong.
    return ['status' => 400];
  }

}
