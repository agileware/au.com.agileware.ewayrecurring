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
  // One worker in CI: the tests share one site and one scripted eWAY stub.
  workers: CI ? 1 : undefined,
  fullyParallel: false,
  retries: CI ? 1 : 0,
  reporter: CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    // Local DDEV and Docker sites use self-signed certificates.
    ignoreHTTPSErrors: true,
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
      // The real eWAY sandbox hosted page. Needs EWAY_SANDBOX_KEY and EWAY_SANDBOX_PASSWORD, and runs
      // nightly rather than on every push.
      name: 'sandbox',
      testMatch: /specs\/sandbox\/.*\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
