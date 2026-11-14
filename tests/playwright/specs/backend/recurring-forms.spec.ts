import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4, setSettings } from '../../fixtures/civi';
import { resetEwaySettings } from '../../fixtures/backend';
import { newTransactionId } from '../../fixtures/contribution-page';
import { responses, type EwayStub } from '../../fixtures/eway-stub';
import {
  createRecurring,
  makeRetryDue,
  recurRow,
  runRecurringJob,
  setOtherDueRecurringAside,
  type RecurringRecord,
} from '../../fixtures/recurring-records';
import {
  cancelRecurringUrl,
  failedListRow,
  openFailedList,
  openRecurringTab,
  recurringRow,
  recurringViewUrl,
  SCREEN_NOISE,
  updateRecurringUrl,
} from '../../fixtures/recurring-screens';
import testData from '../../fixtures/test-data.json';

/**
 * PW-B-recurforms: the two core forms staff use on a recurring contribution, Cancel Recurring Contribution and Update
 * Recurring Contribution, as they behave for an eWAY record, and what a record in Failed status looks like on every screen
 * it appears on. The extension takes the "send cancellation request" choice off the cancel form (eWAY has no cancel
 * request to send) and says which schedule fields may be edited. Test plan: developer/TEST_PLAN.md section 5.2.
 *
 * Staff reach the forms from the row's Edit and Cancel links on the contact's Recurring Contributions tab, which open them
 * in a dialog, so most cases open them that way. A Failed record is listed under Inactive with a View link only, so its
 * forms are opened by address, as a staff member with the link would.
 */

test.use({ allowedProblems: [SCREEN_NOISE] });

test.beforeEach(() => resetEwaySettings());
test.afterEach(() => resetEwaySettings());

/** Open a row's "more" menu and press one of its links; the form opens in a dialog. */
async function openRowAction(page: Page, record: Pick<RecurringRecord, 'recurId' | 'contactId'>, action: 'Edit' | 'Cancel'): Promise<Locator> {
  await openRecurringTab(page, record.contactId);
  const row = recurringRow(page, record);
  await expect(row).toBeVisible();
  if (action === 'Cancel') {
    await row.locator('.btn-slide').click();
  }
  await row.getByRole('link', { name: action, exact: true }).click();
  const dialog = page.locator('.ui-dialog');
  await expect(dialog.locator(action === 'Edit' ? 'form#UpdateSubscription' : 'form#CancelSubscription')).toBeVisible();
  return dialog;
}

/** Press a button in the dialog's button bar. */
async function pressInDialog(page: Page, name: string | RegExp): Promise<void> {
  await page.locator('.ui-dialog-buttonpane').getByRole('button', { name }).click();
}

/** Make a record belong to the seeded non-eWAY (Dummy) processor and drop its saved card. */
function moveToDummyProcessor(recurId: number): void {
  const [dummy] = api4<{ id: number }>('PaymentProcessor', 'get', {
    select: ['id'],
    where: [['name', '=', testData.processors.dummy], ['is_test', '=', false]],
  });
  api4('ContributionRecur', 'update', { where: [['id', '=', recurId]], values: { payment_processor_id: dummy.id, payment_token_id: null } });
}

/** Run the job once with eWAY declining the card. */
function runDeclined(stub: EwayStub, record: RecurringRecord): void {
  stub.reset();
  stub.respond('postTransaction', responses.paymentDeclined('D4401', newTransactionId()));
  runRecurringJob();
  expect(stub.requests('postTransaction')).toHaveLength(1);
  expect((stub.requests('postTransaction')[0].args[0] as any).Customer.TokenCustomerID).toBe(record.token);
}

/** A record that really ends up Failed: due, then declined until the limit of two failed charges is used up. */
function failedThroughCron(stub: EwayStub, label: string): RecurringRecord {
  setSettings({ eway_recurring_contribution_max_retry: 2 });
  const record = createRecurring({ label, nextDue: '-1 day' });
  setOtherDueRecurringAside(record.recurId);
  runDeclined(stub, record);
  makeRetryDue(record.recurId);
  runDeclined(stub, record);
  const state = recurRow(record.recurId);
  expect(state['contribution_status_id:name']).toBe('Failed');
  expect(state.failure_count).toBe(2);
  return record;
}

