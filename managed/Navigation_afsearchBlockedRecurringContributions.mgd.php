<?php
use CRM_eWAYRecurring_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_afsearchBlockedRecurringContributions',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Blocked Recurring Contributions'),
        'name' => 'afsearchBlockedRecurringContributions',
        'url' => 'civicrm/contribute/blockedrecurring',
        'icon' => 'crm-i fa-list-alt',
        'permission' => [
          'edit contributions',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 16,
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];
