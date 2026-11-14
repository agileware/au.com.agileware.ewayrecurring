import { api4, evaluate } from './civi';
import { newCustomerToken, uniqueDonor, type Donor } from './contribution-page';
import testData from './test-data.json';

/**
 * Recurring contributions, saved cards and verification queue rows created straight in the database, for the specs
 * that start from "a donor already has a recurring contribution" (update billing details, the verification queue).
 * Each call makes a donor with an email address no earlier run used, so a test never depends on what another test
 * left behind and nothing here needs the seed.
 *
 * Dates are written the way PHP's strtotime reads them ('-1 day', '+1 month'), and worked out on the server, so they
 * agree with the clock the extension uses.
 */

/** The status names of a recurring contribution that these specs need. */
export type RecurStatus = 'In Progress' | 'Pending' | 'Failed' | 'Completed' | 'Cancelled';

export interface RecurringOptions {
  /** Shows in the donor's email address, which helps when reading the site. */
  label: string;
  status?: RecurStatus;
  /** When the next payment is due (strtotime text). Default ten days ahead, so the cron job leaves it alone; pass '-1 day' for a record the job should charge. */
  nextDue?: string;
  failureCount?: number;
  /** The date the next retry is due (strtotime text), for a record that has failed. */
  retryDate?: string;
  /** Give the donor a billing address (default true). */
  billingAddress?: boolean;
  /** Give the record a Completed contribution, which the cron job needs before it will charge it (default true). */
  completedContribution?: boolean;
  /** Extra saved cards the donor has, besides the one on the record. */
  extraCards?: { token?: string; masked?: string; expiry?: string }[];
  /** Expiry of the card on the record (strtotime text or a date). Default two years ahead. */
  cardExpiry?: string;
  amount?: number;
}

export interface RecurringRecord {
  donor: Donor;
  contactId: number;
  recurId: number;
  /** The id of the card the record is charging. */
  tokenId: number;
  /** eWAY's customer token for that card. */
  token: string;
  /** The ids of the extra cards, in the order asked for. */
  extraTokenIds: number[];
  processorId: number;
  /** The id of the Completed contribution, when one was made. */
  contributionId: number | null;
}

