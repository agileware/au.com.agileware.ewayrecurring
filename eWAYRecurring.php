<?php

include_once 'au_com_agileware_ewayrecurring.class.php';
require_once 'eWAYRecurring.civix.php';

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
  CRM_Core_BAO_Extension::setSchemaVersion('au.com.agileware.ewayrecurring', 6);
  // Update schemaVersion if added new version in upgrade process.
  // Also add database related CREATE queries.
  CRM_Core_DAO::executeQuery("CREATE TABLE `civicrm_contribution_page_recur_cycle` (`page_id` int(10) NOT NULL DEFAULT '0', `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY (`page_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  CRM_Core_DAO::executeQuery("CREATE TABLE `civicrm_ewayrecurring` (`processor_id` int(10) NOT NULL, `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY(`processor_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  CRM_Core_DAO::executeQuery("UPDATE `civicrm_payment_processor_type` SET billing_mode = 3 WHERE name = 'eWay_Recurring'");
  _ewayrecurring_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function ewayrecurring_civicrm_uninstall() {
  $drops = [
    'DROP TABLE `civicrm_ewayrecurring`',
    'DROP TABLE `civicrm_contribution_page_recur_cycle`',
  ];

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
      CRM_Core_Session::setStatus(ts('Please edit and save (without any changes) your existing EWay payment processor after updating.', ts('eWAYRecurring Action Required')));
    }
    return [$schemaVersion < 7];
  }
  elseif ($op == 'enqueue') {
    if (NULL == $queue) {
      return CRM_Core_Error::fatal('au.com.agileware.ewayrecurring: No Queue supplied for upgrade');
    }
    if ($schemaVersion < 3) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', [
          3,
          "CREATE TABLE `civicrm_contribution_page_recur_cycle` (`page_id` int(10) NOT NULL DEFAULT '0', `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY (`page_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
        ],
          'Install page_recur_cycle table'
        )
      );

    }
    if ($schemaVersion < 4) {
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', [
          4,
          "CREATE TABLE `civicrm_ewayrecurring` (`processor_id` int(10) NOT NULL, `cycle_day` int(2) DEFAULT NULL, PRIMARY KEY(`processor_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
        ],
          'Install cycle_day table'
        )
      );
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
          "UPDATE civicrm_payment_processor_type SET user_name_label = 'API Key', password_label = 'API Password', billing_mode = 3 WHERE name = 'eWay_Recurring'",
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
      // those fields are marked as deprecated.
      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', [
          7,
          "UPDATE civicrm_payment_processor_type SET billing_mode = 4 WHERE name = 'eWay_Recurring'",
        ],
          'Change processor billing mode.'
        )
      );

      $queue->createItem(
        new CRM_Queue_Task('_ewayrecurring_upgrade_schema', [
          7,
          "UPDATE civicrm_payment_processor SET billing_mode = 4 WHERE payment_processor_type_id = (SELECT id FROM civicrm_payment_processor_type WHERE name = 'eWay_Recurring')",
        ],
          'Updating existing processors.'
        )
      );

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
    'params' => [
      'version' => 3,
      'name' => 'eWay_Recurring',
      'title' => 'eWAY Recurring',
      'description' => 'Recurring payments payment processor for eWay',
      'class_name' => 'au.com.agileware.ewayrecurring',
      'user_name_label' => 'API Key',
      'password_label' => 'API Password',
      'billing_mode' => 'notify',
      'is_recur' => '1',
      'payment_type' => '1',
    ],
  ];
  $entities[] = [
    'module' => 'au.com.agileware.ewayrecurring',
    'name' => 'eWay_Recurring_cron',
    'entity' => 'Job',
    'update' => 'never',
    // Ensure local changes are kept, eg. setting the job active
    'params' => [
      'version' => 3,
      'run_frequency' => 'Always',
      'name' => 'eWAY Recurring Payments',
      'description' => 'Process pending and scheduled payments in the eWay_Recurring processor',
      'api_entity' => 'Job',
      'api_action' => 'run_payment_cron',
      'parameters' => "processor_name=eWay_Recurring",
      'is_active' => '0',
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
      'name' => 'eWAY Transaction Verifications',
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

function ewayrecurring_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {
    if (!($page_id = $form->getVar('_id'))) {
      return;
    }
    $form->addElement('text', 'recur_cycleday', ts('Recurring Payment Date'));
    $sql = 'SELECT cycle_day FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
    $default_cd = CRM_Core_DAO::singleValueQuery($sql, [
      1 => [
        $page_id,
        'Int',
      ],
    ]);
    if ($default_cd) {
      $form->setDefaults(['recur_cycleday' => $default_cd]);
    }
  }
  elseif ($formName == 'CRM_Contribute_Form_UpdateSubscription') {
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
          list($defaults['next_scheduled_date'],
            $defaults['next_scheduled_date_time']) = CRM_Utils_Date::setDateDefaults($default_nsd);
          $form->setDefaults($defaults);
        }
      }
    }
  }
  elseif ($formName == 'CRM_Admin_Form_PaymentProcessor' && (($form->getVar('_paymentProcessorDAO') &&
        $form->getVar('_paymentProcessorDAO')->name == 'eWay_Recurring') || ($form->getVar('_ppDAO') && $form->getVar('_ppDAO')->name == 'eWay_Recurring')) &&
    ($processor_id = $form->getVar('_id'))) {
    $form->addElement('text', 'recur_cycleday', ts('Recurring Payment Date'));
    $sql = 'SELECT cycle_day FROM civicrm_ewayrecurring WHERE processor_id = %1';
    $default_cd = CRM_Core_DAO::singleValueQuery($sql, [
      1 => [
        $processor_id,
        'Int',
      ],
    ]);
    if ($default_cd) {
      $form->setDefaults(['recur_cycleday' => $default_cd]);
    }
  }
}

