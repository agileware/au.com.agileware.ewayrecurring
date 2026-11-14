import { test, expect } from '../../fixtures/base';
import { api4 } from '../../fixtures/civi';
import { responses, STUB_HOST } from '../../fixtures/eway-stub';
import {
  continueToHostedPage,
  contributionCount,
  fillDonation,
  freePageId,
  horizontalOverflowReport,
  latestContribution,
  newAccessCode,
  newTransactionId,
  nonAsciiPageId,
  onceOffPageId,
  pageUrl,
  queueRow,
  reviewContribution,
  transactionApproved,
  uniqueDonor,
} from '../../fixtures/contribution-page';
import { expectShownInTheme } from '../../fixtures/visibility';

/**
 * PW-F1-09 to PW-F1-13: the once-off contribution page with unusual input (non-ASCII text), in test mode, with
 * nothing to pay, on a phone, and inside the CMS theme. Test plan: developer/TEST_PLAN.md section 5.1.
 */
test.describe('once-off contribution page: variants', () => {
  /** Printable ASCII only: what eWAY accepts. */
  const ASCII_ONLY = /^[\x20-\x7E]*$/;

  test('PW-F1-09 accents, apostrophes and emoji complete the payment and reach eWAY as plain ASCII', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('nonascii', {
      firstName: 'Zoë',
      lastName: "O'Brien-Müller 😀",
      street: "12 Rue de l'Église, Appartement n°5 ☃",
      city: 'Saint-Étienne',
    });
    const accessCode = newAccessCode('F109');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));
    stub.respond('getTransaction', transactionApproved(25));

    // The page's title has an accent too, and goes to eWAY in the invoice description.
    await anonPage.goto(pageUrl(nonAsciiPageId()));
    await fillDonation(anonPage, 25, donor);
    await reviewContribution(anonPage);
    // The donor sees their details as typed.
    const content = anonPage.locator('#crm-main-content-wrapper');
    await expect(content).toContainText('Zoë');
    await expect(content).toContainText('Saint-Étienne');
    await continueToHostedPage(anonPage);

    // What eWAY was sent is plain ASCII, with the letters that survive still in place. (How an accent is turned
    // into ASCII depends on the server's locale: "e" on some, "?" on others, so the exact text is not checked.)
    const request = stub.requests('postAccessCodeShared')[0].args[0] as Record<string, any>;
    const sent: Record<string, string> = {
      FirstName: request.Customer.FirstName,
      LastName: request.Customer.LastName,
      Street1: request.Customer.Street1,
      City: request.Customer.City,
      InvoiceDescription: request.Payment.InvoiceDescription,
    };
    for (const [field, value] of Object.entries(sent)) {
      expect(value, `${field} sent to eWAY is ASCII only`).toMatch(ASCII_ONLY);
    }
    expect(sent.FirstName).toMatch(/^Zo/);
    expect(sent.LastName).toContain("O'Brien-M");
    expect(sent.Street1).toContain("12 Rue de l'");
    expect(sent.City).toMatch(/^Saint-/);
    expect(sent.InvoiceDescription).toContain('Online Contribution: Playwright caf');
    expect(sent.InvoiceDescription).toContain("s friends");
    // The name on eWAY's page is the sanitised one too.
    await expect(anonPage.locator('#customer')).toHaveText(`${sent.FirstName} ${sent.LastName}`);

    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText('processed successfully');

    // The donor's record keeps what they typed, accents and emoji included: only the copy for eWAY is cleaned.
    const contribution = latestContribution(donor.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Completed');
    const [contact] = api4('Contact', 'get', {
      select: ['first_name', 'last_name', 'address_primary.street_address', 'address_primary.city'],
      where: [['id', '=', contribution.contact_id]],
    });
    expect(contact.first_name).toBe(donor.firstName);
    expect(contact.last_name).toBe(donor.lastName);
    expect(contact['address_primary.street_address']).toBe(donor.street);
    expect(contact['address_primary.city']).toBe(donor.city);
  });

  test('PW-F1-10 the page in test mode uses the test processor and records a test contribution', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('testmode');
    const accessCode = newAccessCode('F110');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));
    stub.respond('getTransaction', transactionApproved(25));

    // CiviCRM's test mode for a contribution page is its "preview" action (the page's own "Test-drive" link).
    await anonPage.goto(pageUrl(onceOffPageId(), { action: 'preview' }));
    await expect(anonPage.locator('body')).toContainText(/test-drive/i);
    await fillDonation(anonPage, 25, donor);
    await reviewContribution(anonPage);
    await continueToHostedPage(anonPage);
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText('processed successfully');

    // A test contribution, completed, and no live one.
    const contribution = latestContribution(donor.email, true)!;
    expect(contribution['contribution_status_id:name']).toBe('Completed');
    expect(contribution.is_test).toBeTruthy();
    expect(latestContribution(donor.email, false)).toBeUndefined();

    // The verification queue row belongs to the test processor, not the live one.
    const processors = api4('PaymentProcessor', 'get', {
      select: ['id', 'is_test'],
      where: [['name', '=', 'Playwright eWAY'], ['is_test', 'IN', [false, true]]],
    });
    const testProcessor = processors.find((p: any) => p.is_test);
    const liveProcessor = processors.find((p: any) => !p.is_test);
    const row = queueRow(contribution.id)!;
    expect(Number(row.payment_processor_id)).toBe(testProcessor.id);
    expect(Number(row.payment_processor_id)).not.toBe(liveProcessor.id);
    expect(Number(row.status)).toBe(1);
    // The thank you step checked the payment as a test one: it asked eWAY once, about this payment.
    expect(stub.requests('getTransaction').map((r) => r.args[0])).toEqual([accessCode]);
  });

  test('PW-F1-11 a free option completes without asking eWAY for anything', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('free');

    await anonPage.goto(pageUrl(freePageId()));
    await anonPage.getByRole('radio', { name: /Free/ }).check();
    await anonPage.locator('#email-5').fill(donor.email);
    // Nothing to pay, so there is no billing address to ask for.
    await expect(anonPage.locator('#billing_first_name')).toBeHidden();
    await reviewContribution(anonPage);
    await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText('$0.00');
    await anonPage.locator('#_qf_Confirm_next-bottom').click();

    await expect(anonPage.locator('body')).toContainText('Your donation has been received.');
    await expect(anonPage.locator('body')).toContainText('processed successfully');
    expect(anonPage.url()).not.toContain(STUB_HOST);

    const contribution = latestContribution(donor.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Completed');
    expect(Number(contribution.total_amount)).toBe(0);
    expect(contribution.trxn_id).toBeFalsy();
    expect(contributionCount(donor.email)).toBe(1);

    // eWAY heard nothing, and there is nothing for the verification job to chase.
    expect(stub.requests()).toEqual([]);
    expect(queueRow(contribution.id)).toBeUndefined();
  });

  test('PW-F1-12 a phone-sized screen completes the whole journey without sideways scrolling', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('mobile');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F112')));
    stub.respond('getTransaction', transactionApproved(25));

    await anonPage.setViewportSize({ width: 375, height: 812 });
    await anonPage.goto(pageUrl(onceOffPageId()));
    await expect(anonPage.getByRole('radio', { name: /\$25\b/ })).toBeVisible();
    expect(await horizontalOverflowReport(anonPage), 'the form fits the screen width').toBe('');

    await fillDonation(anonPage, 25, donor);
    // The submit button can be reached and is inside the screen's width.
    await anonPage.locator('#_qf_Main_upload-bottom').scrollIntoViewIfNeeded();
    const button = await anonPage.locator('#_qf_Main_upload-bottom').boundingBox();
    expect(button!.x).toBeGreaterThanOrEqual(0);
    expect(button!.x + button!.width).toBeLessThanOrEqual(375);
    expect(await horizontalOverflowReport(anonPage), 'the filled-in form fits the screen width').toBe('');

    await reviewContribution(anonPage);
    await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText('$25.00');
    expect(await horizontalOverflowReport(anonPage), 'the confirm step fits the screen width').toBe('');

    await continueToHostedPage(anonPage);
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText('processed successfully');
    expect(await horizontalOverflowReport(anonPage), 'the thank you page fits the screen width').toBe('');
    expect(latestContribution(donor.email)!['contribution_status_id:name']).toBe('Completed');
  });

  test('PW-F1-13 the declined message and the thank you page show properly inside the theme', async ({ anonPage, stub }) => {
    // The theme is Astra on WordPress (the workflow pins it with WP_THEME). Olivero, Cassiopeia and Standalone's
    // own template are still to be checked when those sites can run this suite.
    const declined = uniqueDonor('themedecline');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F113D')));
    stub.respond('getTransaction', responses.transactionFound({ TransactionID: newTransactionId(), TransactionStatus: false, ResponseCode: '01', ResponseMessage: 'D4401', TokenCustomerID: '' }));

    await anonPage.goto(pageUrl(onceOffPageId()));
    await fillDonation(anonPage, 25, declined);
    await reviewContribution(anonPage);
    await continueToHostedPage(anonPage);
    await anonPage.locator('#pay').click();

    // Back on the form, with the eWAY wording of the decline in CiviCRM's error box.
    await expect(anonPage.locator('#_qf_Main_upload-bottom')).toBeVisible();
    const error = anonPage.locator('.messages.error, .messages.status').filter({ hasText: /declined/i });
    await expectShownInTheme(error, 'the declined message');
    await expect(error).toContainText('Your payment was declined');

    // The same for a payment that goes through.
    const approved = uniqueDonor('themethanks');
    stub.reset();
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F113T')));
    stub.respond('getTransaction', transactionApproved(25));
    await anonPage.goto(pageUrl(onceOffPageId()));
    await fillDonation(anonPage, 25, approved);
    await reviewContribution(anonPage);
    await continueToHostedPage(anonPage);
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText('processed successfully');

    await expectShownInTheme(anonPage.getByText('Your transaction has been processed successfully'), 'the success message');
    await expectShownInTheme(anonPage.getByText('Your donation has been received.'), 'the thank you text');
    await expectShownInTheme(anonPage.getByRole('heading', { name: /Thank you for your donation/ }), 'the thank you title');
  });
});
