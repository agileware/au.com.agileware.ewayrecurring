import { test, expect, skipOnCms } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4 } from '../../fixtures/civi';
import {
  FIELD,
  SETTINGS_TITLE,
  chooseProcessor,
  chosenProcessors,
  offeredProcessors,
  openAdministerMenuEntry,
  openSettings,
  removeChosenProcessor,
  resetEwaySettings,
  settingsRow,
  settingsUrl,
  storedSettings,
  submitSettings,
} from '../../fixtures/backend';
import { liveProcessorId } from '../../fixtures/recurring-records';
import testData from '../../fixtures/test-data.json';

/**
 * PW-B-settings: the extension's settings page (civicrm/ewayrecurring/settings), where staff set the retry limit, the
 * retry delay, receipts for recurring payments and the settlement sync. Test plan: developer/TEST_PLAN.md section 5.2.
 *
 * Every case starts and ends with the settings at their defaults, so none depends on what another saved.
 */

/** Core's refusal page, on a CMS whose admin screens have already printed their menu, warns about the status code. */
// A refused user gets HTTP 403 on a development site and HTTP 500 on a fresh CI site (core's own permission page).
const REFUSAL_NOISE = /Cannot set response code|status of (403|500)|HTTP (403|500)/;
const REFUSAL_STATUS = [403, 500];
/** The extension's name for the form's error summary. */
const ERRORS_INTRO = 'Please correct the following errors in the form fields below';