function ewayrecurring_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount' ||
    $formName == 'CRM_Admin_Form_PaymentProcessor') {
    $cycle_day = CRM_Utils_Array::value('recur_cycleday', $fields);
    if ($cycle_day == '') {
      return;
    }
    if (!CRM_Utils_Type::validate($cycle_day, 'Int', FALSE, ts('Cycle day')) || $cycle_day < 1 || $cycle_day > 31) {
      $errors['recur_cycleday'] = ts('Recurring Payment Date must be a number between 1 and 31');
    }

    if (empty(CRM_Utils_Array::value('user_name', $fields, ''))) {
      $errors['user_name'] = ts('API Key is a required field.');
    }

    if (empty(CRM_Utils_Array::value('password', $fields, ''))) {
      $errors['password'] = ts('API Password is a required field.');
    }

  }
  elseif ($formName == 'CRM_Contribute_Form_UpdateSubscription') {

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
  }
}

function ewayrecurring_civicrm_postProcess($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {
    if (!($page_id = $form->getVar('_id'))) {
      CRM_Core_Error::fatal("Attempt to process a contribution page form with no id");
    }
    $cycle_day = $form->getSubmitValue('recur_cycleday');
    $is_recur = $form->getSubmitValue('is_recur');
    /* Do not continue if this is not a recurring payment */
    if (!$is_recur) {
      return;
    }
    if (!$cycle_day) {
      $sql = 'DELETE FROM civicrm_contribution_page_recur_cycle WHERE page_id = %1';
      CRM_Core_DAO::executeQuery($sql, [1 => [$page_id, 'Int']]);

      /* Update existing recurring contributions for this page */
      $sql = 'UPDATE civicrm_contribution_recur ccr
          INNER JOIN civicrm_contribution cc
                  ON cc.invoice_id            = ccr.invoice_id
           LEFT JOIN civicrm_ewayrecurring ceway
                  ON ccr.payment_processor_id = ceway.processor_id
                 SET ccr.cycle_day            = COALESCE(ceway.cycle_day, ccr.cycle_day)
               WHERE ccr.invoice_id           = cc.invoice_id
                 AND cc.contribution_page_id  = %1';

      CRM_Core_DAO::executeQuery($sql, [1 => [$page_id, 'Int']]);
    }
    else {
      // Relies on a MySQL extension.
      $sql = 'REPLACE INTO civicrm_contribution_page_recur_cycle (page_id, cycle_day) VALUES (%1, %2)';
      CRM_Core_DAO::executeQuery($sql, [
        1 => [$page_id, 'Int'],
        2 => [$cycle_day, 'Int'],
      ]);

      /* Update existing recurring contributions for this page */
      $sql = 'UPDATE civicrm_contribution_recur ccr,
                     civicrm_contribution cc
                 SET ccr.cycle_day  = %2
               WHERE ccr.invoice_id = cc.invoice_id
                 AND cc.contribution_page_id = %1';

      CRM_Core_DAO::executeQuery($sql, [
        1 => [$page_id, 'Int'],
        2 => [$cycle_day, 'Int'],
      ]);
    }
  }
  elseif ($formName == 'CRM_Admin_Form_PaymentProcessor' && (($form->getVar('_paymentProcessorDAO') &&
        $form->getVar('_paymentProcessorDAO')->name == 'eWay_Recurring') || ($form->getVar('_ppDAO') && $form->getVar('_ppDAO')->name == 'eWay_Recurring'))) {
    if (!($processor_id = $form->getVar('_id'))) {
      CRM_Core_Error::fatal("Attempt to configure a payment processor admin form with no id");
    }

    $cycle_day = $form->getSubmitValue('recur_cycleday');

    if (!$cycle_day) {
      $sql = 'DELETE FROM civicrm_ewayrecurring WHERE processor_id = %1';
      CRM_Core_DAO::executeQuery($sql, [1 => [$processor_id, 'Int']]);
      $cycle_day = 0;
    }
    else {
      // Relies on a MySQL extension.
      $sql = 'REPLACE INTO civicrm_ewayrecurring (processor_id, cycle_day) VALUES (%1, %2)';
      CRM_Core_DAO::executeQuery($sql, [
        1 => [$processor_id, 'Int'],
        2 => [$cycle_day, 'Int'],
      ]);
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

    CRM_Core_DAO::executeQuery($sql, [
      1 => [$processor_id, 'Int'],
      2 => [$cycle_day, 'Int'],
    ]);
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
  }
}

/**
 * Validate eWAY contribution by AccessCode, Invoice ID and Payment Processor.
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
 * Disable AJAX for contribution tab.
 *
 * @param $pattern
 *
 * @return array|false
 */
function ewayrecurring_civicrm_tabset($tabsetName, &$tabs, $context) {
  if ($tabsetName == 'civicrm/contact/view') {
    foreach ($tabs as $index => $tab) {
      if ($tab['id'] == 'contribute') {
        $tabs[$index]['class'] = str_replace('livePage', '', $tabs[$index]['class']);
        break;
      }
    }
  }
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