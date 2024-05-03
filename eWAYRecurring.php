<?php

require_once 'eWAYRecurring.civix.php';
require_once 'vendor/autoload.php';

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_eWAYRecurring_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function ewayrecurring_civicrm_config(&$config) {
  _ewayrecurring_civix_civicrm_config($config);
  Civi::dispatcher()->addListener('civi.api.authorize', ['CRM_eWAYRecurring_APIWrapper', 'authorize'], -100);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function ewayrecurring_civicrm_install() {
  _ewayrecurring_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function ewayrecurring_civicrm_postInstall() {
  // Update schemaVersion if added new version in upgrade process.
  CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', 20200);
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function ewayrecurring_civicrm_enable() {
  // Ensure payment processor is active, will be deactivated if extension is disabled and user has no way to reactivate
  \Civi\Api4\PaymentProcessorType::update()
                                 ->addValue('is_active', TRUE)
                                 ->addWhere('name', '=', 'eWay_Recurring')
                                 ->execute();
  _ewayrecurring_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @param array $entities list of managed entities to update
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function ewayrecurring_civicrm_managed(&$entities) {
  $entities[] = [
    'module' => 'au.com.agileware.ewayrecurring',
    'name' => 'eWay_Recurring',
    'entity' => 'PaymentProcessorType',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'eWay_Recurring',
      'title' => 'eWay Recurring',
      'description' => 'Recurring payments payment processor for eWay',
      'class_name' => 'Payment_eWAYRecurring',
      'user_name_label' => 'API Key',
      'password_label' => 'API Password',
      'billing_mode' => 'notify',
      'is_active' => '1',
      'is_recur' => '1',
      'payment_type' => '1',
    ],
  ];
  $entities[] = [
    'module' => 'au.com.agileware.ewayrecurring',
    'name' => 'eWay_Recurring_cron',
    'entity' => 'Job',
    'update' => 'always',
    // Ensure local changes are kept, eg. setting the job active
    'params' => [
      'version' => 3,
      'run_frequency' => 'Always',
      'name' => 'eWay Recurring Payments',
      'description' => 'Process pending and scheduled payments in the eWay_Recurring processor',
      'api_entity' => 'Job',
      'api_action' => 'run_payment_cron',
      'parameters' => 'processor_name=eWay_Recurring',
      'is_active' => '1',
    ],
  ];
  $entities[] = [
    'module' => 'au.com.agileware.ewayrecurring',
    'name' => 'eWay_Failed_Transaction_ActivityType',
    'entity' => 'OptionValue',
    'update' => 'always',
    'params' => [
      'version' => 3,
      'option_group_id' => 'activity_type',
      'label' => 'eWay Transaction Failed',
      'is_reserved' => 1,
      'filter' => 1,
    ],
  ];
  $entities[] = [
    'module' => 'au.com.agileware.ewayrecurring',
    'name' => 'eWay_Succeed_Transaction_ActivityType',
    'entity' => 'OptionValue',
    'update' => 'always',
    'params' => [
      'version' => 3,
      'option_group_id' => 'activity_type',
      'label' => 'eWay Transaction Succeeded',
      'is_reserved' => 1,
      'filter' => 1,
    ],
  ];
  $entities[] = [
    'module' => 'au.com.agileware.ewayrecurring',
    'name' => 'eWay_Transaction_Verification_cron',
    'entity' => 'Job',
    'update' => 'never',
    // Ensure local changes are kept, eg. setting the job active
    'params' => [
      'version' => 3,
      'run_frequency' => 'Always',
      'name' => 'eWay Transaction Verifications',
      'description' => 'Process pending transaction verifications in the eWay_Recurring processor',
      'api_entity' => 'EwayContributionTransactions',
      'api_action' => 'validate',
      'parameters' => '',
      'is_active' => '1',
    ],
  ];
  $entities[] = [
    'module' => 'au.com.agileware.ewayrecurring',
    'name' => 'eWay_fillTokensMeta_cron',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'run_frequency' => 'Hourly',
      'name' => 'eWay Recurring: fill missing tokens metadata',
      'description' => 'Loops through PaymentTokens for eWAY Recurring linked PaymentTokens that are missing expiry date or masked card number and queries eWAY Rapid API to fill these details in',
      'api_entity' => 'EwayRecurring',
      'api_action' => 'fillTokensMeta',
      'parameters' => '',
      'is_active' => '1',
    ],
  ];
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function ewayrecurring_civicrm_entityTypes(&$entityTypes) {
  $entityTypes[] = [
    'name' => 'EwayContributionTransactions',
    'class' => 'CRM_eWAYRecurring_DAO_EwayContributionTransactions',
    'table' => 'civicrm_eway_contribution_transactions',
  ];
}

/**
 * @param $formName
 * @param $form CRM_Core_Form
 */
function ewayrecurring_civicrm_buildForm($formName, &$form) {
  if (($form instanceof CRM_Contribute_Form_CancelSubscription) &&
    ($form->_paymentProcessor['payment_processor_type'] == 'eWay_Recurring')) {
    $form->removeElement('send_cancel_request');
  }
}

function ewayrecurring_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  $paymentProcessorID = \Civi\Api4\Managed::get(FALSE)
    ->addSelect('entity_id')
    ->addWhere('module', '=', 'au.com.agileware.ewayrecurring')
    ->addWhere('name', '=', 'eWay_Recurring')
    ->setLimit(1)
    ->execute()
    ->first()
    ['entity_id'];

  switch($formName) {
    case'CRM_Admin_Form_PaymentProcessor':
      if(($fields['payment_processor_type_id'] ?? NULL) != $paymentProcessorID) {
        break;
      }

      if (empty($fields['user_name'])) {
        $errors['user_name'] = E::ts('API Key is a required field.');
      }

      if (empty($fields['password'])) {
        $errors['password'] = E::ts('API Password is a required field.');
      }
      break;
  }
}

/**
 * Implements hook_civicrm_preProcess().
 *
 * @param $formName
 * @param $form
 *
 * @throws \Civi\Payment\Exception\PaymentProcessorException
 */
function ewayrecurring_civicrm_preProcess($formName, &$form) {
  Civi::$statics['openedeWayForm'] = $formName;
	switch($formName) {
    case 'CRM_Contribute_Form_Contribution_ThankYou':
      $paymentProcessor = $form->getVar('_paymentProcessor');
      $paymentProcessor = $paymentProcessor['object'];
      $invoiceID = $form->_params['invoiceID'];
      $validated = &Civi::$statics[__FUNCTION__ . '::validated'];
      if(!isset($validated[$invoiceID])) {
        CRM_eWAYRecurring_Utils::validateEwayContribution($paymentProcessor, $invoiceID);
      }
      // fixme CIVIEWAY-144 temporary fix, remove this if the issue is solved in core
      if (!$form->_priceSetId || CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceSet', $form->_priceSetId, 'is_quick_config')) {
        $form->assign('lineItem', FALSE);
      }
      break;

    case 'CRM_Contribute_Form_Contribution_Confirm':
    case 'CRM_Event_Form_Registration_Confirm':
      $qfKey = $form->get('qfKey');
      $eWAYResponse = $qfKey ? unserialize(CRM_Core_Session::singleton()
        ->get('eWAYResponse', $qfKey)) : FALSE;
      $paymentProcessor = $form->getVar('_paymentProcessor') ?? NULL;
      if (!empty($eWAYResponse->AccessCode) && ($paymentProcessor['object'] instanceof CRM_Core_Payment_eWAYRecurring)) {
        $transaction = CRM_eWAYRecurring_Utils::validateEwayAccessCode($eWAYResponse->AccessCode, $paymentProcessor);
        if ($transaction['hasTransactionFailed']) {
          CRM_Core_session::setStatus(E::ts(
            'A transaction has already been submitted, but failed. Continuing will result in a new transaction.'
          ));
          CRM_Core_Session::singleton()->set('eWAYResponse', NULL, $qfKey);
        }
        elseif (!$transaction['transactionNotProcessedYet']) {
          throw new PaymentProcessorException(
            $form instanceof CRM_Event_Form_Registration_Confirm
              ? E::ts('Payment already completed for this Registration')
              : E::ts('Payment already completed for this Contribution')
          );
        }
      }
      break;
  }
}

function _ewayrecurring_upgrade_schema_version(CRM_Queue_TaskContext $ctx, $schema) {
  CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', $schema);
  return CRM_Queue_Task::TASK_SUCCESS;
}

function _ewayrecurring_upgrade_schema(CRM_Queue_TaskContext $ctx, $schema, $st, $params = []) {
  $result = CRM_Core_DAO::executeQuery($st, $params);
  if (!is_a($result, 'DB_Error')) {
    CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', $schema);
    return CRM_Queue_Task::TASK_SUCCESS;
  }
  else {
    return CRM_Queue_Task::TASK_FAIL;
  }
}

/* Because we can't rely on PHP having anonymous functions. */
function _ewayrecurring_get_pp_id($processor) {
  return $processor['id'];
}

function ewayrecurring_civicrm_navigationMenu(&$menu) {
  _ewayrecurring_civix_insert_navigation_menu($menu, 'Administer', [
    'label' => E::ts('eWay Recurring Settings'),
    'name' => 'eWayRecurringSettings',
    'url' => 'civicrm/ewayrecurring/settings',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
}

/**
 * Implements hook_civicrm_coreResourceList().
 */
function ewayrecurring_civicrm_coreResourceList(&$list, $region) {
  if ($region == 'html-header') {
    Civi::resources()->addScriptFile('au.com.agileware.ewayrecurring', 'js/eway.js', [ 'region' => $region, 'weight' => 9 ]);
    $result = civicrm_api3('PaymentProcessorType', 'get', [
      'sequential' => 1,
      'name' => 'eWay_Recurring',
      'api.PaymentProcessor.get' => ['payment_processor_type_id' => "\$value.id"],
    ]);
    if ($result['is_error'] || $result['values'][0]['api.PaymentProcessor.get']['is_error']) {
      return;
    }
    $ids = [];
    foreach ($result['values'][0]['api.PaymentProcessor.get']['values'] as $pp) {
      $ids[] = $pp['id'];
    }
    CRM_Core_Resources::singleton()->addVars('agilewareEwayExtension', array('paymentProcessorId' => $ids));
  }
}

/**
 * Implements hook_civicrm_permission
 *
 * @param $permissions permissions list to add to
 */
function ewayrecurring_civicrm_permission(&$permissions) {
  $permissions['view payment tokens'] = [
    'label' => E::ts('CiviContribute: view payment tokens'),
  ];
  $permissions['edit payment tokens'] = [
    'label' => E::ts('CiviContribute: edit payment tokens'),
  ];
}
