import { test, expect } from '../../fixtures/base';
import { api4 } from '../../fixtures/civi';
import { latestContribution, pageUrl, queueRow, reviewContribution } from '../../fixtures/contribution-page';
import {
  AMOUNTS,
  fillSandboxDonation,
  hasSandboxCredentials,
  prepareSandboxSite,
  removeSandboxCredentials,
  SKIP_MESSAGE,
  skipWhenSandboxApproves,
  submitCardOnHostedPage,
  uniqueDonor,
  waitForReturnFromSandbox,
  waitForSandboxHostedPage,
  type SandboxSite,
} from '../../fixtures/sandbox';

/**
 * PW-X-01: a once-off payment through the REAL eWAY sandbox. The extension calls eWAY's sandbox API to create the shared
 * payment page, the donor pays on eWAY's own sandbox hosted page with eWAY's published test card, and eWAY sends the
 * browser back to the thank you step, which asks the sandbox for the result. Nothing is stubbed. Test plan:
 * developer/TEST_PLAN.md section 5.4. How the stub is bypassed, the credentials, the test card and the amounts eWAY's
 * sandbox declines are explained in fixtures/sandbox.ts.
 *
 * The page is used in test-drive mode, which is how CiviCRM uses the processor's test-mode record, and that record is
 * the one that talks to the sandbox. The contribution is therefore a test one.
 */
test.describe('once-off payment on the real sandbox', { tag: '@sandbox' }, () => {
  test.skip(!hasSandboxCredentials(), SKIP_MESSAGE);

  let site: SandboxSite;

  test.beforeAll(() => {
    if (hasSandboxCredentials()) {
      site = prepareSandboxSite();
    }
  });

  test.afterAll(() => {
    if (hasSandboxCredentials()) {
      removeSandboxCredentials();
    }
  });

  /** Go through the page as a donor, up to and including pressing Pay on eWAY's hosted page. */
  async function payOnSandbox(page: import('@playwright/test').Page, amount: number, label: string) {
    const donor = uniqueDonor(label);
    await page.goto(pageUrl(site.onceOffPageId, { action: 'preview' }));
    await expect(page.locator('body')).toContainText(/test-drive/i);
    await fillSandboxDonation(page, amount, donor);
    await reviewContribution(page);
    await page.locator('#_qf_Confirm_next-bottom').click();
    await waitForSandboxHostedPage(page);
    await submitCardOnHostedPage(page);
    return donor;
  }

  test('PW-X-01 an approved sandbox amount completes the contribution', async ({ anonPage }) => {
    test.setTimeout(180_000);
    const donor = await payOnSandbox(anonPage, AMOUNTS.approved, 'x01');

    // eWAY sends the browser back to the thank you step, which checked the payment with the sandbox.
    await expect(anonPage.locator('body')).toContainText('Thank you for your donation', { timeout: 60_000 });
    await expect(anonPage.locator('body')).toContainText('processed successfully');

    const contribution = latestContribution(donor.email, true)!;
    expect(contribution['contribution_status_id:name']).toBe('Completed');
    expect(contribution.is_test).toBeTruthy();
    expect(Number(contribution.total_amount)).toBe(AMOUNTS.approved);
    // eWAY's own transaction id: digits, not the stub's.
    expect(String(contribution.trxn_id)).toMatch(/^\d{6,}$/);
    // The payment belongs to the sandbox processor's test record.
    expect(Number(queueRow(contribution.id)!.payment_processor_id)).toBe(site.testProcessorId);
    expect(Number(queueRow(contribution.id)!.status)).toBe(1);
    // A once-off payment is not a recurring record.
    expect(contribution.contribution_recur_id).toBeFalsy();
    expect(latestContribution(donor.email, false)).toBeUndefined();
  });

  test('PW-X-01 a declined sandbox amount fails the contribution and says why', async ({ anonPage }) => {
    test.setTimeout(180_000);
    const donor = await payOnSandbox(anonPage, AMOUNTS.declined, 'x01d');
    await waitForReturnFromSandbox(anonPage);
    await expect.poll(() => latestContribution(donor.email, true)!['contribution_status_id:name'], { timeout: 60_000 }).not.toBe('Pending');

    // eWAY's sandbox declines by the cents of the amount (see fixtures/sandbox.ts): $10.05 is D4405, Do Not Honour.
    // An account set to "Always Approve" approves it instead, which says nothing about the extension: say so and skip.
    const contribution = latestContribution(donor.email, true)!;
    skipWhenSandboxApproves(contribution['contribution_status_id:name'], AMOUNTS.declined);

    await expect(anonPage.locator('body')).toContainText(/Do Not Honour/i);
    await expect(anonPage.locator('body')).not.toContainText('processed successfully');
    expect(contribution['contribution_status_id:name']).toBe('Failed');
    expect(Number(contribution.total_amount)).toBe(AMOUNTS.declined);
    expect(Number(queueRow(contribution.id)!.status)).toBe(1);
    // A declined payment saves no card.
    expect(api4('PaymentToken', 'get', { where: [['contact_id', '=', contribution.contact_id]] })).toHaveLength(0);
  });
});
