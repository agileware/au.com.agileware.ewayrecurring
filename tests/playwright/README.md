# Playwright tests

Browser tests for the eWay Recurring extension. The plan, case IDs and status are in
[developer/TEST_PLAN.md](../../developer/TEST_PLAN.md) (sections 2.3 to 2.5 and 5).

What exists so far is the scaffold, six infrastructure checks (`specs/infrastructure/`, cases `PW-INF-01` to
`PW-INF-06`) and the donor journey for the once-off contribution page (`specs/frontend/`, group `PW-F1`): the happy
path and the decline (`contribution-once-off.spec.ts`), required billing details
(`contribution-once-off-validation.spec.ts`), cancelling, going back, double clicks and the donor who never returns
(`contribution-once-off-interruptions.spec.ts`), and the variants: non-ASCII text, test mode, a free option, a phone
screen and the CMS theme (`contribution-once-off-variants.spec.ts`). The rest of the journeys (`specs/frontend/`,
`specs/backend/`, `specs/security/`, `specs/sandbox/`) are still to be written.

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
(eWAY and non-eWAY processors, a donor with a saved card, three once-off contribution pages (plain, accented title,
free option), outbound mail redirected to the database).
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
| `PERSONAS` | Comma separated personas to sign in, for a site where not all of them exist (default all four) |
| `WP_CLEAN_URLS` | `0` for a WordPress site without pretty permalinks |

If your prefix needs quoting (`ddev exec` joins its arguments back into one string), point `CIVI_EXEC_PREFIX` at a
small wrapper script that quotes each argument. For DDEV, `ddev-exec.sh` in this folder is that script (see its
header for `DDEV_PROJECT_DIR`). `developer/LOCAL_DEV.md` has the whole safe routine for a development machine:
snapshot, deploy, run, restore.

`npm run typecheck` checks the TypeScript without a site. `npx playwright test --list` lists the tests.

## Layout

```
fixtures/
  cms.ts                  CMS, frontUrl(), adminUrl(), appendQuery(), login()
  contribution-page.ts    the seeded once-off pages: find them, fill them in, move through the steps, read the results,
                          run the verification job
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
  frontend/               PW-F1 so far (contribution-once-off*.spec.ts)
  backend/ security/ sandbox/    still to be written
```
