<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM                                                            |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/*
 +--------------------------------------------------------------------+
 | eWay Recurring Payment Processor Extension                         |
 +--------------------------------------------------------------------+
 | Licensed to CiviCRM under the Academic Free License version 3.0    |
 |                                                                    |
 | Originally written & contributed by Dolphin Software P/L - March   |
 | 2008                                                               |
 |                                                                    |
 | This is a combination of the original eWay payment processor, with |
 | customisations to handle recurring payments as well. Originally    |
 | started by Chris Ward at Community Builders in 2012.               |
 |                                                                    |
 +--------------------------------------------------------------------+
 |                                                                    |
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | This code was initially based on the recent PayJunction module     |
 | contributed by Phase2 Technology, and then plundered bits from     |
 | the AuthorizeNet module contributed by Ideal Solution, and         |
 | referenced the eWAY code in Drupal 5.7's ecommerce-5.x-3.4 and     |
 | ecommerce-5.x-4.x-dev modules.                                     |
 |                                                                    |
 | Plus a bit of our own code of course - Peter Barwell               |
 | contact PB@DolphinSoftware.com.au if required.                     |
 |                                                                    |
 | NOTE: The eWAY gateway only allows a single currency per account   |
 |       (per eWAY CustomerID) ie you can only have one currency per  |
 |       added Payment Processor.                                     |
 |       The only way to add multi-currency is to code it so that a   |
 |       different CustomerID is used per currency.                   |
 |                                                                    |
 +--------------------------------------------------------------------+
*/

include_once 'au_com_agileware_ewayrecurring.class.php';

function ewayrecurring_civicrm_buildForm ($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {
    if(!($page_id = $form->getVar('_id')))
      return;
    $form->addElement('text', 'recur_cycleday', ts('Recurring Payment Date'));
    $sql = 'SELECT cycle_day FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
    $default_cd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($page_id, 'Int')));
    if($default_cd) {
      $form->setDefaults(array('recur_cycleday' => $default_cd));
    }
  } elseif ($formName == 'CRM_Contribute_Form_UpdateSubscription') {
    $paymentProcessor = $form->getVar('_paymentProcessorObj');
    if(($paymentProcessor instanceof au_com_agileware_ewayrecurring)){
      $crid = $form->getVar('_crid');
      $sql = 'SELECT next_sched_contribution_date FROM civicrm_contribution_recur WHERE id = %1';
      $form->addDateTime('next_scheduled_date', ts('Next Scheduled Date'), FALSE, array('formatType' => 'activityDateTime'));
      if($default_nsd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($crid, 'Int')))){
	list($defaults['next_scheduled_date'],
	     $defaults['next_scheduled_date_time']) = CRM_Utils_Date::setDateDefaults($default_nsd);
	$form->setDefaults($defaults);
      }
    }
  } elseif ($formName == 'CRM_Admin_Form_PaymentProcessor' &&
	    $form->getVar('_ppType') == 'eWay_Recurring' &&
	    ($processor_id = $form->getVar('_id'))) {
    $form->addElement('text', 'recur_cycleday', ts('Recurring Payment Date'));
    $sql = 'SELECT cycle_day FROM civicrm_ewayrecurring WHERE processor_id = %1';
    $default_cd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($processor_id, 'Int')));
    if($default_cd) {
      $form->setDefaults(array('recur_cycleday' => $default_cd));
    }
  }
}

function ewayrecurring_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount' ||
      $formName == 'CRM_Admin_Form_PaymentProcessor') {
    $cycle_day = CRM_Utils_Array::value('recur_cycleday', $fields);
    if($cycle_day == '')
      return;
    if (!CRM_Utils_Type::validate($cycle_day, 'Int', FALSE, ts('Cycle day')) || $cycle_day < 1 || $cycle_day > 31) {
      $errors['recur_cycleday'] = ts('Recurring Payment Date must be a number between 1 and 31');
    }
  } elseif ($formName == 'CRM_Contribute_Form_UpdateSubscription') {

    $submitted_nsd = strtotime(CRM_Utils_Array::value('next_scheduled_date', $fields) . ' ' . CRM_Utils_Array::value('next_scheduled_date_time', $fields));

    $crid = $form->getVar('_crid');

    $sql = 'SELECT UNIX_TIMESTAMP(MAX(receive_date)) FROM civicrm_contribution WHERE contribution_recur_id = %1';
    $current_nsd = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($crid, 'Int')));
    $form->setVar('_currentNSD', $current_nsd);

    if($submitted_nsd < $current_nsd)
      $errors['next_scheduled_date'] = ts('Cannot schedule next contribution date before latest received date');
    elseif ($submitted_nsd < time())
      $errors['next_scheduled_date'] = ts('Cannot schedule next contribution in the past');
  }
}

