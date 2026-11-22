<?php

namespace Civi\EwayRecurring\Api4;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\Provider\Generic\SpecProviderInterface;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Adds two calculated yes/no fields to ContributionRecur: is_eway_processor (this file's first part) and
 * is_blocked_by_cancelled_contribution (FEATURES.md, FR-6).
 *
 * The Failed Recurring Contributions list shows only records paid through eWAY. It used to find them by joining the
 * payment processor, but APIv4 drops a join to an entity the user may not read, and PaymentProcessor (which holds the API
 * credentials) is for administrators only, so the list failed for everyone else (DEF-62). This field answers the same
 * question inside the SQL of the query, with no join and no permission to read PaymentProcessor, and exposes nothing but
 * 1 or 0. It reads no credential and no processor name, and it is not a way to read any processor field.
 *
 * It is registered as a spec provider in ewayrecurring_civicrm_container() (FEATURES.md, FR-4, decision F1).
 */
class ContributionRecurEwaySpecProvider implements SpecProviderInterface {

  /**
   * The name of the calculated field.
   */
  const FIELD = 'is_eway_processor';

  /**
   * The name of the eWAY processor type (the managed record in eWAYRecurring.php).
   */
  const PROCESSOR_TYPE = 'eWay_Recurring';

  /**
   * The name of the second calculated field (FR-6).
   */
  const BLOCKED_FIELD = 'is_blocked_by_cancelled_contribution';

  /**
   * @param \Civi\Api4\Service\Spec\RequestSpec $spec
   */
  public function modifySpec(RequestSpec $spec): void {
    $field = new FieldSpec(self::FIELD, $spec->getEntity(), 'Boolean');
    $field->setLabel(ts('Paid Through eWAY'))
      ->setTitle(ts('Paid Through eWAY'))
      ->setDescription(ts('Yes when the payment processor is an eWAY Recurring one. Shows nothing else about the processor.'))
      // Like the core fields of this kind, it sits on the processor id column and is worked out in SQL.
      ->setColumnName('payment_processor_id')
      ->setType('Extra')
      ->setInputType('CheckBox')
      ->setReadonly(TRUE)
      ->setSqlRenderer([__CLASS__, 'renderSql']);
    $spec->addFieldSpec($field);

    $blocked = new FieldSpec(self::BLOCKED_FIELD, $spec->getEntity(), 'Boolean');
    $blocked->setLabel(ts('Waiting on a Cancelled Contribution'))
      ->setTitle(ts('Waiting on a Cancelled Contribution'))
      ->setDescription(ts('Yes when the record is due to be charged but a Cancelled contribution dated on or after the date it is waiting on stops the scheduled job charging it.'))
      ->setColumnName('id')
      ->setType('Extra')
      ->setInputType('CheckBox')
      ->setReadonly(TRUE)
      ->setSqlRenderer([__CLASS__, 'renderBlockedSql']);
    $spec->addFieldSpec($blocked);
  }

  /**
   * Only reading: the field cannot be written, and nothing else needs it.
   *
   * @param string $entity
   * @param string $action
   * @return bool
   */
  public function applies($entity, $action): bool {
    return $entity === 'ContributionRecur' && $action === 'get';
  }

  /**
   * The SQL for is_blocked_by_cancelled_contribution: 1 when the record's waiting date has passed (its retry date while it
   * has one, otherwise its next scheduled date) and it has a Cancelled contribution dated on or after that date. That is
   * the contribution that makes the scheduled job skip the record (ProcessTrait::get_scheduled_contributions()), so the
   * record would be charged but for it. Other conditions (eWAY, In Progress, live) are for the caller to add.
   *
   * @param array $field
   *   The field, with `sql_name` set to the recurring record's id column (table alias, then column).
   * @return string
   */
  public static function renderBlockedSql(array $field): string {
    $idColumn = $field['sql_name'];
    $t = substr($idColumn, 0, strrpos($idColumn, '.'));
    $waitingOn = "IF({$t}.`failure_retry_date` IS NOT NULL, {$t}.`failure_retry_date`, {$t}.`next_sched_contribution_date`)";
    $cancelled = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Cancelled');
    return "IF(EXISTS ("
      . "SELECT 1 FROM `civicrm_contribution` `blocked_c` "
      . "WHERE `blocked_c`.`contribution_recur_id` = {$idColumn} "
      . "AND `blocked_c`.`contribution_status_id` = {$cancelled} "
      . "AND {$waitingOn} <= NOW() "
      . "AND `blocked_c`.`receive_date` >= {$waitingOn}"
      . "), 1, 0)";
  }

  /**
   * The SQL for the field: 1 when the row's payment processor is of the eWAY type, otherwise 0 (including no processor).
   *
   * @param array $field
   *   The field, with `sql_name` set to the recurring record's payment_processor_id column.
   * @return string
   */
  public static function renderSql(array $field): string {
    $type = self::PROCESSOR_TYPE;
    return "IF(EXISTS ("
      . "SELECT 1 FROM `civicrm_payment_processor` `eway_pp` "
      . "INNER JOIN `civicrm_payment_processor_type` `eway_ppt` ON `eway_ppt`.`id` = `eway_pp`.`payment_processor_type_id` "
      . "WHERE `eway_pp`.`id` = {$field['sql_name']} AND `eway_ppt`.`name` = '$type'"
      . "), 1, 0)";
  }

}
