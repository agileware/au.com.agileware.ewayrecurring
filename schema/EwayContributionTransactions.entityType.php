<?php
use CRM_eWAYRecurring_ExtensionUtil as E;

return [
  'name' => 'EwayContributionTransactions',
  'table' => 'civicrm_eway_contribution_transactions',
  'class' => 'CRM_eWAYRecurring_DAO_EwayContributionTransactions',
  'getInfo' => fn() => [
    'title' => E::ts('Eway Contribution Transactions'),
    'title_plural' => E::ts('Eway Contribution Transactionses'),
    'description' => E::ts('FIXME'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique EwayContributionTransactions ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'contribution_id' => [
      'title' => E::ts('Contribution ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to Contribution'),
      'entity_reference' => [
        'entity' => 'Contribution',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'payment_processor_id' => [
      'title' => E::ts('Payment Processor ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to PaymentProcessor'),
      'entity_reference' => [
        'entity' => 'PaymentProcessor',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'access_code' => [
      'title' => E::ts('Access Code'),
      'sql_type' => 'text',
      'input_type' => 'Text',
      'usage' => [
        'import',
        'export',
        'duplicate_matching',
      ],
    ],
    'failed_message' => [
      'title' => E::ts('Failed Message'),
      'sql_type' => 'text',
      'input_type' => 'Text',
      'default' => NULL,
      'usage' => [
        'import',
        'export',
        'duplicate_matching',
      ],
    ],
    'status' => [
      'title' => E::ts('Status'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'default' => 0,
    ],
    'tries' => [
      'title' => E::ts('Tries'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'default' => 0,
    ],
    'is_email_receipt' => [
      'title' => E::ts('Is Email Receipt'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'description' => E::ts('Should CRM send receipt email when payment completed?'),
      'default' => 1,
    ],
  ],
];