function ewayrecurring_civicrm_postProcess ($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {
    if(!($page_id = $form->getVar('_id')))
      CRM_Core_Error::fatal("Attempt to process a contribution page form with no id");
    $cycle_day = $form->getSubmitValue('recur_cycleday');
    $is_recur = $form->getSubmitValue('is_recur');
    /* Do not continue if this is not a recurring payment */
    if (!$is_recur)
      return;
    if(!$cycle_day){
      $sql = 'DELETE FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int')));

      /* Update existing recurring contributions for this page */
      $sql = 'UPDATE civicrm_contribution_recur ccr
          INNER JOIN civicrm_contribution cc
                  ON cc.invoice_id = ccr.invoice_id
           LEFT JOIN civicrm_ewayrecurring ceway
                  ON ccr.payment_processor_id = ceway.processor_id
                 SET ccr.cycle_day  = ceway.cycle_day
               WHERE ccr.invoice_id = cc.invoice_id
                 AND cc.contribution_page_id = %1';

      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int')));
    }  else {
      // Relies on a MySQL extension.
      $sql = 'REPLACE INTO civicrm_contribution_page_recur_cycle (page_id, cycle_day) VALUES (%1, %2)';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int'),
					     2 => array($cycle_day, 'Int')));

      /* Update existing recurring contributions for this page */
      $sql = 'UPDATE civicrm_contribution_recur ccr,
                     civicrm_contribution cc
                 SET ccr.cycle_day  = %2
               WHERE ccr.invoice_id = cc.invoice_id
                 AND cc.contribution_page_id = %1';

      CRM_Core_DAO::executeQuery($sql, array(1 => array($page_id, 'Int'),
					     2 => array($cycle_day, 'Int')));
    }
  } elseif ($formName == 'CRM_Admin_Form_PaymentProcessor' &&
	    $form->getVar('_ppType') == 'eWay_Recurring') {
    if(!($processor_id = $form->getVar('_id')))
      CRM_Core_Error::fatal("Attempt to configure a payment processor admin form with no id");

    $cycle_day = $form->getSubmitValue('recur_cycleday');

    if (!$cycle_day){
      $sql = 'DELETE FROM civicrm_ewayrecurring WHERE processor_id = %1';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int')));
      $cycle_day = 0;
    } else {
      // Relies on a MySQL extension.
      $sql = 'REPLACE INTO civicrm_ewayrecurring (processor_id, cycle_day) VALUES (%1, %2)';
      CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int'),
					     2 => array($cycle_day, 'Int')));
    }

    $sql = 'UPDATE civicrm_contribution_recur ccr
        INNER JOIN civicrm_contribution cc
                ON cc.invoice_id = ccr.invoice_id
         LEFT JOIN civicrm_ewayrecurring ceway
                ON ccr.payment_processor_id = ceway.processor_id
         LEFT JOIN civicrm_contribution_page_recur_cycle ccprc
                ON ccprc.page_id = cc.contribution_page_id
               SET ccr.cycle_day = %2
             WHERE ceway.processor_id = %1
               AND ccprc.cycle_day is NULL';

    CRM_Core_DAO::executeQuery($sql, array(1 => array($processor_id, 'Int'),
					   2 => array($cycle_day, 'Int')));
  }
}

/*
 * Implements hook_civicrm_config()
 *
 * Include path for our overloaded templates */
