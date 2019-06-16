<?php

return [
  'eway_recurring_contribution_max_retry' => [
    'group_name' => 'eWay Recurring Settings',
    'group' => 'eWAYRecurring',
    'name' => 'eway_recurring_contribution_max_fail_attempts',
    'type' => 'Integer',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => '3',
    'description' => 'Maximum number of retries for failed eWay recurring contributions.',
    'title' => 'Maximum Number of Retries',
    'help_text' => 'Maximum number of retries for failed eWay recurring contributions.',
    'html_type' => 'Text',
    'html_attributes' => [
      'size' => 50,
    ],
    'quick_form_type' => 'Element',
  ],
  'eway_recurring_contribution_retry_delay' => [
    'group_name' => 'eWay Recurring Settings',
    'group' => 'eWAYRecurring',
    'name' => 'eway_recurring_contribution_retry_delay',
    'type' => 'Integer',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => '4',
    'description' => 'Number of days after CiviCRM should retry recurring payment.',
    'title' => 'Retry delay(in days)',
    'help_text' => 'Number of days after CiviCRM should retry recurring payment.',
    'html_type' => 'Text',
    'html_attributes' => [
      'size' => 50,
    ],
    'quick_form_type' => 'Element',
  ],
];
