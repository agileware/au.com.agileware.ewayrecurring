<?php
use CRM_eWAYRecurring_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Failed Recurring Contributions'),
  'icon' => 'fa-list-alt',
  'server_route' => 'civicrm/contribute/failedrecurring',
  'permission' => [
    'edit contributions',
  ],
];
