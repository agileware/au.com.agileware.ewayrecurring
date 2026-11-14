import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { api4 } from '../../fixtures/civi';
import { responses, STUB_HOST } from '../../fixtures/eway-stub';
import {
  latestContribution,
  newAccessCode,
  newTransactionId,
  queueRow,
  transactionApproved,
  transactionNotProcessedYet,
  uniqueDonor,
} from '../../fixtures/contribution-page';
import {
  continueFromEventConfirm,
  contributionOfParticipant,
  eventUrl,
  fillAdditionalParticipant,
  fillFreeRegistration,
  fillPaidRegistration,
  freeEventId,
  PAID_EVENT_FEE,
  paidEventId,
  participantsOf,
  participantTotal,
  pressReview,
} from '../../fixtures/event-registration';

/**
 * PW-F3: a visitor registers for an event and pays through the extension's eWAY payment processor. The first step
 * is the registration form (with the billing address), then Review, a confirm step, eWAY's hosted page, and the
 * thank you step. Test plan: developer/TEST_PLAN.md section 5.1.
 */
test.describe('event registration', () => {
  let paidId: number;

  test.beforeAll(() => {
    paidId = paidEventId();
  });

  test('PW-F3-01 a paid registration goes through the hosted page and ends Registered with a completed contribution', async ({ anonPage, stub }) => {
    const person = uniqueDonor('event01');
    const accessCode = newAccessCode('F301');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));

    await anonPage.goto(eventUrl(paidId));
    await fillPaidRegistration(anonPage, person);
    await pressReview(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').waitFor();
    await continueFromEventConfirm(anonPage);

    // Waiting on eWAY: the registration is held and nothing is paid.
    await expect(anonPage.locator('#access-code')).toHaveText(accessCode);
    const [waiting] = participantsOf(person.email);
    expect(waiting['status_id:name']).toBe('Pending from incomplete transaction');
    const asked = stub.requests('postAccessCodeShared')[0].args[0] as Record<string, any>;
    const amountInCents = Number(asked.Payment.TotalAmount);

    stub.respond('getTransaction', transactionApproved(amountInCents / 100));
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText(/thank you for registering/i);

    const participants = participantsOf(person.email);
    expect(participants).toHaveLength(1);
    expect(participants[0]['status_id:name']).toBe('Registered');
    expect(participants[0].event_id).toBe(paidId);
    const contribution = contributionOfParticipant(participants[0].id)!;
    expect(contribution.status).toBe('Completed');
    expect(Math.round(Number(contribution.total_amount) * 100)).toBe(amountInCents);
    expect(Number(queueRow(contribution.id)?.status)).toBe(1);
    expect(api4('Contribution', 'get', { select: ['id'], where: [['id', '=', contribution.id]] })).toHaveLength(1);
    expect(latestContribution(person.email)!.id).toBe(contribution.id);
  });

  /** Go through the paid event's form and confirm step up to eWAY's hosted page. Returns the confirm step's address. */
  async function reachHostedPage(page: Page, person: ReturnType<typeof uniqueDonor>): Promise<string> {
    await page.goto(eventUrl(paidId));
    await fillPaidRegistration(page, person);
    await pressReview(page);
    await page.locator('#_qf_Confirm_next-bottom').waitFor();
    const confirmUrl = page.url();
    await continueFromEventConfirm(page);
    return confirmUrl;
  }

  test('PW-F3-02 a declined payment says so, the registration is not completed, and the visitor can try again', async ({ anonPage, stub }) => {
    const person = uniqueDonor('event02');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F302A')));
    await reachHostedPage(anonPage, person);

    stub.respond('getTransaction', responses.transactionFound({ TransactionID: newTransactionId(), TransactionStatus: false, ResponseCode: '01', ResponseMessage: 'D4401', TokenCustomerID: '' }));
    await anonPage.locator('#pay').click();
    await anonPage.waitForURL((url) => url.hostname !== STUB_HOST);
    await expect(anonPage.locator('body')).toContainText(/declined/i);

    const [participant] = participantsOf(person.email);
    // The registration stays unfinished: it is not Registered and the contribution is Failed.
    expect(participant['status_id:name']).toBe('Pending from incomplete transaction');
    const failed = contributionOfParticipant(participant.id)!;
    expect(failed.status).toBe('Failed');

    // Try again from the same page: this time eWAY approves.
    stub.reset();
    const secondCode = newAccessCode('F302B');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(secondCode));
    await anonPage.goto(eventUrl(paidId));
    await fillPaidRegistration(anonPage, person);
    await pressReview(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').waitFor();
    await continueFromEventConfirm(anonPage);
    const asked = stub.requests('postAccessCodeShared')[0].args[0] as Record<string, any>;
    stub.respond('getTransaction', transactionApproved(Number(asked.Payment.TotalAmount) / 100));
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText(/thank you for registering/i);

    // The second go is a new registration: the first one is left as it was, and the second is Registered and paid.
    const all = participantsOf(person.email);
    expect(all.map((p) => p['status_id:name'])).toEqual(['Pending from incomplete transaction', 'Registered']);
    expect(all[0].id).toBe(participant.id);
    expect(contributionOfParticipant(all[1].id)!.status).toBe('Completed');
    expect(contributionOfParticipant(participant.id)!.status).toBe('Failed');
  });

  test('PW-F3-03 cancelling on the hosted page returns to the confirm step on the same registration', async ({ anonPage, stub }) => {
    const person = uniqueDonor('event03');
    const accessCode = newAccessCode('F303');
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(accessCode));
    const confirmUrl = await reachHostedPage(anonPage, person);
    const qfKey = new URL(confirmUrl).searchParams.get('qfKey');
    expect(qfKey).toBeTruthy();
    const [waiting] = participantsOf(person.email);

    // Coming back to the confirm step, the extension asks eWAY whether anything was paid: nothing was.
    stub.respond('getTransaction', transactionNotProcessedYet(), { repeat: true });
    await anonPage.locator('#cancel').click();
    await anonPage.waitForURL((url) => url.hostname !== STUB_HOST);

    // The confirm step again, for the same registration (same key), not a fresh form.
    await expect(anonPage.locator('#_qf_Confirm_next-bottom')).toBeVisible();
    expect(new URL(anonPage.url()).searchParams.get('qfKey')).toBe(qfKey);
    await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText(person.email);
    await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText(person.street);

    // Nothing was paid or duplicated: the one registration still waits, and no second payment was set up.
    expect(participantsOf(person.email).map((p) => p.id)).toEqual([waiting.id]);
    expect(participantsOf(person.email)[0]['status_id:name']).toBe('Pending from incomplete transaction');
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(contributionOfParticipant(waiting.id)!.status).toBe('Pending');

    // Pressing Continue again goes back to the same payment page, still without a second payment being set up.
    await continueFromEventConfirm(anonPage);
    await expect(anonPage.locator('#access-code')).toHaveText(accessCode);
    expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
    expect(participantsOf(person.email)).toHaveLength(1);
  });

  // The extension refuses on purpose by throwing from its confirm step, and CiviCRM answers with its error page and
  // an HTTP 500. The browser logs the 500 as a console error, so this test expects it.
  test.describe('a payment already completed', () => {
    test.use({ allowedProblems: [/Failed to load resource: the server responded with a status of 500/] });

    test('PW-F3-04 going back to the confirm step after paying is refused with "Payment already completed"', async ({ anonPage, stub }) => {
      const person = uniqueDonor('event04');
      stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F304')));
      await reachHostedPage(anonPage, person);
      const [waiting] = participantsOf(person.email);

      // On eWAY's side the payment went through, but the visitor's browser never came back with it (Back was pressed,
      // or the confirm page was opened again).
      stub.respond('getTransaction', transactionApproved(30), { repeat: true });
      await anonPage.goBack();
      await anonPage.waitForLoadState('load');

      await expect(anonPage.locator('body')).toContainText('Payment already completed for this Registration');
      await expect(anonPage.locator('#_qf_Confirm_next-bottom')).toHaveCount(0);
      // No second payment was set up, and the registration is left for the verification job to settle.
      expect(stub.requests('postAccessCodeShared')).toHaveLength(1);
      expect(participantsOf(person.email).map((p) => p.id)).toEqual([waiting.id]);
      expect(participantsOf(person.email)[0]['status_id:name']).toBe('Pending from incomplete transaction');
    });
  });

  // CiviCRM core's thank you step for a registration with more than one person prints a PHP warning from its own
  // template, DisplayProfile.tpl ("Undefined array key additionalCustomPost", seen on core 6.16). It is core's, not
  // the extension's, so this test lists it.
  test.describe('more than one person', () => {
    test.use({ allowedProblems: [/Undefined array key "additionalCustomPost"/] });

    test('PW-F3-05 three people on one registration send eWAY the total for all three', async ({ anonPage, stub }) => {
      const first = uniqueDonor('event05a');
      const second = uniqueDonor('event05b', { firstName: 'Second', lastName: 'Guest' });
      const third = uniqueDonor('event05c', { firstName: 'Third', lastName: 'Guest' });
      stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F305')));

      await anonPage.goto(eventUrl(paidId));
      await fillPaidRegistration(anonPage, first, 2);
      // The price of one ticket, as the form shows it (a site may add tax to the fee).
      const each = await participantTotal(anonPage);
      expect(each).toBeGreaterThanOrEqual(PAID_EVENT_FEE);
      await pressReview(anonPage);
      await fillAdditionalParticipant(anonPage, second);
      await fillAdditionalParticipant(anonPage, third);
      await anonPage.locator('#_qf_Confirm_next-bottom').waitFor();
      await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText(second.email);
      await expect(anonPage.locator('#crm-main-content-wrapper')).toContainText(third.email);
      await continueFromEventConfirm(anonPage);

      // eWAY was asked for one payment of three tickets.
      const asked = stub.requests('postAccessCodeShared');
      expect(asked).toHaveLength(1);
      const request = asked[0].args[0] as Record<string, any>;
      expect(Number(request.Payment.TotalAmount)).toBe(Math.round(each * 3 * 100));
      await expect(anonPage.locator('#amount')).toHaveText((each * 3).toFixed(2));

      stub.respond('getTransaction', transactionApproved(each * 3));
      await anonPage.locator('#pay').click();
      await expect(anonPage.locator('body')).toContainText(/thank you for registering/i);

      // Three participants, all Registered, with the other two registered by the first, and one contribution.
      const everyone = [first, second, third].map((person) => participantsOf(person.email));
      for (const found of everyone) {
        expect(found).toHaveLength(1);
        expect(found[0]['status_id:name']).toBe('Registered');
      }
      expect(everyone[0][0].registered_by_id).toBeFalsy();
      expect(everyone[1][0].registered_by_id).toBe(everyone[0][0].id);
      expect(everyone[2][0].registered_by_id).toBe(everyone[0][0].id);
      const contribution = contributionOfParticipant(everyone[0][0].id)!;
      expect(contribution.status).toBe('Completed');
      expect(Math.round(Number(contribution.total_amount) * 100)).toBe(Math.round(each * 3 * 100));
    });
  });

  test('PW-F3-06 a free event is registered without asking eWAY', async ({ anonPage, stub }) => {
    const person = uniqueDonor('event06');
    await anonPage.goto(eventUrl(freeEventId()));
    // Nothing to pay: no ticket choice and no billing address.
    await expect(anonPage.locator('#billing_first_name')).toHaveCount(0);
    await fillFreeRegistration(anonPage, person);
    await pressReview(anonPage);
    await anonPage.locator('#_qf_Confirm_next-bottom').click();
    await expect(anonPage.locator('body')).toContainText(/thank you for registering/i);
    expect(anonPage.url()).not.toContain(STUB_HOST);

    const participants = participantsOf(person.email);
    expect(participants).toHaveLength(1);
    expect(participants[0]['status_id:name']).toBe('Registered');
    expect(participants[0].event_id).toBe(freeEventId());
    expect(contributionOfParticipant(participants[0].id)).toBeUndefined();
    expect(api4('Contribution', 'get', { select: ['id'], where: [['contact_id.email_primary.email', '=', person.email]] })).toHaveLength(0);
    // eWAY was not asked anything, and no verification row waits.
    expect(stub.requests()).toHaveLength(0);
  });
});
