import { getSetting, setSettings } from './civi';
import testData from './test-data.json';

/**
 * Scripts and serves the fake eWAY, which has two halves (developer/TEST_PLAN.md section 2.3):
 *
 * 1. Server to eWAY calls. The test-support extension in stub-extension/ swaps eWAY's HTTP service for one that
 *    answers from a queue kept in a CiviCRM setting, and logs what it was asked. This file writes that queue and
 *    reads that log.
 * 2. Browser to eWAY's hosted payment page. The shared payment URL the stub returns points at a reserved fake
 *    host, eway-stub.test, on a port this run listens on. The browser is told to resolve that host to this
 *    machine (launchOptions in playwright.config.ts), and hosted-page-server.ts answers with a small page whose
 *    Pay and Cancel buttons send the browser on to the RedirectUrl or CancelUrl with the AccessCode appended, as
 *    eWAY's page does. (Playwright's page.route() cannot be used: it does not see a redirect from the site to
 *    another host, which is how the extension sends the donor there.)
 */
export const STUB_HOST: string = testData.stub.hostedHost;
/** The port the fake hosted page is served on. */
export const HOSTED_PORT = Number(process.env.EWAY_STUB_PORT || 4580);
/** The address of the fake hosted page, as the stub puts it in the shared payment URL. */
export const HOSTED_BASE = `http://${STUB_HOST}:${HOSTED_PORT}`;
const QUEUE_SETTING = 'ewayrecurring_stub_queue';
const LOG_SETTING = 'ewayrecurring_stub_log';
const HOSTED_BASE_SETTING = 'ewayrecurring_stub_hosted_base';

/** The methods of eWAY's HTTP service the stub answers. */
export type EwayMethod =
  | 'getTransaction'
  | 'getTransactionInvoiceNumber'
  | 'getTransactionInvoiceReference'
  | 'postTransaction'
  | 'postTransactionRefund'
  | 'postAccessCodeShared'
  | 'postAccessCode'
  | 'getAccessCode'
  | 'getCustomer'
  | 'getSettlementSearch';

export interface ScriptedResponse {
  method: EwayMethod;
  /**
   * The JSON body to return. In a postAccessCodeShared response the string {{hosted_url}} becomes the stub's
   * hosted page address for that request.
   */
  response: Record<string, unknown>;
  /** Keep answering with this response for every later call as well. */
  repeat?: boolean;
}

export interface LoggedRequest {
  method: EwayMethod;
  args: unknown[];
  time: string;
}

export class EwayStub {
  /** Empty the queue and the log, and say where the fake hosted page is. Call at the start of a test. */
  reset(): void {
    setSettings({ [QUEUE_SETTING]: [], [LOG_SETTING]: [], [HOSTED_BASE_SETTING]: HOSTED_BASE });
  }

  /** Queue a response for one eWAY method. Each call takes the next queued response, in order. */
  respond(method: EwayMethod, response: Record<string, unknown>, options: { repeat?: boolean } = {}): void {
    const queue = (getSetting<ScriptedResponse[]>(QUEUE_SETTING) || []).slice();
    queue.push({ method, response, repeat: options.repeat });
    setSettings({ [QUEUE_SETTING]: queue });
  }

  /** What the extension has asked eWAY since the last reset, optionally for one method. */
  requests(method?: EwayMethod): LoggedRequest[] {
    const log = getSetting<LoggedRequest[]>(LOG_SETTING) || [];
    return method ? log.filter((entry) => entry.method === method) : log;
  }
}