function ewayrecurring_civicrm_config(&$config) {
  $template =& CRM_Core_Smarty::singleton();

  $ewayrecurringRoot =
    dirname(__FILE__) . DIRECTORY_SEPARATOR;

  $ewayrecurringDir = $ewayrecurringRoot . 'templates';

  if (is_array($template->template_dir)) {
    array_unshift($template->template_dir, $ewayrecurringDir);
  }
  else {
    $template->template_dir = array($ewayrecurringDir, $template->template_dir);
  }

  // also fix php include path
  $include_path = $ewayrecurringRoot . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

function ewayrecurring_civicrm_managed(&$entities) {
   $entities[] = array(
     'module' => 'au.com.agileware.ewayrecurring',
     'name' => 'eWay_Recurring',
     'entity' => 'PaymentProcessorType',
     'params' => array(
       'version' => 3,
       'name' => 'eWay_Recurring',
       'title' => 'eWAY Recurring',
       'description' => 'Recurring payments payment processor for eWay',
       'class_name' => 'au.com.agileware.ewayrecurring',
       'user_name_label' => 'Username',
       'password_label' => 'Password',
       //'signature_label' => '',
       'subject_label' => 'Customer ID',
       'url_site_default' => 'https://www.eway.com.au/gateway_cvn/xmlpayment.asp',
       //'url_api_default' => '',
       'url_recur_default' => 'https://www.eway.com.au/gateway/ManagedPaymentService/managedCreditCardPayment.asmx?WSDL',
       'url_site_test_default' => 'https://www.eway.com.au/gateway_cvn/xmltest/testpage.asp',
       //'url_api_test_default' => '',
       'url_recur_test_default' => 'https://www.eway.com.au/gateway/ManagedPaymentService/test/managedcreditcardpayment.asmx?WSDL',
       //'url_button_default' => '',
       'billing_mode' => 'form',
       'is_recur' => '1',
       'payment_type' => '1',
     ),
   );
   $entities[] = array(
     'module' => 'au.com.agileware.ewayrecurring',
     'name' => 'eWay_Recurring_cron',
     'entity' => 'Job',
     'params' => array (
       'version' => 3,
       'run_frequency' => 'Always',
       'name' => 'eWAY Recurring Payments',
       'description' => 'Process pending and scheduled payments in the eWay_Recurring processor',
       'api_entity' => 'Job',
       'api_action' => 'run_payment_cron',
       'parameters' => "processor_name=eWay_Recurring",
       'is_active' => '0'
     ),
   );
}

function ewayrecurring_civicrm_install() {
  // Do nothing here because the schema version can't be set during this hook.
}

function ewayrecurring_civicrm_uninstall() {
  $drops = array('DROP TABLE `civicrm_ewayrecurring`',
		 'DROP TABLE `civicrm_contribution_page_recur_cycle`');

  foreach($drops as $st) {
    CRM_Core_DAO::executeQuery($st, array());
  }
}

function ewayrecurring_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  $schemaVersion = intval(CRM_Core_BAO_Extension::getSchemaVersion('au.com.agileware.ewayrecurring'));
  $upgrades = array();

  if ($op == 'check') {
    return array($schemaVersion < 5);
  } elseif ($op == 'enqueue') {
    if(NULL == $queue) {
      return CRM_Core_Error::fatal('au.com.agileware.ewayrecurring: No Queue supplied for upgrade');
    }
    if($schemaVersion < 3) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', array(
            3,
	    "CREATE TABLE `civicrm_contribution_page_recur_cycle` (`page_id` int(10) NOT NULL DEFAULT '0', `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY (`page_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
          ),
          'Install page_recur_cycle table'
        )
      );

    }
    if($schemaVersion < 4) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', array(
            4,
            "CREATE TABLE `civicrm_ewayrecurring` (`processor_id` int(10) NOT NULL, `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY(`processor_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
          ),
          'Install cycle_day table'
        )
      );
    }
    if ($schemaVersion < 5) {
      $queue->createItem(
        new CRM_Queue_Task(
          '_ewayrecurring_fix_installments',
          array(5),
          'Fix installment counts from old versions'
        )
      );
    }
  }
}

function _ewayrecurring_upgrade_schema(CRM_Queue_TaskContext $ctx, $schema, $st, $params = array()) {
  $result = CRM_Core_DAO::executeQuery($st, $params);
  if (!is_a($result, 'DB_Error')) {
    CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', $schema);
    return CRM_Queue_Task::TASK_SUCCESS;
  } else {
    return CRM_Queue_Task::TASK_FAIL;
  }
}

function _ewayrecurring_fix_installments(CRM_Queue_TaskContext $ctx, $schema) {
  try {
    $pptype = civicrm_api3(
      'PaymentProcessorType', 'getsingle', array(
        'class_name' => "au.com.agileware.ewayRecurring",
        'api.PaymentProcessor.get' => array(),
      )
    );

    /* Get all recurring contributions with installment limits */
    $installment_recurring = civicrm_api3(
      'ContributionRecur', 'get' , array(
        'sequential' => 1,
        'installments' => array('>' => 0),
        'payment_processor_id' => array('IN' => array_map(
                                  '_ewayrecurring_get_pp_id',
                                  $pptype['api.PaymentProcessor.get']['values']
                                )),
      )
    );

    /* Restore original installment limit */
    foreach($installment_recurring['values'] as & $recurring_contribution) {
      $ccount = civicrm_api3('Contribution', 'getcount', array('contribution_recur_id' => $recurring_contribution['id']));
      /* Completed recurring contributions will still have an installment recorded. */
      $recurring_contribution['installments'] += ($recurring_contribution['contribution_status_id'] != 1? $ccount: $ccount - 1);
    }

    civicrm_api3(
      'ContributionRecur', 'replace', $installment_recurring
    );

    CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', $schema);
    return CRM_Queue_Task::TASK_SUCCESS;
  }
  catch (CiviCRM_API3_Exception $e) {
    return CRM_Queue_Task::TASK_FAIL;
  }
}

/* Because we can't rely on PHP having anonymous functions. */
function _ewayrecurring_get_pp_id($processor) {
  return $processor['id'];
}