<?php

require_once 'eWAYRecurring.civix.php';
include_once 'au_com_agileware_ewayrecurring.class.php';

use CRM_eWAYRecurring_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function ewayrecurring_civicrm_config(&$config) {
  _ewayrecurring_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function ewayrecurring_civicrm_xmlMenu(&$files) {
  _ewayrecurring_civix_civicrm_xmlMenu($files);
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
  _ewayrecurring_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function ewayrecurring_civicrm_uninstall() {
  foreach ($drops as $st) {
    CRM_Core_DAO::executeQuery($st, []);
  }
  _ewayrecurring_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function ewayrecurring_civicrm_enable() {
  _ewayrecurring_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function ewayrecurring_civicrm_disable() {
  _ewayrecurring_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function ewayrecurring_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  $schemaVersion = intval(CRM_Core_BAO_Extension::getSchemaVersion('au.com.agileware.ewayrecurring'));
  $upgrades = [];

  if ($op == 'check') {
    if ($schemaVersion < 6) {
      $setting_url = CRM_Utils_System::url('civicrm/admin/paymentProcessor', ['reset' => 1]);
      CRM_Core_Session::setStatus(ts('Version 2.x of the eWay Payment Processor extension uses the new eWay Rapid API. Please go to the <a href="%2">Payment Processor page</a> and update the eWay API credentials with the new API Key and API Password. For more details see the <a href="%1">upgrade notes</a>.', [
        1 => 'https://github.com/agileware/au.com.agileware.ewayrecurring/blob/master/UPGRADE.md',
        2 => $setting_url,
      ]), ts('eWay Payment Processor Update'));
    }
    if ($schemaVersion < 7) {
      CRM_Core_Session::setStatus(ts('Please edit and save (without any changes) your existing eWay payment processor after updating.'), ts('eWay Payment Processor Update'));
    }
    return [$schemaVersion < 20201];
  }
  elseif ($op == 'enqueue') {
    if (NULL == $queue) {
      return CRM_Core_Error::fatal('au.com.agileware.ewayrecurring: No Queue supplied for upgrade');
    }
    if ($schemaVersion < 5) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema_version', [
          5,
        ],
          'Update schema version'
        )
      );
    }
    if ($schemaVersion < 6) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', [
          6,
          "UPDATE civicrm_payment_processor_type SET user_name_label = 'API Key', password_label = 'API Password' WHERE name = 'eWay_Recurring'",
        ],
          'Perform Rapid API related changes'
        )
      );

      // add the table if not exist
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', [
          6,
          "CREATE TABLE IF NOT EXISTS `civicrm_eway_contribution_transactions`(
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique EwayContributionTransactions ID',
    `contribution_id` INT UNSIGNED COMMENT 'FK to Contact',
    `payment_processor_id` INT UNSIGNED COMMENT 'FK to PaymentProcessor',
    `access_code` TEXT,
    `failed_message` TEXT DEFAULT NULL,
    `status` INT UNSIGNED DEFAULT 0,
    `tries` INT UNSIGNED DEFAULT 0,
    PRIMARY KEY(`id`),
    CONSTRAINT FK_civicrm_eway_contribution_transactions_contribution_id FOREIGN KEY(`contribution_id`) REFERENCES `civicrm_contribution`(`id`) ON DELETE CASCADE,
    CONSTRAINT FK_civicrm_eway_contribution_transactions_payment_processor_id FOREIGN KEY(`payment_processor_id`) REFERENCES `civicrm_payment_processor`(`id`) ON DELETE CASCADE
);",
        ],
          'Create the table if not exist.'
        )
      );
    }
    if ($schemaVersion < 7) {
      // CIVIEWAY-76 remember the send email option
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', [
          7,
          "ALTER TABLE `civicrm_eway_contribution_transactions` ADD `is_email_receipt` TINYINT(1) DEFAULT 1",
        ],
          'Save the send email option.'
        )
      );
    }

    if ($schemaVersion < 20200) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', [
          20200,
          "UPDATE civicrm_payment_processor SET billing_mode = 4 WHERE payment_processor_type_id = (SELECT id FROM civicrm_payment_processor_type WHERE name = 'eWay_Recurring')",
        ],
          'Updating existing processors.'
        )
      );
    }
  }
  return _ewayrecurring_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
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
      'class_name' => 'au.com.agileware.ewayrecurring',
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
      'parameters' => "processor_name=eWay_Recurring",
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
      'option_group_id' => "activity_type",
      'label' => "eWay Transaction Failed",
    ],
  ];
  $entities[] = [
    'module' => 'au.com.agileware.ewayrecurring',
    'name' => 'eWay_Succeed_Transaction_ActivityType',
    'entity' => 'OptionValue',
    'update' => 'always',
    'params' => [
      'version' => 3,
      'option_group_id' => "activity_type",
      'label' => "eWay Transaction Succeed",
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
      'parameters' => "",
      'is_active' => '1',
    ],
  ];
  _ewayrecurring_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function ewayrecurring_civicrm_caseTypes(&$caseTypes) {
  _ewayrecurring_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function ewayrecurring_civicrm_angularModules(&$angularModules) {
  _ewayrecurring_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function ewayrecurring_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _ewayrecurring_civix_civicrm_alterSettingsFolders($metaDataFolders);
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
  _ewayrecurring_civix_civicrm_entityTypes($entityTypes);
}

function _contribution_status_id($name) {
  return CRM_Utils_Array::key($name, \CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name'));
}

/**
 * @param $formName
 * @param $form CRM_Core_Form
 */
function ewayrecurring_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_UpdateSubscription') {
    $paymentProcessor = $form->getVar('_paymentProcessorObj');
    if (($paymentProcessor instanceof au_com_agileware_ewayrecurring)) {
      ($crid = $form->getVar('contributionRecurID')) || ($crid = $form->getVar('_crid'));
      if ($crid) {
        $sql = 'SELECT next_sched_contribution_date FROM civicrm_contribution_recur WHERE id = %1';
        $form->addDateTime('next_scheduled_date', ts('Next Scheduled Date'), FALSE, ['formatType' => 'activityDateTime']);
        if ($default_nsd = CRM_Core_DAO::singleValueQuery($sql, [
          1 => [
            $crid,
            'Int',
          ],
        ])) {
          [$defaults['next_scheduled_date'],
            $defaults['next_scheduled_date_time']] = CRM_Utils_Date::setDateDefaults($default_nsd);
          $form->setDefaults($defaults);
        }
        // add next scheduled date field
        $template = $form->toSmarty();
        $datePicker = CRM_Core_Smarty::singleton()->fetchWith('CRM/common/jcalendar.tpl', [
          'elementName' => 'next_scheduled_date',
          'form' => $template,
        ]);
        Civi::resources()
          ->addScript("CRM.eway.modifyUpdateSubscriptionForm(" .
            json_encode([
              'next_scheduled_date' => $template['next_scheduled_date'],
              'date_picker' => $datePicker,
            ]) .
            ");");
      }
    }
  }
  elseif ($formName == 'CRM_Contribute_Form_CancelSubscription' && $form->getVar('_paymentProcessorObj') instanceof au_com_agileware_ewayrecurring) {
    // remove send request to eway field
    $form->removeElement('send_cancel_request');
  }
}