test.describe('cancel recurring contribution', () => {
  test('PW-B-recurforms-01 the cancel form for an eWAY record has no "send cancellation request" choice, and cancelling sets Cancelled without contacting eWAY', async ({ adminPage, stub }) => {
    test.setTimeout(90_000);
    const record = createRecurring({ label: 'forms01' });
    const dialog = await openRowAction(adminPage, record, 'Cancel');

    // The form offers a reason and the notify tick, and no choice about eWAY. Core's template used to print PHP warnings
    // for the missing choice, which the problem collector would report (DEF-59).
    await expect(dialog.locator('#cancel_reason')).toBeVisible();
    await expect(dialog.locator('#is_notify')).toBeVisible();
    await expect(dialog.locator('[name=send_cancel_request]')).toHaveCount(0);
    await expect(dialog.getByText(/Send cancellation request/)).toHaveCount(0);
    await expect(dialog.locator('.crm-contributionrecur-form-block-send_cancel_request')).toHaveCount(0);

    await dialog.locator('#cancel_reason').fill('Playwright cancel');
    await pressInDialog(adminPage, /Cancel Recurring Contribution/);
    await expect(adminPage.locator('#crm-notification-container')).toContainText('has been cancelled');

    const cancelled = recurRow(record.recurId);
    expect(cancelled['contribution_status_id:name']).toBe('Cancelled');
    expect(api4('ContributionRecur', 'get', { select: ['cancel_reason'], where: [['id', '=', record.recurId]] })[0].cancel_reason).toBe('Playwright cancel');
    // Nothing was asked of eWAY: no call was made, so none is in the stub's log.
    expect(stub.requests()).toHaveLength(0);

    // The tab now lists it as inactive and Cancelled.
    await openRecurringTab(adminPage, record.contactId);
    await expect(recurringRow(adminPage, record)).toContainText('Cancelled');
    await expect(recurringRow(adminPage, record).getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
  });

  test('PW-B-recurforms-02 the same form for a record on another processor still offers the "send cancellation request" choice', async ({ adminPage, stub }) => {
    test.setTimeout(90_000);
    const record = createRecurring({ label: 'forms02' });
    moveToDummyProcessor(record.recurId);
    const dialog = await openRowAction(adminPage, record, 'Cancel');

    await expect(dialog.getByText(/Send cancellation request to/)).toBeVisible();
    await expect(dialog.locator('input[name=send_cancel_request]')).toHaveCount(2);
    await expect(dialog.locator('#CIVICRM_QFID_0_send_cancel_request')).toBeVisible();
    await expect(dialog.locator('#CIVICRM_QFID_1_send_cancel_request')).toBeVisible();
    // It starts on Yes, which asks the processor to cancel.
    await expect(dialog.locator('#CIVICRM_QFID_1_send_cancel_request')).toBeChecked();

    // Close without cancelling: the record is untouched. (Not Now is the dialog's other button.)
    await pressInDialog(adminPage, /Not Now/);
    await expect(adminPage.locator('.ui-dialog')).toHaveCount(0);
    expect(recurRow(record.recurId)['contribution_status_id:name']).toBe('In Progress');
    expect(stub.requests()).toHaveLength(0);
  });
});

test.describe('update recurring contribution', () => {
  test('PW-B-recurforms-03 the update form allows amount, installments, interval, unit, next scheduled date and end date, with the next date row shown once and a working date picker', async ({ adminPage }) => {
    const record = createRecurring({ label: 'forms03' });
    const dialog = await openRowAction(adminPage, record, 'Edit');
    const form = dialog.locator('form#UpdateSubscription');

    // The six editable fields the extension lists (getEditableRecurringScheduleFields).
    for (const field of ['#amount', '#installments', '#frequency_interval', '#frequency_unit', '#next_sched_contribution_date', '#end_date']) {
      await expect(form.locator(field), field).toHaveCount(1);
    }
    await expect(form.locator('#amount')).toHaveValue('10.00');
    await expect(form.locator('#installments')).toHaveValue('0');
    await expect(form.locator('#frequency_interval')).toHaveValue('1');
    await expect(form.locator('#frequency_unit')).toHaveValue('month');

    // The next scheduled date is on the form once, as core's own field. The row eway.js can build ("next_scheduled_date")
    // is never added: nothing calls it (DEF-60).
    await expect(form.getByText('Next Scheduled Contribution Date')).toHaveCount(1);
    await expect(form.locator('[name=next_sched_contribution_date]')).toHaveCount(1);
    await expect(form.locator('[name=next_scheduled_date]')).toHaveCount(0);
    await expect(form.locator('.crm-contribution-form-block-receive_date')).toHaveCount(0);

    // The date picker opens and picking a day fills the field.
    const shown = form.locator('#next_sched_contribution_date').locator('xpath=following-sibling::input[1]');
    await shown.click();
    const picker = adminPage.locator('#ui-datepicker-div');
    await expect(picker).toBeVisible();
    await picker.locator('a.ui-state-default', { hasText: /^20$/ }).click();
    await expect(picker).toBeHidden();
    await expect(form.locator('#next_sched_contribution_date')).toHaveValue(/^\d{4}-\d{2}-20/);
    await expect(shown).toHaveValue(/^20\/\d{2}\/\d{4}$/);
    // The end date has its own picker and starts empty.
    await expect(form.locator('#end_date')).toHaveValue('');
    await pressInDialog(adminPage, /Cancel/);
  });

  test('PW-B-recurforms-04 saving persists every field, the next scheduled date included, and the form shows them again when it is reopened', async ({ adminPage, stub }) => {
    test.setTimeout(90_000);
    const record = createRecurring({ label: 'forms04' });
    const dialog = await openRowAction(adminPage, record, 'Edit');
    const form = dialog.locator('form#UpdateSubscription');

    await form.locator('#amount').fill('12.50');
    await form.locator('#installments').fill('6');
    await form.locator('#frequency_interval').fill('2');
    await form.locator('#s2id_frequency_unit').click();
    await adminPage.locator('.select2-drop-active .select2-result-label', { hasText: /^week$/ }).click();
    // The dates are typed into the visible box as dd/mm/yyyy; the form keeps them as yyyy-mm-dd behind it.
    const nextDate = form.locator('#next_sched_contribution_date').locator('xpath=following-sibling::input[1]');
    await nextDate.fill('15/03/2031');
    await nextDate.press('Tab');
    const endDate = form.locator('#end_date').locator('xpath=following-sibling::input[1]');
    await endDate.fill('15/03/2033');
    await endDate.press('Tab');
    await expect(form.locator('#next_sched_contribution_date')).toHaveValue('2031-03-15');
    await expect(form.locator('#end_date')).toHaveValue('2033-03-15');

    await pressInDialog(adminPage, 'Save');
    await expect(adminPage.locator('#crm-notification-container')).toContainText('Recurring contribution has been updated');
    await expect(adminPage.locator('.ui-dialog')).toHaveCount(0);

    const saved = api4<Record<string, any>>('ContributionRecur', 'get', {
      select: ['amount', 'installments', 'frequency_interval', 'frequency_unit', 'next_sched_contribution_date', 'end_date', 'contribution_status_id:name'],
      where: [['id', '=', record.recurId]],
    })[0];
    expect(saved.amount).toBe(12.5);
    expect(saved.installments).toBe(6);
    expect(saved.frequency_interval).toBe(2);
    expect(saved.frequency_unit).toBe('week');
    // A date only: the form has no time box, so the time is midnight.
    expect(saved.next_sched_contribution_date).toBe('2031-03-15 00:00:00');
    expect(String(saved.end_date)).toMatch(/^2033-03-15/);
    expect(saved['contribution_status_id:name']).toBe('In Progress');
    // Nothing was sent to eWAY: the schedule is kept in CiviCRM.
    expect(stub.requests()).toHaveLength(0);

    // Reopened, the form shows what was saved.
    const reopened = (await openRowAction(adminPage, record, 'Edit')).locator('form#UpdateSubscription');
    await expect(reopened.locator('#amount')).toHaveValue('12.50');
    await expect(reopened.locator('#installments')).toHaveValue('6');
    await expect(reopened.locator('#frequency_interval')).toHaveValue('2');
    await expect(reopened.locator('#frequency_unit')).toHaveValue('week');
    await expect(reopened.locator('#next_sched_contribution_date').locator('xpath=following-sibling::input[1]')).toHaveValue('15/03/2031');
    // Core's form does not fill the end date in again (it is saved, see above, but the box is empty), so it is not checked here.
  });
});

test.describe('a recurring contribution in Failed status', () => {
  test('PW-B-recurforms-05 a Failed record reached through the cron shows without errors on every screen, can be reached from search, and Reactivate gives its links back', async ({ adminPage, stub }) => {
    test.setTimeout(240_000);
    const record = failedThroughCron(stub, 'forms05');

    // The contact's tab lists it under Inactive, with View only (core treats Failed as inactive, decision 11).
    await openRecurringTab(adminPage, record.contactId);
    const row = recurringRow(adminPage, record);
    await expect(row).toContainText('Failed');
    await expect(adminPage.locator('h3', { hasText: 'Inactive Recurring Contributions' })).toBeVisible();
    await expect(row.getByRole('link')).toHaveCount(1);
    await expect(row.getByRole('link', { name: 'View' })).toBeVisible();
    await expect(row.locator('.btn-slide')).toHaveCount(0);

    // Its own page opens and says Failed.
    await row.getByRole('link', { name: 'View' }).click();
    await expect(adminPage.locator('.ui-dialog')).toContainText('View Recurring Contribution');
    await expect(adminPage.locator('.ui-dialog tr', { hasText: /^\s*Status/ }).first()).toContainText('Failed');
    await adminPage.goto(recurringViewUrl(record));
    await expect(adminPage.locator('tr', { hasText: /^\s*Status/ }).first()).toContainText('Failed');
    await expect(adminPage.locator('tr', { hasText: /^\s*Failure Count/ })).toContainText('2');

    // The update form opens by address and saves.
    await adminPage.goto(updateRecurringUrl(record));
    await expect(adminPage.locator('form#UpdateSubscription')).toBeVisible();
    await expect(adminPage.locator('#amount')).toHaveValue('10.00');
    await adminPage.locator('#amount').fill('11.00');
    await adminPage.locator('#_qf_UpdateSubscription_next-bottom').click();
    await expect(adminPage.locator('#crm-notification-container')).toContainText('Recurring contribution has been updated');
    expect(recurRow(record.recurId).amount).toBe(11);
    expect(recurRow(record.recurId)['contribution_status_id:name']).toBe('Failed');

    // The cancel form opens by address, with no choice about eWAY (and no PHP warnings).
    await adminPage.goto(cancelRecurringUrl(record));
    await expect(adminPage.locator('form#CancelSubscription')).toBeVisible();
    await expect(adminPage.locator('#cancel_reason')).toBeVisible();
    await expect(adminPage.locator('[name=send_cancel_request]')).toHaveCount(0);

    // Contribution search finds it by the recurring contribution's status.
    await adminPage.goto(adminUrl('contribute/search', { reset: 1 }));
    await adminPage.getByText('Recurring Contributions', { exact: true }).click();
    await adminPage.locator('#s2id_contribution_recur_contribution_status_id').click();
    await adminPage.locator('.select2-drop-active .select2-result-label', { hasText: /^Failed$/ }).click();
    await adminPage.locator('#contribution_recur_processor_id').fill(record.token);
    await adminPage.locator('#_qf_Search_refresh').first().click();
    const results = adminPage.locator('table.selector tbody tr');
    await expect(results.first()).toBeVisible();
    await expect(results.filter({ hasText: record.donor.lastName }).first()).toBeVisible();
    // The search lists one row for the record (not one per payment), and it is the donor's.
    await expect(results).toHaveCount(1);
    await expect(results.first().locator(`a[href*="cid=${record.contactId}"]`).first()).toBeVisible();

    // Reactivate from the Failed Recurring Contributions list puts it back In Progress.
    await openFailedList(adminPage);
    const listed = failedListRow(adminPage, record.recurId);
    await expect(listed).toHaveCount(1);
    await listed.getByTitle('Reactivate').click();
    await pressInDialog(adminPage, /Reactivate Recurring Contributions/);
    await expect(adminPage.locator('#crm-notification-container')).toContainText('Successfully reactivated 1 Recurring Contributions');
    const back = recurRow(record.recurId);
    expect(back['contribution_status_id:name']).toBe('In Progress');
    expect(back.failure_count).toBe(0);
    expect(back.failure_retry_date).toBeFalsy();

    // The tab offers the active record's links again: Edit, Cancel and Change Billing Details.
    await openRecurringTab(adminPage, record.contactId);
    const active = recurringRow(adminPage, record);
    await expect(active).toContainText('In Progress');
    await active.locator('.btn-slide').click();
    for (const name of ['View', 'Edit', 'Cancel', 'Change Billing Details']) {
      await expect(active.getByRole('link', { name, exact: true }), name).toBeVisible();
    }
    await expect(adminPage.getByRole('heading', { name: 'Active Recurring Contributions', exact: true })).toBeVisible();
  });

  test('PW-B-recurforms-05 a Failed record can be cancelled from the cancel form opened by address, and eWAY is not contacted', async ({ adminPage, stub }) => {
    test.setTimeout(180_000);
    const record = failedThroughCron(stub, 'forms05cancel');
    stub.reset();

    await adminPage.goto(cancelRecurringUrl(record));
    await expect(adminPage.locator('form#CancelSubscription')).toBeVisible();
    await adminPage.locator('#cancel_reason').fill('Playwright cancel a failed record');
    await adminPage.locator('#_qf_CancelSubscription_next-bottom').click();
    await expect(adminPage.locator('#crm-notification-container')).toContainText('has been cancelled');

    expect(recurRow(record.recurId)['contribution_status_id:name']).toBe('Cancelled');
    expect(stub.requests()).toHaveLength(0);

    // It is Cancelled on the tab, and gone from the Failed Recurring Contributions list.
    await openRecurringTab(adminPage, record.contactId);
    await expect(recurringRow(adminPage, record)).toContainText('Cancelled');
    await openFailedList(adminPage);
    await expect(failedListRow(adminPage, record.recurId)).toHaveCount(0);
  });
});
