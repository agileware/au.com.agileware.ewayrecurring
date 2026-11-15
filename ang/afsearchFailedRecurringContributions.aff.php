<?php
use CRM_eWAYRecurring_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Failed Recurring Contributions'),
  'icon' => 'fa-list-alt',
  'server_route' => 'civicrm/contribute/failedrecurring',
  'permission' => [
    // The list reads the payment processor, which only an administrator may read (DEF-62).
    'administer CiviCRM',
  ],
];
