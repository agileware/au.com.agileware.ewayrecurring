# Playwright tests

Browser tests for the eWay Recurring extension. The plan, case IDs and status are in
[developer/TEST_PLAN.md](../../developer/TEST_PLAN.md) (sections 2.3 to 2.5 and 5).

What exists so far is the scaffold, six infrastructure checks (`specs/infrastructure/`, cases `PW-INF-01` to
`PW-INF-06`) and the donor journey for the once-off contribution page (`specs/frontend/`, group `PW-F1`): the happy
path and the decline (`contribution-once-off.spec.ts`), required billing details
(`contribution-once-off-validation.spec.ts`), cancelling, going back, double clicks and the donor who never returns
(`contribution-once-off-interruptions.spec.ts`), and the variants: non-ASCII text, test mode, a free option, a phone
screen and the CMS theme (`contribution-once-off-variants.spec.ts`). Recurring set-up on the contribution page
(group `PW-F2`, `contribution-recurring.spec.ts`) and event registration (group `PW-F3`, `event-registration.spec.ts`)
follow, then the donor-facing update billing, verification queue and public endpoint groups (`PW-F4` to `PW-F6`). The
back office has begun (`specs/backend/`): the settings page (`PW-B-settings`, `settings.spec.ts`), the payment
processor admin screens and the extension being switched off and on (`PW-B-processor`, `payment-processor.spec.ts`)
and who may read and change saved cards (`PW-B-permissions`, `token-permissions.spec.ts`). The scheduled jobs
(`PW-B-jobs`, `scheduled-jobs.spec.ts`), what the recurring payments job does and what staff then see
(`PW-B-cron`, `recurring-cron.spec.ts`), the System Status check (`PW-B-status`, `system-status.spec.ts`) and the
Cancel and Update Recurring Contribution forms with a Failed record (`PW-B-recurforms`, `recurring-forms.spec.ts`)
are written too, and so are the Failed Recurring Contributions list and its Reactivate action (`PW-B-failed`,
`failed-recurring.spec.ts`) and back-office saved-card payments (`PW-B-backoffice`, `backoffice-token-payment.spec.ts`).
The real eWAY sandbox cases (`specs/sandbox/`, `PW-X-01` to `PW-X-05`, tagged `@sandbox`) are written too: see
[The real sandbox cases](#the-real-sandbox-cases) below. The security cases (`specs/security/`, `PW-S-01` to `PW-S-05`) attack the extension's entry
points as a visitor or a signed-in persona would: markup payloads (`markup-payloads.spec.ts`), tampering with the return page and
the token pages (`return-page-tampering.spec.ts`), who may open each route and API entity (`route-permissions.spec.ts`), the API
key and password never leaving the server (`secret-exposure.spec.ts`) and a forged card id on the card forms (`tampered-card.spec.ts`).
They use plain HTTP requests where the status and the redirect matter, and the same fake eWAY as the other groups.

## How it works

- **A real site.** The tests drive a WordPress, Drupal, Joomla or Standalone site that has CiviCRM and this
  extension. Choose the CMS with `CMS`. Only WordPress has been run so far.
- **A fake eWAY, in two halves.**
  - Server to eWAY calls: `fixtures/stub-extension/` is a small CiviCRM extension that replaces eWAY's HTTP service
    (through `CRM_eWAYRecurring_Utils::setHttpServiceFactory()`) with one that answers from a queue kept in a CiviCRM
    setting. `fixtures/eway-stub.ts` writes that queue and reads the log of what the extension asked.
  - Browser to eWAY's hosted payment page: the shared payment URL the stub returns points at
    `http://eway-stub.test:4580`. The browser resolves that host to this machine (`--host-resolver-rules` in
    `playwright.config.ts`) and `fixtures/hosted-page-server.ts`, started by `global-setup.ts`, answers with a page
    whose Pay and Cancel buttons send the browser back to the site with the access code appended, as eWAY's page does.
    Set `EWAY_STUB_PORT` if 4580 is taken. (`page.route()` does not work for this: it does not see the redirect from
    the site to another host.)
- **Personas.** `admin`, `finance`, `tokenviewer` and `limited` are logged in once by `fixtures/global-setup.ts`
  and used as pre-authenticated pages (`adminPage`, `financePage`, ...). `anonPage` is a visitor who is not signed in.
  `finance` and `tokenviewer` also have "view all contacts": without it core's contact access control hides every
  saved card from them (a token read returns an empty list and a delete removes nothing, with no error).
- **Problems fail the test.** The shared `test` in `fixtures/base.ts` fails any test that sees an uncaught page error,
  a console error, a failed request to `civicrm/ajax/*` or `civicrm/ewayrecurring/*`, or PHP output on the page. A
  test that expects one lists it: `test.use({ allowedProblems: [/pattern/] })`.
- **cv from the tests.** `fixtures/civi.ts` runs `cv` against the site so a spec can read and change CiviCRM data.

Import `test` and `expect` from `fixtures/base`, never from `@playwright/test`. Start each test title with its plan
ID, for example `test('PW-F1-05 approved payment completes the contribution', ...)`.

## Setting up a site

The setup scripts run inside the site, as the web user, from the extension's folder. They are safe to run again.

| CMS | Script | State |
| --- | --- | --- |
| WordPress | `fixtures/setup-environment.sh` | Works: creates a role and user per persona, then runs `setup-common.sh` |
| Drupal, Joomla, Standalone | `setup-accounts-<cms>.sh` (planned) | Not written yet. `setup-common.sh` is shared and CMS independent |

`setup-common.sh` installs the stub extension into the site's extensions folder and runs `seed-data.php`
(eWAY and non-eWAY processors, a donor with a saved card, four contribution pages (plain once-off, accented title,
free option, recurring), a paid event that takes up to two more people and a free event, outbound mail redirected to
the database).
The seed grows as specs are written.

On the shared GitHub workflow the extension under test is bind mounted into the extensions folder, so Docker creates
that folder as root and the web user cannot add a second extension beside it. `setup-common.sh` notices that (or
`PLAYWRIGHT_FORCE_EXT_FALLBACK=1`) and, using `fixtures/extension-dir.php`, points CiviCRM's `extensionsDir` and
`extensionsURL` at a writable sibling, `ext-playwright`, holding links to the extensions already there plus the stub.
The two settings can be absolute values or `[civicrm.files]/ext` placeholders; either way they must end in `/ext`.
A change to the shared workflow (a branch of `ci-workflows`, not yet merged) would hand that folder to the web user, so the fallback would not be needed on GitHub; it stays for other hosts.

On GitHub the WordPress site is built by the shared `playwright-tests` workflow in `agileware/ci-workflows`, which
calls `setup-environment.sh` (see `.github/workflows/frontend-tests.yml`).

## Running the tests

```bash
cd tests/playwright
npm ci
npx playwright install chromium
BASE_URL=https://my-site.test \
WP_ADMIN_USER=admin WP_ADMIN_PASS=... \
CIVI_EXEC_PREFIX="docker exec -u www-data -w /path/to/extension my-wordpress-container" \
npx playwright test
```

| Variable | Meaning |
| --- | --- |
| `BASE_URL` | The site's address. Default `http://localhost:8080` |
| `CMS` | `wordpress` (default), `drupal`, `joomla` or `standalone` |
| `WP_ADMIN_USER`, `WP_ADMIN_PASS` | The administrator persona's login, on every CMS. Default `admin` / `admin` |
| `CIVI_CV` | The cv command. Default `cv` (the Joomla development site uses `jcv`) |
| `CIVI_EXEC_PREFIX` | The command that runs something where CiviCRM is, such as `docker exec ... <container>`. Split on spaces, so no quoted arguments. Empty when cv runs on this machine |
| `CIVI_EXEC_CWD` | The folder to run the prefix from (for tools that need to be started from a project folder) |
| `IGNORE_PROBLEMS` | Regular expressions, separated by two bars, for problems to ignore on a local site that has other extensions making noise (for example a PHP warning from another extension). Leave it unset on CI |
| `EWAY_STUB_PORT` | The port the fake hosted page is served on. Default 4580 |
| `EWAY_SANDBOX_API_KEY`, `EWAY_SANDBOX_API_PASSWORD` | The API key and password of an eWAY sandbox account, for the real-sandbox cases only (see below). When either is missing those cases skip. Never put values in a file in the repository |
| `PERSONAS` | Comma separated personas to sign in, for a site where not all of them exist (default all four) |
| `WP_CLEAN_URLS` | `0` for a WordPress site without pretty permalinks |

If your prefix needs quoting (`ddev exec` joins its arguments back into one string), point `CIVI_EXEC_PREFIX` at a
small wrapper script that quotes each argument. For DDEV, `ddev-exec.sh` in this folder is that script (see its
header for `DDEV_PROJECT_DIR`). `developer/LOCAL_DEV.md` has the whole safe routine for a development machine:
snapshot, deploy, run, restore.

## The real sandbox cases

`specs/sandbox/` holds `PW-X-01` to `PW-X-05`. Unlike every other spec they do not use the fake eWAY: the PHP code calls
eWAY's real sandbox API (`https://api.sandbox.ewaypayments.com/`, the endpoint the extension picks for a test-mode
processor) and the donor or staff member pays on eWAY's own sandbox hosted page, with eWAY's published test card
`4444333322221111`. They check the stub against reality, so they catch drift in eWAY's responses. The site must be able to
reach the internet.

