import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the eWay Recurring extension.
 *
 * The site under test and the CMS are chosen with environment variables, see README.md:
 *   BASE_URL, CMS, CIVI_CV, CIVI_EXEC_PREFIX, WP_ADMIN_USER, WP_ADMIN_PASS.
 *
 * Test plan: developer/TEST_PLAN.md section 2.3.
 */
const CI = !!process.env.CI;

export default defineConfig({
  testDir: './specs',
  globalSetup: require.resolve('./fixtures/global-setup'),
  timeout: CI ? 60_000 : 45_000,
  expect: { timeout: 10_000 },
  // One worker, always: the tests share one site and one scripted eWAY stub (its queue is a CiviCRM setting), so
  // two tests running at once would take each other's scripted answers.
  workers: 1,
  fullyParallel: false,
  retries: CI ? 1 : 0,
  reporter: CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    // Local DDEV and Docker sites use self-signed certificates.
    ignoreHTTPSErrors: true,
    launchOptions: { args: ['--host-resolver-rules=MAP eway-stub.test 127.0.0.1'] },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      testIgnore: /specs\/sandbox\//,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // The real eWAY sandbox (PW-X, specs/sandbox/). Needs EWAY_SANDBOX_API_KEY and EWAY_SANDBOX_API_PASSWORD in the
      // environment; without them every case skips. Meant to run nightly rather than on every push, and can be
      // selected with --project=sandbox or --grep @sandbox.
      name: 'sandbox',
      testMatch: /specs\/sandbox\/.*\.spec\.ts/,
      // eWAY's sandbox is slow to answer, so give every wait on it longer than the 10 seconds of the other specs.
      expect: { timeout: 30_000 },
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
