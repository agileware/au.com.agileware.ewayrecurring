import type { APIResponse, Page } from '@playwright/test';
import { frontUrl } from './cms';
import { api4, evaluate } from './civi';
import { responses } from './eway-stub';
import { uniqueDonor } from './contribution-page';
import { liveProcessorId } from './recurring-records';
import testData from './test-data.json';

/**
 * Helpers for the security specs (specs/security/, PW-S): plain HTTP requests made as an attacker would, with a page's
 * session (or none), where the status, headers and body are read exactly. Test plan: developer/TEST_PLAN.md section 5.3.
 *
 * Most of the checks here are requests, not browser navigations, because a navigation hides the status and the
 * redirect. Where a refusal is CiviCRM's own error page the status depends on the site (403 here, 500 on a fresh
 * site), so those checks accept any status of 400 or more.
 */

/** Text that means PHP printed a warning, notice or trace into the response. */
export const PHP_OUTPUT = /(Warning|Notice|Deprecated|Fatal error|Parse error)(<\/b>)?:\s|Undefined (array key|variable|index|offset)|Stack trace:|\bon line \d+/;

/**
 * A response without core's own warning about the status code. When CiviCRM builds an error page inside the CMS's admin it
 * tries to set the HTTP status after the page has begun, and PHP warns ("Cannot set response code - headers already sent").
 * That is core's, not the extension's, and appears on every refusal page of the back-office address.
 */
export function withoutCoreNoise(body: string): string {
  return body.replace(/(<br \/>\s*)?(<b>)?Warning(<\/b>)?:\s+http_response_code\(\): Cannot set response code[\s\S]{0,400}?on line (<b>)?\d+(<\/b>)?(<br \/>)?/g, '');
}

/** The headers a script made with jQuery sends, which a plain navigation or a cross-site form post lacks. */
export const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

export interface Answer {
  status: number;
  type: string;
  body: string;
  location?: string;
  headers: Record<string, string>;
}

export async function answer(response: APIResponse): Promise<Answer> {
  return {
    status: response.status(),
    type: response.headers()['content-type'] || '',
    body: await response.text(),
    location: response.headers()['location'],
    headers: response.headers(),
  };
}

/** GET an address with a page's session, without following redirects. */
export async function get(page: Page, url: string, headers: Record<string, string> = {}): Promise<Answer> {
  return answer(await page.request.get(url, { headers, maxRedirects: 0 }));
}

/** POST a form the way the back-office script does (jQuery sends the header), without following redirects. */
export async function post(page: Page, url: string, form: Record<string, string | number>, headers: Record<string, string> = XHR): Promise<Answer> {
  return answer(await page.request.post(url, { headers, form, maxRedirects: 0 }));
}

/** Parse an answer as JSON, failing with the text if it is not JSON (a PHP warning in front, say). */
export function json(result: Answer): any {
  try {
    return JSON.parse(result.body);
  } catch {
    throw new Error(`Not JSON (status ${result.status}, ${result.type}): ${result.body.slice(0, 400)}`);
  }
}

/** True when the text is a JSON document, which a refused request must not be (the page is HTML). */
export function isJsonText(text: string): boolean {
  try {
    JSON.parse(text);
    return true;
  } catch {
    return false;
  }
}

/** The visible text of an HTML answer, without scripts and styles. */
export function visibleText(html: string): string {
  return html
    .replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&#0?39;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/\s+/g, ' ')
    .trim();
}

/** How many contributions the site has, live and test, so a test can show a request changed nothing. */
export function contributionTotal(): number {
  return Number(evaluate("echo (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contribution');"));
}

/** How many saved cards (payment tokens) the whole site has. */
export function tokenTotal(): number {
  return api4('PaymentToken', 'get', { select: ['id'] }).length;
}

/** The id of a payment processor by name and mode. */
export function processorId(name: string, isTest: boolean): number {
  const rows = api4<{ id: number }>('PaymentProcessor', 'get', {
    select: ['id'],
    where: [['name', '=', name], ['is_test', '=', isTest]],
  });
  if (!rows.length) {
    throw new Error(`The seeded processor "${name}" (test: ${isTest}) does not exist. Run the setup script.`);
  }
  return rows[0].id;
}

