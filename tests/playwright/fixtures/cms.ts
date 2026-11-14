import type { Page } from '@playwright/test';

/**
 * The CMS the site runs on, and how to reach its pages.
 *
 * The suites are written once and the CMS is chosen at run time with the CMS environment variable. URLs and
 * logins are only ever built here and in global-setup.ts: specs never hardcode a path.
 *
 * Only WordPress has been run so far. The Drupal, Joomla and Standalone entries follow the table in
 * developer/TEST_PLAN.md section 2.5 and are untested until those sites are built.
 */
export type Cms = 'wordpress' | 'drupal' | 'joomla' | 'standalone';

const KNOWN: Cms[] = ['wordpress', 'drupal', 'joomla', 'standalone'];
const requested = (process.env.CMS || 'wordpress').toLowerCase();
if (!KNOWN.includes(requested as Cms)) {
  throw new Error(`CMS must be one of ${KNOWN.join(', ')}, not "${requested}"`);
}

export const CMS = requested as Cms;

/** The cv command for the site. The Joomla development site uses jcv. */
export const CIVI_CV = process.env.CIVI_CV || 'cv';

/** WordPress without pretty permalinks puts CiviCRM behind /?page=CiviCRM&q=<path>. */
const WP_CLEAN_URLS = process.env.WP_CLEAN_URLS !== '0';

export type Query = Record<string, string | number | boolean | undefined>;

function queryString(query: Query = {}): string {
  const pairs = Object.entries(query)
    .filter(([, value]) => value !== undefined)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  return pairs.join('&');
}

function withQuery(path: string, query: Query = {}): string {
  const qs = queryString(query);
  if (!qs) {
    return path;
  }
  return path + (path.includes('?') ? '&' : '?') + qs;
}

/** A CiviCRM page a donor or anonymous visitor uses. Pass the CiviCRM path without a leading slash. */
export function frontUrl(civiPath: string, query: Query = {}): string {
  switch (CMS) {
    case 'wordpress':
      return WP_CLEAN_URLS
        ? withQuery(`/civicrm/${civiPath}/`, query)
        : withQuery('/', { page: 'CiviCRM', q: `civicrm/${civiPath}`, ...query });
    case 'joomla':
      return withQuery('/index.php', { option: 'com_civicrm', task: `civicrm/${civiPath}`, ...query });
    case 'drupal':
    case 'standalone':
      return withQuery(`/civicrm/${civiPath}`, query);
  }
}

/** A CiviCRM back-office page. Pass the CiviCRM path without a leading slash. */
export function adminUrl(civiPath: string, query: Query = {}): string {
  switch (CMS) {
    case 'wordpress':
      return withQuery('/wp-admin/admin.php', { page: 'CiviCRM', q: `civicrm/${civiPath}`, ...query });
    case 'joomla':
      return withQuery('/administrator/index.php', { option: 'com_civicrm', task: `civicrm/${civiPath}`, ...query });
    case 'drupal':
    case 'standalone':
      return withQuery(`/civicrm/${civiPath}`, query);
  }
}

/** Append a query string to a URL that may already have one, as eWAY does with the access code. */
export function appendQuery(url: string, query: Query): string {
  return withQuery(url, query);
}

interface LoginSpec {
  url: string;
  /** Fill in and submit the CMS's login form. */
  submit: (page: Page, username: string, password: string) => Promise<void>;
  /** True while the page is still the login page. */
  stillOnLogin: (page: Page) => boolean;
}

const LOGIN: Record<Cms, LoginSpec> = {
  wordpress: {
    url: '/wp-login.php',
    submit: async (page, username, password) => {
      await page.locator('#user_login').fill(username);
      await page.locator('#user_pass').fill(password);
      await page.locator('#wp-submit').click();
    },
    stillOnLogin: (page) => page.url().includes('wp-login.php'),
  },
  drupal: {
    url: '/user/login',
    submit: async (page, username, password) => {
      await page.locator('#edit-name').fill(username);
      await page.locator('#edit-pass').fill(password);
      await page.locator('#edit-submit').click();
    },
    stillOnLogin: (page) => page.url().includes('/user/login'),
  },
  joomla: {
    url: '/administrator/index.php',
    submit: async (page, username, password) => {
      await page.locator('#mod-login-username').fill(username);
      await page.locator('#mod-login-password').fill(password);
      await page.locator('button[type=submit]').first().click();
    },
    // Joomla keeps the login form on the same URL, so login() looks for the form instead.
    stillOnLogin: () => false,
  },
  standalone: {
    // Selectors to confirm when the Standalone site is built.
    url: '/civicrm/login',
    submit: async (page, username, password) => {
      await page.locator('input[name=username]').fill(username);
      await page.locator('input[name=password]').fill(password);
      await page.locator('button[type=submit], input[type=submit]').first().click();
    },
    stillOnLogin: (page) => page.url().includes('/civicrm/login'),
  },
};

/**
 * Log in through the CMS's own form. Resolves when the browser has left the login page; throws with the page's
 * visible error if it has not.
 */
export async function login(page: Page, username: string, password: string): Promise<void> {
  const spec = LOGIN[CMS];
  await page.goto(spec.url);
  await spec.submit(page, username, password);
  await page.waitForLoadState('load');
  const stuck = CMS === 'joomla' ? (await page.locator('#mod-login-username').count()) > 0 : spec.stillOnLogin(page);
  if (stuck) {
    const message = (await page.locator('#login_error, .alert-danger, .messages--error, .alert-error').first().textContent().catch(() => '')) || 'no error shown';
    throw new Error(`Login as "${username}" failed on ${CMS}: ${message.trim()}`);
  }
}
