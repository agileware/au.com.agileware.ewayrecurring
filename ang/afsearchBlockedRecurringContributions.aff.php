<?php
use CRM_eWAYRecurring_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Blocked Recurring Contributions'),
  'icon' => 'fa-list-alt',
  'server_route' => 'civicrm/contribute/blockedrecurring',
  'permission' => [
    'edit contributions',
  ],
];