/** The seeded processors: the live and the test-mode eWAY record, and the Dummy one that is not eWAY's. */
export function seededProcessors(): { live: number; test: number; dummy: number } {
  return {
    live: liveProcessorId(),
    test: processorId(testData.processors.live, true),
    dummy: processorId(testData.processors.dummy, false),
  };
}

/** A contact. Made fresh, so a test does not depend on what an earlier run left. */
export function newContact(label: string): { id: number; email: string } {
  const donor = uniqueDonor(label);
  const [contact] = api4<{ id: number }>('Contact', 'create', {
    values: { contact_type: 'Individual', first_name: donor.firstName, last_name: donor.lastName },
  });
  api4('Email', 'create', { values: { contact_id: contact.id, email: donor.email, is_primary: true } });
  return { id: contact.id, email: donor.email };
}

/** Ids of Australia and the Australian Capital Territory, which the billing fields of the forms carry. */
export function australia(): { country: number; state: number } {
  const [country] = api4<{ id: number }>('Country', 'get', { select: ['id'], where: [['name', '=', 'Australia']] });
  const [state] = api4<{ id: number }>('StateProvince', 'get', {
    select: ['id'],
    where: [['name', '=', 'Australian Capital Territory'], ['country_id', '=', country.id]],
  });
  return { country: country.id, state: state.id };
}

/**
 * The billing fields as the back-office form posts them (jQuery serialises the whole form). The fields carry a
 * suffix, the id of the billing location type ("-5"), which the extension matches by prefix.
 */
export function billingForm(overrides: Record<string, string | number | undefined> = {}): Record<string, string | number> {
  const { country, state } = australia();
  const form: Record<string, string | number | undefined> = {
    billing_first_name: 'Pat',
    billing_last_name: 'Tester',
    'billing_street_address-5': '1 Test Street',
    'billing_city-5': 'Canberra',
    'billing_postal_code-5': '2600',
    'billing_country_id-5': country,
    'billing_state_province_id-5': state,
    ...overrides,
  };
  return Object.fromEntries(Object.entries(form).filter(([, value]) => value !== undefined)) as Record<string, string | number>;
}

/** The address of a public route of the extension, as the back-office script builds it with CRM.url(path, query, 'front'). */
export function routeUrl(route: 'createtoken' | 'savetoken' | 'verifypayment' | 'settings', query: Record<string, string | number> = {}): string {
  return frontUrl(`ewayrecurring/${route}`, query);
}

/** The invoice id of a contribution (the secret the return address carries). */
export function invoiceOf(contributionId: number): string {
  const [{ invoice_id: invoice }] = api4('Contribution', 'get', { select: ['invoice_id'], where: [['id', '=', contributionId], ['is_test', 'IN', [false, true]]] });
  return invoice;
}

/** eWAY's answer for an approved transaction made for `invoice` and `amount` dollars (the fields the return page compares). */
export function approvedTransaction(invoice: string, amount: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return responses.transactionFound({
    TransactionID: Number(`8${Date.now().toString().slice(-8)}${Math.floor(Math.random() * 10)}`),
    TransactionStatus: true,
    ResponseCode: '00',
    ResponseMessage: 'A2000',
    TotalAmount: Math.round(amount * 100),
    InvoiceNumber: invoice,
    InvoiceReference: invoice,
    TokenCustomerID: '',
    ...overrides,
  });
}

/** eWAY's answer for a declined transaction made for `invoice` and `amount` dollars. */
export function declinedTransaction(invoice: string, amount: number): Record<string, unknown> {
  return approvedTransaction(invoice, amount, { TransactionStatus: false, ResponseCode: '01', ResponseMessage: 'D4401' });
}

/**
 * True when this site's Failed Recurring Contributions list is the current declaration (it filters on the calculated field
 * is_eway_processor, FR-4) and not the older one that joins the processor table. The managed saved search is updated by
 * the extension's upgrade or a managed reconcile, so a development site that has not had either still has the old one,
 * whose list only an administrator can load. A fresh site, such as the GitHub one, always has the current one.
 */
export function failedListIsCurrent(): boolean {
  const [row] = api4('SavedSearch', 'get', { select: ['api_params'], where: [['name', '=', 'Failed_Recurring_Contributions']] });
  return JSON.stringify(row?.api_params ?? {}).includes('is_eway_processor');
}