test.describe('settings page', () => {
  test.beforeEach(() => resetEwaySettings());
  test.afterEach(() => resetEwaySettings());

  test('PW-B-settings-01 the page opens for the administrator with the documented defaults and nothing chosen for the settlement sync', async ({ adminPage }) => {
    await openSettings(adminPage);

    await expect(adminPage.locator('h1, .page-title, .crm-page-title').filter({ hasText: SETTINGS_TITLE }).first()).toBeVisible();
    await expect(adminPage).toHaveTitle(new RegExp(SETTINGS_TITLE));

    // The five settings, each with its label and a help text.
    const labels = [
      [FIELD.retries, 'Maximum Number of Retries'],
      [FIELD.delay, 'Retry delay (in days)'],
      [FIELD.window, 'Settlement Sync: Window (days)'],
      [FIELD.processors, 'Settlement Sync: Payment Processors'],
    ] as const;
    for (const [field, label] of labels) {
      await expect(settingsRow(adminPage, field).locator('td.label')).toContainText(label);
      await expect(settingsRow(adminPage, field).locator('.description')).not.toBeEmpty();
    }
    await expect(adminPage.getByText('Send receipt for all recurring contributions')).toBeVisible();

    // Defaults: 3 retries, 4 days, receipts No, window 10.
    await expect(adminPage.locator(FIELD.retries)).toHaveValue('3');
    await expect(adminPage.locator(FIELD.delay)).toHaveValue('4');
    await expect(adminPage.locator('input[name=eway_recurring_keep_sending_receipts][value="0"]')).toBeChecked();
    await expect(adminPage.locator('input[name=eway_recurring_keep_sending_receipts][value="1"]')).not.toBeChecked();
    await expect(adminPage.locator(FIELD.window)).toHaveValue('10');

    // Nothing is chosen for the settlement sync, in the box or in the select behind it.
    expect(await chosenProcessors(adminPage)).toEqual([]);
    expect(await adminPage.locator(`${FIELD.processors} option:checked`).count()).toBe(0);

    await expect(adminPage.locator(FIELD.submit)).toBeVisible();
  });

  test('PW-B-settings-02 the "eWay Recurring Settings" entry under Administer opens the settings page', async ({ adminPage }) => {
    await adminPage.goto(adminUrl('dashboard', { reset: 1 }));

    const entry = await openAdministerMenuEntry(adminPage, 'eWay Recurring Settings');
    await expect(entry).toHaveCount(1);
    // It is a direct child of Administer, not under one of its groups, and it goes to the settings page.
    const parent = await entry.evaluate((a) => a.closest('ul')?.closest('li')?.querySelector(':scope > a')?.textContent?.trim());
    expect(parent).toBe('Administer');
    expect(await entry.getAttribute('href')).toContain(encodeURIComponent('civicrm/ewayrecurring/settings'));

    await entry.click();
    await expect(adminPage.locator(FIELD.retries)).toBeVisible();
    expect(decodeURIComponent(adminPage.url())).toContain('civicrm/ewayrecurring/settings');
    await expect(adminPage).toHaveTitle(new RegExp(SETTINGS_TITLE));
  });

  test('PW-B-settings-03 blank, non-numeric, negative and fractional retries and delays are refused with a message and nothing is saved', async ({ adminPage }) => {
    await openSettings(adminPage);

    const refused: [string, 'retries' | 'delay', string, string][] = [
      ['blank retries', 'retries', '', 'Retries should be a valid number'],
      ['letters in retries', 'retries', 'abc', 'Retries should be a valid number'],
      ['blank delay', 'delay', '', 'Retry delay should be a valid number'],
      ['letters in delay', 'delay', 'abc', 'Retry delay should be a valid number'],
      ['negative retries', 'retries', '-1', 'Retries should be a valid number'],
      ['fractional retries', 'retries', '2.5', 'Retries should be a valid number'],
      ['negative delay', 'delay', '-4', 'Retry delay should be a valid number'],
      ['fractional delay', 'delay', '0.5', 'Retry delay should be a valid number'],
    ];
    for (const [name, which, value, message] of refused) {
      // Start each attempt from the valid defaults, so exactly one field is wrong.
      await adminPage.locator(FIELD.retries).fill('3');
      await adminPage.locator(FIELD.delay).fill('4');
      await submitSettings(adminPage, { [which]: value });

      const field = which === 'retries' ? FIELD.retries : FIELD.delay;
      await expect(settingsRow(adminPage, field), name).toContainText(message);
      await expect(adminPage.getByText(ERRORS_INTRO), name).toBeVisible();
      // The other field has no error, and what was typed is still in the box.
      const other = which === 'retries' ? FIELD.delay : FIELD.retries;
      await expect(settingsRow(adminPage, other), name).not.toContainText('should be a valid number');
      await expect(adminPage.locator(field), name).toHaveValue(value);
    }
    // Every attempt was refused with the form shown again, so nothing was ever saved.
    expect(storedSettings()).toMatchObject({
      eway_recurring_contribution_max_retry: '3',
      eway_recurring_contribution_retry_delay: '4',
    });
  });

  test('PW-B-settings-03 the settlement window is refused at 0, 91, blank and letters, and accepted at 1 and 90', async ({ adminPage }) => {
    await openSettings(adminPage);
    const message = 'Settlement sync window must be between 1 and 90 days';

    for (const value of ['0', '91', '', 'abc', '-5']) {
      await submitSettings(adminPage, { retries: '3', delay: '4', window: value });
      await expect(settingsRow(adminPage, FIELD.window), `window "${value}"`).toContainText(message);
    }
    expect(storedSettings(), 'a refused window is not saved').toMatchObject({ eway_settlement_window_days: '10' });

    for (const value of ['1', '90']) {
      await submitSettings(adminPage, { retries: '3', delay: '4', window: value });
      // Saved: the page comes back as a fresh form, with no error and the value that was entered.
      await expect(adminPage.getByText(ERRORS_INTRO)).toHaveCount(0);
      await expect(adminPage.locator(FIELD.window)).toHaveValue(value);
      expect(storedSettings()).toMatchObject({ eway_settlement_window_days: value });
    }
  });

  test('PW-B-settings-04 saving stores every setting, the page comes back to itself with a confirmation, and the values survive a reload', async ({ adminPage }) => {
    await openSettings(adminPage);

    await adminPage.locator('input[name=eway_recurring_keep_sending_receipts][value="1"]').check();
    await chooseProcessor(adminPage, testData.processors.live);
    await submitSettings(adminPage, { retries: '5', delay: '7', window: '30' });

    // The save redirects back to the same page (a fresh form, not the POST).
    await expect(adminPage.locator(FIELD.retries)).toHaveValue('5');
    expect(decodeURIComponent(adminPage.url())).toContain('civicrm/ewayrecurring/settings');
    await expect(adminPage.locator('#crm-notification-container')).toContainText(/saved/i);

    const liveId = liveProcessorId();
    expect(storedSettings()).toEqual({
      eway_recurring_contribution_max_retry: '5',
      eway_recurring_contribution_retry_delay: '7',
      eway_recurring_keep_sending_receipts: true,
      eway_settlement_window_days: '30',
      eway_settlement_processors: [String(liveId)],
    });

    // A reload, and a fresh visit from the address bar, both show what was saved.
    for (const reload of [() => adminPage.reload(), () => adminPage.goto(settingsUrl())]) {
      await reload();
      await expect(adminPage.locator(FIELD.retries)).toHaveValue('5');
      await expect(adminPage.locator(FIELD.delay)).toHaveValue('7');
      await expect(adminPage.locator('input[name=eway_recurring_keep_sending_receipts][value="1"]')).toBeChecked();
      await expect(adminPage.locator(FIELD.window)).toHaveValue('30');
      expect(await chosenProcessors(adminPage)).toEqual([testData.processors.live]);
    }
  });

  test('PW-B-settings-05 the settlement processor box offers live, active eWAY processors only', async ({ adminPage }) => {
    // A live eWAY processor that is switched off, which must not be offered.
    const inactive = api4<{ id: number }>('PaymentProcessor', 'create', {
      values: {
        name: `Playwright inactive eWAY ${Date.now()}`,
        title: `Playwright inactive eWAY ${Date.now()}`,
        'payment_processor_type_id:name': 'eWay_Recurring',
        is_test: false,
        is_active: false,
        user_name: 'playwright-key',
        password: 'playwright-password',
        domain_id: 1,
      },
    })[0];
    try {
      const all = api4<{ id: number; title: string; is_test: boolean; is_active: boolean; type: string }>('PaymentProcessor', 'get', {
        select: ['id', 'title', 'is_test', 'is_active', 'payment_processor_type_id:name'],
        where: [['is_test', 'IN', [true, false]]],
      }).map((row: any) => ({ ...row, type: row['payment_processor_type_id:name'] }));
      const expected = all.filter((row) => row.type === 'eWay_Recurring' && !row.is_test && row.is_active).map((row) => row.title).sort();
      const liveId = liveProcessorId();
      const testRowId = all.find((row) => row.title === testData.processors.live && row.is_test)!.id;
      expect(testRowId).not.toBe(liveId);

      await openSettings(adminPage);
      // The list behind the box is what the page built: one option per live, active eWAY processor, valued by its id.
      const options = await adminPage.locator(`${FIELD.processors} option`).evaluateAll((all) => all.map((o) => ({ value: (o as HTMLOptionElement).value, text: (o as HTMLOptionElement).text })));
      expect(options.map((o) => o.text).sort()).toEqual(expected);
      expect(options.map((o) => o.value)).toContain(String(liveId));
      expect(options.map((o) => o.value)).not.toContain(String(testRowId));
      expect(options.map((o) => o.value)).not.toContain(String(inactive.id));

      // And that is what a person sees when they open the box.
      const offered = await offeredProcessors(adminPage);
      expect(offered.slice().sort()).toEqual(expected);
      expect(offered).toContain(testData.processors.live);
      expect(offered).not.toContain(testData.processors.dummy);
      expect(offered.filter((title) => title === testData.processors.live)).toHaveLength(1);
      expect(offered.some((title) => title.includes('inactive'))).toBe(false);
    } finally {
      api4('PaymentProcessor', 'delete', { where: [['id', '=', inactive.id]] });
    }
  });

  test('PW-B-settings-06 choosing a processor saves it, and taking every choice away clears the setting', async ({ adminPage }) => {
    const liveId = String(liveProcessorId());

    await openSettings(adminPage);
    await chooseProcessor(adminPage, testData.processors.live);
    await submitSettings(adminPage);
    await expect(adminPage.locator(FIELD.retries)).toHaveValue('3');
    expect(storedSettings().eway_settlement_processors).toEqual([liveId]);

    // Back on the page the choice shows. Take it away and save: a select with nothing chosen posts no value at all, and
    // the setting must still be cleared rather than keep the old choice.
    await openSettings(adminPage);
    expect(await chosenProcessors(adminPage)).toEqual([testData.processors.live]);
    await removeChosenProcessor(adminPage, testData.processors.live);
    expect(await chosenProcessors(adminPage)).toEqual([]);
    await submitSettings(adminPage);
    await expect(adminPage.locator(FIELD.retries)).toHaveValue('3');

    expect(storedSettings().eway_settlement_processors).toEqual([]);
    await openSettings(adminPage);
    expect(await chosenProcessors(adminPage)).toEqual([]);
  });

  test.describe('refused users', () => {
    test.use({ allowedProblems: [REFUSAL_NOISE] });

    test('PW-B-settings-07 the finance and limited users are refused: no form, no menu entry, and a post changes nothing', async ({ financePage, limitedPage }) => {
      for (const [name, page] of [['finance', financePage], ['limited', limitedPage]] as const) {
        const response = await page.goto(settingsUrl());
        expect(REFUSAL_STATUS, `${name}: HTTP status ${response?.status()}`).toContain(response?.status());
        await expect(page.getByText('You do not have permission to access this page.'), name).toBeVisible();
        await expect(page.locator(FIELD.retries), name).toHaveCount(0);
        await expect(page.locator(FIELD.submit), name).toHaveCount(0);

        // A post made without the form (as a script would) is refused as well, and nothing changes.
        const post = await page.request.post(settingsUrl(), {
          form: { eway_recurring_contribution_max_retry: '9', eway_recurring_contribution_retry_delay: '9', eway_settlement_window_days: '9', _qf_Settings_submit: '1' },
          maxRedirects: 0,
        });
        expect(REFUSAL_STATUS, `${name}: post status ${post.status()}`).toContain(post.status());
        expect(storedSettings(), `${name}: settings unchanged`).toMatchObject({
          eway_recurring_contribution_max_retry: '3',
          eway_recurring_contribution_retry_delay: '4',
          eway_settlement_window_days: '10',
        });

        // The menu does not offer the entry either.
        await page.goto(adminUrl('dashboard', { reset: 1 }));
        await expect(page.locator('#civicrm-menu a', { hasText: 'eWay Recurring Settings' }), `${name}: menu entry`).toHaveCount(0);
      }
    });
  });

  test('PW-B-settings-08 from the CMS admin screen, CiviCRM and then the Administer menu lead to the settings page', async ({ adminPage }) => {
    skipOnCms(test, ['drupal', 'joomla', 'standalone'], "the CMS's own navigation is not scripted for this CMS yet");

    // WordPress: the admin sidebar has a CiviCRM entry (and a Settings entry that is CiviCRM's own); the extension adds
    // nothing of its own to it. Its entry is in CiviCRM's menu bar, which WordPress shows on every CiviCRM screen.
    await adminPage.goto('/wp-admin/index.php');
    const sidebar = adminPage.locator('#adminmenu');
    await expect(sidebar.getByText('eWay', { exact: false })).toHaveCount(0);
    await sidebar.getByRole('link', { name: 'CiviCRM', exact: true }).first().click();
    await expect(adminPage.locator('#civicrm-menu')).toBeVisible();

    await (await openAdministerMenuEntry(adminPage, 'eWay Recurring Settings')).click();
    await expect(adminPage.locator(FIELD.retries)).toBeVisible();
    await expect(adminPage).toHaveTitle(new RegExp(SETTINGS_TITLE));
  });
});