function ewayrecurring_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  switch($formName) {
    case'CRM_Admin_Form_PaymentProcessor':
      if (empty(CRM_Utils_Array::value('user_name', $fields, ''))) {
        $errors['user_name'] = ts('API Key is a required field.');
      }

      if (empty(CRM_Utils_Array::value('password', $fields, ''))) {
        $errors['password'] = ts('API Password is a required field.');
      }
      break;
    case 'CRM_Contribute_Form_UpdateSubscription':

      $submitted_nsd = strtotime(CRM_Utils_Array::value('next_scheduled_date', $fields) . ' ' . CRM_Utils_Array::value('next_scheduled_date_time', $fields));

      ($crid = $form->getVar('contributionRecurID')) || ($crid = $form->getVar('_crid'));

      $sql = 'SELECT UNIX_TIMESTAMP(MAX(receive_date)) FROM civicrm_contribution WHERE contribution_recur_id = %1';
      $current_nsd = CRM_Core_DAO::singleValueQuery($sql, [1 => [$crid, 'Int']]);
      $form->setVar('_currentNSD', $current_nsd);

      if ($submitted_nsd < $current_nsd) {
        $errors['next_scheduled_date'] = ts('Cannot schedule next contribution date before latest received date');
      }
      elseif ($submitted_nsd < time()) {
        $errors['next_scheduled_date'] = ts('Cannot schedule next contribution in the past');
      }
      break;
  }
}

