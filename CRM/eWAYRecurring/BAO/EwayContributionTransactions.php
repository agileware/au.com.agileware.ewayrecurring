<?php

class CRM_eWAYRecurring_BAO_EwayContributionTransactions extends CRM_eWAYRecurring_DAO_EwayContributionTransactions {

  /**
   * Create a new EwayContributionTransactions based on array-data
   *
   * @param array $params key-value pairs
   * @return _DAO_EwayContributionTransactions|NULL
   *
  public static function create($params) {
    $className = '_DAO_EwayContributionTransactions';
    $entityName = 'EwayContributionTransactions';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}
