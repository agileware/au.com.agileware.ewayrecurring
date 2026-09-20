<?php

use CRM_eWAYRecurring_ExtensionUtil as E;

/**
 * EwaySettlement.Sync API specification.
 *
 * @param array $spec
 */
function _civicrm_api3_eway_settlement_Sync_spec(&$spec) {
  $spec['contribution_id'] = [
    'name' => 'contribution_id',
    'title' => 'Contribution ID',
    'description' => 'Optional. Restrict the sync to a single contribution (manual / QA use). '
      . 'The contribution must satisfy every guard and fall within '
      . 'eway_settlement_window_days; only that contribution\'s own processor is queried.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 0,
  ];
}

/**
 * EwaySettlement.Sync API
 *
 * Queries the eWAY Settlement Search API and reconciles fee_amount and
 * net_amount on Completed contributions that have not yet been reconciled.
 *
 * Invoked by the "eWay Settlement Sync" scheduled job (Daily). Pass
 * contribution_id to reconcile a single contribution: it is still subject to
 * the settlement window and all safety guards, and only its own processor is
 * queried.
 *
 * @param array $params
 * @return array API result descriptor
 */
function civicrm_api3_eway_settlement_Sync($params) {
  $contributionId = !empty($params['contribution_id']) ? (int) $params['contribution_id'] : NULL;
  $sync = new CRM_eWAYRecurring_SettlementSync();
  $sync->sync($contributionId);
  return civicrm_api3_create_success([], $params, 'EwaySettlement', 'Sync');
}
