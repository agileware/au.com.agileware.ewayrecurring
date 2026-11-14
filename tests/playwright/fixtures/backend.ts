import { expect, type Locator, type Page } from '@playwright/test';
import { api4, getSetting } from './civi';
import { adminUrl } from './cms';

/**
 * Helpers for the back-office specs (specs/backend/): the extension's settings page, the settings it owns, the select2
 * box on that page, and calling an API from a signed-in CiviCRM page the way the browser does.
 */

/** The settings the extension's settings page owns, with the defaults the setting definitions give them. */
export const SETTING_DEFAULTS = {
  eway_recurring_contribution_max_retry: '3',
  eway_recurring_contribution_retry_delay: '4',
  eway_recurring_keep_sending_receipts: false,
  eway_settlement_window_days: '10',
  eway_settlement_processors: [] as string[],
};

/** Put every setting on the settings page back to its default (not stored at all), so a case never depends on what another saved. */
export function resetEwaySettings(): void {
  api4('Setting', 'revert', { select: Object.keys(SETTING_DEFAULTS) });
}

/**
 * The settings page's stored values in one shape whatever wrote them: numbers as text (the form saves text, the
 * API saves numbers), the receipts switch as a boolean, the processors as a list of ids as text.
 */
export function storedSettings(): typeof SETTING_DEFAULTS {
  const raw = (name: keyof typeof SETTING_DEFAULTS) => getSetting(name);
  const receipts = raw('eway_recurring_keep_sending_receipts') as unknown;
  const processors = (raw('eway_settlement_processors') as unknown as unknown[]) ?? [];
  return {
    eway_recurring_contribution_max_retry: String(raw('eway_recurring_contribution_max_retry')),
    eway_recurring_contribution_retry_delay: String(raw('eway_recurring_contribution_retry_delay')),
    eway_recurring_keep_sending_receipts: receipts === true || receipts === '1' || receipts === 1,
    eway_settlement_window_days: String(raw('eway_settlement_window_days')),
    eway_settlement_processors: processors.map(String),
  };
}

/** The settings page's address. */
export function settingsUrl(): string {
  return adminUrl('ewayrecurring/settings', { reset: 1 });
}

/** The heading CiviCRM shows for the settings page (the page's title, not the menu entry's). */
export const SETTINGS_TITLE = 'eWay Recurring Contributions Settings';

/** The ids of the form fields on the settings page. */
export const FIELD = {
  retries: '#eway_recurring_contribution_max_retry',
  delay: '#eway_recurring_contribution_retry_delay',
  window: '#eway_settlement_window_days',
  processors: '#eway_settlement_processors',
  submit: '#_qf_Settings_submit-bottom',
};

/** Open the settings page and wait for the form. */
export async function openSettings(page: Page): Promise<void> {
  await page.goto(settingsUrl());
  await expect(page.locator(FIELD.retries)).toBeVisible();
}

/**
 * Fill the three text fields (leave one out to keep what it shows), press Submit and wait for the page that comes back,
 * whether that is the form again with its errors or the saved form after the redirect.
 */
export async function submitSettings(page: Page, values: { retries?: string; delay?: string; window?: string } = {}): Promise<void> {
  if (values.retries !== undefined) {
    await page.locator(FIELD.retries).fill(values.retries);
  }
  if (values.delay !== undefined) {
    await page.locator(FIELD.delay).fill(values.delay);
  }
  if (values.window !== undefined) {
    await page.locator(FIELD.window).fill(values.window);
  }
  // Leaving a field that shows an error makes CiviCRM take the message away, which moves the button up a line. A click
  // aimed before that move lands on nothing, so move the focus to the button first and let the page settle.
  await page.locator(FIELD.submit).focus();
  await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
  // The form posts to the server, which answers with the page again (errors) or with a redirect to a fresh form (saved).
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator(FIELD.submit).click()]);
  await expect(page.locator(FIELD.retries)).toBeVisible();
}

/**
 * Open CiviCRM's Administer menu by clicking it (hovering does not open it in a browser driven by a script) and return
 * the entry with this text. The menu is built by script after the page loads, so a click that comes too early is
 * repeated until the entry shows.
 */
export async function openAdministerMenuEntry(page: Page, entryText: string): Promise<Locator> {
  const menu = page.locator('#civicrm-menu');
  await expect(menu).toBeVisible();
  const administer = menu.getByText('Administer', { exact: true }).first();
  const entry = menu.locator('a', { hasText: entryText });
  await expect(async () => {
    await administer.click();
    await expect(entry).toBeVisible({ timeout: 1500 });
  }).toPass({ timeout: 15_000 });
  return entry;
}

/** The row of the settings form that holds a field. */
export function settingsRow(page: Page, field: string) {
  return page.locator('tr', { has: page.locator(field) });
}

/** The select2 box for the settlement sync processors. */
export function processorBox(page: Page) {
  return page.locator('#s2id_eway_settlement_processors');
}

/** The processors chosen in the select2 box, by the text shown in each tag. */
export async function chosenProcessors(page: Page): Promise<string[]> {
  return (await processorBox(page).locator('.select2-search-choice').allInnerTexts()).map((text) => text.trim());
}

/** Open the select2 box and choose the entry with this text. */
export async function chooseProcessor(page: Page, title: string): Promise<void> {
  await processorBox(page).locator('.select2-choices').click();
  await page.locator('.select2-drop-active .select2-result-label', { hasText: title }).first().click();
}

/** The entries the open select2 box offers, as text. */
export async function offeredProcessors(page: Page): Promise<string[]> {
  await processorBox(page).locator('.select2-choices').click();
  const offered = (await page.locator('.select2-drop-active .select2-result-label').allInnerTexts()).map((text) => text.trim());
  await page.keyboard.press('Escape');
  return offered;
}

/** Remove a chosen processor by pressing the cross on its tag. */
export async function removeChosenProcessor(page: Page, title: string): Promise<void> {
  await processorBox(page)
    .locator('.select2-search-choice', { hasText: title })
    .locator('.select2-search-choice-close')
    .click();
}

/** The outcome of an APIv4 call made from a page. */
export interface BrowserApiResult {
  ok: boolean;
  /** The rows, when it worked. */
  values?: any[];
  /** The HTTP status CiviCRM answered with, when it did not. */
  status?: number;
}

/**
 * Call APIv4 from a signed-in CiviCRM page with CRM.api4(), which is how the APIv4 Explorer and every CiviCRM screen
 * call it: through the ajax route, as the signed-in user, with that user's permissions checked. The page must already be
 * on a CiviCRM screen.
 */
export async function browserApi4(page: Page, entity: string, action: string, params: Record<string, unknown> = {}): Promise<BrowserApiResult> {
  return page.evaluate(
    async ([e, a, p]) => {
      try {
        const values = await (window as any).CRM.api4(e, a, p);
        return { ok: true, values: Array.from(values) };
      } catch (error: any) {
        return { ok: false, status: typeof error === 'object' ? error.status : undefined };
      }
    },
    [entity, action, params] as [string, string, Record<string, unknown>],
  );
}

/** A name nothing else on the site has, for records a case creates and removes. */
export function uniqueName(prefix: string): string {
  return `${prefix} ${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;
}

/** Delete payment processors (live and test rows) by their title, for the cases that create their own. */
export function deleteProcessorsTitled(title: string): void {
  const rows = api4<{ id: number }>('PaymentProcessor', 'get', {
    select: ['id'],
    where: [['title', '=', title], ['is_test', 'IN', [true, false]]],
  });
  for (const row of rows) {
    api4('PaymentProcessor', 'delete', { where: [['id', '=', row.id]] });
  }
}