/** A PHP single quoted string literal. */
function php(value: unknown): string {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

/** The id of the seeded live eWAY processor. */
export function liveProcessorId(): number {
  const rows = api4<{ id: number }>('PaymentProcessor', 'get', {
    select: ['id'],
    where: [['name', '=', testData.processors.live], ['is_test', '=', false]],
  });
  if (!rows.length) {
    throw new Error(`The seeded processor "${testData.processors.live}" does not exist. Run the setup script.`);
  }
  return rows[0].id;
}

/**
 * A donor with a billing address, a saved card and a recurring contribution charging that card. Everything is
 * created in one call to the site.
 */
export function createRecurring(options: RecurringOptions): RecurringRecord {
  const donor = uniqueDonor(options.label);
  const token = newCustomerToken();
  const spec = {
    donor,
    status: options.status ?? 'In Progress',
    nextDue: options.nextDue ?? '+10 days',
    failureCount: options.failureCount ?? 0,
    retryDate: options.retryDate ?? null,
    billingAddress: options.billingAddress ?? true,
    completedContribution: options.completedContribution ?? true,
    cardExpiry: options.cardExpiry ?? '+2 years',
    extraCards: options.extraCards ?? [],
    amount: options.amount ?? 10,
    token,
    processorId: liveProcessorId(),
  };
  const out = evaluate(`
    $spec = json_decode(${php(JSON.stringify(spec))}, TRUE);
    $d = $spec['donor'];
    $contactId = (int) civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => $d['firstName'],
      'last_name' => $d['lastName'],
      'email' => $d['email'],
    ])['id'];
    if ($spec['billingAddress']) {
      $country = \\Civi\\Api4\\Country::get(FALSE)->addWhere('name', '=', $d['country'])->execute()->first();
      $state = \\Civi\\Api4\\StateProvince::get(FALSE)->addWhere('name', '=', $d['state'])->addWhere('country_id', '=', $country['id'])->execute()->first();
      civicrm_api3('Address', 'create', [
        'contact_id' => $contactId,
        'location_type_id' => 'Billing',
        'is_billing' => 1,
        'is_primary' => 0,
        'street_address' => $d['street'],
        'city' => $d['city'],
        'postal_code' => $d['postcode'],
        'country_id' => $country['id'],
        'state_province_id' => $state['id'],
      ]);
    }
    $expiry = function ($when) {
      return date('Y-m-t', strtotime($when));
    };
    $tokenId = (int) civicrm_api3('PaymentToken', 'create', [
      'contact_id' => $contactId,
      'payment_processor_id' => $spec['processorId'],
      'token' => $spec['token'],
      'expiry_date' => $expiry($spec['cardExpiry']),
      'masked_account_number' => '444433XXXXXX1111',
      'created_date' => 'now',
    ])['id'];
    $extraIds = [];
    foreach ($spec['extraCards'] as $card) {
      $extraIds[] = (int) civicrm_api3('PaymentToken', 'create', [
        'contact_id' => $contactId,
        'payment_processor_id' => $spec['processorId'],
        'token' => $card['token'] ?? ('9' . random_int(10000000000, 99999999999)),
        'expiry_date' => $expiry($card['expiry'] ?? '+3 years'),
        'masked_account_number' => $card['masked'] ?? '510510XXXXXX5100',
        'created_date' => 'now',
      ])['id'];
    }
    $recurValues = [
      'contact_id' => $contactId,
      'amount' => $spec['amount'],
      'currency' => 'AUD',
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'installments' => 0,
      'contribution_status_id:name' => $spec['status'],
      'financial_type_id' => 1,
      'payment_processor_id' => $spec['processorId'],
      'payment_token_id' => $tokenId,
      'processor_id' => $spec['token'],
      'is_test' => 0,
      'failure_count' => $spec['failureCount'],
      'start_date' => date('Y-m-d H:i:s', strtotime('-2 months')),
      'next_sched_contribution_date' => date('Y-m-d 00:00:00', strtotime($spec['nextDue'])),
    ];
    if ($spec['retryDate']) {
      $recurValues['failure_retry_date'] = date('Y-m-d 00:00:00', strtotime($spec['retryDate']));
    }
    $recurId = (int) \\Civi\\Api4\\ContributionRecur::create(FALSE)->setValues($recurValues)->execute()->first()['id'];
    $contributionId = NULL;
    if ($spec['completedContribution']) {
      $contributionId = (int) civicrm_api3('Contribution', 'create', [
        'contact_id' => $contactId,
        'total_amount' => $spec['amount'],
        'financial_type_id' => 1,
        'contribution_status_id' => 'Completed',
        'contribution_recur_id' => $recurId,
        'receive_date' => date('Y-m-d H:i:s', strtotime('-1 month')),
        'payment_instrument_id' => 1,
        'currency' => 'AUD',
        'invoice_id' => md5(uniqid('', TRUE)),
        'is_email_receipt' => 0,
      ])['id'];
    }
    echo json_encode([
      'donor' => $d,
      'contactId' => $contactId,
      'recurId' => $recurId,
      'tokenId' => $tokenId,
      'token' => $spec['token'],
      'extraTokenIds' => $extraIds,
      'processorId' => $spec['processorId'],
      'contributionId' => $contributionId,
    ]);
  `);
  // Anything else the site printed (a PHP warning from another extension, say) would break the JSON; show it.
  const start = out.indexOf('{"donor"');
  return JSON.parse(out.slice(start, out.lastIndexOf('}') + 1)) as RecurringRecord;
}

/** The recurring contribution with the fields these specs assert on. */
export function recurRow(recurId: number): Record<string, any> {
  const rows = api4('ContributionRecur', 'get', {
    select: [
      'id', 'contact_id', 'amount', 'contribution_status_id:name', 'payment_processor_id', 'processor_id',
      'payment_token_id', 'failure_count', 'failure_retry_date', 'next_sched_contribution_date', 'is_test',
    ],
    where: [['id', '=', recurId], ['is_test', 'IN', [false, true]]],
  });
  return rows[0];
}

/** The contributions that belong to a recurring contribution, oldest first. */
export function contributionsOf(recurId: number): Record<string, any>[] {
  return api4('Contribution', 'get', {
    select: ['id', 'contribution_status_id:name', 'total_amount', 'trxn_id', 'receive_date'],
    where: [['contribution_recur_id', '=', recurId], ['is_test', 'IN', [false, true]]],
    orderBy: { id: 'ASC' },
  });
}

/** The contact's billing address (the one marked as billing), or undefined. */
export function billingAddressOf(contactId: number): Record<string, any> | undefined {
  return api4('Address', 'get', {
    select: ['id', 'street_address', 'city', 'postal_code', 'state_province_id:label', 'country_id:label', 'location_type_id:name'],
    where: [['contact_id', '=', contactId], ['is_billing', '=', true]],
    orderBy: { id: 'ASC' },
  })[0];
}

/** The saved cards (payment token rows) a contact has, oldest first. */
export function cardsOf(contactId: number): Record<string, any>[] {
  return api4('PaymentToken', 'get', {
    select: ['id', 'token', 'masked_account_number', 'expiry_date', 'payment_processor_id', 'contact_id'],
    where: [['contact_id', '=', contactId]],
    orderBy: { id: 'ASC' },
  });
}

/** The activities of one type that are about a contact (as source or target), newest first. */
export function activitiesOf(contactId: number, typeName: string): Record<string, any>[] {
  return api4('Activity', 'get', {
    select: ['id', 'subject', 'activity_type_id:name', 'details'],
    where: [['activity_type_id:name', '=', typeName], ['OR', [['source_contact_id', '=', contactId], ['target_contact_id', 'CONTAINS', contactId]]]],
    orderBy: { id: 'DESC' },
  });
}

/**
 * Move every due recurring contribution on the site other than `keepRecurId` a long way into the future, and clear
 * any retry date that is due, so the cron job charges only the record under test. The cron job asks eWAY for every
 * record that is due, and the stub answers by method and order, not by customer, so a leftover due record from an
 * earlier test would take the scripted answers. Only the schedule changes; nothing is charged.
 */
export function setOtherDueRecurringAside(keepRecurId: number): void {
  evaluate(`
    $rows = \\Civi\\Api4\\ContributionRecur::get(FALSE)
      ->addSelect('id', 'next_sched_contribution_date', 'failure_retry_date')
      ->addWhere('id', '!=', ${Number(keepRecurId)})
      ->addWhere('is_test', 'IN', [FALSE, TRUE])
      ->addWhere('contribution_status_id:name', 'IN', ['In Progress', 'Pending'])
      ->execute();
    foreach ($rows as $row) {
      $values = [];
      if (!empty($row['next_sched_contribution_date']) && strtotime($row['next_sched_contribution_date']) <= time()) {
        $values['next_sched_contribution_date'] = date('Y-m-d 00:00:00', strtotime('+10 years'));
      }
      if (!empty($row['failure_retry_date']) && strtotime($row['failure_retry_date']) <= time()) {
        $values['failure_retry_date'] = date('Y-m-d 00:00:00', strtotime('+10 years'));
      }
      if ($values) {
        \\Civi\\Api4\\ContributionRecur::update(FALSE)->addWhere('id', '=', $row['id'])->setValues($values)->execute();
      }
    }
  `);
}

/**
 * Run the scheduled recurring payments job (the call the scheduled job makes). The stub must have a postTransaction
 * answer queued for each record that is due.
 */
export function runRecurringJob(): void {
  evaluate(`civicrm_api3('Job', 'run_payment_cron', ['processor_name' => 'eWay_Recurring']);`);
}

/** The setting that says how many failed charges a record may have before it is marked Failed. */
export function maxRetries(): number {
  return Number(evaluate(`echo (int) Civi::settings()->get('eway_recurring_contribution_max_retry');`));
}

/**
 * Let time pass for a recurring contribution that has failed, as far as the cron job can tell. The job retries a
 * record on or after its retry date, and treats the record as already handled if it has a contribution received on or
 * after that date, so after each failed run the failure (a contribution made just now) and the retry date (a few days
 * out) have to be moved back: the contributions made in the last two days are dated three days ago, and the retry date
 * becomes yesterday. Nothing is charged.
 */
export function makeRetryDue(recurId: number): void {
  evaluate(`
    CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_contribution SET receive_date = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE contribution_recur_id = %1 AND receive_date > DATE_SUB(NOW(), INTERVAL 2 DAY)',
      [1 => [${Number(recurId)}, 'Integer']]
    );
    \\Civi\\Api4\\ContributionRecur::update(FALSE)
      ->addWhere('id', '=', ${Number(recurId)})
      ->addValue('failure_retry_date', date('Y-m-d 00:00:00', strtotime('-1 day')))
      ->execute();
  `);
}

/**
 * Make a recurring contribution's next payment due (yesterday), and move the contributions made in the last two days
 * back, so the cron job charges it. Used to let the schedule date arrive.
 */
export function makeNextPaymentDue(recurId: number): void {
  evaluate(`
    CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_contribution SET receive_date = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE contribution_recur_id = %1 AND receive_date > DATE_SUB(NOW(), INTERVAL 2 DAY)',
      [1 => [${Number(recurId)}, 'Integer']]
    );
    \\Civi\\Api4\\ContributionRecur::update(FALSE)
      ->addWhere('id', '=', ${Number(recurId)})
      ->addValue('next_sched_contribution_date', date('Y-m-d 00:00:00', strtotime('-1 day')))
      ->execute();
  `);
}