/** The fake hosted page. All behaviour is in its inline script. */
export function hostedPageHtml(): string {
  return `<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>eWAY stub hosted page</title></head>
<body>
  <main>
    <h1>eWAY stub: hosted payment page</h1>
    <dl>
      <dt>Access code</dt><dd id="access-code"></dd>
      <dt>Amount</dt><dd id="amount"></dd>
      <dt>Invoice reference</dt><dd id="reference"></dd>
      <dt>Customer</dt><dd id="customer"></dd>
    </dl>
    <form id="card" onsubmit="return false">
      <label>Card number <input id="card-number" value="4444333322221111"></label>
      <label>Name on card <input id="card-name" value="Playwright Donor"></label>
      <label>Expiry <input id="card-expiry" value="12/31"></label>
      <label>CVN <input id="card-cvn" value="123"></label>
      <button type="button" id="pay">Pay</button>
      <button type="button" id="cancel">Cancel</button>
    </form>
  </main>
  <script>
    const q = new URLSearchParams(location.search);
    const text = (id, value) => { document.getElementById(id).textContent = value || ''; };
    text('access-code', q.get('AccessCode'));
    text('amount', q.get('Amount'));
    text('reference', q.get('InvoiceReference'));
    text('customer', q.get('Customer'));
    // Append to a URL that may already have a query string (Joomla, WordPress without pretty permalinks).
    const withAccessCode = (url) => url + (url.includes('?') ? '&' : '?') + 'AccessCode=' + encodeURIComponent(q.get('AccessCode') || '');
    document.getElementById('pay').addEventListener('click', () => { location.href = withAccessCode(q.get('RedirectUrl')); });
    document.getElementById('cancel').addEventListener('click', () => { location.href = withAccessCode(q.get('CancelUrl') || q.get('RedirectUrl')); });
  </script>
</body>
</html>`;
}

/** Builders for the JSON eWAY returns, shaped like the real API. They mirror FakeEwayResponses.php. */
export const responses = {
  /** postAccessCodeShared: a Responsive Shared Page was created. */
  sharedPageCreated: (accessCode: string): Record<string, unknown> => ({
    AccessCode: accessCode,
    SharedPaymentUrl: '{{hosted_url}}',
    FormActionURL: `https://${STUB_HOST}/process`,
    Errors: null,
  }),

  /** postTransaction (Direct, MOTO): approved. */
  paymentApproved: (transactionId = 10000001): Record<string, unknown> => ({
    AuthorisationCode: '123456',
    ResponseCode: '00',
    ResponseMessage: 'A2000',
    TransactionID: transactionId,
    TransactionStatus: true,
    TransactionType: 'MOTO',
    Errors: null,
  }),

  /** postTransaction (Direct, MOTO): the card was declined. */
  paymentDeclined: (message = 'D4401', transactionId = 10000002): Record<string, unknown> => ({
    AuthorisationCode: '',
    ResponseCode: '01',
    ResponseMessage: message,
    TransactionID: transactionId,
    TransactionStatus: false,
    TransactionType: 'MOTO',
    Errors: null,
  }),

  /** Any call: eWAY rejected the request with one or more comma separated error codes. */
  errors: (codes: string): Record<string, unknown> => ({ Errors: codes }),

  /** getTransaction: the access code has a transaction. Pass overrides for any field. */
  transactionFound: (overrides: Record<string, unknown> = {}): Record<string, unknown> => ({
    Transactions: [
      {
        TransactionID: 10000001,
        TransactionStatus: true,
        ResponseCode: '00',
        ResponseMessage: 'A2000',
        TokenCustomerID: '912345678901',
        TotalAmount: 1000,
        ...overrides,
      },
    ],
    Errors: null,
  }),

  /** getCustomer: a saved card. eWAY returns the expiry year as two digits. */
  customerFound: (token = '912345678901', reference = 'civi-1', card: Record<string, unknown> = {}): Record<string, unknown> => ({
    Customers: [
      {
        TokenCustomerID: token,
        Reference: reference,
        FirstName: 'Test',
        LastName: 'Donor',
        CardDetails: { Number: '444433XXXXXX1111', Name: 'Test Donor', ExpiryMonth: '12', ExpiryYear: '31', ...card },
      },
    ],
    Errors: null,
  }),
};
