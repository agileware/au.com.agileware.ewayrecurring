<?php
use CRM_eWAYRecurring_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_afsearchFailedRecurringContributions',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Failed Recurring Contributions'),
        'name' => 'afsearchFailedRecurringContributions',
        'url' => 'civicrm/contribute/failedrecurring',
        'icon' => 'crm-i fa-list-alt',
        'permission' => [
          'edit contributions',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 15,
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];
