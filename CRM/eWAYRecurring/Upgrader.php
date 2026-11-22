<?php

use Civi\Api4\PaymentProcessor;
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
    // A fresh install already has the column, and a step must be safe to run again.
    if (!CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_eway_contribution_transactions', 'is_email_receipt')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_eway_contribution_transactions` ADD `is_email_receipt` TINYINT(1) DEFAULT 1");
    }
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

  public function upgrade_20601() {
    $this->ctx->log->info('Apply 2.6.1 update; Update class names for eWAYRecurring payment processor type.');
    PaymentProcessorType::update(FALSE)
      ->addValue('class_name', 'Payment_eWAYRecurring')
      ->addWhere('class_name', '=', 'au.com.agileware.ewayrecurring')
      ->execute();
    PaymentProcessor::update(FALSE)
      ->addValue('class_name', 'Payment_eWAYRecurring')
      ->addWhere('class_name', '=', 'au.com.agileware.ewayrecurring')
      ->addWhere('is_test', '=', FALSE)
      ->execute();
    return TRUE;
  }

  /**
   * 3.0.0: mark recurring contributions that the old retry behaviour left stuck as Failed.
   *
   * Up to 2.8.2 a recurring contribution that used up its retries kept the status In Progress
   * with its retry date set, so no cron list selected it and nothing showed it. From 3.0.0 such
   * a record is marked Failed when its retries run out, but ones that were already stuck at
   * upgrade time need this one-off repair.
   */
  public function upgrade_30000() {
    $this->log('Apply 3.0.0 update; Mark recurring contributions left stuck by the old retry behaviour as Failed.');
    $changed = $this->markStuckRecurringContributionsFailed();
    $this->log(sprintf(
      'Marked %d recurring contribution(s) Failed because their retries were used up (ids: %s), and %d that had a retry date but no failures (ids: %s).',
      count($changed['exhausted']),
      implode(', ', $changed['exhausted']) ?: 'none',
      count($changed['no_failures']),
      implode(', ', $changed['no_failures']) ?: 'none'
    ));
    return TRUE;
  }

  /**
   * 3.0.0: link an eWay Recurring processor type that pre-dates managed entities to the extension (DEF-57).
   *
   * @return bool
   */
  public function upgrade_30001() {
    $this->log('Apply 3.0.0 update; Link the eWay Recurring processor type to its managed record when it has none.');
    $this->log($this->linkProcessorTypeToManagedRecord()
      ? 'Added the missing managed record for the eWay Recurring processor type.'
      : 'The eWay Recurring processor type already has its managed record, or does not exist; nothing to do.');
    return TRUE;
  }

  /**
   * Make sure CiviCRM's managed record for the processor type exists and points at it.
   *
   * A site whose processor type was created before the extension declared it as a managed entity has the type but
   * no record in civicrm_managed. CiviCRM then tries to create the type again on every cache flush (and logs
   * "Unable to create managed entity ... already exists"), and disabling the extension leaves the type active.
   * The record is added only when it is missing, or when it points at a type id that no longer exists, so a
   * second run changes nothing. The type itself is not touched.
   *
   * @return bool TRUE when a record was added or repaired.
   */
  public function linkProcessorTypeToManagedRecord(): bool {
    $typeId = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_payment_processor_type WHERE name = 'eWay_Recurring' ORDER BY id LIMIT 1");
    if (!$typeId) {
      return FALSE;
    }
    $typeId = (int) $typeId;
    $module = 'au.com.agileware.ewayrecurring';
    $params = [1 => [$module, 'String'], 2 => ['eWay_Recurring', 'String']];

    $existing = CRM_Core_DAO::executeQuery(
      "SELECT id, entity_id FROM civicrm_managed WHERE module = %1 AND name = %2 AND entity_type = 'PaymentProcessorType' LIMIT 1",
      $params
    );
    if (!$existing->fetch()) {
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_managed (module, name, entity_type, entity_id) VALUES (%1, %2, 'PaymentProcessorType', %3)",
        $params + [3 => [$typeId, 'Integer']]
      );
      return TRUE;
    }
    if ((int) $existing->entity_id === $typeId) {
      return FALSE;
    }
    $stillThere = CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_payment_processor_type WHERE id = %1', [1 => [(int) $existing->entity_id, 'Integer']]);
    if ($stillThere) {
      // It points at another, existing type: leave it for an administrator to look at.
      return FALSE;
    }
    CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_managed SET entity_id = %1 WHERE id = %2',
      [1 => [$typeId, 'Integer'], 2 => [(int) $existing->id, 'Integer']]
    );
    return TRUE;
  }

  /**
   * Mark stuck recurring contributions Failed.
   *
   * Both rules apply only to In Progress, live (not test-mode) recurring contributions of an
   * eWAY recurring payment processor that have a retry date. Each rule is one SQL update, so no
   * hooks or rules run and no activity or mail is created.
   *
   * Rule 1: failures above 0 and at or above the retry limit saved when this runs.
   * Rule 2: no failures. Nothing picks these up either: the normal cron list needs no retry date
   * and the retry list needs at least one failure.
   *
   * Nothing but the status changes, and nobody is charged.
   *
   * @return array[] The ids changed, as ['exhausted' => int[], 'no_failures' => int[]].
   */
  public function markStuckRecurringContributionsFailed(): array {
    $inProgress = (int) CRM_eWAYRecurring_Utils::contribution_status_id('In Progress', TRUE);
    $failed = (int) CRM_eWAYRecurring_Utils::contribution_status_id('Failed', TRUE);
    $retryLimit = (int) Civi::settings()->get('eway_recurring_contribution_max_retry');

    $processorIds = CRM_Core_DAO::executeQuery(
      "SELECT pp.id FROM civicrm_payment_processor pp
       INNER JOIN civicrm_payment_processor_type ppt ON ppt.id = pp.payment_processor_type_id
       WHERE ppt.name = 'eWay_Recurring'"
    )->fetchAll();
    $processorIds = array_map('intval', array_column($processorIds, 'id'));
    if (!$processorIds) {
      return ['exhausted' => [], 'no_failures' => []];
    }

    $inScope = 'payment_processor_id IN (' . implode(', ', $processorIds) . ')'
      . " AND contribution_status_id = $inProgress"
      . ' AND COALESCE(is_test, 0) = 0'
      . ' AND failure_retry_date IS NOT NULL';
    $rules = [
      'exhausted' => "$inScope AND COALESCE(failure_count, 0) > 0 AND failure_count >= $retryLimit",
      'no_failures' => "$inScope AND COALESCE(failure_count, 0) = 0",
    ];

    $changed = [];
    foreach ($rules as $name => $where) {
      $ids = array_map('intval', array_column(
        CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_contribution_recur WHERE $where ORDER BY id")->fetchAll(),
        'id'
      ));
      if ($ids) {
        CRM_Core_DAO::executeQuery(
          "UPDATE civicrm_contribution_recur SET contribution_status_id = $failed WHERE id IN (" . implode(', ', $ids) . ')'
        );
      }
      $changed[$name] = $ids;
    }
    return $changed;
  }

  /**
   * Log to the upgrade output when running inside an upgrade, and always to the CiviCRM log.
   */
  private function log(string $message): void {
    if (!empty($this->ctx->log)) {
      $this->ctx->log->info($message);
    }
    Civi::log()->info('eWAY Recurring upgrade: ' . $message);
  }

}
