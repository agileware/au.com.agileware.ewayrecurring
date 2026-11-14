# Playwright tests

Browser tests for the eWay Recurring extension. The plan, case IDs and status are in
[developer/TEST_PLAN.md](../../developer/TEST_PLAN.md) (sections 2.3 to 2.5 and 5).

What exists so far is the scaffold and six infrastructure checks (`specs/infrastructure/`, cases `PW-INF-01` to
`PW-INF-06`). They prove the test site, the personas, the fake eWAY and the helpers work, so the journeys that
follow can build on them. The journeys themselves (`specs/frontend/`, `specs/backend/`, `specs/security/`,
`specs/sandbox/`) are still to be written.

## How it works

- **A real site.** The tests drive a WordPress, Drupal, Joomla or Standalone site that has CiviCRM and this
  extension. Choose the CMS with `CMS`. Only WordPress has been run so far.
- **A fake eWAY, in two halves.**
  - Server to eWAY calls: `fixtures/stub-extension/` is a small CiviCRM extension that replaces eWAY's HTTP service
    (through `CRM_eWAYRecurring_Utils::setHttpServiceFactory()`) with one that answers from a queue kept in a CiviCRM
    setting. `fixtures/eway-stub.ts` writes that queue and reads the log of what the extension asked.
  - Browser to eWAY's hosted payment page: the shared payment URL the stub returns points at `eway-stub.test`.
    Playwright answers that host itself (`stub.serveHostedPage(page)`) with a page whose Pay and Cancel buttons send
    the browser back to the site with the access code appended, as eWAY's page does.
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
(eWAY and non-eWAY processors, a donor with a saved card, outbound mail redirected to the database).
The seed grows as specs are written.

On the shared GitHub workflow the extension under test is bind mounted into the extensions folder, so Docker creates
that folder as root and the web user cannot add a second extension beside it. `setup-common.sh` notices that (or
`PLAYWRIGHT_FORCE_EXT_FALLBACK=1`) and points CiviCRM's `extensionsDir` and `extensionsURL` at a writable sibling,
`[civicrm.files]/ext-playwright`, holding links to the extensions already there plus the stub.

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
| `PERSONAS` | Comma separated personas to sign in, for a site where not all of them exist (default all four) |
| `WP_CLEAN_URLS` | `0` for a WordPress site without pretty permalinks |

If your prefix needs quoting (`ddev exec` joins its arguments back into one string), point `CIVI_EXEC_PREFIX` at a
small wrapper script that quotes each argument.

`npm run typecheck` checks the TypeScript without a site. `npx playwright test --list` lists the tests.

## Layout

```
fixtures/
  cms.ts                  CMS, frontUrl(), adminUrl(), appendQuery(), login()
  civi.ts                 cv api4 / ev / scr wrappers
  eway-stub.ts            scripted responses, the request log, the fake hosted page, response builders
  global-setup.ts         signs each persona in once
  base.ts                 test with personas, stub and the problem collector
  test-users.json         personas (single source of truth)
  test-data.json          names of the seeded records
  setup-environment.sh    WordPress accounts, then setup-common.sh
  setup-common.sh         stub extension and seed, every CMS
  seed-data.php           idempotent seed, run with cv scr
  stub-extension/         test-support CiviCRM extension (never install it on a real site)
specs/
  infrastructure/         checks of the above
  frontend/ backend/ security/ sandbox/    still to be written
```
