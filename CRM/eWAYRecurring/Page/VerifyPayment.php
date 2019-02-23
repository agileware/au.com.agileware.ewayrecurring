<?php

class CRM_eWAYRecurring_Page_VerifyPayment extends CRM_Core_Page {

  public function run() {
    $store = NULL;

    $contributionInvoiceID = CRM_Utils_Request::retrieve('contributionInvoiceID', 'String', $store, FALSE, "");
    $paymentProcessorID = CRM_Utils_Request::retrieve('paymentProcessorID', 'String', $store, FALSE, "0");

    $redirectUrl = CRM_Utils_System::url('civicrm');
    if (!empty($contributionInvoiceID)) {
      $paymentProcessorInfo = civicrm_api3('PaymentProcessor', 'get', array(
        'id'         => $paymentProcessorID,
        'sequential' => 1,
      ));

      $paymentProcessorInfo = $paymentProcessorInfo['values'];

      if (count($paymentProcessorInfo) > 0) {
        $paymentProcessorInfo = $paymentProcessorInfo[0];
        $paymentProcessor = new au_com_agileware_ewayrecurring(($paymentProcessorInfo['is_test']) ? 'test' : 'live', $paymentProcessorInfo);
        $response = validateEwayContribution($paymentProcessor, $contributionInvoiceID);
        
        if ($response) {
          $redirectUrl = CRM_Utils_System::url('civicrm/contact/view', array(
            'cid' => $response['contribution']['contact_id'],
          ));
        }
      }
    }

    CRM_Utils_System::redirect($redirectUrl);
  }

}
