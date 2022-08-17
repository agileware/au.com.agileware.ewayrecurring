<?php

use CRM_eWAYRecurring_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_eWAYRecurring_Upgrader extends CRM_eWAYRecurring_Upgrader_Base {
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
}
