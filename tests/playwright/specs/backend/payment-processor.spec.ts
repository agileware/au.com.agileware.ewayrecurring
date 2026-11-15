import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/base';
import { adminUrl } from '../../fixtures/cms';
import { api4, evaluate } from '../../fixtures/civi';
import { deleteProcessorsTitled, uniqueName } from '../../fixtures/backend';
import { pageIdByTitle } from '../../fixtures/contribution-page';
import testData from '../../fixtures/test-data.json';

/**
 * PW-B-processor: the eWay Recurring payment processor type in CiviCRM's own Payment Processors admin screens (the
 * extension adds the type, its two credential labels, and a check that both credentials are filled in). Test plan:
 * developer/TEST_PLAN.md section 5.2.
 */

/** The form's heading and the status CiviCRM shows after saving. */
const ADD_URL = () => adminUrl('admin/paymentProcessor/edit', { reset: 1, action: 'add' });
const LIST_URL = () => adminUrl('admin/paymentProcessor', { reset: 1 });

/** Choose a processor type on the add form. CiviCRM then reloads the credential section with that type's labels. */
async function chooseType(page: Page, label: string): Promise<void> {
  const reloaded = page.waitForResponse((response) => /pp=\d+/.test(response.url()) && response.request().method() === 'GET');
  await page.locator('#payment_processor_type_id').selectOption({ label });
  await reloaded;
  await expect(page.locator('#payment_processor_type_id option:checked')).toHaveText(label);
}

/** Every credential field a processor type can show, in the order CiviCRM lists them. */
const CREDENTIAL_FIELDS = ['user_name', 'password', 'signature', 'url_site', 'url_api', 'url_recur', 'url_button'];

/** The labels of the credential fields the form shows in the live section and in the test section, in order. */
async function credentialLabels(page: Page): Promise<{ live: string[]; test: string[] }> {
  const section = async (prefix: string) => {
    const labels: string[] = [];
    for (const field of CREDENTIAL_FIELDS) {
      const input = page.locator(`#${prefix}${field}`);
      if (await input.count()) {
        labels.push((await page.locator(`label[for="${prefix}${field}"]`).innerText()).replace(/\s+/g, ' ').trim());
      }
    }
    return labels;
  };
  return { live: await section(''), test: await section('test_') };
}

/** Fill in the part of the add form every type needs. */
async function fillCommon(page: Page, title: string): Promise<void> {
  await page.locator('#title').fill(title);
  await page.locator('#financial_account_id').selectOption({ label: 'Payment Processor Account' });
}

function processorRows(title: string): Record<string, any>[] {
  return api4('PaymentProcessor', 'get', {
    select: ['id', 'title', 'is_test', 'is_active', 'user_name', 'password', 'class_name', 'payment_processor_type_id:name'],
    where: [['title', '=', title], ['is_test', 'IN', [true, false]]],
  });
}