/**
 * Implements hook_civicrm_preProcess().
 *
 * @param $formName
 * @param $form
 */
function ewayrecurring_civicrm_preProcess($formName, &$form) {
  Civi::$statics['openedeWayForm'] = $formName;
  if ($formName == 'CRM_Contribute_Form_Contribution_ThankYou') {
    $paymentProcessor = $form->getVar('_paymentProcessor');
    $paymentProcessor = $paymentProcessor['object'];
    validateEwayContribution($paymentProcessor, $form->_params['invoiceID']);
    // fixme CIVIEWAY-144 temporary fix, remove this if the issue is solved in core
    if (!$form->_priceSetId || CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceSet', $form->_priceSetId, 'is_quick_config')) {
      $form->assign('lineItem', FALSE);
    }
  }
}

/**
 * Validate eWay contribution by AccessCode, Invoice ID and Payment Processor.
 *
 * @param $paymentProcessor
 * @param $invoiceID
 *
 * @return array|null
 * @throws CRM_Core_Exception
 * @throws CiviCRM_API3_Exception
 */
function validateEwayContribution($paymentProcessor, $invoiceID) {
  if ($paymentProcessor instanceof au_com_agileware_ewayrecurring) {
    $contribution = civicrm_api3('Contribution', 'get', [
      'invoice_id' => $invoiceID,
      'sequential' => TRUE,
      'return' => [
        'contribution_page_id',
        'contribution_recur_id',
        'is_test',
      ],
      'is_test' => ($paymentProcessor->_mode == 'test') ? 1 : 0,
    ]);

    if (count($contribution['values']) > 0) {
      // Include eWay SDK.
      require_once extensionPath('vendor/autoload.php');

      $contribution = $contribution['values'][0];
      $eWayAccessCode = CRM_Utils_Request::retrieve('AccessCode', 'String', $form, FALSE, "");
      $qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $form, FALSE, "");

      $paymentProcessor->validateContribution($eWayAccessCode, $contribution, $qfKey, $paymentProcessor->getPaymentProcessor());

      return [
        'contribution' => $contribution,
      ];
    }
    return NULL;
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

/**
 * Get the path of a resource file (in this extension).
 *
 * @param string|NULL $file
 *   Ex: NULL.
 *   Ex: 'css/foo.css'.
 *
 * @return string
 *   Ex: '/var/www/example.org/sites/default/ext/org.example.foo'.
 *   Ex: '/var/www/example.org/sites/default/ext/org.example.foo/css/foo.css'.
 */
function extensionPath($file = NULL) {
  // return CRM_Core_Resources::singleton()->getPath(self::LONG_NAME, $file);
  return __DIR__ . ($file === NULL ? '' : (DIRECTORY_SEPARATOR . $file));
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
    Civi::resources()->addScriptFile('au.com.agileware.ewayrecurring', 'js/eway.js', $region);
    $result = civicrm_api3('PaymentProcessorType', 'get', [
      'sequential' => 1,
      'name' => "eWay_Recurring",
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
