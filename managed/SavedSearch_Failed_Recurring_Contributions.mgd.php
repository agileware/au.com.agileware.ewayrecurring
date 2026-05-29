<?php
use CRM_eWAYRecurring_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_Failed_Recurring_Contributions',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Failed_Recurring_Contributions',
        'label' => 'Failed Recurring Contributions',
        'api_entity' => 'ContributionRecur',
        'api_params' => [
          'version' => 4,
          'select' => [
            'contact_id.display_name',
            'id',
            'start_date',
            'end_date',
            'next_sched_contribution_date',
            'contribution_status_id:label',
            'amount',
            'frequency_interval',
            'frequency_unit:label',
            'failure_count',
            'failure_retry_date',
            'ContributionRecur_PaymentProcessor_payment_processor_id_01.title',
          ],
          'orderBy' => [],
          'where' => [
            [
              'ContributionRecur_PaymentProcessor_payment_processor_id_01.payment_processor_type_id:name',
              '=',
              'eWay_Recurring',
            ],
            [
              'OR',
              [
                [
                  'is_test',
                  '=',
                  FALSE,
                ],
              ],
            ],
          ],
          'groupBy' => [],
          'join' => [
            [
              'PaymentToken AS ContributionRecur_PaymentToken_payment_token_id_01',
              'INNER',
              [
                'payment_token_id',
                '=',
                'ContributionRecur_PaymentToken_payment_token_id_01.id',
              ],
            ],
            [
              'PaymentProcessor AS ContributionRecur_PaymentProcessor_payment_processor_id_01',
              'INNER',
              [
                'payment_processor_id',
                '=',
                'ContributionRecur_PaymentProcessor_payment_processor_id_01.id',
              ],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Failed_Recurring_Contributions_SearchDisplay_Failed_Recurring_Contribution_Listing',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Failed_Recurring_Contribution_Listing',
        'label' => 'Failed Recurring Contribution Listing',
        'saved_search_id.name' => 'Failed_Recurring_Contributions',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [
            [
              'contribution_status_id:label',
              'ASC',
            ],
            [
              'failure_retry_date',
              'ASC',
            ],
          ],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'contact_id.display_name',
              'label' => 'Contact',
              'sortable' => TRUE,
              'link' => [
                'path' => '',
                'entity' => 'Contact',
                'action' => 'view',
                'join' => 'contact_id',
                'target' => '',
                'task' => '',
              ],
              'title' => 'View Contact',
            ],
            [
              'type' => 'field',
              'key' => 'id',
              'label' => 'ID',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'start_date',
              'label' => 'Start Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'next_sched_contribution_date',
              'label' => 'Next Scheduled Date',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'contribution_status_id:label',
              'label' => 'Status',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'amount',
              'label' => 'Amount',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'frequency_unit:label',
              'label' => 'Frequency',
              'sortable' => FALSE,
              'rewrite' => '[frequency_interval] [frequency_unit:label]',
            ],
            [
              'type' => 'field',
              'key' => 'failure_count',
              'label' => 'Number of Failures',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'failure_retry_date',
              'label' => 'Retry Failed Attempt On',
              'sortable' => TRUE,
            ],
            [
              'size' => 'btn-sm',
              'links' => [
                [
                  'path' => '',
                  'icon' => 'fa-undo',
                  'text' => 'Reactivate',
                  'style' => 'success',
                  'conditions' => [
                    [
                      'contribution_status_id:name',
                      'IN',
                      [
                        'Failing',
                        'Failed',
                      ],
                    ],
                  ],
                  'task' => 'reset_recur_status',
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
            'update',
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