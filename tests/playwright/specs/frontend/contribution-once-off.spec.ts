import { test, expect } from '../../fixtures/base';
import { api4 } from '../../fixtures/civi';
import { responses, STUB_HOST } from '../../fixtures/eway-stub';
import {
  DEFAULT_DONOR,
  fillDonation,
  latestContribution,
  newAccessCode,
  newTransactionId,
  onceOffPageId,
  pageUrl,
  queueRow,
  reviewContribution,
} from '../../fixtures/contribution-page';

/**
 * PW-F1: a donor makes a once-off contribution through the extension's eWAY payment processor.
 * Test plan: developer/TEST_PLAN.md section 5.1.
 */
test.describe('once-off contribution page', () => {
  let pageId: number;

  test.beforeAll(() => {
    pageId = onceOffPageId();
  });

  test('PW-F1-01 the page has no card fields and uses the only processor', async ({ anonPage }) => {
    await anonPage.goto(pageUrl(pageId));

    await expect(anonPage.getByRole('radio', { name: /\$10\b/ })).toBeVisible();
    // The card is entered on eWAY's page, so none of the card fields are here.
    await expect(anonPage.locator('input[name*="credit_card"], input[name*="cvv"], #credit_card_number, #cvv2')).toHaveCount(0);
    // With one processor there is nothing to choose: it is preselected, not offered.
    await expect(anonPage.locator('input[name="payment_processor_id"]')).toHaveCount(1);
    await expect(anonPage.locator('input[name="payment_processor_id"]')).toHaveAttribute('type', 'hidden');
  });

  test('PW-F1-03 the confirm step shows the amount and billing address and a button to pay', async ({ anonPage }) => {
    await anonPage.goto(pageUrl(pageId));
    await fillDonation(anonPage, 25);
    await reviewContribution(anonPage);

    const content = anonPage.locator('#crm-main-content-wrapper');
    await expect(content).toContainText('$25.00');
    await expect(content).toContainText(DEFAULT_DONOR.street);
    await expect(content).toContainText(DEFAULT_DONOR.city);
    await expect(content).toContainText(DEFAULT_DONOR.postcode);
    await expect(content).toContainText(DEFAULT_DONOR.email);
    await expect(anonPage.locator('#_qf_Confirm_next-bottom')).toBeVisible();
  });

  test('PW-F1-04 paying sends the donor to the hosted page with the right amount and details', async ({ anonPage, stub }) => {
    const accessCode = newAccessCode('F104');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));

    await anonPage.goto(pageUrl(pageId));
    await fillDonation(anonPage, 25);
    await reviewContribution(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').click();

    await anonPage.waitForURL((url) => url.hostname === STUB_HOST);
    await expect(anonPage.locator('#access-code')).toHaveText(accessCode);
    await expect(anonPage.locator('#amount')).toHaveText('25.00');
    await expect(anonPage.locator('#customer')).toHaveText(`${DEFAULT_DONOR.firstName} ${DEFAULT_DONOR.lastName}`);

    // The invoice reference shown on eWAY's page is the contribution's invoice id.
    const contribution = latestContribution(DEFAULT_DONOR.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Pending');
    await expect(anonPage.locator('#reference')).toHaveText(contribution.invoice_id);

    // What the extension asked eWAY for.
    const asked = stub.requests('postAccessCodeShared');
    expect(asked).toHaveLength(1);
    const request = asked[0].args[0] as Record<string, any>;
    expect(Number(request.Payment.TotalAmount)).toBe(2500);
    // eWAY sends the donor back to the thank you step, which checks the payment as it loads.
    expect(request.RedirectUrl).toContain('_qf_ThankYou_display');
  });

  test('PW-F1-05 an approved payment completes the contribution and shows the thank you page', async ({ anonPage, stub }) => {
    const accessCode = newAccessCode('F105');
    const transactionId = newTransactionId();
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));
    stub.respond('getTransaction', responses.transactionFound({ TransactionID: transactionId, TransactionStatus: true, TotalAmount: 2500, TokenCustomerID: '' }));

    await anonPage.goto(pageUrl(pageId));
    await fillDonation(anonPage, 25);
    await reviewContribution(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').click();
    await anonPage.waitForURL((url) => url.hostname === STUB_HOST);
    await anonPage.locator('#pay').click();

    await expect(anonPage.locator('body')).toContainText('Thank you for your donation');
    await expect(anonPage.locator('body')).toContainText('processed successfully');

    const contribution = latestContribution(DEFAULT_DONOR.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Completed');
    expect(String(contribution.trxn_id)).toBe(String(transactionId));
    expect(Number(contribution.total_amount)).toBe(25);
    expect(contribution.is_test).toBeFalsy();
    expect(contribution.contribution_recur_id).toBeFalsy();

    const row = queueRow(contribution.id);
    expect(Number(row?.status)).toBe(1);

    // A once-off payment saves no card.
    const tokens = api4('PaymentToken', 'get', { where: [['contact_id', '=', contribution.contact_id]] });
    const card = tokens.filter((token: any) => token.token !== '912345678901');
    expect(card).toHaveLength(0);

    // eWAY was asked once to set up the page and once about the result.
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(stub.requests('getTransaction').map((r) => r.args[0])).toEqual([accessCode]);
  });

  test('PW-F1-06 a declined payment says so, fails the contribution, and the form can be used again', async ({ anonPage, stub }) => {
    const accessCode = newAccessCode('F106');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));
    stub.respond('getTransaction', responses.transactionFound({ TransactionID: newTransactionId(), TransactionStatus: false, ResponseCode: '01', ResponseMessage: 'D4401', TokenCustomerID: '' }));

    await anonPage.goto(pageUrl(pageId));
    await fillDonation(anonPage, 10);
    await reviewContribution(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').click();
    await anonPage.waitForURL((url) => url.hostname === STUB_HOST);
    await anonPage.locator('#pay').click();

    await expect(anonPage.locator('body')).toContainText(/declined/i);

    const contribution = latestContribution(DEFAULT_DONOR.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Failed');
    const tokens = api4('PaymentToken', 'get', { where: [['contact_id', '=', contribution.contact_id]] });
    expect(tokens.filter((token: any) => token.token !== '912345678901')).toHaveLength(0);

    // The donor can go again from the same page.
    await anonPage.goto(pageUrl(pageId));
    await expect(anonPage.getByRole('radio', { name: /\$10\b/ })).toBeVisible();
  });
});
