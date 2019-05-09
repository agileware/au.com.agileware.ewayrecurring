<?php

class CRM_eWAYRecurring_Page_VerifyUpdateToken extends CRM_Core_Page {

  public function run() {
    $store = NULL;

    $recurringContributionID = CRM_Utils_Request::retrieve('recurringContributionID', 'String', $store, FALSE, "");
    $eWayAccessCode = CRM_Utils_Request::retrieve('AccessCode', 'String', $form, FALSE, "");
    $paymentProcessorID = CRM_Utils_Request::retrieve('paymentProcessorID', 'String', $store, FALSE, "0");

    $redirectUrl = CRM_Utils_System::url('civicrm');
    if (!empty($recurringContributionID)) {

      try {
        $recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', array(
          'id' => $recurringContributionID,
        ));

        $paymentProcessorInfo = civicrm_api3('PaymentProcessor', 'get', array(
          'id'         => $paymentProcessorID,
          'sequential' => 1,
        ));

        $paymentProcessorInfo = $paymentProcessorInfo['values'];

        if (count($paymentProcessorInfo) > 0) {
          $paymentProcessorInfo = $paymentProcessorInfo[0];
          //$paymentProcessorInfo['is_test'] = 1;

          $response = CRM_eWAYRecurring_eWAYRecurringUtils::validateEwayAccessCode($eWayAccessCode, $paymentProcessorInfo, TRUE);
          $hasTransactionFailed = $response['hasTransactionFailed'];
          $transactionResponseError = $response['transactionResponseError'];

          if ($hasTransactionFailed) {
            CRM_Core_Session::setStatus(
              ts('Failed to update billing details, Error: ' . $transactionResponseError),
              ts('Update Billing Details'), 'error');

            $redirectUrl = CRM_Utils_System::url('civicrm/contribute/updatebilling', array(
              'cid'         => $recurringContribution['contact_id'],
              'context'     => 'contribution',
              'crid'        => $recurringContribution['id'],
            ), TRUE, NULL, FALSE);
          }
          else {
            //----------------------------------------------------------------------------------------------------
            // Updating the billing details should fixed failed contributions
            //----------------------------------------------------------------------------------------------------

            CRM_eWAYRecurring_eWAYRecurringUtils::updateCustomerDetails($response, $recurringContribution);

            if(_contribution_status_id('Failed') == $recurringContribution['contribution_status_id']) {
              CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
                $recurringContribution['id'],
                'contribution_status_id',
                _contribution_status_id('In Progress') );
            }

            CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
              $recurringContribution['id'],
              'failure_count',
              0 );

            CRM_Core_Session::setStatus(
              ts('Billing details has been updated successfully.'),
              ts('Update Billing Details'), 'success');

            $redirectUrl = CRM_Utils_System::url('civicrm/contact/view', array(
              'cid'         => $recurringContribution['contact_id'],
            ), TRUE, NULL, FALSE);

          }
        }

      }
      catch (CiviCRM_API3_Exception $e) {

      }
    }

    CRM_Utils_System::redirect($redirectUrl);
  }

}
