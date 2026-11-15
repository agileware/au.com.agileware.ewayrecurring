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
          // The list reads the payment processor, which only an administrator may read (DEF-62).
          'administer CiviCRM',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 15,
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];
