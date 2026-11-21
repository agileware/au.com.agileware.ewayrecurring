<?php
use CRM_eWAYRecurring_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Blocked_Recurring_Contributions',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Blocked_Recurring_Contributions',
        'label' => E::ts('Blocked Recurring Contributions'),
        'api_entity' => 'ContributionRecur',
        'api_params' => [
          'version' => 4,
          'select' => [
            'contact_id',
            'contact_id.display_name',
            'id',
            'next_sched_contribution_date',
            'failure_retry_date',
            'contribution_status_id:label',
            'amount',
            'frequency_interval',
            'frequency_unit:label',
          ],
          'orderBy' => [],
          'where' => [
            // Calculated fields the extension adds to ContributionRecur (see
            // Civi\EwayRecurring\Api4\ContributionRecurEwaySpecProvider): paid through eWAY, and due but kept from being
            // charged by a Cancelled contribution dated on or after the date it is waiting on (FEATURES.md, FR-6).
            ['is_eway_processor', '=', TRUE],
            ['is_blocked_by_cancelled_contribution', '=', TRUE],
            ['is_test', '=', FALSE],
            ['contribution_status_id:name', '=', 'In Progress'],
          ],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Blocked_Recurring_Contributions_SearchDisplay_Blocked_Recurring_Contribution_Listing',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Blocked_Recurring_Contribution_Listing',
        'label' => E::ts('Blocked Recurring Contribution Listing'),
        'saved_search_id.name' => 'Blocked_Recurring_Contributions',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            ['next_sched_contribution_date', 'ASC'],
          ],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'contact_id.display_name',
              'label' => E::ts('Contact'),
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'contact_id',
                'target' => '',
                'task' => '',
              ],
              'title' => E::ts('View Contact'),
            ],
            [
              'type' => 'field',
              'key' => 'id',
              'label' => E::ts('Recurring ID'),
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/contact/view/contributionrecur?reset=1&id=[id]&cid=[contact_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => 'crm-popup',
                'task' => '',
              ],
              'title' => E::ts('View the recurring contribution and its contributions'),
            ],
            [
              'type' => 'field',
              'key' => 'next_sched_contribution_date',
              'label' => E::ts('Next Scheduled Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'failure_retry_date',
              'label' => E::ts('Retry Failed Attempt On'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'amount',
              'label' => E::ts('Amount'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'frequency_unit:label',
              'label' => E::ts('Frequency'),
              'sortable' => FALSE,
              'rewrite' => '[frequency_interval] [frequency_unit:label]',
            ],
            [
              'size' => 'btn-sm',
              'links' => [
                [
                  'path' => '',
                  'icon' => 'fa-undo',
                  'text' => E::ts('Reactivate'),
                  'style' => 'success',
                  'conditions' => [],
                  'task' => 'reset_recur_status',
                  'entity' => 'ContributionRecur',
                  'action' => '',
                  'join' => '',
                  'target' => 'crm-popup',
                ],
                [
                  'path' => '',
                  'icon' => 'fa-ban',
                  'text' => E::ts('Cancel'),
                  'style' => 'danger',
                  'conditions' => [],
                  'task' => 'cancel_recur',
                  'entity' => 'ContributionRecur',
                  'action' => '',
                  'join' => '',
                  'target' => 'crm-popup',
                ],
              ],
              'type' => 'buttons',
              'alignment' => 'text-right',
            ],
          ],
          'actions' => [
            'download',
            'reset_recur_status',
            'cancel_recur',
          ],
          'classes' => [
            'table',
            'table-striped',
          ],
          'columnMode' => 'custom',
          'actions_display_mode' => 'buttons',
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];
