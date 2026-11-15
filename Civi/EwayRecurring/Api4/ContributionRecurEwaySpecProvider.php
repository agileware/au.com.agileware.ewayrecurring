<?php

namespace Civi\EwayRecurring\Api4;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\Provider\Generic\SpecProviderInterface;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Adds one calculated yes/no field to ContributionRecur: is_eway_processor.
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
