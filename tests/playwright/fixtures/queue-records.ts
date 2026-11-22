import { api4, evaluate } from './civi';
import { newAccessCode, onceOffPageId, uniqueDonor, type Donor } from './contribution-page';
import { liveProcessorId } from './recurring-records';

/**
 * Pending payments waiting in the verification queue, created straight in the database for the specs of the
 * verification job (PW-F5). A donor who goes to eWAY's hosted page and never comes back leaves exactly this: a Pending
 * contribution and a queue row (status 0, no tries yet) holding the access code eWAY gave. PW-F1-08 follows a real
 * donor there through the browser; these cases start from the abandoned state so they can be set up in a moment.
 */

export interface PendingPayment {
  donor: Donor;
  contactId: number;
  contributionId: number;
  accessCode: string;
  queueId: number;
  amount: number;
}

export interface PendingPaymentOptions {
  /** Shows in the donor's email address. */
  label: string;
  amount?: number;
  /** Whether the donor is to get a receipt when the payment completes (the queue row's flag). Default false. */
  receipt?: boolean;
}

/** A PHP single quoted string literal. */
function php(value: unknown): string {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

/** A donor, a Pending contribution of `amount` dollars on the seeded once-off page, and its queue row. */
export function createPendingPayment(options: PendingPaymentOptions): PendingPayment {
  const donor = uniqueDonor(options.label);
  const accessCode = newAccessCode(options.label.toUpperCase().slice(0, 6));
  const amount = options.amount ?? 25;
  const spec = {
    donor,
    accessCode,
    amount,
    receipt: options.receipt ? 1 : 0,
    processorId: liveProcessorId(),
    pageId: onceOffPageId(),
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
    $contributionId = (int) civicrm_api3('Contribution', 'create', [
      'contact_id' => $contactId,
      'total_amount' => $spec['amount'],
      'currency' => 'AUD',
      'financial_type_id' => 'Donation',
      'contribution_status_id' => 'Pending',
      'payment_instrument_id' => 1,
      'contribution_page_id' => $spec['pageId'],
      'invoice_id' => md5(uniqid('', TRUE)),
      'source' => 'Playwright abandoned payment',
      'is_email_receipt' => 0,
    ])['id'];
    $queueId = (int) civicrm_api3('EwayContributionTransactions', 'create', [
      'contribution_id' => $contributionId,
      'payment_processor_id' => $spec['processorId'],
      'access_code' => $spec['accessCode'],
      'is_email_receipt' => $spec['receipt'],
    ])['id'];
    echo json_encode(['contactId' => $contactId, 'contributionId' => $contributionId, 'queueId' => $queueId]);
  `);
  const ids = JSON.parse(out.slice(out.indexOf('{"contactId"'), out.lastIndexOf('}') + 1));
  return { donor, accessCode, amount, ...ids };
}

/** The queue row as the extension keeps it. */
export function queueRowById(queueId: number): Record<string, any> {
  const out = evaluate(
    `echo json_encode(civicrm_api3('EwayContributionTransactions', 'getsingle', ['id' => ${Number(queueId)}]));`,
  );
  return JSON.parse(out.slice(out.indexOf('{'), out.lastIndexOf('}') + 1));
}

/** A contribution's status name and transaction id. */
export function contributionState(contributionId: number): { status: string; trxnId: string | null; total: number; receiptDate: string | null } {
  const [row] = api4('Contribution', 'get', {
    select: ['contribution_status_id:name', 'trxn_id', 'total_amount', 'receipt_date'],
    where: [['id', '=', contributionId], ['is_test', 'IN', [false, true]]],
  });
  return { status: row['contribution_status_id:name'], trxnId: row.trxn_id ?? null, total: Number(row.total_amount), receiptDate: row.receipt_date ?? null };
}

/** How many mails the site has caught (outbound mail is redirected to the database) for one recipient address. */
export function mailCountFor(email: string): number {
  return Number(evaluate(
    `echo (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_spool WHERE recipient_email = %1', [1 => [${php(email)}, 'String']]);`,
  ));
}

/** The subjects of the mails the site has caught for one recipient address, oldest first. */
export function mailSubjectsFor(email: string): string[] {
  const out = evaluate(`
    $dao = CRM_Core_DAO::executeQuery('SELECT headers FROM civicrm_mailing_spool WHERE recipient_email = %1 ORDER BY id', [1 => [${php(email)}, 'String']]);
    $subjects = [];
    while ($dao->fetch()) {
      $subjects[] = preg_match('/^Subject: (.*)$/mi', $dao->headers, $m) ? trim($m[1]) : '';
    }
    echo json_encode($subjects);
  `);
  return JSON.parse(out.slice(out.indexOf('['), out.lastIndexOf(']') + 1));
}
