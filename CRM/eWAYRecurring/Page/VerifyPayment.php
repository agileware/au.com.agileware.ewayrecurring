<?php

class CRM_eWAYRecurring_Page_VerifyPayment extends CRM_Core_Page {

  public function run() {
    $store = NULL;

    $contributionInvoiceID = CRM_Utils_Request::retrieve('contributionInvoiceID', 'String', $store, FALSE, "");
    $paymentProcessorID = CRM_Utils_Request::retrieve('paymentProcessorID', 'String', $store, FALSE, "0");
    $qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $store, FALSE, "");
    $component = CRM_Utils_Request::retrieve('component', 'String', $store, FALSE, "");
    $entryURL = CRM_Utils_Request::retrieve('entryURL', 'String', $store, FALSE, "");

    if (empty($contributionInvoiceID) || empty($paymentProcessorID) || empty($qfKey) || empty($component) || empty($entryURL)) {
      header("HTTP/1.1 400 Bad Request");
      die();
    }

    $redirectUrl = $entryURL;
    $paymentProcessorInfo = civicrm_api3('PaymentProcessor', 'get', [
      'id' => $paymentProcessorID,
      'sequential' => 1,
    ]);

    $paymentProcessorInfo = $paymentProcessorInfo['values'];

    if (count($paymentProcessorInfo) > 0) {
      $paymentProcessorInfo = $paymentProcessorInfo[0];
      $paymentProcessor = new au_com_agileware_ewayrecurring(($paymentProcessorInfo['is_test']) ? 'test' : 'live', $paymentProcessorInfo);
      try {
        $response = validateEwayContribution($paymentProcessor, $contributionInvoiceID);
      } catch (CRM_Core_Exception $e) {
      } catch (CiviCRM_API3_Exception $e) {
      }

      if ($response) {
        if ($component == 'event') {
          $redirectUrl = CRM_Utils_System::url('civicrm/event/register', [
            'qfKey' => $qfKey,
            '_qf_ThankYou_display' => 'true',
          ]);
        }
        else {
          $redirectUrl = CRM_Utils_System::url('civicrm/contact/view', [
            'cid' => $response['contribution']['contact_id'],
          ]);
        }
      }
    }


    CRM_Utils_System::redirect($redirectUrl);
  }

}
