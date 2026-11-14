import { test, expect } from '../../fixtures/base';
import { api4 } from '../../fixtures/civi';
import {
  contributionCount,
  fillDonation,
  latestContribution,
  onceOffPageId,
  pageUrl,
  reviewContribution,
  submitWithoutBrowserValidation,
  uniqueDonor,
} from '../../fixtures/contribution-page';
import { STUB_HOST } from '../../fixtures/eway-stub';

/**
 * PW-F1-02: the once-off contribution page refuses missing billing details before the donor leaves the site, in the
 * browser and again on the server. Test plan: developer/TEST_PLAN.md section 5.1.
 */
test.describe('once-off contribution page: required billing details', () => {
  let pageId: number;

  test.beforeAll(() => {
    pageId = onceOffPageId();
  });

  /** The browser-side error labels CiviCRM puts beside a field, keyed by the field's id. */
  const inlineError = (page: import('@playwright/test').Page, fieldId: string) => page.locator(`label#${fieldId}-error`);

  test('PW-F1-02 the browser refuses an empty billing form and the donor is not sent anywhere', async ({ anonPage, stub }) => {
    const posts: string[] = [];
    anonPage.on('request', (request) => {
      if (request.method() === 'POST' && request.url().includes('contribute/transact')) {
        posts.push(request.url());
      }
    });

    await anonPage.goto(pageUrl(pageId));
    // The billing block only shows once there is something to pay, so choose an amount and leave the rest empty.
    await anonPage.getByRole('radio', { name: /\$25\b/ }).check();
    await anonPage.locator('#_qf_Main_upload-bottom').click();

    for (const field of ['email-5', 'billing_first_name', 'billing_last_name', 'billing_street_address-5', 'billing_city-5', 'billing_country_id-5', 'billing_postal_code-5']) {
      await expect(inlineError(anonPage, field), `${field} is flagged`).toHaveText('This field is required.');
    }
    // Still on the first step, nothing was posted, and eWAY was not asked for anything.
    await expect(anonPage.locator('#_qf_Main_upload-bottom')).toBeVisible();
    expect(anonPage.url()).toContain('reset=1');
    expect(posts).toEqual([]);
    expect(stub.requests()).toEqual([]);

    // Filling the form in clears the complaints and lets the donor on to the confirm step.
    await fillDonation(anonPage, 25);
    await reviewContribution(anonPage);
    await expect(anonPage.locator('#_qf_Confirm_next-bottom')).toBeVisible();
  });

  test('PW-F1-02 the server refuses a missing name or email when the browser checks are skipped', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('required');
    const before = contributionCount(donor.email);

    await anonPage.goto(pageUrl(pageId));
    await anonPage.getByRole('radio', { name: /\$25\b/ }).check();
    await submitWithoutBrowserValidation(anonPage, '_qf_Main_upload');

    // Back on the first step, with the server's own messages.
    await expect(anonPage.locator('#_qf_Main_upload-bottom')).toBeVisible();
    const content = anonPage.locator('#crm-main-content-wrapper');
    await expect(content).toContainText('Email Address is a required field');
    await expect(content).toContainText('Billing First Name is a required field');
    await expect(content).toContainText('Billing Last Name is a required field');

    // Nothing was created and eWAY was not asked for anything.
    expect(anonPage.url()).not.toContain(STUB_HOST);
    expect(contributionCount(donor.email)).toBe(before);
    expect(stub.requests()).toEqual([]);

    // One missing field at a time: with the email and first name given, only the last name is refused.
    await anonPage.getByRole('radio', { name: /\$25\b/ }).check();
    await anonPage.locator('#email-5').fill(donor.email);
    await anonPage.locator('#billing_first_name').fill(donor.firstName);
    await submitWithoutBrowserValidation(anonPage, '_qf_Main_upload');
    await expect(content).toContainText('Billing Last Name is a required field');
    await expect(content).not.toContainText('Billing First Name is a required field');
    await expect(content).not.toContainText('Email Address is a required field');
    expect(contributionCount(donor.email)).toBe(before);
    expect(stub.requests()).toEqual([]);
  });

  test('PW-F1-02 a missing country is stopped on the site, before eWAY is asked for anything', async ({ anonPage, stub }) => {
    // The server only insists on the name and email. eWAY needs a country, so the extension refuses the payment
    // itself when there is none, and that is what keeps the donor from being sent to the hosted page without one.
    const donor = uniqueDonor('nocountry');

    await anonPage.goto(pageUrl(pageId));
    await anonPage.getByRole('radio', { name: /\$25\b/ }).check();
    await anonPage.locator('#email-5').fill(donor.email);
    await anonPage.locator('#billing_first_name').fill(donor.firstName);
    await anonPage.locator('#billing_last_name').fill(donor.lastName);
    await submitWithoutBrowserValidation(anonPage, '_qf_Main_upload');
    await anonPage.locator('#_qf_Confirm_next-bottom').waitFor();

    await anonPage.locator('#_qf_Confirm_next-bottom').click();

    // Sent back to the first step with an error about the country, not on to eWAY.
    await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText(/country/i);
    await expect(anonPage.locator('#_qf_Main_upload-bottom')).toBeVisible();
    expect(anonPage.url()).not.toContain(STUB_HOST);
    expect(stub.requests('postAccessCodeShared')).toEqual([]);

    // The attempt did not leave a payment that could be completed.
    const contribution = latestContribution(donor.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Failed');
    expect(api4('PaymentToken', 'get', { where: [['contact_id', '=', contribution.contact_id]] })).toHaveLength(0);
  });
});