How the stub is turned off for them: each spec's `beforeAll` (`prepareSandboxSite()` in `fixtures/sandbox.ts`) creates a
processor named "Playwright eWAY Sandbox" (a live record with dummy credentials, and a test record) and two contribution
pages that use it, then saves the sandbox credentials on the **test** record. The stub extension makes one exception: the
test-mode record of that processor gets the real HTTP service. Everything else, and that processor in live mode, stays
stubbed, so nothing can reach eWAY's live gateway. The cases use the contribution pages' test-drive mode
(`action=preview`) and the back-office credit card form in test mode (`mode=test`), which are the ways CiviCRM uses a
processor's test record. `afterAll` overwrites the saved credentials, and a database snapshot restore removes the processor.

Run them (the variables only for this command, never written down; from this folder, after the usual site set-up):

```bash
set -a; . /path/to/eway-sandbox.env; set +a      # sets EWAY_SANDBOX_API_KEY and EWAY_SANDBOX_API_PASSWORD
npx playwright test --project=sandbox            # or: npx playwright test --grep @sandbox
```

Without the two variables every sandbox case skips with a message that names them, so a normal run and a CI run without
the secrets stay green. A plain `npx playwright test` runs the sandbox project too (it skips). The credentials are
sent to the site in one call that saves them on the processor, and that call never shows its command in an error. Do not
take a screenshot or trace of a processor's admin form, and no sandbox spec opens one.

