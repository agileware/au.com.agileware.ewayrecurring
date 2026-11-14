import { test, expect } from '../../fixtures/base';
import { api4 } from '../../fixtures/civi';
import { responses, STUB_HOST } from '../../fixtures/eway-stub';
import {
  continueToHostedPage,
  contributionCount,
  fillDonation,
  latestContribution,
  newAccessCode,
  onceOffPageId,
  pageUrl,
  queueRow,
  reviewContribution,
  runVerificationJob,
  setOtherQueueRowsAside,
  transactionApproved,
  transactionNotProcessedYet,
  uniqueDonor,
} from '../../fixtures/contribution-page';

/**
 * PW-F1-07 and PW-F1-08: what happens when the donor does not follow the happy path (cancels on eWAY's page, goes
 * back, clicks twice, reloads, or never comes back at all). Test plan: developer/TEST_PLAN.md section 5.1.
 *
 * The thing to prove each time is that the donor cannot be charged twice and no payment is lost.
 */
test.describe('once-off contribution page: interruptions', () => {
  let pageId: number;

  test.beforeAll(() => {
    pageId = onceOffPageId();
  });

  /** Go through the form and the confirm step up to eWAY's hosted page, and return the contribution created on the way. */
  async function reachHostedPage(page: import('@playwright/test').Page, donor: ReturnType<typeof uniqueDonor>, amount = 25) {
    await page.goto(pageUrl(pageId));
    await fillDonation(page, amount, donor);
    await reviewContribution(page);
    await continueToHostedPage(page);
    return latestContribution(donor.email)!;
  }

  test('PW-F1-07 cancelling on the hosted page returns to the form with the donor\'s details kept', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('cancel');
    const accessCode = newAccessCode('F107C');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));

    const contribution = await reachHostedPage(anonPage, donor);
    await anonPage.locator('#cancel').click();

    // Back on the contribution form, not on an error page, with what the donor typed still in the boxes.
    await anonPage.waitForURL((url) => url.hostname !== STUB_HOST);
    await expect(anonPage.locator('#_qf_Main_upload-bottom')).toBeVisible();
    await expect(anonPage.locator('#email-5')).toHaveValue(donor.email);
    await expect(anonPage.locator('#billing_first_name')).toHaveValue(donor.firstName);
    await expect(anonPage.locator('#billing_last_name')).toHaveValue(donor.lastName);
    await expect(anonPage.locator('#billing_street_address-5')).toHaveValue(donor.street);
    await expect(anonPage.getByRole('radio', { name: /\$25\b/ })).toBeChecked();

    // Nothing was charged and the payment was not settled either way: it waits for the verification job.
    expect(latestContribution(donor.email)!['contribution_status_id:name']).toBe('Pending');
    expect(Number(queueRow(contribution.id)?.status)).toBe(0);
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(stub.requests('getTransaction')).toHaveLength(0);
  });

  test('PW-F1-07 after cancelling, the donor can go again and pay, and is charged once', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('cancelretry');
    const firstCode = newAccessCode('F107R1');
    const secondCode = newAccessCode('F107R2');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(firstCode));

    const abandoned = await reachHostedPage(anonPage, donor);
    await anonPage.locator('#cancel').click();
    await anonPage.waitForURL((url) => url.hostname !== STUB_HOST && url.search.includes('cancel=1'));

    // The second go: the confirm step asks eWAY about the first access code (nothing paid yet), then the
    // extension sets up a new shared page for the new contribution.
    stub.respond('getTransaction', transactionNotProcessedYet(), { repeat: true });
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(secondCode));
    await anonPage.locator('#_qf_Main_upload-bottom').click();
    await anonPage.locator('#_qf_Confirm_next-bottom').waitFor();
    await continueToHostedPage(anonPage);
    await expect(anonPage.locator('#access-code')).toHaveText(secondCode);

    // Pay this time.
    stub.reset();
    stub.respond('getTransaction', transactionApproved(25));
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText('processed successfully');

    // One payment completed. The cancelled attempt is still its own Pending record, waiting for the job.
    const contributions = api4('Contribution', 'get', {
      select: ['id', 'contribution_status_id:name'],
      where: [['contact_id.email_primary.email', '=', donor.email]],
      orderBy: { id: 'ASC' },
    });
    expect(contributions.map((c: any) => c['contribution_status_id:name'])).toEqual(['Pending', 'Completed']);
    expect(contributions[0].id).toBe(abandoned.id);
    expect(stub.requests('getTransaction').map((r) => r.args[0])).toEqual([secondCode]);
    expect(Number(queueRow(abandoned.id)?.status)).toBe(0);
  });

  test('PW-F1-07 going back from the confirm step, by its button or the browser, still lets the donor pay once', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('backconfirm');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F107B')));

    await anonPage.goto(pageUrl(pageId));
    await fillDonation(anonPage, 25, donor);
    await reviewContribution(anonPage);

    // The confirm step's own Go Back button keeps everything the donor typed.
    await anonPage.locator('#_qf_Confirm_back-bottom').click();
    await expect(anonPage.locator('#_qf_Main_upload-bottom')).toBeVisible();
    await expect(anonPage.locator('#email-5')).toHaveValue(donor.email);
    await expect(anonPage.locator('#billing_first_name')).toHaveValue(donor.firstName);
    await expect(anonPage.locator('#billing_last_name')).toHaveValue(donor.lastName);
    await expect(anonPage.locator('#billing_street_address-5')).toHaveValue(donor.street);
    await expect(anonPage.locator('#billing_city-5')).toHaveValue(donor.city);
    await expect(anonPage.getByRole('radio', { name: /\$25\b/ })).toBeChecked();
    await reviewContribution(anonPage);

    // The browser's Back button gives a form again (the browser decides which boxes it refills, so the donor
    // types the details in once more) and the journey carries on from there.
    await anonPage.goBack();
    await expect(anonPage.locator('#_qf_Main_upload-bottom')).toBeVisible();
    await fillDonation(anonPage, 25, donor);
    await reviewContribution(anonPage);
    await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText('$25.00');
    await continueToHostedPage(anonPage);

    // One contribution and one payment set up, however many times the donor moved back and forth.
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(contributionCount(donor.email)).toBe(1);
  });

  test('PW-F1-07 Back from the hosted page and Continue again is refused, with no second payment set up', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('backhosted');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F107H')));

    await reachHostedPage(anonPage, donor);

    // The donor presses the browser's Back button on eWAY's page (nothing paid) and goes on again. The confirm
    // step asks eWAY about the access code both when it shows and when it is submitted.
    stub.respond('getTransaction', transactionNotProcessedYet(), { repeat: true });
    await anonPage.goBack();
    await anonPage.locator('#_qf_Confirm_next-bottom').waitFor();
    await anonPage.locator('#_qf_Confirm_next-bottom').click();

    // CiviCRM refuses the same invoice a second time, so the donor is not sent to eWAY again and there is still
    // exactly one payment set up.
    await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText(/duplicate/i);
    expect(anonPage.url()).not.toContain(STUB_HOST);
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(contributionCount(donor.email)).toBe(1);
    expect(latestContribution(donor.email)!['contribution_status_id:name']).toBe('Pending');
  });

  test('PW-F1-07 clicking Continue twice sets up one payment, not two', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('double');
    // A second answer is queued so that a second call would succeed and show up in the counts below.
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F107D1')));
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F107D2')));
    stub.respond('getTransaction', transactionNotProcessedYet(), { repeat: true });

    await anonPage.goto(pageUrl(pageId));
    await fillDonation(anonPage, 25, donor);
    await reviewContribution(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').dblclick();
    await anonPage.waitForURL((url) => url.hostname === STUB_HOST);

    expect(contributionCount(donor.email)).toBe(1);
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
  });

  // The extension refuses on purpose by throwing from its confirm step, and CiviCRM answers that with its error
  // page and an HTTP 500. The browser logs the 500 as a console error, so this test expects it.
  test.describe('a payment already completed', () => {
    test.use({ allowedProblems: [/Failed to load resource: the server responded with a status of 500/] });

    test('PW-F1-07 a donor who paid but went Back to the confirm step is told so and cannot pay again', async ({ anonPage, stub }) => {
      const donor = uniqueDonor('paidback');
      stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F107P')));

      const contribution = await reachHostedPage(anonPage, donor);

      // On eWAY's side the payment went through, but the donor's browser never came back with it (they pressed Back,
      // or the tab was closed and the confirm page was opened again).
      stub.respond('getTransaction', transactionApproved(25), { repeat: true });
      await anonPage.goBack();
      await anonPage.waitForLoadState('load');

      await expect(anonPage.locator('body')).toContainText('Payment already completed for this Contribution');
      // There is no button to pay with, and no second payment was set up.
      await expect(anonPage.locator('#_qf_Confirm_next-bottom')).toHaveCount(0);
      expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
      expect(contributionCount(donor.email)).toBe(1);
      // The record itself is left for the verification job to settle (PW-F1-08).
      expect(latestContribution(donor.email)!['contribution_status_id:name']).toBe('Pending');
      expect(Number(queueRow(contribution.id)?.status)).toBe(0);
    });
  });

  test('PW-F1-07 opening the Thank You address again after paying does not check or charge again', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('repost');
    const accessCode = newAccessCode('F107T');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));
    // Repeating, so that a second look at eWAY would be answered and show up in the log below.
    stub.respond('getTransaction', transactionApproved(25), { repeat: true });

    await reachHostedPage(anonPage, donor);
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText('processed successfully');
    const thankYouUrl = anonPage.url();
    const completed = latestContribution(donor.email)!;
    expect(completed['contribution_status_id:name']).toBe('Completed');

    for (const reopen of [() => anonPage.reload(), () => anonPage.goto(thankYouUrl)]) {
      await reopen();
      // CiviCRM has finished with the visit, so it says the session has expired and offers a fresh form.
      await expect(anonPage.locator('body')).toContainText(/session has expired/i);
      await expect(anonPage.locator('#_qf_Main_upload-bottom')).toBeVisible();
      expect(anonPage.url()).not.toContain(STUB_HOST);
    }

    // Still the one completed payment: no second contribution, same transaction, no further call to eWAY.
    expect(contributionCount(donor.email)).toBe(1);
    const after = latestContribution(donor.email)!;
    expect(after['contribution_status_id:name']).toBe('Completed');
    expect(String(after.trxn_id)).toBe(String(completed.trxn_id));
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(stub.requests('getTransaction')).toHaveLength(1);
  });

  test('PW-F1-08 a donor who never comes back leaves a Pending payment that the verification job completes', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('neverback');
    const accessCode = newAccessCode('F108');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));

    // The donor reaches eWAY's page and the tab closes there. Whether they paid is not known to the site.
    const contribution = await reachHostedPage(anonPage, donor);
    await anonPage.close();

    expect(latestContribution(donor.email)!['contribution_status_id:name']).toBe('Pending');
    const waiting = queueRow(contribution.id)!;
    expect(Number(waiting.status)).toBe(0);
    expect(Number(waiting.tries)).toBe(0);
    expect(waiting.access_code).toBe(accessCode);

    // eWAY did take the payment. Earlier tests leave their own waiting rows, and the stub answers by order, not by
    // access code, so put those aside and give the job one answer for this donor.
    setOtherQueueRowsAside(contribution.id);
    const approved = transactionApproved(25);
    stub.respond('getTransaction', approved);

    const counts = runVerificationJob();
    expect(counts.success).toBe(1);
    expect(counts.failed).toBe(0);
    expect(counts.not_processed).toBe(0);
    expect(stub.requests('getTransaction').map((r) => r.args[0])).toEqual([accessCode]);

    const completed = latestContribution(donor.email)!;
    expect(completed['contribution_status_id:name']).toBe('Completed');
    expect(String(completed.trxn_id)).toBe(String((approved.Transactions as any[])[0].TransactionID));
    expect(Number(completed.total_amount)).toBe(25);
    expect(Number(queueRow(contribution.id)?.status)).toBe(1);

    // Running the job again finds nothing to do and does not ask eWAY about it again.
    const again = runVerificationJob();
    expect(again.success).toBe(0);
    expect(stub.requests('getTransaction')).toHaveLength(1);
    expect(contributionCount(donor.email)).toBe(1);
  });
});
