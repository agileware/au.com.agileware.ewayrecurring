<?php

// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
return [
  [
    'name' => 'Cron:EwaySettlement.Sync',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'eWay Settlement Sync',
      'description' => 'Queries the eWAY Settlement Search API and reconciles fee_amount and net_amount on Completed contributions that have not yet been reconciled.',
      'run_frequency' => 'Daily',
      'api_entity' => 'EwaySettlement',
      'api_action' => 'Sync',
      'parameters' => '',
      'is_active' => 0,
    ],
  ],
];