eWAY's sandbox declines by the cents of the amount when the sandbox account is on "Use Cents Value" (Settings > Sandbox >
Customise Sandbox Behaviour): $10.00 is approved (A2000), $10.05 is declined with D4405 (Do Not Honour), $10.51 with D4451
(Insufficient Funds). Source: eWAY Rapid API reference, "Sandbox Testing". An account set to "Always Approve" approves
every amount; the decline cases then skip with a message saying so (the account used so far does this).

`npm run typecheck` checks the TypeScript without a site. `npx playwright test --list` lists the tests.

## Layout

```
fixtures/
  cms.ts                  CMS, frontUrl(), adminUrl(), appendQuery(), login()
  contribution-page.ts    the seeded once-off pages: find them, fill them in, move through the steps, read the results,
                          run the verification job
  event-registration.ts   the seeded events: find them, fill in the registration steps, read participants and the
                          contribution that pays for one
  scheduled-jobs.ts      the Scheduled Jobs screen and job log: find a job's row, run it from the screen, read its log
  recurring-screens.ts   the contact's Contributions, Recurring Contributions and Activities tabs, the recurring record's
                          own page and forms, the Failed Recurring Contributions list
  backend.ts              back-office helpers: the settings page (open, submit, the select2 box, reset and read the
                          settings it owns), CiviCRM's menus (Administer, Contributions), and CRM.api4() called from a signed-in page
  sandbox.ts              the real-sandbox cases: reads the credentials, makes the sandbox processor and pages, drives eWAY's
                          sandbox hosted page, the sandbox test card and amounts
  security.ts             the security specs: plain HTTP requests as an attacker makes them (get, post, the answer's status and body), the
                          return address and eWAY's answers for an invoice, the seeded processors, a fresh contact, and the billing form fields
  visibility.ts           checks that a message really shows inside the theme (size, position, contrast, not covered)
  civi.ts                 cv api4 / ev / scr wrappers
  eway-stub.ts            scripted responses, the request log, the fake hosted page's HTML, response builders
  hosted-page-server.ts   serves the fake hosted page for the run
  global-setup.ts         signs each persona in once
  base.ts                 test with personas, stub and the problem collector
  test-users.json         personas (single source of truth)
  test-data.json          names of the seeded records
  setup-environment.sh    WordPress accounts, then setup-common.sh
  setup-common.sh         stub extension and seed, every CMS
  extension-dir.php       works out the writable extensions folder used when the usual one is root-owned
  seed-data.php           idempotent seed, run with cv scr
  stub-extension/         test-support CiviCRM extension (never install it on a real site)
ddev-exec.sh             runs a command inside a DDEV site, for CIVI_EXEC_PREFIX
specs/
  infrastructure/         checks of the above
  frontend/               PW-F1 (contribution-once-off*.spec.ts), PW-F2 (contribution-recurring.spec.ts), PW-F3 (event-registration.spec.ts)
  backend/                PW-B-settings (settings.spec.ts), PW-B-processor (payment-processor.spec.ts), PW-B-permissions (token-permissions.spec.ts),
                          PW-B-jobs (scheduled-jobs.spec.ts), PW-B-cron (recurring-cron.spec.ts), PW-B-status (system-status.spec.ts),
                          PW-B-recurforms (recurring-forms.spec.ts), PW-B-failed (failed-recurring.spec.ts),
                          PW-B-backoffice (backoffice-token-payment.spec.ts)
  sandbox/                PW-X (sandbox-once-off, sandbox-recurring, sandbox-card, sandbox-settlement .spec.ts), the real eWAY
                          sandbox, skipped unless the two credential variables are set
  security/               PW-S: markup-payloads, return-page-tampering, route-permissions, secret-exposure, tampered-card (.spec.ts)
```
