<?php

class CRM_eWAYRecurring_Page_VerifyPayment extends CRM_Core_Page {

  public function run() {
    $store = NULL;

	// Set a guard on page run, since we don't want it to run a second time.
    // Not sure how thisis necessary, but apparently it's a thing that can happen.
	$running = &Civi::$statics[__CLASS__];

	if(is_null($running)) {
	  $running = true;
	}
	else {
	  return;
	}

    $contributionInvoiceID = CRM_Utils_Request::retrieve('contributionInvoiceID', 'String', $store, FALSE, "");
    $paymentProcessorID = CRM_Utils_Request::retrieve('paymentProcessorID', 'String', $store, FALSE, "0");
    $qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $store, FALSE, "");
    $component = CRM_Utils_Request::retrieve('component', 'String', $store, FALSE, "");

    // Missing any parameter should be a bad request
    if (empty($contributionInvoiceID) || empty($paymentProcessorID) || empty($component)) {
      header("HTTP/1.1 400 Bad Request");
      die();
    }

    $paymentProcessorInfo = civicrm_api3('PaymentProcessor', 'get', [
      'id' => $paymentProcessorID,
      'sequential' => 1,
    ]);
    $paymentProcessorInfo = $paymentProcessorInfo['values'];

    if (count($paymentProcessorInfo) > 0) {
      $paymentProcessorInfo = $paymentProcessorInfo[0];
      $paymentProcessor = new au_com_agileware_ewayrecurring(($paymentProcessorInfo['is_test']) ? 'test' : 'live', $paymentProcessorInfo);

      Civi::dispatcher()->addListener('hook_civicrm_alterMailParams', [$this, 'fixParticipantsForTemplate']);

      try {
        // This function will do redirect if the payment failed
        $response = validateEwayContribution($paymentProcessor, $contributionInvoiceID);
      } catch (CRM_Core_Exception $e) {
      } catch (CiviCRM_API3_Exception $e) {
      }

      Civi::dispatcher()->removeListener('hook_civicrm_alterMailParams', [$this, 'fixParticipantsForTemplate']);

      // payment success
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

    // Neither success nor fail. The payment information is wrong.
    if (!isset($redirectUrl)) {
      header("HTTP/1.1 400 Bad Request");
      die();
    }

    CRM_Utils_System::redirect($redirectUrl);
  }

  public function fixParticipantsForTemplate($event) {
    $params = &$event->params;

    if (isset($params['groupName']) && $params['groupName'] == 'msg_tpl_workflow_event' && $params['valueName'] == 'event_online_receipt' && empty($params['tplParams']['part'] && !empty($params['tplParams']['lineItem']))) {
	  //set variable that email is no test and should look like live email
	  $params['isTest'] = 0;
	  $primaryParticipantId = $params['tplParams']['participantID'];
	  $primaryContactId = $params['tplParams']['participant'][$primaryParticipantId]['contact_id'];
	  // Loop via lineItems and load additional participants
	  foreach ($params['tplParams']['lineItem'] as $k => $v) {
	    foreach ($v as $participantData) {
		  $result = civicrm_api3('Participant', 'getsingle', array(
		    'id' => $participantData['entity_id'],
		  ));

		  $params['tplParams']['part'][$k]['info'] = $result['display_name'];
		  if ($participantData['entity_id'] == $primaryParticipantId) {
		    $params['tplParams']['isPrimary'] = 1;
		  }
	    }
	  }
	  // Load additional profiles assigned to event
	  $additionalParticipantProfile = civicrm_api3('UFJoin', 'get', array(
	    'return' => array('uf_group_id.id', 'module'),
	    'entity_id' => $params['tplParams']['event']['id'],
	    'module' => 'CiviEvent_Additional',
	  ));

	  // Build main participant profile
	  $template = &CRM_Core_Smarty::singleton();
	  $preProfileID = CRM_Utils_Array::value('custom_pre_id', $params['tplParams']);
	  $postProfileID = CRM_Utils_Array::value('custom_post_id', $params['tplParams']);
	  if ($preProfileID) {
	    CRM_Event_BAO_Event::buildCustomDisplay($preProfileID, 'customPre', $primaryContactId, $template, $primaryParticipantId, FALSE
	    );
	  }
	  if ($postProfileID) {
	    CRM_Event_BAO_Event::buildCustomDisplay($postProfileID, 'customPost', $primaryContactId, $template, $primaryParticipantId, FALSE
	    );
	  }

	  // Build additional custom profile
	  if (!$additionalParticipantProfile['is_error'] && !empty($additionalParticipantProfile['values'])) {
	    foreach ($additionalParticipantProfile['values'] as $profileId => $profileData) {
		  $params['tplParams']['additional_custom_pre_id'][$profileId] = $profileData['uf_group_id.id'];
	    }

	    $customProfile = CRM_Event_BAO_Event::buildCustomProfile($primaryParticipantId, $params['tplParams'], $primaryContactId);
	    if (count($customProfile)) {
		  $params['tplParams']['customProfile'] = $customProfile;
	    }
	  }
    }
  }
}
