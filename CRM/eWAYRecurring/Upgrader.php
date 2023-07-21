<?php

use Civi\Api4\PaymentProcessorType;
use CRM_eWAYRecurring_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_eWAYRecurring_Upgrader extends CRM_Extension_Upgrader_Base {
  public function upgrade_6() {
    $setting_url = CRM_Utils_System::url('civicrm/admin/paymentProcessor', ['reset' => 1]);
    $this->ctx->log->info(E::ts('Version 2.x of the eWay Payment Processor extension uses the new eWay Rapid API. Please go to the <a href="%2">Payment Processor page</a> and update the eWay API credentials with the new API Key and API Password. For more details see the <a href="%1">upgrade notes</a>.', [
      1 => 'https://github.com/agileware/au.com.agileware.ewayrecurring/blob/master/UPGRADE.md',
      2 => $setting_url,
    ]));

    $this->ctx->log->info('Update Payment Processor labels for Rapid API');
    CRM_Core_DAO::executeQuery("UPDATE civicrm_payment_processor_type SET user_name_label = 'API Key', password_label = 'API Password' WHERE name = 'eWay_Recurring'");

    $this->ctx->log->info('Create eWAY Contribution Transactions store.');
    $query = <<<SQL
CREATE TABLE IF NOT EXISTS `civicrm_eway_contribution_transactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique EwayContributionTransactions ID',
    `contribution_id` INT UNSIGNED COMMENT 'FK to Contact',
    `payment_processor_id` INT UNSIGNED COMMENT 'FK to PaymentProcessor',
    `access_code` TEXT,
    `failed_message` TEXT DEFAULT NULL,
    `status` INT UNSIGNED DEFAULT 0,
    `tries` INT UNSIGNED DEFAULT 0,
    PRIMARY KEY(`id`),
    CONSTRAINT FK_civicrm_eway_contribution_transactions_contribution_id FOREIGN KEY(`contribution_id`) REFERENCES `civicrm_contribution` (`id`) ON DELETE CASCADE,
    CONSTRAINT FK_civicrm_eway_contribution_transactions_payment_processor_id FOREIGN KEY(`payment_processor_id`) REFERENCES `civicrm_payment_processor` (`id`) ON DELETE CASCADE
);
SQL;
    CRM_Core_DAO::executeQuery($query);
    return TRUE;
  }

  public function upgrade_7() {
    $this->ctx->log->info('Add email receipt field to eWAY Contribution Transactions store.');
    CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_eway_contribution_transactions` ADD `is_email_receipt` TINYINT(1) DEFAULT 1");
    return TRUE;
  }

  public function upgrade_20201() {
    $this->ctx->log->info('Applying 2.2.1 update; Fix billing mode for payment processor.');
    CRM_Core_DAO::executeQuery("UPDATE civicrm_payment_processor_type SET billing_mode = 4 WHERE name = 'eWay_Recurring'");
    return TRUE;
  }

  public function upgrade_20300() {
    $this->ctx->log->info('Apply 2.3.0 update; Drop tables supporting unmaintained cycle day.');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS `civicrm_ewayrecurring`');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS `civicrm_contribution_page_recur_cycle`');
    return TRUE;
  }

  public function upgrade_20600() {
    $this->ctx->log->info('Apply 2.6.0 update; Update class names for eWAYRecurring payment processor type.');
    PaymentProcessorType::update(FALSE)
      ->addValue('class_name', 'Payment_eWAYRecurring')
      ->addWhere('class_name', '=', 'au.com.agileware.ewayrecurring')
      ->execute();
    return TRUE;
  }

}
