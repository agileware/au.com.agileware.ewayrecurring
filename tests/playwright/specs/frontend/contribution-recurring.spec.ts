import { test, expect } from '../../fixtures/base';
import { api4 } from '../../fixtures/civi';
import { responses } from '../../fixtures/eway-stub';
import {
  chooseRecurrence,
  continueToHostedPage,
  expectedNextPayment,
  fillDonation,
  latestContribution,
  latestRecurring,
  newAccessCode,
  newCustomerToken,
  newTransactionId,
  pageUrl,
  recurringCount,
  recurringPageId,
  reviewContribution,
  serverToday,
  tokensOf,
  transactionApprovedWithCard,
  uniqueDonor,
  type Donor,
  type Recurrence,
} from '../../fixtures/contribution-page';
import type { EwayStub } from '../../fixtures/eway-stub';
import type { Page } from '@playwright/test';

/**
 * PW-F2: a donor sets up a recurring contribution on the once-off style contribution page (tick "I want to
 * contribute this amount every ..."). The first payment goes through eWAY's hosted page; on return the extension
 * saves the card as a payment token and starts the schedule. PW-F1 covers the journey itself, so these cases check
 * what is new for recurring. Test plan: developer/TEST_PLAN.md section 5.1.
 *
 * The seeded page lets the donor choose the interval, the unit (week, month, year) and the number of installments.
 */