test.describe('payment processor admin', () => {
  test('PW-B-processor-01 "eWay Recurring" is offered as a processor type, with API Key and API Password as its credentials', async ({ adminPage }) => {
    await adminPage.goto(ADD_URL());
    const types = await adminPage.locator('#payment_processor_type_id option').allInnerTexts();
    expect(types.filter((type) => type.trim() === 'eWay Recurring')).toHaveLength(1);

    await chooseType(adminPage, 'eWay Recurring');
    // Both sections, live and test, are relabelled; the fields eWAY does not use are gone.
    const labels = await credentialLabels(adminPage);
    expect(labels.live).toEqual(['API Key', 'API Password', 'Site URL', 'Recurring Payments URL']);
    expect(labels.test).toEqual(['API Key', 'API Password', 'Site URL', 'Recurring Payments URL']);
    await expect(adminPage.locator('input[name=signature], input[name=url_api], input[name=url_button]')).toHaveCount(0);

    // For contrast, another type keeps its own labels.
    await chooseType(adminPage, 'Dummy Payment Processor');
    const dummy = await credentialLabels(adminPage);
    expect(dummy.live).toEqual(['User Name', 'Site URL', 'Recurring Payments URL']);
  });

  test.describe('required credentials', () => {
    test('PW-B-processor-02 an eWay Recurring processor saved without its API Key and API Password is refused with both messages, and other types are not affected', async ({ adminPage }) => {
      const eway = uniqueName('PW no credentials eWAY');
      const dummy = uniqueName('PW no credentials Dummy');
      const before = api4('PaymentProcessor', 'get', { select: ['id'], where: [['is_test', 'IN', [true, false]]] }).length;
      try {
        await adminPage.goto(ADD_URL());
        await chooseType(adminPage, 'eWay Recurring');
        await fillCommon(adminPage, eway);
        await adminPage.locator('#_qf_PaymentProcessor_next-bottom').click();

        // Each credential has its message beside it, and the summary lists them.
        const key = adminPage.locator('tr', { has: adminPage.locator('#user_name') });
        const password = adminPage.locator('tr', { has: adminPage.locator('#password') });
        await expect(key).toContainText('API Key is a required field.');
        await expect(password).toContainText('API Password is a required field.');
        await expect(adminPage.getByText('Please correct the following errors in the form fields below')).toBeVisible();
        expect(processorRows(eway)).toHaveLength(0);

        // One credential is not enough: the password is still required.
        await adminPage.locator('#user_name').fill('pw-live-key');
        await adminPage.locator('#_qf_PaymentProcessor_next-bottom').click();
        await expect(adminPage.locator('tr', { has: adminPage.locator('#password') })).toContainText('API Password is a required field.');
        await expect(adminPage.locator('tr', { has: adminPage.locator('#user_name') })).not.toContainText('required field');
        expect(processorRows(eway)).toHaveLength(0);

        // The test section follows the same rule: a key without its password is refused, and it names the test field
        // that is missing (DEF-56, fixed: the live section is no longer demanded when the test section is complete).
        await adminPage.locator('#user_name').fill('');
        await adminPage.locator('#test_user_name').fill('pw-test-key');
        await adminPage.locator('#_qf_PaymentProcessor_next-bottom').click();
        await expect(adminPage.locator('tr', { has: adminPage.locator('#test_password') })).toContainText('Test API Password is a required field.');
        await expect(adminPage.locator('tr', { has: adminPage.locator('#user_name') })).not.toContainText('required field');
        expect(processorRows(eway)).toHaveLength(0);

        // Another type is not touched by the check: with nothing filled in, only CiviCRM's own message appears, and with
        // a live user name alone (no password at all) it saves.
        await adminPage.goto(ADD_URL());
        await chooseType(adminPage, 'Dummy Payment Processor');
        await fillCommon(adminPage, dummy);
        await adminPage.locator('#_qf_PaymentProcessor_next-bottom').click();
        await expect(adminPage.getByText('You must have at least the test or live section filled')).toBeVisible();
        await expect(adminPage.getByText('API Key is a required field.')).toHaveCount(0);
        await expect(adminPage.getByText('API Password is a required field.')).toHaveCount(0);
        expect(processorRows(dummy)).toHaveLength(0);

        await adminPage.locator('#user_name').fill('pw-dummy-user');
        await adminPage.locator('#_qf_PaymentProcessor_next-bottom').click();
        await expect(adminPage.locator('#crm-notification-container')).toContainText(`${dummy} has been saved`);
        expect(processorRows(dummy).map((row) => row.is_test).sort()).toEqual([false, true]);
        expect(processorRows(dummy)[0]['payment_processor_type_id:name']).toBe('Dummy');
      } finally {
        deleteProcessorsTitled(eway);
        deleteProcessorsTitled(dummy);
      }
      expect(api4('PaymentProcessor', 'get', { select: ['id'], where: [['is_test', 'IN', [true, false]]] }).length).toBe(before);
    });
  });

  test.describe('a processor with test credentials only', () => {
    test.use({ allowedProblems: [/Undefined array key "weight"/] });

    test('PW-B-processor-02 an eWay Recurring processor with only the test (sandbox) section filled in is saved', async ({ adminPage }) => {
      const title = uniqueName('PW sandbox only eWAY');
      try {
        await adminPage.goto(ADD_URL());
        await chooseType(adminPage, 'eWay Recurring');
        await fillCommon(adminPage, title);
        await adminPage.locator('#test_user_name').fill('pw-test-key');
        await adminPage.locator('#test_password').fill('pw-test-pass');
        await adminPage.locator('#_qf_PaymentProcessor_next-bottom').click();
        // The form accepts it and saves both rows. CiviCRM then checks the LIVE row, which is empty, and says so with its
        // "configuration invalid" error instead of the "has been saved" message: the live details are still to be added.
        const notice = adminPage.locator('#crm-notification-container');
        await expect(notice).toContainText('Payment processor configuration invalid');
        await expect(notice).toContainText('API Key is not set for this payment processor');
        await expect(adminPage.getByText('Please correct the following errors in the form fields below')).toHaveCount(0);

        const rows = processorRows(title);
        expect(rows).toHaveLength(2);
        expect(rows.find((row) => row.is_test)).toMatchObject({ user_name: 'pw-test-key', password: 'pw-test-pass', class_name: 'Payment_eWAYRecurring' });
        // The live row is left empty, so it cannot be used for live payments until the live details are added.
        expect(rows.find((row) => !row.is_test)).toMatchObject({ class_name: 'Payment_eWAYRecurring' });
      } finally {
        deleteProcessorsTitled(title);
      }
    });
  });

  test.describe('a new processor', () => {
    // Core prints this warning on the contribution page's tabs on some sites, which is not this extension's.
    test.use({ allowedProblems: [/Undefined array key "weight"/] });

    test('PW-B-processor-03 a valid new processor is saved with its credentials and is then offered on a contribution page', async ({ adminPage }) => {
      const title = uniqueName('PW new eWAY');
      try {
        await adminPage.goto(ADD_URL());
        await chooseType(adminPage, 'eWay Recurring');
        await fillCommon(adminPage, title);
        await adminPage.locator('#user_name').fill('pw-live-key');
        await adminPage.locator('#password').fill('pw-live-pass');
        await adminPage.locator('#_qf_PaymentProcessor_next-bottom').click();

        // CiviCRM confirms, and shows the saved processor for editing.
        await expect(adminPage.locator('#crm-notification-container')).toContainText(`${title} has been saved`);
        await expect(adminPage.locator('#user_name')).toHaveValue('pw-live-key');

        const rows = processorRows(title);
        const live = rows.find((row) => !row.is_test)!;
        const test = rows.find((row) => row.is_test)!;
        expect(rows).toHaveLength(2);
        expect(live).toMatchObject({ is_active: true, user_name: 'pw-live-key', password: 'pw-live-pass', class_name: 'Payment_eWAYRecurring', 'payment_processor_type_id:name': 'eWay_Recurring' });
        // The test row is made empty, because only the live section was filled in.
        expect(test).toMatchObject({ class_name: 'Payment_eWAYRecurring', 'payment_processor_type_id:name': 'eWay_Recurring' });

        // It is in the list of processors, as an eWay Recurring one.
        await adminPage.goto(LIST_URL());
        const row = adminPage.locator('tr', { hasText: title.replace(/ /g, '_') }).first();
        await expect(row).toContainText('eWay Recurring');
        await expect(row).toContainText('Yes');

        // And the contribution page's Amounts tab lists it among the processors a page can use (not ticked).
        const pageId = pageIdByTitle(testData.pages.onceOff.title);
        await adminPage.goto(adminUrl('admin/contribute/amount', { reset: 1, action: 'update', id: pageId }));
        await adminPage.getByRole('link', { name: 'Amounts' }).first().click();
        const checkbox = adminPage.locator(`#payment_processor_${live.id}`);
        await expect(checkbox).toBeVisible();
        await expect(adminPage.locator(`label[for="payment_processor_${live.id}"]`)).toHaveText(title);
        await expect(checkbox).not.toBeChecked();
        // The page's own processor is still the ticked one.
        await expect(adminPage.locator('input[name^="payment_processor"]:checked')).toHaveCount(1);
      } finally {
        deleteProcessorsTitled(title);
      }
    });
  });

  test.describe('the extension switched off and on', () => {
    // The extensions screen is CiviCRM's own; it redirects through a form and prints nothing the extension owns.
    const KEY = 'au.com.agileware.ewayrecurring';

    /** Click Disable or Enable for the extension on CiviCRM's Extensions screen, and confirm on the page that follows. */
    async function toggleExtension(page: Page, action: 'Disable' | 'Enable'): Promise<void> {
      await page.goto(adminUrl('admin/extensions', { reset: 1 }));
      const row = page.locator('tr', { hasText: KEY }).first();
      await row.getByRole('link', { name: action, exact: true }).click();
      // Enable has no question to answer on every version; Disable always asks.
      const confirm = page.getByRole('button', { name: action, exact: true });
      await confirm.click();
      await expect(page.locator('tr', { hasText: KEY }).first()).toContainText(action === 'Disable' ? 'Disabled' : 'Enabled');
    }

    function typeState(): { id: number; is_active: boolean } {
      return api4<{ id: number; is_active: boolean }>('PaymentProcessorType', 'get', {
        select: ['id', 'is_active'],
        where: [['name', '=', 'eWay_Recurring']],
      })[0];
    }

    test('PW-B-processor-04 disabling and enabling the extension from the Extensions screen leaves the processor type, the processors and the scheduled jobs active', async ({ adminPage }, testInfo) => {
      test.setTimeout(120_000);
      const processorsBefore = api4<{ id: number; is_active: boolean }>('PaymentProcessor', 'get', {
        select: ['id', 'is_active'],
        where: [['payment_processor_type_id:name', '=', 'eWay_Recurring'], ['is_test', 'IN', [true, false]]],
      });
      expect(processorsBefore.length).toBeGreaterThan(0);

      try {
        await toggleExtension(adminPage, 'Disable');

        // While it is off CiviCRM takes the processor type out of use when the extension manages that record. A site
        // whose type was created before the extension managed it (an older site) keeps it, so only note what happened,
        // then make sure the type is inactive so that enabling has something to put right on every site.
        testInfo.annotations.push({ type: 'type while disabled', description: String(typeState().is_active ? 'still active' : 'inactive') });
        if (typeState().is_active) {
          api4('PaymentProcessorType', 'update', { values: { is_active: false }, where: [['name', '=', 'eWay_Recurring']] });
        }
        expect(typeState().is_active).toBe(false);

        await toggleExtension(adminPage, 'Enable');
      } finally {
        // Never leave the site with the extension off, whatever happened above.
        evaluate(`civicrm_api3('Extension', 'enable', ['keys' => '${KEY}']); echo 'ok';`);
      }

      // The type is active again, every processor is as it was, and the add form offers the type.
      expect(typeState().is_active).toBe(true);
      const processorsAfter = api4<{ id: number; is_active: boolean }>('PaymentProcessor', 'get', {
        select: ['id', 'is_active'],
        where: [['payment_processor_type_id:name', '=', 'eWay_Recurring'], ['is_test', 'IN', [true, false]]],
      });
      expect(processorsAfter).toEqual(processorsBefore);

      // The scheduled jobs are on again too (core leaves them off when an extension is enabled again, DEF-53), except the
      // settlement sync, which is shipped switched off.
      const jobs = Object.fromEntries(
        api4<{ name: string; is_active: boolean }>('Job', 'get', { select: ['name', 'is_active'], where: [['name', 'LIKE', 'eWay%']] }).map((job) => [job.name, job.is_active]),
      );
      expect(jobs).toEqual({
        'eWay Recurring Payments': true,
        'eWay Transaction Verifications': true,
        'eWay Recurring: fill missing tokens metadata': true,
        'eWay Settlement Sync': false,
      });

      await adminPage.goto(ADD_URL());
      await expect(adminPage.locator('#payment_processor_type_id option', { hasText: 'eWay Recurring' })).toHaveCount(1);
      await adminPage.goto(LIST_URL());
      await expect(adminPage.locator('tr', { hasText: testData.processors.live }).first()).toContainText('Yes');
    });
  });
});
