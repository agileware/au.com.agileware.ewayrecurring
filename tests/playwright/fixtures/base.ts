import { test as base, expect, type BrowserContext, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';
import { statePath } from './global-setup';
import { EwayStub } from './eway-stub';
import { CMS, type Cms } from './cms';

/**
 * The test every spec imports, in place of Playwright's own.
 *
 * It adds:
 *  - the personas as pages that are already signed in (adminPage, financePage, tokenviewerPage, limitedPage) and
 *    anonPage for a visitor who is not;
 *  - the eWAY stub (stub), emptied at the start of each test;
 *  - a collector that fails every test on uncaught page errors, console errors, failed requests to
 *    civicrm/ajax/* and civicrm/ewayrecurring/*, and PHP output on the page (developer/TEST_PLAN.md section 2.4).
 *    A test that expects one of these lists it in allowedProblems with test.use({ allowedProblems: [/.../] }).
 */

/** Text that means PHP or Smarty printed something it should not have. */
const PHP_OUTPUT = /(Warning|Notice|Deprecated|Fatal error|Parse error):\s|Undefined (array key|variable|index|offset)|Smarty(Exception| error)|Stack trace:/;

/**
 * Known noise that is not the extension's: Chrome complaining about an unload handler somewhere in CiviCRM or the
 * CMS admin. Add to this list only for something outside this extension.
 */
const IGNORED: RegExp[] = [/Permissions policy violation: unload is not allowed/];

/**
 * Extra problems to ignore on a site that has other extensions or plugins making noise, from the environment
 * variable IGNORE_PROBLEMS: regular expressions separated by two bars, for example
 *   IGNORE_PROBLEMS='scheduledccrecipients||some-other-extension'
 * For local sites only. The CI site has nothing but this extension, so it ignores nothing extra.
 */
for (const pattern of (process.env.IGNORE_PROBLEMS || '').split('||').map((p) => p.trim()).filter(Boolean)) {
  IGNORED.push(new RegExp(pattern));
}

/** Requests whose failure is a problem. */
const WATCHED_REQUESTS = /\/civicrm\/(ajax|ewayrecurring)\/|civicrm%2F(ajax|ewayrecurring)%2F|q=civicrm\/(ajax|ewayrecurring)\//;

export class ProblemCollector {
  private readonly found: string[] = [];

  constructor(private readonly allowed: RegExp[]) {}

  /**
   * Note a problem unless an ignore pattern matches it. The patterns are tried against `detail`, which can be
   * longer than the `problem` text that is reported, so a file name late in a PHP message still matches.
   */
  private record(problem: string, detail: string = problem): void {
    if (![...IGNORED, ...this.allowed].some((pattern) => pattern.test(detail))) {
      this.found.push(problem);
    }
  }

  /** Start listening to a page. */
  watch(page: Page): void {
    page.on('pageerror', (error) => this.record(`page error: ${error.message}`));
    page.on('console', (message) => {
      if (message.type() === 'error') {
        this.record(`console error: ${message.text()}`);
      }
    });
    page.on('response', (response) => {
      if (response.status() >= 400 && WATCHED_REQUESTS.test(response.url())) {
        this.record(`HTTP ${response.status()} from ${response.url()}`);
      }
    });
    page.on('requestfailed', (request) => {
      if (WATCHED_REQUESTS.test(request.url())) {
        this.record(`request failed: ${request.url()} (${request.failure()?.errorText})`);
      }
    });
  }

  /** Look at what is on the page now for PHP output. Call before the page closes. */
  async checkPage(page: Page): Promise<void> {
    if (page.isClosed()) {
      return;
    }
    const text = await page.locator('body').innerText({ timeout: 2000 }).catch(() => '');
    const match = PHP_OUTPUT.exec(text);
    if (match) {
      const context = (length: number) => text.slice(Math.max(0, match.index - 40), match.index + length).replace(/\s+/g, ' ');
      this.record(`PHP output on ${page.url()}: ${context(120)}`, context(400));
    }
  }

  assertNone(): void {
    expect(this.found, `Problems seen while the test ran:\n${this.found.map((problem) => `- ${problem}`).join('\n')}`).toEqual([]);
  }
}

type Persona = 'admin' | 'finance' | 'tokenviewer' | 'limited';

type Fixtures = {
  problems: ProblemCollector;
  stub: EwayStub;
  adminPage: Page;
  financePage: Page;
  tokenviewerPage: Page;
  limitedPage: Page;
  anonPage: Page;
};

type Options = {
  /** Problems this test expects, matched against the text the collector would report. */
  allowedProblems: RegExp[];
};

async function personaPage(
  browser: import('@playwright/test').Browser,
  problems: ProblemCollector,
  persona: Persona | null,
  use: (page: Page) => Promise<void>,
  baseURL: string | undefined,
): Promise<void> {
  let context: BrowserContext;
  if (persona === null) {
    context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
  } else {
    const path = statePath(persona);
    if (!existsSync(path)) {
      throw new Error(`No saved session for "${persona}". It is created by global-setup; check PERSONAS and the setup script for ${CMS}.`);
    }
    context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, storageState: path });
  }
  const page = await context.newPage();
  problems.watch(page);
  await use(page);
  await problems.checkPage(page);
  await context.close();
}

export const test = base.extend<Fixtures & Options>({
  allowedProblems: [[], { option: true }],

  problems: async ({ allowedProblems }, use) => {
    const collector = new ProblemCollector(allowedProblems);
    await use(collector);
    collector.assertNone();
  },

  // The default page is watched too.
  page: async ({ page, problems }, use) => {
    problems.watch(page);
    await use(page);
    await problems.checkPage(page);
  },

  stub: async ({}, use) => {
    const stub = new EwayStub();
    stub.reset();
    await use(stub);
  },

  adminPage: async ({ browser, problems, baseURL }, use) => personaPage(browser, problems, 'admin', use, baseURL),
  financePage: async ({ browser, problems, baseURL }, use) => personaPage(browser, problems, 'finance', use, baseURL),
  tokenviewerPage: async ({ browser, problems, baseURL }, use) => personaPage(browser, problems, 'tokenviewer', use, baseURL),
  limitedPage: async ({ browser, problems, baseURL }, use) => personaPage(browser, problems, 'limited', use, baseURL),
  anonPage: async ({ browser, problems, baseURL }, use) => personaPage(browser, problems, null, use, baseURL),
});

export { expect };

/**
 * Skip the current test on the listed CMSs. Give the reason so the report says why:
 *   skipOnCms(test, ['joomla'], 'no access levels scripted yet');
 */
export function skipOnCms(testRef: typeof test, cmsList: Cms[], reason: string): void {
  testRef.skip(cmsList.includes(CMS), `${reason} (${CMS})`);
}
