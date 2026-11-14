import { test, expect } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4, evaluateJson } from '../../fixtures/civi';
import { HOSTED_BASE, responses, STUB_HOST } from '../../fixtures/eway-stub';
import testData from '../../fixtures/test-data.json';

/**
 * Infrastructure checks: the test site, the personas, the stub and the helpers work, so the specs that build on
 * them can trust them. Test plan: developer/TEST_PLAN.md, group PW-INF.
 */
test.describe('infrastructure', () => {
  test('PW-INF-01 the site is up and the administrator session works', async ({ adminPage }) => {
    await adminPage.goto(adminUrl('dashboard', { reset: 1 }));
    await expect(adminPage.locator('.crm-container').first()).toBeVisible();
  });

  test('PW-INF-02 the extension and the stub are enabled and the processors are seeded', async () => {
    const extensions = api4<{ key: string }>('Extension', 'get', {
      select: ['key'],
      where: [
        ['key', 'IN', ['au.com.agileware.ewayrecurring', 'ewayrecurring_playwright_stub']],
        ['status', '=', 'installed'],
      ],
    });
    expect(extensions.map((e) => e.key).sort()).toEqual(['au.com.agileware.ewayrecurring', 'ewayrecurring_playwright_stub']);

    const ewayProcessors = api4<{ is_test: boolean }>('PaymentProcessor', 'get', {
      select: ['is_test'],
      where: [
        ['name', '=', testData.processors.live],
        ['payment_processor_type_id:name', '=', 'eWay_Recurring'],
        // APIv4 lists live processors only unless asked for both.
        ['is_test', 'IN', [false, true]],
      ],
    });
    // One live and one test row, as CiviCRM keeps them.
    expect(ewayProcessors).toHaveLength(2);
  });

  test('PW-INF-03 a scripted answer reaches the extension through the stub, and the call is logged', async ({ stub }) => {
    stub.respond('getCustomer', responses.customerFound('912345678901', 'civi-7'));

    const answer = evaluateJson<{ token: string; reference: string; card: string }>(`
      $p = Civi\\Api4\\PaymentProcessor::get(FALSE)
        ->addWhere('name', '=', '${testData.processors.live}')
        ->addWhere('is_test', '=', FALSE)
        ->execute()->first();
      $r = CRM_eWAYRecurring_Utils::getEWayClient($p)->queryCustomer('912345678901');
      echo json_encode([
        'token' => $r->Customers[0]->TokenCustomerID,
        'reference' => $r->Customers[0]->Reference,
        'card' => $r->Customers[0]->CardDetails->Number,
      ]);
    `);

    expect(answer).toEqual({ token: '912345678901', reference: 'civi-7', card: '444433XXXXXX1111' });
    const logged = stub.requests('getCustomer');
    expect(logged).toHaveLength(1);
    expect(logged[0].args).toEqual(['912345678901']);
  });

  test('PW-INF-04 a call nothing was queued for is logged as unexpected and fails loudly', async ({ stub }) => {
    const message = evaluateJson<{ failed: boolean; message: string }>(`
      $p = Civi\\Api4\\PaymentProcessor::get(FALSE)
        ->addWhere('name', '=', '${testData.processors.live}')
        ->addWhere('is_test', '=', FALSE)
        ->execute()->first();
      try {
        CRM_eWAYRecurring_Utils::getEWayClient($p)->queryCustomer('1');
        echo json_encode(['failed' => FALSE, 'message' => '']);
      }
      catch (Throwable $e) {
        echo json_encode(['failed' => TRUE, 'message' => $e->getMessage()]);
      }
    `);

    expect(message.failed).toBe(true);
    expect(stub.requests('getCustomer')).toHaveLength(1);
  });

  test('PW-INF-05 the shared payment page URL the stub returns points at the fake hosted page', async ({ stub }) => {
    stub.respond('postAccessCodeShared', responses.sharedPageCreated('ACINFRA05'));

    const url = evaluateJson<{ url: string }>(`
      $p = Civi\\Api4\\PaymentProcessor::get(FALSE)
        ->addWhere('name', '=', '${testData.processors.live}')
        ->addWhere('is_test', '=', FALSE)
        ->execute()->first();
      $r = CRM_eWAYRecurring_Utils::getEWayClient($p)->createTransaction(\\Eway\\Rapid\\Enum\\ApiMethod::RESPONSIVE_SHARED, [
        'Customer' => ['FirstName' => 'Playwright', 'LastName' => 'Donor'],
        'Payment' => ['TotalAmount' => 1050, 'InvoiceReference' => 'INV-INFRA-05', 'CurrencyCode' => 'AUD'],
        'RedirectUrl' => 'https://site.test/return?x=1',
        'CancelUrl' => 'https://site.test/cancel',
        'TransactionType' => \\Eway\\Rapid\\Enum\\TransactionType::PURCHASE,
      ]);
      echo json_encode(['url' => $r->SharedPaymentUrl]);
    `).url;

    const parsed = new URL(url);
    expect(parsed.hostname).toBe(STUB_HOST);
    expect(parsed.origin).toBe(HOSTED_BASE);
    expect(parsed.searchParams.get('AccessCode')).toBe('ACINFRA05');
    expect(parsed.searchParams.get('RedirectUrl')).toBe('https://site.test/return?x=1');
    expect(parsed.searchParams.get('CancelUrl')).toBe('https://site.test/cancel');
    expect(parsed.searchParams.get('Amount')).toBe('10.50');
    expect(parsed.searchParams.get('InvoiceReference')).toBe('INV-INFRA-05');
  });

  test('PW-INF-06 the hosted page stub sends the browser back with the access code, whether or not the URL has a query', async ({ page, baseURL }) => {
    // The site's front page: a harmless page that exists on every CMS, unlike a made-up path that would 404.
    const back = `${baseURL}/`;
    const hosted = (extra: Record<string, string>) =>
      `${HOSTED_BASE}/pay?` + new URLSearchParams({ AccessCode: 'ACINFRA06', Amount: '10.00', InvoiceReference: 'INV-6', Customer: 'Playwright Donor', ...extra }).toString();

    // A return URL with no query string gets ?AccessCode=
    await page.goto(hosted({ RedirectUrl: back, CancelUrl: back }));
    await expect(page.locator('#access-code')).toHaveText('ACINFRA06');
    await expect(page.locator('#amount')).toHaveText('10.00');
    await page.locator('#pay').click();
    await page.waitForURL((u) => u.searchParams.has('AccessCode'));
    expect(new URL(page.url()).pathname).toBe('/');
    expect(new URL(page.url()).searchParams.get('AccessCode')).toBe('ACINFRA06');

    // One that already has a query string (Joomla, WordPress without pretty permalinks) gets &AccessCode=
    await page.goto(hosted({ RedirectUrl: `${back}?return=1&step=verify`, CancelUrl: back }));
    await page.locator('#pay').click();
    await page.waitForURL((u) => u.searchParams.has('AccessCode'));
    const returned = new URL(page.url());
    expect(returned.searchParams.get('return')).toBe('1');
    expect(returned.searchParams.get('step')).toBe('verify');
    expect(returned.searchParams.get('AccessCode')).toBe('ACINFRA06');

    // Cancel goes to the cancel URL, also with the access code.
    await page.goto(hosted({ RedirectUrl: back, CancelUrl: `${back}?cancelled=1` }));
    await page.locator('#cancel').click();
    await page.waitForURL((u) => u.searchParams.has('AccessCode'));
    expect(new URL(page.url()).searchParams.get('cancelled')).toBe('1');
  });
});