test.describe('recurring contribution page', () => {
  let pageId: number;

  test.beforeAll(() => {
    pageId = recurringPageId();
  });

  interface Card {
    token: string;
    month?: string;
    year?: string;
  }

  /**
   * Go through the page as a donor up to eWAY's hosted page. Leaves the browser there, with eWAY scripted to
   * approve the payment and to report `card` as the saved card.
   */
  async function reachHostedPage(page: Page, stub: EwayStub, donor: Donor, options: { amount?: number; recurrence?: Recurrence; card: Card; code: string }): Promise<void> {
    const amount = options.amount ?? 25;
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(options.code));
    stub.respond('getTransaction', transactionApprovedWithCard(amount, options.card.token));
    stub.respond('getCustomer', responses.customerFound(options.card.token, 'civi-1', {
      ...(options.card.month ? { ExpiryMonth: options.card.month } : {}),
      ...(options.card.year ? { ExpiryYear: options.card.year } : {}),
    }));
    await page.goto(pageUrl(pageId));
    await fillDonation(page, amount, donor);
    if (options.recurrence) {
      await chooseRecurrence(page, options.recurrence);
    }
    await reviewContribution(page);
    await continueToHostedPage(page);
  }

  /** Pay on the hosted page and wait for the thank you step. */
  async function pay(page: Page): Promise<void> {
    await page.locator('#pay').click();
    await expect(page.locator('body')).toContainText('processed successfully');
  }

  test('PW-F2-01 the recurring controls appear once ticked, and an unticked box is a once-off payment', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('recur01');
    await anonPage.goto(pageUrl(pageId));

    // The option is offered, unticked, with its three controls switched off until the donor ticks it.
    const tick = anonPage.locator('#is_recur');
    await expect(tick).toBeVisible();
    await expect(tick).not.toBeChecked();
    for (const control of ['#frequency_interval', '#frequency_unit', '#installments']) {
      await expect(anonPage.locator(control), `${control} is off before ticking`).toBeDisabled();
    }
    await tick.check();
    for (const control of ['#frequency_interval', '#frequency_unit', '#installments']) {
      await expect(anonPage.locator(control), `${control} is on after ticking`).toBeEnabled();
    }
    // The unit offers what the page was set up with, nothing more.
    expect(await anonPage.locator('#frequency_unit option').evaluateAll((options) => options.map((o) => (o as HTMLOptionElement).value))).toEqual(['week', 'month', 'year']);
    await tick.uncheck();
    await expect(anonPage.locator('#frequency_interval')).toBeDisabled();

    // Left unticked, the donor pays once: no recurring record and no saved card, and eWAY is not asked for one.
    stub.respond('postAccessCodeShared', responses.sharedPageCreated(newAccessCode('F201')));
    stub.respond('getTransaction', transactionApprovedWithCard(25, newCustomerToken()));
    await fillDonation(anonPage, 25, donor);
    await reviewContribution(anonPage);
    await expect(anonPage.locator('#crm-main-content-wrapper')).not.toContainText(/every/i);
    await continueToHostedPage(anonPage);
    await pay(anonPage);

    const contribution = latestContribution(donor.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Completed');
    expect(contribution.contribution_recur_id).toBeFalsy();
    expect(recurringCount(donor.email)).toBe(0);
    expect(tokensOf(contribution.contact_id)).toHaveLength(0);
    expect(stub.requests('getCustomer')).toHaveLength(0);
  });

  test('PW-F2-02 an approved first payment starts the schedule and saves the card', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('recur02');
    const card = { token: newCustomerToken(), month: '02', year: '33' };
    const recurrence: Recurrence = { interval: 1, unit: 'month' };

    await reachHostedPage(anonPage, stub, donor, { recurrence, card, code: newAccessCode('F202') });

    // Before the donor pays the schedule has not started: the record waits, with no card.
    const before = latestRecurring(donor.email)!;
    expect(before['contribution_status_id:name']).toBe('Pending');
    expect(before.payment_token_id).toBeFalsy();

    const dayBefore = serverToday();
    await pay(anonPage);
    const dayAfter = serverToday();

    const contribution = latestContribution(donor.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Completed');
    const recur = latestRecurring(donor.email)!;
    expect(contribution.contribution_recur_id).toBe(recur.id);
    expect(recur['contribution_status_id:name']).toBe('In Progress');
    expect(Number(recur.amount)).toBe(25);
    expect(recur.frequency_unit).toBe('month');
    expect(Number(recur.frequency_interval)).toBe(1);

    // The extension keeps eWAY's customer token, and points the record at the saved card.
    expect(recur.processor_id).toBe(card.token);
    expect(String(recur.trxn_id)).toBe(String(contribution.trxn_id));
    const tokens = tokensOf(recur.contact_id);
    expect(tokens).toHaveLength(1);
    expect(recur.payment_token_id).toBe(tokens[0].id);
    expect(tokens[0].token).toBe(card.token);
    expect(tokens[0].masked_account_number).toBe('444433XXXXXX1111');
    // eWAY reports the expiry as month 02, year 33: the card is good to the last day of that month.
    expect(tokens[0].expiry_date).toMatch(/^2033-02-28/);
    // The card belongs to the processor the page used.
    expect(tokens[0].payment_processor_id).toBe(recur.payment_processor_id);

    // The next payment is one month from today, at midnight.
    const accepted = new Set([dayBefore, dayAfter].map((day) => expectedNextPayment(day, recurrence)));
    expect(accepted, `next payment ${recur.next_sched_contribution_date}`).toContain(String(recur.next_sched_contribution_date));

    // eWAY was asked about the payment once and about the saved card once.
    expect(stub.requests('getTransaction')).toHaveLength(1);
    expect(stub.requests('getCustomer').map((r) => r.args[0])).toEqual([card.token]);
    // Only the donor's own records were touched.
    expect(api4('ContributionRecur', 'get', { select: ['id'], where: [['contact_id', '=', recur.contact_id]] })).toHaveLength(1);
  });

  // Every unit, with an interval of one and with a longer one.
  const schedules: Recurrence[] = [
    { interval: 1, unit: 'week' },
    { interval: 2, unit: 'week' },
    { interval: 1, unit: 'month' },
    { interval: 3, unit: 'month' },
    { interval: 1, unit: 'year' },
    { interval: 2, unit: 'year' },
  ];
  for (const recurrence of schedules) {
    test(`PW-F2-03 every ${recurrence.interval} ${recurrence.unit}(s) schedules the next payment that far from today`, async ({ anonPage, stub }) => {
      const donor = uniqueDonor(`recur03${recurrence.unit}${recurrence.interval}`);
      await reachHostedPage(anonPage, stub, donor, { recurrence, card: { token: newCustomerToken() }, code: newAccessCode('F203') });

      const dayBefore = serverToday();
      await pay(anonPage);
      const dayAfter = serverToday();

      const recur = latestRecurring(donor.email)!;
      expect(recur['contribution_status_id:name']).toBe('In Progress');
      expect(recur.frequency_unit).toBe(recurrence.unit);
      expect(Number(recur.frequency_interval)).toBe(recurrence.interval);
      const accepted = new Set([dayBefore, dayAfter].map((day) => expectedNextPayment(day, recurrence)));
      expect(accepted, `next payment ${recur.next_sched_contribution_date}`).toContain(String(recur.next_sched_contribution_date));
    });
  }

  test('PW-F2-04 the installments limit is stored on the recurring record, and blank means no limit', async ({ anonPage, stub }) => {
    const limited = uniqueDonor('recur04limit');
    await reachHostedPage(anonPage, stub, limited, { recurrence: { interval: 1, unit: 'month', installments: 6 }, card: { token: newCustomerToken() }, code: newAccessCode('F204A') });
    await pay(anonPage);
    const limitedRecur = latestRecurring(limited.email)!;
    expect(limitedRecur['contribution_status_id:name']).toBe('In Progress');
    expect(Number(limitedRecur.installments)).toBe(6);

    // The same page with the box left blank sets up a schedule with no end.
    stub.reset();
    const open = uniqueDonor('recur04open');
    await reachHostedPage(anonPage, stub, open, { recurrence: { interval: 1, unit: 'month' }, card: { token: newCustomerToken() }, code: newAccessCode('F204B') });
    await pay(anonPage);
    const openRecur = latestRecurring(open.email)!;
    expect(openRecur['contribution_status_id:name']).toBe('In Progress');
    expect(Number(openRecur.installments || 0)).toBe(0);
  });

  test('PW-F2-05 a declined first payment leaves no running schedule and no saved card', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('recur05');
    const card = { token: newCustomerToken() };
    await reachHostedPage(anonPage, stub, donor, { recurrence: { interval: 1, unit: 'month' }, card, code: newAccessCode('F205') });
    // eWAY declines: replace the approval scripted for the return with a decline.
    stub.reset();
    stub.respond('getTransaction', responses.transactionFound({ TransactionID: newTransactionId(),TransactionStatus: false, ResponseCode: '01', ResponseMessage: 'D4401', TokenCustomerID: card.token }));
    await anonPage.locator('#pay').click();
    await expect(anonPage.locator('body')).toContainText(/declined/i);

    const contribution = latestContribution(donor.email)!;
    expect(contribution['contribution_status_id:name']).toBe('Failed');
    // The record CiviCRM made when the donor pressed Continue is left as it was: Pending, with no card and no
    // eWAY customer token (processor_id still holds CiviCRM's own placeholder). The extension does not delete it.
    // The cron job ignores it, because it has no completed payment.
    const recur = latestRecurring(donor.email)!;
    expect(recur['contribution_status_id:name']).toBe('Pending');
    expect(recur.payment_token_id).toBeFalsy();
    expect(recur.processor_id).not.toBe(card.token);
    expect(tokensOf(contribution.contact_id)).toHaveLength(0);
    // eWAY was not asked for a saved card.
    expect(stub.requests('getCustomer')).toHaveLength(0);
  });

  test('PW-F2-06 a returning donor whose saved card comes back from eWAY keeps one row for it', async ({ anonPage, stub }) => {
    const donor = uniqueDonor('recur06');
    const card = { token: newCustomerToken() };
    // The donor has been here before: a contact with this email address and one saved card.
    const [{ id: contactId }] = api4('Contact', 'create', {
      values: { contact_type: 'Individual', first_name: donor.firstName, last_name: donor.lastName },
    });
    api4('Email', 'create', { values: { contact_id: contactId, email: donor.email, is_primary: true } });
    const [liveProcessor] = api4('PaymentProcessor', 'get', { select: ['id'], where: [['name', '=', 'Playwright eWAY'], ['is_test', '=', false]] });
    api4('PaymentToken', 'create', {
      values: {
        contact_id: contactId,
        payment_processor_id: liveProcessor.id,
        token: card.token,
        masked_account_number: '444433XXXXXX1111',
        expiry_date: '2031-12-31',
      },
    });
    const [existing] = tokensOf(contactId);

    // The donor sets up a recurring payment, and eWAY reports the same card under the same customer token.
    await reachHostedPage(anonPage, stub, donor, { recurrence: { interval: 1, unit: 'month' }, card, code: newAccessCode('F206') });
    await pay(anonPage);

    // The payment lands on the same contact (the donor was recognised, not duplicated).
    const recur = latestRecurring(donor.email)!;
    expect(recur.contact_id).toBe(contactId);
    expect(recur['contribution_status_id:name']).toBe('In Progress');
    expect(api4('Contact', 'get', { select: ['id'], where: [['email_primary.email', '=', donor.email]] })).toHaveLength(1);

    // The extension's dedupe rule (same contact and processor, same token or same masked number) keeps this to one
    // card: the new recurring record points at the row the donor already had (DEF-36, before the fix it added a
    // second identical row).
    const tokens = tokensOf(contactId);
    expect(tokens).toHaveLength(1);
    expect(tokens[0].id).toBe(existing.id);
    expect(tokens[0].token).toBe(card.token);
    expect(tokens[0].masked_account_number).toBe('444433XXXXXX1111');
    expect(recur.payment_token_id).toBe(existing.id);
  });
});
