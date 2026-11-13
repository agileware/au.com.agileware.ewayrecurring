# Test plan and coverage status: eWay Recurring Payment Processor

| | |
| --- | --- |
| Extension version | 2.8.2 |
| Status last updated | 2026-11-14 |
| Owner | Extension maintainers (update this file in the same pull request as any test change) |
| Related | [TESTING.md](TESTING.md) (how to run tests), [LOCAL_DEV.md](LOCAL_DEV.md) (local environment) |

## 0. About this document

This is both the **plan** for automated test coverage of `au.com.agileware.ewayrecurring` and
the **record** of what is covered today. Every planned test case has a stable ID and a status, so
you can answer "is this behaviour tested?" by searching this file, and "what should I test
next?" by filtering on `todo`.

Two layers are covered:

1. **PHPUnit (headless)**: fast tests of PHP logic, with the eWAY gateway faked. IDs start `PU-`.
2. **Playwright (front end)**: browser tests against a throwaway WordPress + CiviCRM site,
   following the pattern used in
   [au.com.agileware.eventmanagelocations](https://github.com/agileware/au.com.agileware.eventmanagelocations)
   and [au.com.agileware.elections](https://github.com/agileware/au.com.agileware.elections).
   IDs start `PW-`.

### Reading the status

| Status | Meaning |
| --- | --- |
| `done` | A test exists, is tagged with the ID (see below), and passes in CI |
| `wip` | Being written, in an open pull request |
| `todo` | Planned, nothing blocking it |
| `blocked` | Cannot be written until a prerequisite lands (usually seam S1 in section 2.1, or the page refactor noted in PU-K) |
| `n/a` | Dropped. The Case column must say why, and the ID is never reused |

### Maintaining this file

1. **IDs are permanent.** Never renumber or reuse one. To drop a case set it to `n/a` and say why.
   To add a case, append the next number in its group.
2. **Tag the test with its ID** so the link is searchable.
   PHPUnit: a docblock line `@testPlan PU-F8` on the test method.
   Playwright: start the test title with the ID, for example `test('PW-F1-05 approved payment completes the contribution', ...)`.
   Upgrade tests (`UP-`): a comment `# testPlan UP-03` beside the check in the upgrade scripts under `tests/upgrade/`.
3. **Change the status in the same pull request** that adds, changes or removes the test.
   For Playwright cases, `done` means passing on every CMS in the case's CMS column
   (`all` = WordPress, Drupal, Joomla and Standalone; `wp-only` = WordPress, used for the real-sandbox cases). Use `wip`
   while only some CMSs pass, and record per-CMS progress in the CMS table in section 0.1.
4. **Refresh the counts** in section 0.1 with the command below, and add a line to the change log
   (section 8).
5. **Characterisation tests** (those marked "characterises DEF-n") pin down what the code
   does today. If the maintainers decide the behaviour is a bug and fix it, update the test, set the
   defect to `Fixed` in section 3, and note it in the change log.
6. New behaviour or a new bug fix should get a new case here, not just a test, so this file stays
   the complete list.

Handy commands (run from the extension root):

```bash
# Count cases per suite and status
grep -E '^\| (PU|PW|UP)-[A-Za-z0-9-]+ \| (todo|wip|blocked|done|n/a) \|' developer/TEST_PLAN.md \
  | awk -F'|' '{id=$2; gsub(/ /,"",id); st=$3; gsub(/ /,"",st); \
      s=(id ~ /^PU/) ? substr(id,1,4) : id; sub(/-[0-9]+$/,"",s); print s, st}' | sort | uniq -c

# Cases marked done that no test mentions (should print nothing)
for id in $(grep -oE '^\| (PU|PW|UP)-[A-Za-z0-9-]+ \| done' developer/TEST_PLAN.md | awk '{print $2}'); do
  grep -rqF "$id" tests/ || echo "not tagged: $id"; done
```

### 0.1 Status summary (as of the date above)

Cases defined: **244**. Done: **0**. In progress: **48**. Blocked: **9**. To do: **186**. Dropped (`n/a`): **1** (UP-07).

| Suite | Layer | Home | Cases | Done | WIP | Blocked | Todo | N/A | Needs |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| PU-A | PHPUnit | `ProcessorBasicsTest` | 10 | 0 | 0 | 0 | 10 | 0 | none |
| PU-B | PHPUnit | `DoPaymentTest` | 17 | 0 | 0 | 0 | 17 | 0 | none (S1 done) |
| PU-C | PHPUnit | `ReturnUrlsTest` | 3 | 0 | 0 | 0 | 3 | 0 | none |
| PU-D | PHPUnit | `ValidateContributionTest` | 12 | 0 | 12 | 0 | 0 | 0 | none (S1 done) |
| PU-E | PHPUnit | `PendingTransactionsTest` | 12 | 0 | 12 | 0 | 0 | 0 | none (S1 done) |
| PU-F | PHPUnit | `RecurringProcessingTest` | 24 | 0 | 24 | 0 | 0 | 0 | none (S1 done) |
| PU-G | PHPUnit | `PaymentTokenTest` | 10 | 0 | 0 | 3 | 7 | 0 | none (S1 done); PU-G8 to G10 need the `die()` refactor |
| PU-H | PHPUnit | `HooksAndManagedTest` | 12 | 0 | 0 | 0 | 12 | 0 | none |
| PU-I | PHPUnit | `PermissionsTest` | 4 | 0 | 0 | 0 | 4 | 0 | none |
| PU-J | PHPUnit | `SettingsFormTest` | 7 | 0 | 0 | 0 | 7 | 0 | none |
| PU-K | PHPUnit | `PagesTest` | 6 | 0 | 0 | 6 | 0 | 0 | page refactor |
| PU-L | PHPUnit | `SchemaAndUpgradeTest` | 5 | 0 | 0 | 0 | 5 | 0 | none |
| PU-M | PHPUnit | `SettlementSync gaps` | 4 | 0 | 0 | 0 | 4 | 0 | none |
| PU-N | PHPUnit | `SubscriptionManagementTest` | 4 | 0 | 0 | 0 | 4 | 0 | none |
| PU-O | PHPUnit | `FailedRecurringSearchTest` | 5 | 0 | 0 | 0 | 5 | 0 | none |
| UP | Upgrade (CI) | `tests/upgrade/` | 9 | 0 | 0 | 0 | 8 | 1 | none (shared upgrade workflow exists); UP-07 dropped |
| PW-F1 | Playwright | `specs/frontend/` | 13 | 0 | 0 | 0 | 13 | 0 | Phase 0 infra, S1 stub |
| PW-F2 | Playwright | `specs/frontend/` | 6 | 0 | 0 | 0 | 6 | 0 | Phase 0 infra, S1 stub |
| PW-F3 | Playwright | `specs/frontend/` | 6 | 0 | 0 | 0 | 6 | 0 | Phase 0 infra, S1 stub |
| PW-F4 | Playwright | `specs/frontend/` | 5 | 0 | 0 | 0 | 5 | 0 | Phase 0 infra, S1 stub |
| PW-F5 | Playwright | `specs/frontend/` | 4 | 0 | 0 | 0 | 4 | 0 | Phase 0 infra, S1 stub |
| PW-F6 | Playwright | `specs/frontend/` | 7 | 0 | 0 | 0 | 7 | 0 | Phase 0 infra, S1 stub |
| PW-B-settings | Playwright | `specs/backend/` | 8 | 0 | 0 | 0 | 8 | 0 | Phase 0 infra |
| PW-B-processor | Playwright | `specs/backend/` | 4 | 0 | 0 | 0 | 4 | 0 | Phase 0 infra |
| PW-B-jobs | Playwright | `specs/backend/` | 5 | 0 | 0 | 0 | 5 | 0 | Phase 0 infra, S1 stub |
| PW-B-cron | Playwright | `specs/backend/` | 5 | 0 | 0 | 0 | 5 | 0 | Phase 0 infra, S1 stub |
| PW-B-failed | Playwright | `specs/backend/` | 10 | 0 | 0 | 0 | 10 | 0 | Phase 0 infra |
| PW-B-status | Playwright | `specs/backend/` | 2 | 0 | 0 | 0 | 2 | 0 | Phase 0 infra |
| PW-B-recurforms | Playwright | `specs/backend/` | 4 | 0 | 0 | 0 | 4 | 0 | Phase 0 infra |
| PW-B-backoffice | Playwright | `specs/backend/` | 10 | 0 | 0 | 0 | 10 | 0 | Phase 0 infra, S1 stub |
| PW-B-permissions | Playwright | `specs/backend/` | 3 | 0 | 0 | 0 | 3 | 0 | Phase 0 infra |
| PW-S | Playwright | `specs/security/` | 4 | 0 | 0 | 0 | 4 | 0 | Phase 0 infra |
| PW-X | Playwright | `specs/sandbox/` | 4 | 0 | 0 | 0 | 4 | 0 | Phase 0 infra, S1 stub |

#### Playwright status by CMS

A Playwright case is `done` only when it passes on **every CMS listed in its CMS column**.
This table shows progress per CMS while that is not yet true. Cell values: `none`, `partial`,
`full`, or `n/a` (cannot run there). Update by hand from the latest CI or local run, and put the
run date in the change log.

| Playwright group | WordPress | Drupal | Joomla | Standalone |
| --- | --- | --- | --- | --- |
| Infrastructure (`cms.ts`, setup scripts, stub, smoke spec) | none | none | none | none |
| Frontend (PW-F1 to PW-F6) | none | none | none | none |
| Backend (PW-B-*) | none | none | none | none |
| Security (PW-S) | none | none | none | none |
| Sandbox (PW-X) | none | n/a | n/a | n/a |

Tests that already existed before this plan (not part of the case counts above):

| Test class | Tests | Status | Covers |
| --- | --- | --- | --- |
| `SettlementSyncTest.php` (headless) | 38 | Passing in CI | FEAT-8: processor selection and allowlist, look-back window, candidate selection and de-duplication, fee/net reconciliation and rounding, settlement report paging and error handling, sync orchestration and scoping. Gaps are PU-M. |
| `E2ETest.php` (`@group e2e`, excluded from the default run) | 2 | Not run in CI | Event registration and contribution page against the **real eWAY sandbox**. Hardcodes site IDs and sandbox credentials. To be replaced by PW-F1 and PW-F3, then deleted (see Phase 4). |

---

## 1. Where we are today

| Area | State | Notes |
| --- | --- | --- |
| Settlement Sync (`SettlementSync.php`, `EwaySettlement.Sync`) | Covered | See the table above. |
| Everything else (payment processor class, recurring cron, verification queue, tokens, hooks, settings form, pages, Search Kit action, JS, permissions, upgrader) | **No automated tests** | This is where all the money movement and customer-facing behaviour lives. |
| Front end | One legacy end-to-end class, not run in CI | Drives the real sandbox, parses HTML with regexes, embeds credentials. Replaced by the Playwright suite. |
| CI | PHPUnit only | `phpunit-tests.yml` calls the shared `civicrm-phpunit-tests.yml`. No front-end or upgrade workflow yet. The shared `playwright-tests.yml` (WordPress only) and `civicrm-extension-upgrade-tests.yml` already exist in `agileware/ci-workflows`. |
| Coverage config | Too broad | `phpunit.xml.dist` includes `./`, which pulls `vendor/`, `mixin/`, `tests/` and `*.civix.php` into coverage numbers. Restrict to `CRM/`, `api/`, `eWAYRecurring.php`. |

### Feature inventory and coverage

"Coverage now" is the current state. Update it when the linked suites reach `done`.

| # | Feature (from [user docs](../docs/README.md)) | Main code | Headless suites | Playwright suites | Coverage now |
| --- | --- | --- | --- | --- | --- |
| FEAT-1 | Once-off payment via Responsive Shared Page | `CRM_Core_Payment_eWAYRecurring::doPayment`, `VerifyPayment`, `Utils::validateContribution` | PU-B, PU-C, PU-D | PW-F1 | None |
| FEAT-2 | Recurring set-up (token saved, schedule started) | `Utils::updateRecurringContribution`, `updateCustomerDetails` | PU-D | PW-F2 | None |
| FEAT-3 | Recurring processing by cron, with retry of failures | `ProcessTrait::process_recurring_payments` and helpers | PU-F | PW-B-cron | None |
| FEAT-4 | Saved-card ("stored credit card") back-office payments | `doPayment` back-office branch, `js/eway.js`, `PaymentToken::createToken/saveToken` | PU-B, PU-G | PW-B-backoffice | None |
| FEAT-5 | Update billing details for a recurring contribution | `updateSubscriptionBillingInfo`, `VerifyUpdateToken` | PU-N, PU-K | PW-F4 | None |
| FEAT-6 | Pending transaction verification queue and job | `EwayContributionTransactions` API, `Utils::validatePendingTransactions` | PU-E | PW-F5 | None |
| FEAT-7 | Token metadata backfill | `PaymentToken::fillTokensMeta`, `EwayRecurring.fillTokensMeta` | PU-G | PW-B-jobs | None |
| FEAT-8 | Settlement Sync | `SettlementSync`, `EwaySettlement.Sync` | existing `SettlementSyncTest`, PU-M | PW-B-settings | Headless: good. Front end: none |
| FEAT-9 | Settings form | `Form/Settings.php`, `settings/eWAYRecurring.setting.php` | PU-J | PW-B-settings | Partial (settings round trip only, via Settlement Sync tests) |
| FEAT-10 | Failed Recurring Contributions listing and Reactivate action | `ang/`, `managed/`, `searchKitTasks` hook | PU-O | PW-B-failed | None |
| FEAT-11 | System status check for stuck failures | `ewayrecurring_civicrm_check` | PU-H | PW-B-status | None |
| FEAT-12 | Cancel / Update Subscription form behaviour | `buildForm` hook, `getEditableRecurringScheduleFields`, `eway.js` | PU-H, PU-N | PW-B-recurforms | None |
| FEAT-13 | Permissions (`view/edit payment tokens`) | `APIWrapper::authorize` | PU-I | PW-B-permissions | None |
| FEAT-14 | Managed entities, install, enable, upgrade | `ewayrecurring_civicrm_managed`, `Upgrader`, civix schema | PU-H, PU-L, UP | PW-B-jobs, PW-B-processor | None |
| FEAT-15 | Payment processor admin validation | `ewayrecurring_civicrm_validateForm` | PU-H | PW-B-processor | None |

---

## 2. Test architecture and prerequisites

### 2.1 The key obstacle: the gateway is hardcoded

Both `CRM_Core_Payment_eWAYRecurring::getEWayClient()` and the static
`CRM_eWAYRecurring_Utils::getEWayClient()` call `\Eway\Rapid::createClient()` directly with the
sandbox or production constant. Nothing can be faked today except `SettlementSync`, which accepts
a Guzzle client. The bundled SDK does expose `Client::setHttpService(HttpServiceContract)`, and
accepts an endpoint URL, but requires it to begin with `https` and end with `/`.

**Proposed seam S1 (small production change, needed by both layers):**
introduce a single client factory (for example `CRM_eWAYRecurring_Utils::getEWayClient($processor)`
being the only place a client is built, with `CRM_Core_Payment_eWAYRecurring::getEWayClient()`
delegating to it) that:

- returns the normal client by default (no behaviour change);
- lets tests register a replacement `HttpServiceContract` (for example via a static override or a
  Civi container service) so the SDK talks to a fake instead of the network.

For Playwright, the same seam is activated by a tiny **test-support CiviCRM extension** that lives
under `tests/playwright/fixtures/stub-extension/` and is copied into the site's extensions
directory and enabled by the setup script only (never shipped). It registers the fake HTTP
service. Using an extension rather than a snippet in `civicrm.settings.php` matters because the
location of that file differs per CMS, whereas an extension is activated identically on
WordPress, Drupal, Joomla and Standalone with `cv`. This also avoids needing TLS, a trusted CA
inside the container, or the real sandbox.

### 2.2 PHPUnit conventions

Follow [TESTING.md](TESTING.md): classes `CRM_eWAYRecurring_<Name>Test`, `HeadlessInterface`,
`HookInterface`, `TransactionalInterface`, APIv4 for data set-up, `@group headless`.

Shared helpers (new `tests/phpunit/CRM/eWAYRecurring/Test/` directory, mirroring the
`CouncilFixture` approach in the elections extension):

- `EwayFixture`: creates processors (live, test, legacy class name), contributions, recurring
  contributions in each state, payment tokens, financial transactions. The helpers currently
  private to `SettlementSyncTest` move here so both test classes share them.
- `FakeEwayHttp`: a scriptable `HttpServiceContract` that returns canned eWAY JSON for
  `createTransaction`, `queryTransaction`, `queryCustomer`, `createCustomer`, and records the
  request payloads so tests can assert on what was sent.
- `FakeEwayResponses`: builders for success, decline, connection failure, "not processed yet",
  and invalid credential responses.

### 2.3 Playwright layout

Mirrors the sibling repos. Everything lives under `tests/playwright/`.

```
tests/playwright/
  package.json                  scripts: test, test:headed, test:ui, report, seed
  playwright.config.ts          chromium; CI: 1 worker, 60s timeout, retries 1, trace on failure
  tsconfig.json
  README.md
  fixtures/
    cms.ts                      CMS=wordpress|drupal|joomla|standalone: frontUrl(), adminUrl(), CIVI_CV
    global-setup.ts             logs each persona in once per CMS and saves the session (storageState)
    base.ts                     personas as pre-authenticated pages, problem collector, cms-skip helper
    civi.ts                     cv api4 / cv ev / cv scr wrappers via CIVI_EXEC_PREFIX and CIVI_CV
    eway-stub.ts                scripts the stub and serves the fake hosted page via page.route()
    test-users.json             usernames, passwords, roles (single source of truth)
    test-data.json              names of seeded processors, pages, contacts, tokens
    setup-environment.sh        WordPress: roles, users, pages; then common steps below
    setup-accounts-drupal.sh    Drupal: roles and users with drush
    setup-accounts-joomla.sh    Joomla: administrator, plus user groups, access rules and users
    setup-accounts-standalone.sh  Standalone: roles and users with cv
    setup-common.sh             every CMS: install stub extension, processor, outbound mail, seed
    seed-data.php               run with cv scr, idempotent
    stub-extension/             test-support CiviCRM extension: registers the fake HttpService
  specs/
    frontend/                   donors: PW-F1 to PW-F6 (journeys, queue effects, public endpoints)
    backend/                    staff: settings, processor admin, jobs, failed list, forms, tokens
    security/                   tampering, XSS, access control
    sandbox/                    @sandbox, real eWAY sandbox, nightly only
```

CI: add `.github/workflows/frontend-tests.yml` identical in shape to the sibling repos, calling
`agileware/ci-workflows/.github/workflows/playwright-tests.yml@main` with
`EXTENSION_NAME: au.com.agileware.ewayrecurring` and
`SETUP_SCRIPT: tests/playwright/fixtures/setup-environment.sh`. The reusable workflow already
installs WordPress, pins the Astra theme, sets pretty permalinks, installs CiviCRM and `cv`,
enables the extension and runs the suite. **It builds WordPress only**, so the other three CMSs
are run against local sites until the per-CMS workflows described in section 2.5 exist (open
decision 7 is settled: separate workflows).

Two behaviours of the shared workflow (as of `ci-workflows` commit `b52e7bd`) matter to the layout above:

- The checkout is made writable for the container user before `SETUP_SCRIPT` runs and writable for
  the runner afterwards. That is what lets `global-setup.ts` save sessions under
  `fixtures/.generated/` and the setup script write fixture files the tests read. Add
  `tests/playwright/fixtures/.generated/` to `.gitignore`.
- The WordPress theme is the workflow's `WP_THEME` input (default `astra`). Set it explicitly in
  `frontend-tests.yml` so the theme the layout cases (PW-F1-12, PW-F1-13) run against cannot change
  by accident if the default does.

**The eWAY stub for Playwright.** The browser never talks to eWAY's API; PHP does. So there are
two things to fake:

1. *Server to eWAY calls* (create transaction, query transaction, query customer, create
   customer): the fake `HttpService` from seam S1. Its responses are driven by a small JSON file
   or a table the tests write through `eway-stub.ts` (for example "the next access code is
   approved", "declined with V6012", "customer token 9123456 has card 4444 ... 1111 expiring
   12/2031").
2. *Browser to eWAY hosted page*: the `SharedPaymentUrl` the fake returns points at a reserved
   fake host (for example `https://eway-stub.test/pay?AccessCode=...`). Playwright answers
   requests to that host itself with `page.route()`, serving a small page with a card form and
   "Pay" / "Cancel" buttons that sends the browser on to the `RedirectUrl` / `CancelUrl` with the
   `AccessCode` appended, as eWAY's page does. Because Playwright serves it, the hosted-page stub
   is identical on every CMS and needs nothing installed on the site.

A separate `sandbox` project runs one happy-path journey against the **real** eWAY sandbox
hosted page (card `4444333322221111`) to catch drift between the stub and reality. It needs
`EWAY_SANDBOX_KEY` and `EWAY_SANDBOX_PASSWORD` secrets and runs on a nightly schedule, not on
every push.

### 2.4 Cross-cutting Playwright checks

As in the elections suite, every test fails on: uncaught page errors, console errors, failed
AJAX requests to `civicrm/ajax/*` and `civicrm/ewayrecurring/*`, and PHP output on the page
(`Warning:`, `Notice:`, `Deprecated:`, `Fatal error`, `Undefined array key`, Smarty errors).
A test that expects an error lists it in `allowedProblems`.

Personas (`fixtures/test-users.json`). The capabilities column uses CiviCRM permission names; each CMS setup script maps them to that CMS (see 2.5):

| Persona | Capabilities | Used for |
| --- | --- | --- |
| `admin` | CMS administrator | settings, processor admin, jobs |
| `finance` | `access_civicrm`, `access_civicontribute`, `edit_contributions`, `view_payment_tokens`, `edit_payment_tokens` | Failed list, back-office payments, tokens |
| `tokenviewer` | `access_civicrm`, `view_payment_tokens` | read-only token access |
| `limited` | `access_civicrm` only | negative permission tests |
| anonymous | none | public contribution page, event registration, direct endpoint hits |

Note from the sibling repos: on WordPress the capability string is the munged form of the CiviCRM
permission (`CRM_Utils_String::munge`, lower case, underscores), for example
`view_payment_tokens`. Drupal and Standalone use the permission label as CiviCRM declares it
(`view payment tokens`).

Seeded data (idempotent, `seed-data.php`): one live eWAY processor and one test eWAY processor
(dummy credentials, stub active), one non-eWAY processor (Dummy) for contrast, a once-off
contribution page, a recurring-enabled contribution page, a paid event, a contact with two valid
tokens and one expired token, and recurring contributions in each state (In Progress, Failing,
Failed, Cancelled, Completed, legacy processor_id-only token).

### 2.5 CMS support

The frontend suite runs against the same four CMS families as the elections extension:
**WordPress, Drupal, Joomla and CiviCRM Standalone.** The suites are written once and
the CMS is chosen at run time with the `CMS` environment variable, as `fixtures/cms.ts` does in the
elections repo. `CIVI_CV` names the `cv` command for the site (the elections Joomla dev site uses
`jcv`).

Note on the sibling repos: the elections Playwright code today implements WordPress, Drupal and
Joomla in `cms.ts`. Standalone appears in that extension's PHP (page frame and breadcrumb) but has no
Playwright support yet, so the Standalone column below is new work here and could later be shared
back.

| | WordPress (default) | Drupal | Joomla | Standalone |
| --- | --- | --- | --- | --- |
| Public CiviCRM page | `/civicrm/<path>/?...` (or `/?page=CiviCRM&q=<path>` when `WP_CLEAN_URLS=0`) | `/civicrm/<path>?...` | `/index.php?option=com_civicrm&task=<path>&...` | `/civicrm/<path>?...` |
| Back-office page | `/wp-admin/admin.php?page=CiviCRM&q=<path>` | `/civicrm/<path>?...` | `/administrator/index.php?option=com_civicrm&task=<path>&...` | `/civicrm/<path>?...` |
| Login | `/wp-login.php` | `/user/login` | `/administrator/index.php` | CiviCRM login page (selectors to confirm when written) |
| Persona accounts | `wp role` / `wp user` | `drush role:perm:add` / `drush user:create` | Joomla user groups, with CiviCRM permissions granted to each group in the `com_civicrm` component permissions (see below), and users in those groups | CiviCRM roles and users through `cv` |
| Setup script | `setup-environment.sh` | `setup-accounts-drupal.sh` | `setup-accounts-joomla.sh` | `setup-accounts-standalone.sh` |
| Where it runs | CI (shared workflow) and local | Local (DDEV) | Local (DDEV) | Local |
| Theme the pages sit in | Astra by default (`WP_THEME` input of the shared workflow) | Olivero | Cassiopeia | Standalone's own page template |

How the suites cope with the differences:

- **URLs and logins** are only ever built by `cms.ts` and `global-setup.ts`; specs never hardcode a
  path.
- **Personas.** `global-setup.ts` logs each persona in once, one after the other, and saves the
  session, because WordPress keeps a user's sessions in one meta value and parallel logins as the
  same user can invalidate each other (the elections suite hit this).
- **Joomla personas.** CiviCRM permissions on Joomla are granted through Joomla's own
  access control for the `com_civicrm` component, not through a CiviCRM role screen. The elections
  suite only creates an administrator there, so its permission-based specs skip on Joomla. This
  suite scripts the rest (decision 8): `setup-accounts-joomla.sh` creates one Joomla user group per
  persona (`finance`, `tokenviewer`, `limited`), grants each group exactly the CiviCRM
  permissions in the persona table by writing the `com_civicrm` component rules, and creates a user
  in each group. The Joomla CLI has no user-group commands, so the groups and rules are written
  with a small PHP or SQL step run inside the site, as `theme-variants.sh` does for Joomla in the
  elections repo. The exact rules format and CLI calls are to be confirmed against the Joomla 5 and
  CiviCRM versions in use when the script is written; until then treat the Joomla persona cases as
  the riskiest part of Phase 0.
- **Return URLs.** eWAY appends the access code to the `RedirectUrl` CiviCRM gives it, and on
  Joomla (and WordPress without pretty permalinks) that URL already contains a query string, so
  the stub must append with `?` or `&` as appropriate. PW-F6-06 covers this.
- **Front-context routes.** `js/eway.js` calls `createtoken` and `savetoken` through
  `CRM.url(..., 'front')` from a back-office page. That routing differs per CMS. PW-F6-07 covers it.
- **Theme and chrome.** Status messages, the declined-payment error and the mobile layout are
  checked in each CMS's theme (PW-F1-12, PW-F1-13). CiviCRM-owned markup is the same everywhere;
  the surrounding theme is what can hide a message.
- **Running a CMS.** Set `BASE_URL`, `CMS` and, if needed, `CIVI_CV` and `CIVI_EXEC_PREFIX`, then
  run the matching `setup-*.sh` followed by `setup-common.sh`. `developer/LOCAL_DEV.md` gains a
  section per CMS in Phase 0.

**CI for the other CMSs (decision 7).** The shared `playwright-tests.yml` builds a WordPress site
only, and most of its steps (WordPress install, theme, permalinks, base page, rewrite flush, plugin
activation) are WordPress-specific. Rather than add a `CMS` input and a condition to most steps, the
plan is:

1. Leave `playwright-tests.yml` unchanged, so WordPress and the other repos that call it are not
   put at risk.
2. Add one workflow per CMS to `agileware/ci-workflows`: `playwright-tests-drupal.yml`,
   `playwright-tests-joomla.yml` and `playwright-tests-standalone.yml`. Each builds its own
   site and takes the same inputs where they apply (`EXTENSION_NAME`, `SETUP_SCRIPT`,
   `PLAYWRIGHT_DIR`).
3. Move the steps they share (Node setup, `npm ci`, Playwright browser install, running the tests,
   uploading the report, traces and logs) into a composite action, for example
   `.github/actions/run-playwright`, used by the three new workflows. The WordPress workflow can
   adopt it later.
4. In this repository, `frontend-tests.yml` runs four jobs, one per workflow. The three new jobs
   start as non-blocking (`continue-on-error`) and run nightly or on a PR label, not on every
   push, until the site builds are stable.

First step, before committing to a timeline: check whether usable Drupal, Joomla and Standalone
container images already exist alongside the WordPress image the shared workflow uses, and scope the
site build for each. Until the workflows exist, the per-CMS status in section 0.1 is recorded by
hand from local runs (DDEV), exactly as for the elections extension.

---

## 3. Suspected defects found during review

These are observations from reading the code. Each should first get a **characterisation test**
that pins down the actual behaviour, then be confirmed with the maintainers as a bug or intended.
Do not fix as part of the test work without agreement.

Defect status values: `Open, unconfirmed` (observed in review, not yet pinned by a test),
`Pinned` (a characterisation test records current behaviour, maintainers have not decided),
`Confirmed bug`, `Intended` (documented as deliberate, test kept as a guard), `Fixed`
(link the commit or release in the change log).

| # | Status | Where | Observation | Test that settles it |
| --- | --- | --- | --- | --- |
| DEF-1 | Open, unconfirmed | `CRM_Core_Payment_eWAYRecurring::getInstance()` | `$mode = empty($paymentProcessor['is_test']) ? 'test' : 'live'` looks inverted (a live processor gets mode `test`). `Utils::validateEwayContribution` then filters contributions by `is_test` from that mode. `VerifyPayment` builds the object with the correct mapping. Whether core ever routes through `getInstance` for these paths needs confirming. | PU-A5, PU-D12 |
| DEF-2 | Open, unconfirmed | `doPayment()`, repeat-submit branch | `$priorAccessCode` is the access code **string**, but is then read as `$priorAccessCode['status']` and `['failed_message']`. That is never a valid lookup, so the "already succeeded / already failed" shortcuts cannot work and, on PHP 8, throw. | PU-B12, PW-F1-07 |
| DEF-3 | Pinned | `ewayrecurring_civicrm_check()` and `get_scheduled_failed_contributions()` | The check and the Failed Recurring list look for recurring status `Failed`/`Failing`, but `mark_recurring_contribution_failed()` deliberately does not set that status (CIVIEWAY-125). It is not clear what ever moves a recurring contribution to Failed once retries are exhausted, so the status check and the "Failed" listing may never show the contributions they exist for. | PU-F14, PU-H12, PW-B-failed-02 **Update:** PU-F14 and PU-F15 confirm it, and show more: the code that restores a recurring contribution and records a success activity after retries is unreachable, because it needs status Failed while the retry list only selects In Progress. |
| DEF-4 | Pinned (not reproduced) | `Utils::validatePendingTransactions()` | `require_once 'vendor/autoload.php'` is relative to the current working directory, so it can fail when the job runs from another directory. | PU-E12 **Update:** PU-E12 runs the job from another directory and it succeeds in the test environment, so this may only fail where PHP's include path does not cover the extension. Low priority. |
| DEF-5 | Open, unconfirmed | `VerifyPayment::run()` | `$response` is undefined if the call throws; duplicated `catch (CRM_Core_Exception)` blocks. Harmless today but fragile. | PU-K3 |
| DEF-6 | Pinned (low impact) | `process_recurring_payments()` amount handling | `$amount_in_cents` is derived with a regex that strips the decimal point, which is only right for amounts with exactly two decimals ("10.5" would become 105 cents). | PU-F8 **Update:** the database stores amounts with exactly two decimals, so the only effect is a leading zero for amounts under a dollar ("099"). PU-F8. |
| DEF-7 | Open, unconfirmed | `ProcessTrait::send_receipt_email` | Unused (the only call site is commented out). Dead code. | none, flag only |
| DEF-8 | Open, unconfirmed | `ewayrecurring_civicrm_postInstall()` | Sets schema version 20200 while upgrade steps run to 20601. Check a fresh install does not replay or skip steps. | PU-L2 |
| DEF-9 | Open, unconfirmed | `saveToken()` | `$cid` is parsed from the eWAY customer `Reference` with a regex and used with no check that it exists, and `queryTransaction(...)->Transactions[0]` is dereferenced with no check. Malformed or hostile responses cause a fatal. | PU-G9, PW-S-02 |
| DEF-10 | Pinned | `ProcessTrait::mark_recurring_contribution_failed()` and the success branch of `process_recurring_payments()` | The "eWay Transaction Failed" and "eWay Transaction Succeeded" activities are never created on CiviCRM 6.16: the code passes the option's label to `Activity.create`, which only accepts the name (`eWay_Transaction_Failed`), so the call is rejected and the exception is caught and logged. Found while writing PU-F13. | PU-F13, PU-F15 |
| DEF-11 | Pinned | `ProcessTrait::process_payment()` | The guard against two consecutive responses with the same TransactionID uses `static $prev_response; $prev_response = &$eWayResponse;`. PHP does not keep a reference assigned to a static between calls, so the guard never fires. CiviCRM's own duplicate check catches it later (the second charge ends Pending). | PU-F20 |
| DEF-12 | Pinned | `process_recurring_payments()` | A contact with no address at all makes the job read `$billing_address['id']` on null, raising a PHP warning. The charge still proceeds. | PU-F24 |

---

## 4. PHPUnit plan

All tests use the fakes from 2.2. IDs are grouped by test class.

### PU-A `ProcessorBasicsTest` (no gateway needed)

| ID | Status | Case |
| --- | --- | --- |
| PU-A1 | todo | `checkConfig()` returns null with key and password, one message each when either is missing, both joined when both missing |
| PU-A2 | todo | `getPaymentFormFields()` is empty on the front end, `contact_payment_token` and `add_credit_card` in back office |
| PU-A3 | todo | `getPaymentFormFieldsMetadata()` shape: select is required, class `eway_credit_card_field`, button `onclick` calls `CRM.eway.addCreditCard()` |
| PU-A4 | todo | `getBillingAddressFields()` maps each field with the billing location id |
| PU-A5 | todo | `getInstance()` caches per `domain/id`, returns the same object twice, and sets `_mode` correctly for live and test processors (characterises DEF-1) |
| PU-A6 | todo | `supportsBackOffice()` true; `getEditableRecurringScheduleFields()` returns the six documented fields |
| PU-A7 | todo | `__serialize()` omits `eWayClient` and `instances`, and the object survives `serialize()` after a client was created |
| PU-A8 | todo | `Utils::sanitizeToAscii()` data provider: empty, null, plain ASCII, accented Latin (transliterated), emoji, CJK, control characters, very long string |
| PU-A9 | todo | `Utils::contribution_status_id()` for contribution and recurring variants |
| PU-A10 | todo | `getEWayClientDetailsArray()`: field truncation (Reference 16, names 50, postcode 30), country given as ISO code, as name, as `country_id`, as billing location key, missing country throws 9007, `TokenCustomerID` included only when `subscriptionId` is set |

### PU-B `DoPaymentTest` (needs S1)

| ID | Status | Case |
| --- | --- | --- |
| PU-B1 | todo | Zero amount returns Completed without calling eWAY |
| PU-B2 | todo | Amount parsing: `"1,234.50"`, `"10"`, `"0.05"` become the right cents |
| PU-B3 | todo | Front-end payload: `RESPONSIVE_SHARED`, `SaveCustomer`, `Capture`, `CustomerReadOnly`, `Options.ContributionID`, `TransactionType` PURCHASE |
| PU-B4 | todo | Back-office without CVV setting uses MOTO; with `cvv_backoffice_required` uses PURCHASE |
| PU-B5 | todo | Invoice number is the first 12 characters of the invoice id; reference 50, description 64; blank description falls back to `Invoice ID: ...`; non-ASCII stripped |
| PU-B6 | todo | `alterPaymentProcessorParams` hook runs and can change the payload |
| PU-B7 | todo | Duplicate invoice (`checkDupe`) fails with the documented message and sets status Failed |
| PU-B8 | todo | Gateway returns errors: `PaymentProcessorException` containing each translated message, params status Failed |
| PU-B9 | todo | Null client response: failure message, no queue row |
| PU-B10 | todo | Success creates one `EwayContributionTransactions` row (access code, contribution, processor, `is_email_receipt` defaulting to 1) and redirects to `SharedPaymentUrl` |
| PU-B11 | todo | Session stores the serialised response under the `qfKey` |
| PU-B12 | todo | Repeat submit with matching and non-matching prior access code (characterises DEF-2) |
| PU-B13 | todo | Back office: valid token sends `TokenCustomerID` (first 16 characters) and MOTO |
| PU-B14 | todo | Back office: missing or unknown token fails with "valid credit card token" |
| PU-B15 | todo | Back office with `contributionRecurID`: `processor_id` and `payment_token_id` written to the recurring record |
| PU-B16 | todo | Back office with `is_recur` but no recurring id: the "update the credit card manually" status message |
| PU-B17 | todo | Back office success sets `payment_status_id` Completed and `trxn_id`; declined transaction fails with the translated message |

### PU-C `ReturnUrlsTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-C1 | todo | Success URL: custom `successUrl`, contribution page, UpdateBilling form with recurring record, default verify-payment URL carrying invoice id, qfKey, processor id, component |
| PU-C2 | todo | Cancel URL: custom, contribution page, UpdateBilling, event register, back-office default |
| PU-C3 | todo | `isFromContributionPage()` true and false |

### PU-D `ValidateContributionTest` (`Utils::validate*`, `completeEWayTransaction`)

| ID | Status | Case |
| --- | --- | --- |
| PU-D1 | wip | `validateEwayAccessCode`: approved, declined (message prefixed "Your payment was declined"), eWAY errors (joined with comma), no response (error code 1001), transaction id empty means not processed yet **Note:** the "no response (error code 1001)" branch cannot be reached: the SDK always returns a response object, and a connection failure arrives as an error response, which is what is tested. |
| PU-D2 | wip | `validatingUpdateToken = TRUE` skips the transaction status checks |
| PU-D3 | wip | Approved pending contribution is completed via `completetransaction` with the transaction id and processor id |
| PU-D4 | wip | Only Pending contributions are touched; an already Completed one is left alone |
| PU-D5 | wip | Receipt flag comes from the queue row (`is_email_receipt` 0 and 1) |
| PU-D6 | wip | Declined: contribution set to Failed with `trxn_id`, `is_email_receipt` 0 |
| PU-D7 | wip | Not processed yet: nothing changes |
| PU-D8 | wip | Recurring: token id read from either response location (`TokenCustomerID` at top level, or under `Transactions[0]`) **Note:** only the nested location can occur through the SDK (the response model has no top-level `TokenCustomerID`); the top-level branch is tested directly. |
| PU-D9 | wip | Recurring approved: recurring record becomes In Progress, `processor_id` and `payment_token_id` set, `next_sched_contribution_date` computed from interval and unit, `trxn_id` stored |
| PU-D10 | wip | `updateCustomerDetails`: creates a new `PaymentToken` (masked number, expiry as last day of month, billing name), updates the existing token when `payment_token_id` is set, returns null when the customer has no card details |
| PU-D11 | wip | `completeEWayTransaction` marks the queue row success, silently ignores an unknown access code |
| PU-D12 | wip | `validateEwayContribution` ignores non-eWAY processors, zero-amount contributions, unknown invoice ids, and respects live/test (`is_test`) |

### PU-E `PendingTransactionsTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-E1 | wip | `EwayContributionTransactions` create / get / delete round trip, defaults (`status` 0, `tries` 0, `is_email_receipt` 1) |
| PU-E2 | wip | Deleting the contribution or processor cascades to the queue row (FK) |
| PU-E3 | wip | `validate`: success path, `tries` incremented, status Success, summary counts correct |
| PU-E4 | wip | Failure path: status Failed, `failed_message` stored |
| PU-E5 | wip | Failure on a recurring contribution calls `mark_recurring_contribution_failed` |
| PU-E6 | wip | Not-processed-yet leaves status 0 and still increments `tries` |
| PU-E7 | wip | Missing contribution or processor deletes the queue row and counts `deleted` **Note:** the processor case is removed by the foreign key before the job runs, so the job's own clean-up is tested with a contribution that is no longer Pending. |
| PU-E8 | wip | Rows with `tries >= MAX_TRIES (7)` mark the contribution Failed and the row Failed |
| PU-E9 | wip | Rows already Success or Failed are not re-queried |
| PU-E10 | wip | `EwayContributionTransactions.validate` returns the four counters via the API |
| PU-E11 | wip | Mixed batch of every outcome in one run |
| PU-E12 | wip | Runs when current directory is not the extension root (characterises DEF-4) **Note:** the job did **not** fail from another working directory in the test environment (see DEF-4). |

### PU-F `RecurringProcessingTest` (the highest-value suite)

Selection (`get_scheduled_contributions`, `get_scheduled_failed_contributions`):

| ID | Status | Case |
| --- | --- | --- |
| PU-F1 | wip | Due In Progress and Pending recurring contributions are picked; future-dated ones are not |
| PU-F2 | wip | Cancelled, Completed, Failed-status ones are not picked |
| PU-F3 | wip | Recurring with `failure_retry_date` set is excluded from the normal list and included in the retry list when due |
| PU-F4 | wip | Retry list honours `eway_recurring_contribution_max_retry` and requires `failure_count > 0` |
| PU-F5 | wip | Excluded when no Completed contribution exists yet (CIVIEWAY-124) |
| PU-F6 | wip | Excluded when a contribution already exists on or after the schedule date or retry date (no double charge) |
| PU-F7 | wip | Only the current processor's records are processed when two eWAY processors exist |

Processing (`process_recurring_payments`, `process_payment`):

| ID | Status | Case |
| --- | --- | --- |
| PU-F8 | wip | Amount to cents conversion (`10.00`, `10.50`, `1234.00`, tax inclusive) (characterises DEF-6) **Note:** amounts under a dollar are sent with a leading zero ("099"), which eWAY reads as 99. Compared numerically. |
| PU-F9 | wip | Next schedule advances by interval and unit for day, week, month, year **Note:** CiviCRM core replaces the extension's date with payment date plus one interval when the date is under 48 hours ahead, so daily schedules always end one day after the payment. The test encodes that rule. |
| PU-F10 | wip | Installments: schedule continues while installments remain, last installment marks the recurring contribution Completed with an end date, unlimited (`0`) never completes |
| PU-F11 | wip | Success: new Completed contribution linked to the recurring record, `trxn_id`, source prefix "eWay Recurring ...", financial type, campaign, page id, `is_test` copied from the first contribution; `failure_count` reset, `failure_retry_date` cleared **Note:** a successful charge keeps the source copied from the first contribution; the "eWay Recurring" prefix is only applied when the charge fails (PU-F13). |
| PU-F12 | wip | Receipt sent only when `eway_recurring_keep_sending_receipts` is on |
| PU-F13 | wip | Decline: Failed contribution, Transaction Error note with eWAY messages, `failure_count` incremented, `failure_retry_date` set to today plus the configured delay at midnight, "eWay Transaction Failed" activity **Note:** the "eWay Transaction Failed" activity is not created (DEF-10); the test pins that. |
| PU-F14 | wip | Retries exhausted: nothing further processed, recurring status behaviour pinned down (characterises DEF-3) |
| PU-F15 | wip | Success after earlier failures: recurring record back to In Progress, schedule updated, "eWay Transaction Succeeded" activity with retry count **Note:** pinned as characterisation: no success activity is recorded (DEF-3, DEF-10). |
| PU-F16 | wip | Exception after a gateway response: contribution left Pending, queue row created with the transaction id as access code |
| PU-F17 | wip | Exception before a gateway response: contribution Failed, recurring marked failed, Contribution Error note |
| PU-F18 | wip | Token resolution order: `payment_token_id` token, fallback to legacy `processor_id`, missing token raises the "No eWAY token found" error and fails cleanly |
| PU-F19 | wip | Race lock: second concurrent run exits early with a logged warning and returns false **Note:** the test releases the lock by hand: core defers `RELEASE_LOCK` while a transaction is open, which is always true in these tests. |
| PU-F20 | wip | Two charges returning the same TransactionID in one run: pinned as characterisation (DEF-11). The extension's own duplicate guard never fires; the second charge ends Pending with a queue row because CiviCRM refuses the duplicate transaction id |
| PU-F21 | wip | Non-ASCII source or financial type text is sanitised in the eWAY payload |
| PU-F22 | wip | `handlePaymentCron` and the `Job.run_payment_cron` API reach `process_recurring_payments` for processor `eWay_Recurring` **Note:** core runs the live and then the test instance of the processor type and fails if either is missing, so the test creates both. |
| PU-F23 | wip | `mark_recurring_contribution_failed` still updates the recurring record when the activity create fails |
| PU-F24 | wip | A contact with no address is still charged, but PHP raises "Trying to access array offset on null" (DEF-12); the test records the warning |

### PU-G `PaymentTokenTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-G1 | todo | `fillTokensMeta`: fills missing masked number and expiry for eWAY tokens only (other processor types untouched) |
| PU-G2 | todo | Expiry becomes the last day of the month, year offset by 2000 |
| PU-G3 | todo | Tokens that already have both fields are not queried |
| PU-G4 | todo | Login failure for a processor skips its tokens without error |
| PU-G5 | todo | Per-token query error is collected into `errors` with the token id and does not stop the batch |
| PU-G6 | todo | Processor lookups are cached across tokens (one `PaymentProcessor.get` per processor) |
| PU-G7 | todo | `getPaymentProcessorById`: found, unknown id returns null |
| PU-G8 | blocked | `saveToken` dedupe rule: same contact and processor with same token **or** same masked number updates the existing row, otherwise creates one |
| PU-G9 | blocked | `saveToken` with no `pp_id`, no access code, empty token, unknown processor: documented JSON errors, no fatal on malformed response (characterises DEF-9) |
| PU-G10 | blocked | `createToken` rejects each missing billing field with `Missing field: ...`, unknown processor, unknown contact |

(`createToken` and `saveToken` call `die()` and `CRM_Utils_JSON::output()`. Where that blocks
headless testing, either refactor the body into a testable method that returns an array and keep
the menu callback as a thin wrapper, or cover it through Playwright PW-B-backoffice and PW-S.)

### PU-H `HooksAndManagedTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-H1 | todo | Managed entities exist after install: processor type `eWay_Recurring` (class `Payment_eWAYRecurring`, labels API Key / API Password, `is_recur`), activity types "eWay Transaction Failed" and "eWay Transaction Succeeded", four scheduled jobs with documented frequencies and active flags |
| PU-H2 | todo | Settlement Sync job exists and is **disabled** by default (`Sync.mgd.php`) |
| PU-H3 | todo | `hook_enable` reactivates the processor type if it had been deactivated |
| PU-H4 | todo | `buildForm`: `send_cancel_request` removed for eWAY processors on `CancelSubscription`, kept for others |
| PU-H5 | todo | `validateForm`: API Key and API Password required on the processor admin form for eWAY type, not required for other types |
| PU-H6 | todo | `hook_permission` declares both token permissions with labels |
| PU-H7 | todo | `navigationMenu` adds "eWay Recurring Settings" under Administer, permission `administer CiviCRM` |
| PU-H8 | todo | `searchKitTasks` registers `ContributionRecur.reset_recur_status` with the module and dialog template |
| PU-H9 | todo | `coreResourceList` adds the script and the `agilewareEwayExtension.paymentProcessorId` list of eWAY processor ids, and quietly does nothing when none exist |
| PU-H10 | todo | `preProcess` Thank You: validates once per invoice, hides line items for quick-config price sets |
| PU-H11 | todo | `preProcess` Confirm (contribution and event): already-paid access code throws the right message per component, failed prior transaction shows the warning and clears the session response, not-processed-yet continues |
| PU-H12 | todo | `hook_check`: no message at zero failures, message with count and link when failures exist past the 4-hour threshold, ignores test recurring records, honours a disabled `StatusPreference`, honours the `statusNames` filter (characterises DEF-3) |

### PU-I `PermissionsTest` (`APIWrapper`)

| ID | Status | Case |
| --- | --- | --- |
| PU-I1 | todo | `PaymentToken.get` allowed with `view payment tokens` or `access CiviContribute`, denied otherwise |
| PU-I2 | todo | `PaymentToken.create/update/delete` need `edit payment tokens` or `access CiviContribute`; `view payment tokens` alone is denied |
| PU-I3 | todo | Other entities are not affected by the listener |
| PU-I4 | todo | Both APIv3 and APIv4 paths honour it with `checkPermissions` on |

### PU-J `SettingsFormTest` (`Civi\Test\FormWrapper`)

| ID | Status | Case |
| --- | --- | --- |
| PU-J1 | todo | Defaults: retries 3, delay 4, receipts off, window 10, processors empty |
| PU-J2 | todo | Validation: blank and non-numeric retries and delay rejected; window rejected at 0 and 91, accepted at 1 and 90 |
| PU-J3 | todo | Save round trip for every setting |
| PU-J4 | todo | Multi-select deselect-all stores an empty array (not the previous value) |
| PU-J5 | todo | Legacy delimited string for processors still pre-populates |
| PU-J6 | todo | Processor options list only live eWAY processors |
| PU-J7 | todo | The setting key used by the cron (`eway_recurring_contribution_max_retry`) is the one the form writes, despite the differing `name` field in the settings file |

### PU-K `PagesTest` (`VerifyPayment`, `VerifyUpdateToken`)

These pages exit through `die()` and redirects, so first extract the decision logic to a method
returning a redirect URL or status (pure refactor), then test it. Until then cover through
Playwright (PW-F6).

| ID | Status | Case |
| --- | --- | --- |
| PU-K1 | blocked | `VerifyPayment`: missing invoice id, processor id or component returns 400 |
| PU-K2 | blocked | Unknown processor returns 400 |
| PU-K3 | blocked | Approved: redirect to event Thank You (with `qfKey`) or contact summary; declined: failure redirect; exception path does not hit an undefined variable (characterises DEF-5) |
| PU-K4 | blocked | `VerifyUpdateToken`: no recurring id redirects to the CiviCRM home |
| PU-K5 | blocked | Approved: token updated, `failure_count` 0, Failed status reset to In Progress, success message, redirect to contact |
| PU-K6 | blocked | Declined: error message with the eWAY text, redirect back to Update Billing |

### PU-L `SchemaAndUpgradeTest`

Headless checks of a fresh install and of individual upgrade steps. Real upgrades from a released
version with seeded data are covered by the `UP` suite below.

| ID | Status | Case |
| --- | --- | --- |
| PU-L1 | todo | Fresh install creates `civicrm_eway_contribution_transactions` with all columns and both FKs |
| PU-L2 | todo | Stored schema version after install matches the latest upgrader step (characterises DEF-8) |
| PU-L3 | todo | Each `upgrade_*` step is idempotent when run twice |
| PU-L4 | todo | `upgrade_20601` rewrites legacy class names on processor type and live processors only |
| PU-L5 | todo | DAO and BAO metadata (`entityType.php`) match the table |

### PU-M `SettlementSync` gaps (add to existing class or a sibling)

| ID | Status | Case |
| --- | --- | --- |
| PU-M1 | todo | `EwaySettlement.Sync` API wrapper: no params, with `contribution_id`, invalid id |
| PU-M2 | todo | Sync respects the form-saved settings (end-to-end from `Form_Settings::saveSettings`) |
| PU-M3 | todo | `SettlementSync` logs and continues when a processor has bad credentials (HTTP 401) |
| PU-M4 | todo | Large settlement set across multiple pages with a mix of matched and unmatched trxn ids |

### PU-N `SubscriptionManagementTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-N1 | todo | `changeSubscriptionAmount` writes `next_sched_contribution_date` from date and time parts; no change when absent |
| PU-N2 | todo | `cancelSubscription` returns true and does not call eWAY |
| PU-N3 | todo | `updateSubscriptionBillingInfo`: throws 9000 without `crid` or token; refuses Completed and Cancelled recurring records with the documented messages |
| PU-N4 | todo | Success: recurring `payment_token_id` and `processor_id` updated, `credit_card_number` and expiry placed in params for the email, billing address created or updated (country, state, postcode optional) |

### PU-O `FailedRecurringSearchTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-O1 | todo | Saved search returns only non-test recurring records on eWAY processors **that have a payment token**; other processors, test records and token-less records are excluded |
| PU-O2 | todo | Search display columns and sort match the managed definition |
| PU-O3 | todo | The Reactivate payload (status In Progress, `failure_count` 0, `failure_retry_date` empty, optional `next_sched_contribution_date`) applied through `ContributionRecur.update` resets all four fields and leaves others alone |
| PU-O4 | todo | Reactivated records are picked up by the next `get_scheduled_contributions` once the date has passed (ties F3 to the UI) |
| PU-O5 | todo | Reactivate on a non-eWAY recurring record changes only its status fields |

### UP upgrade tests in CI (`tests/upgrade/`)

Uses the shared `civicrm-extension-upgrade-tests.yml` workflow in `agileware/ci-workflows`. It
installs an older release (`FROM_REF`) on a WordPress + CiviCRM site, then runs
`tests/upgrade/ci-upgrade-test.sh` as `www-data`, which must seed the old version, replace it with
the commit under test (`NEW_VERSION_DIR`), run `cv ext:upgrade-db`, check the result, and exit
non-zero on any problem. The elections extension is the reference implementation
(`tests/upgrade/ci-upgrade-test.sh`, `seed-v1.php`, `verify-v2.php`, `upgrade-from-v1.sh`, and an
`upgrade-tests.yml` that passes `FROM_REF`). The database upgrade is independent of the CMS, so
this suite runs on WordPress only. Pick `FROM_REF` as the last release the upgrade must support
(decision 9: tag `2.8.1`); each `FROM_REF` is one workflow job. Note that between 2.8.1 and 2.8.2 the extension changed only the menu XML (`is_ajax` flags on `createtoken` and `savetoken`), and the upgrader has no step after `upgrade_20601`. From the 2.8.1 baseline the suite is therefore chiefly a guard that an in-place upgrade keeps data, settings and managed entities and rebuilds the menu, and it will exercise real upgrade steps only once a release adds one. A second, older baseline (2.6.1) would exercise real upgrade steps (`upgrade_20300` and `upgrade_20601`) and the switch of upgrader class; that case, UP-07, was dropped as not needed.

| ID | Status | Case |
| --- | --- | --- |
| UP-01 | todo | Upgrading from `FROM_REF` with `cv ext:upgrade-db` succeeds, no upgrade is left pending, and the stored schema version equals the latest upgrader step (also settles DEF-8) |
| UP-02 | todo | A seeded eWAY processor (live and test, credentials, class name, active flag) is unchanged after the upgrade |
| UP-03 | todo | Seeded recurring contributions in each state, payment tokens and `civicrm_eway_contribution_transactions` rows (including `is_email_receipt`, `tries`, `failed_message`) keep their values, and the foreign keys still exist |
| UP-04 | todo | Managed scheduled jobs: a job an administrator had disabled or rescheduled stays that way (the `update: never` jobs); the Settlement Sync job is added disabled; the "eWay Recurring Payments" job (`update: always`) is reconciled |
| UP-05 | todo | Saved settings (retries, delay, receipts, window, processor allowlist) keep their values, and settings introduced after `FROM_REF` take their defaults |
| UP-06 | todo | Managed Search Kit entities (Failed Recurring Contributions saved search, display, navigation item) are created or updated, and a locally modified copy is left alone (`update: unmodified`) |
| UP-07 | n/a | **Dropped 2026-11-14: not needed.** Originally: A second job with `FROM_REF: 2.6.1` (decision 9): seed a processor with the legacy class name `au.com.agileware.ewayrecurring`, upgrade, and check it is rewritten to `Payment_eWAYRecurring` on the type and on live processors only (`upgrade_20601`; the current step skips test processors). Also covers the move from the legacy `CRM_eWAYRecurring_Upgrader` to `AutomaticUpgrader` (switched between 2.7.0 and 2.7.5).. Kept for the record; it would need a second job with `FROM_REF: 2.6.1`, and was judged not worth the cost. The legacy class-name rewrite (`upgrade_20601`) and the switch of upgrader class are therefore not covered by an automated upgrade test |
| UP-08 | todo | After the upgrade the scheduled-job APIs (`Job.run_payment_cron`, `EwayContributionTransactions.validate`, `EwayRecurring.fillTokensMeta`) run without error on the seeded data (nothing due, so no gateway call) |
| UP-09 | todo | The routes `civicrm/ewayrecurring/createtoken` and `savetoken` carry the `is_ajax` flags added after 2.8.1 (true and false respectively) once the menu is rebuilt by the upgrade, so a plain GET navigation to `createtoken` is refused (CIVI-SA-2026-49) |

### Suite level

- Keep `E2ETest.php` out of the default run, but mark it deprecated in [TESTING.md](TESTING.md) once
  Playwright covers FEAT-1 to FEAT-5, and delete it after the sandbox project (PW-X) is stable.
- Fix `phpunit.xml.dist` coverage to include `CRM/`, `api/` and `eWAYRecurring.php` only, and
  publish a coverage summary from CI.
- Proposed targets: 85% line coverage for `ProcessTrait`, `Utils`, `PaymentToken`,
  `CRM_Core_Payment_eWAYRecurring`; 70% overall for the extension PHP.

---

## 5. Playwright plan: what is under test

Every spec uses the eWAY stub unless marked `@sandbox`. Each case is one `test()`. Cases that
change shared fixtures create their own throwaway records, as the sibling suites do.

### 5.1 Front end (donors), `specs/frontend/`

**PW-F1 once-off contribution page** (`contribution-once-off.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F1-01 | todo | all | Page renders with no card fields (card entry happens on eWAY); processor selectable and pre-selected when the only one |
| PW-F1-02 | todo | all | Required billing fields are enforced client and server side before leaving the site |
| PW-F1-03 | todo | all | Confirm page shows the correct amount, billing address, and an eWAY "Make Contribution" style button |
| PW-F1-04 | todo | all | Submit redirects to the stub hosted page, which shows the right amount, invoice reference and customer details read-only |
| PW-F1-05 | todo | all | Approve on the hosted page: land on Thank You, contribution is Completed in DB with `trxn_id`, queue row status Success, receipt flag respected, contact has a token only for recurring (none for once-off check) |
| PW-F1-06 | todo | all | Decline on the hosted page: friendly "payment was declined" message with the eWAY text, contribution Failed, no token created, form can be resubmitted |
| PW-F1-07 | todo | all | Hosted page "Cancel": back on the contribution form with data kept; browser Back after Confirm; double submit; re-posting the Thank You URL shows the "already completed" message instead of charging twice (covers DEF-2) |
| PW-F1-08 | todo | all | Customer never returns to the site: contribution stays Pending, then `EwayContributionTransactions.validate` completes it (see PW-F5) |
| PW-F1-09 | todo | all | Non-ASCII names, addresses and descriptions (accents, apostrophes, emoji) complete and arrive at the stub sanitised |
| PW-F1-10 | todo | all | Test-mode contribution page (`mode=test`) uses the test processor and marks the contribution `is_test` |
| PW-F1-11 | todo | all | Zero-amount (free price option) completes without any call to eWAY |
| PW-F1-12 | todo | all | Mobile viewport (375 px) completes the journey; no horizontal overflow |
| PW-F1-13 | todo | all | A declined payment and the thank-you page show their messages visibly inside the CMS theme (Astra, Olivero, Cassiopeia, Standalone): not hidden, unstyled or pushed off screen |

**PW-F2 recurring contribution** (`contribution-recurring.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F2-01 | todo | all | Recurring option and interval controls shown; unticked behaves as once-off |
| PW-F2-02 | todo | all | Approve: recurring record is In Progress, `processor_id` and `payment_token_id` set, `next_sched_contribution_date` is today plus the interval at 00:00, token row has masked number and expiry end of month |
| PW-F2-03 | todo | all | Each interval unit (week, month, year) and interval greater than 1 give the right next date |
| PW-F2-04 | todo | all | Installments limit stored on the recurring record |
| PW-F2-05 | todo | all | Decline on the first payment: recurring record does not become In Progress; no token saved |
| PW-F2-06 | todo | all | Returning donor with an existing token on the same contact does not create a duplicate token row (dedupe rule) |

**PW-F3 event registration** (`event-registration.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F3-01 | todo | all | Paid event registration to hosted page to Thank You: participant Registered, contribution Completed |
| PW-F3-02 | todo | all | Decline: participant not registered, message shown, can retry |
| PW-F3-03 | todo | all | Hosted page cancel returns to the Confirm step with qfKey intact |
| PW-F3-04 | todo | all | Returning to Confirm after payment shows "Payment already completed for this Registration" |
| PW-F3-05 | todo | all | Multiple participants, correct total sent |
| PW-F3-06 | todo | all | Free event (no payment) does not use eWAY |

**PW-F4 update billing details** (`update-billing.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F4-01 | todo | all | Link from a recurring contribution opens `civicrm/contribute/updatebilling`, shows the recurring contribution and billing address |
| PW-F4-02 | todo | all | Approve a new card on the hosted page: token replaced, `failure_count` 0, Failed recurring record back to In Progress, success alert, redirect to contact summary |
| PW-F4-03 | todo | all | Decline: error alert with eWAY text, redirect back to the update form, record unchanged |
| PW-F4-04 | todo | all | Completed and Cancelled recurring records cannot have billing updated (documented message) |
| PW-F4-05 | todo | all | Wrong user cannot open another contact's recurring record |

**PW-F5 verification queue effects** (`verification-queue.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F5-01 | todo | all | Abandoned approved payment is completed by the verification job and the donor's receipt is queued |
| PW-F5-02 | todo | all | Abandoned declined payment becomes Failed |
| PW-F5-03 | todo | all | A payment still processing stays Pending and is retried on the next run |
| PW-F5-04 | todo | all | After seven unresolved tries the contribution is marked Failed |

**PW-F6 public endpoints** (`endpoints.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F6-01 | todo | all | `civicrm/ewayrecurring/verifypayment` with no, partial or bogus parameters returns 400, no PHP output, no stack trace |
| PW-F6-02 | todo | all | `verifyupdatetoken` with nothing redirects to the home page |
| PW-F6-03 | todo | all | `savetoken` without `pp_id` returns the window-close script only |
| PW-F6-04 | todo | all | `createtoken` as anonymous is refused; as `finance` with missing billing fields returns `Missing field: ...` JSON |
| PW-F6-05 | todo | all | `createtoken` via plain GET navigation (no XHR header) is refused (`is_ajax`, CIVI-SA-2026-49) |
| PW-F6-06 | todo | all | Return URL as the CMS builds it (clean path on WordPress, Drupal and Standalone; `index.php?option=com_civicrm&task=...` on Joomla; query form on WordPress without pretty permalinks) accepts the access code appended by eWAY with either `?` or `&`, and the payment verifies |
| PW-F6-07 | todo | all | `createtoken` and `savetoken` addressed through `CRM.url(..., 'front')` from a back-office page work on each CMS for a permitted user and are refused for anonymous |

### 5.2 Back office, `specs/backend/`

**PW-B-settings** (`settings.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-settings-01 | todo | all | Page loads at `civicrm/ewayrecurring/settings` for admin with defaults 3, 4, No, 10 and an empty processor list |
| PW-B-settings-02 | todo | all | Menu item "eWay Recurring Settings" appears under Administer and navigates there |
| PW-B-settings-03 | todo | all | Blank or non-numeric retries and delay show the field errors; window 0 and 91 rejected, 1 and 90 accepted |
| PW-B-settings-04 | todo | all | Saving persists and survives reload; page redirects back to itself |
| PW-B-settings-05 | todo | all | Processor multi-select lists only live eWAY processors (test and non-eWAY processors absent) |
| PW-B-settings-06 | todo | all | Selecting then deselecting every processor clears the setting (DB check) |
| PW-B-settings-07 | todo | all | `finance` and `limited` users are refused (administer CiviCRM only) |
| PW-B-settings-08 | todo | all | The settings page is reachable from the CMS's own admin navigation (WordPress admin menu, Drupal toolbar, Joomla administrator menu, Standalone navigation) via the "eWay Recurring Settings" entry |

**PW-B-processor** (`payment-processor.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-processor-01 | todo | all | "eWay Recurring" is offered as a processor type with API Key / API Password labels |
| PW-B-processor-02 | todo | all | Saving without key or password shows both required-field errors; other processor types are unaffected |
| PW-B-processor-03 | todo | all | A valid new processor saves and appears in the contribution page processor list |
| PW-B-processor-04 | todo | all | Deactivating and re-enabling the extension leaves the processor type active |

**PW-B-jobs** (`scheduled-jobs.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-jobs-01 | todo | all | Scheduled Jobs page lists the four eWAY jobs with the documented frequencies and active states |
| PW-B-jobs-02 | todo | all | eWay Settlement Sync job is present and disabled |
| PW-B-jobs-03 | todo | all | Run "fill missing tokens metadata" from the UI with the stub: token with null expiry and masked number is filled; result message shows the count |
| PW-B-jobs-04 | todo | all | Run "eWay Transaction Verifications" from the UI: pending items resolved (ties to PW-F5) |
| PW-B-jobs-05 | todo | all | Run "eWay Recurring Payments" with a due recurring contribution: new contribution appears on the contact's Contributions tab and recurring page |

**PW-B-cron recurring processing visible in the UI** (`recurring-cron.spec.ts`)
(uses `civi.ts` helpers to back-date `next_sched_contribution_date`, then runs the job through
`cv api3 Job.run_payment_cron` and checks the screens, in the same way elections moves stages.)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-cron-01 | todo | all | Due recurring contribution: contact summary shows the new Completed contribution and the recurring row's next date moved forward |
| PW-B-cron-02 | todo | all | Declined charge: Failed contribution with a "Transaction Error" note visible on the contribution, "eWay Transaction Failed" activity on the contact, recurring row shows failures and a retry date |
| PW-B-cron-03 | todo | all | Retry after the delay succeeds: recurring row back to normal, "Transaction Succeeded after N retries" activity |
| PW-B-cron-04 | todo | all | Retries exhausted: nothing further charged; where the contribution appears in the failed list (characterises DEF-3) |
| PW-B-cron-05 | todo | all | Receipt email recorded (outbound mail redirected to the database) only when "Send receipt for all recurring contributions" is on |

**PW-B-failed** (`failed-recurring.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-failed-01 | todo | all | `civicrm/contribute/failedrecurring` loads for `finance`; Contributions menu shows "Failed Recurring Contributions"; `limited` is refused |
| PW-B-failed-02 | todo | all | Default status filter shows Failed and Failing only; columns, sort, 50 per page, contact link work (DEF-3 check) |
| PW-B-failed-03 | todo | all | Seeded non-eWAY, test, and token-less recurring records are not listed |
| PW-B-failed-04 | todo | all | Reactivate button appears only on Failing and Failed rows |
| PW-B-failed-05 | todo | all | Row Reactivate: dialog text singular ("1 selected recurring contribution"), optional date left blank, confirm: success alert, DB shows failures 0, retry date cleared, status In Progress, schedule date unchanged |
| PW-B-failed-06 | todo | all | Reactivate with a chosen date: schedule date set exactly |
| PW-B-failed-07 | todo | all | Select several rows, bulk Reactivate: plural wording, progress shown, all updated |
| PW-B-failed-08 | todo | all | Cancel in the dialog changes nothing |
| PW-B-failed-09 | todo | all | Reactivated record is charged by the next cron run once due |
| PW-B-failed-10 | todo | all | Error from the API shows the "error occurring while attempting to reactivate" alert and closes cleanly (force with a permission-denied user via the update action) |

**PW-B-status** (`system-status.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-status-01 | todo | all | With stuck failures, System Status shows "eWAYRecurring - failed recurring contributions" with the right count and a working link to the failed list |
| PW-B-status-02 | todo | all | With none, no message; with the check disabled in status preferences, no message |

**PW-B-recurforms** (`recurring-forms.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-recurforms-01 | todo | all | Cancel Subscription form for an eWAY recurring record has no "send cancellation request" option; cancelling sets Cancelled and does not contact eWAY |
| PW-B-recurforms-02 | todo | all | Same form for a non-eWAY recurring record still shows the option |
| PW-B-recurforms-03 | todo | all | Update Subscription form allows amount, installments, interval, unit, next scheduled date and end date; the injected "next scheduled date" row from `eway.js` appears once with a working datepicker |
| PW-B-recurforms-04 | todo | all | Saving changes persists each field, including the next scheduled date and time |

**PW-B-backoffice back-office saved-card payments** (`backoffice-token-payment.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-backoffice-01 | todo | all | Record Contribution (credit card) with the eWAY processor shows "Stored Credit Card" and "Add Credit Card" instead of card fields |
| PW-B-backoffice-02 | todo | all | "Add Credit Card" is disabled with the billing-incomplete message until every billing field is completed, enabled after |
| PW-B-backoffice-03 | todo | all | Choosing a contact loads that contact's unexpired tokens, formatted `1111 - 12/2031`, newest expiry first; expired tokens excluded; "No cards found." when none; changing contact reloads |
| PW-B-backoffice-04 | todo | all | Legacy token without masked number shows `MM/YYYY` only |
| PW-B-backoffice-05 | todo | all | Add Credit Card: stub hosted window opens in a new tab, "Click Ok to update the card list" confirmation, after Ok the new card appears in the list and the "credit card list has been updated" alert shows |
| PW-B-backoffice-06 | todo | all | Submit with a stored token: contribution Completed using MOTO, `trxn_id` stored |
| PW-B-backoffice-07 | todo | all | Submit declined: error shown, contribution not completed |
| PW-B-backoffice-08 | todo | all | Record a contribution against an existing recurring record (`crid`): token preselected, recurring record keeps its token |
| PW-B-backoffice-09 | todo | all | Contributions made on a token for a different payment processor are not offered |
| PW-B-backoffice-10 | todo | all | `tokenviewer` can see tokens but cannot create; `limited` sees none |

**PW-B-permissions** (`token-permissions.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-permissions-01 | todo | all | APIv4 Explorer as `tokenviewer`: PaymentToken get allowed, create/delete denied |
| PW-B-permissions-02 | todo | all | `finance` (has `access CiviContribute`) allowed everything |
| PW-B-permissions-03 | todo | all | `limited` denied |

### 5.3 Security, `specs/security/`

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-S-01 | todo | all | Script and markup payloads (`<img onerror>`, `"><script>`, `<svg onload>`) in billing name, address, contribution source, description, and eWAY customer fields appear as text on the confirm, thank-you, recurring and failed-list pages. No script runs, no element injection, no `javascript:` links. Modelled on the elections `xss.spec.ts` |
| PW-S-02 | todo | all | Tampering: swapped `AccessCode`, another contribution's invoice id, `paymentProcessorID` of a different processor, replay of a completed return URL, SQL meta-characters in `contributionInvoiceID`. No contribution state changes, no fatal, no PHP output (covers DEF-9) |
| PW-S-03 | todo | all | Direct access without login to every `civicrm/ewayrecurring/*` route returns the documented permission result |
| PW-S-04 | todo | all | Stored API password never appears in page source, AJAX responses, or the processor list for non-admins |

### 5.4 Real sandbox, `specs/sandbox/` (PW-X, nightly)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-X-01 | todo | wp-only | Once-off payment through the real eWAY sandbox hosted page (`@sandbox`) |
| PW-X-02 | todo | wp-only | Recurring set-up through the sandbox, then one cron run against the saved token |
| PW-X-03 | todo | wp-only | Add Credit Card from the back office through the sandbox |
| PW-X-04 | todo | wp-only | Settlement search call against the sandbox returns the documented empty/not-ready shape (the extension excludes test processors from the sync, so this just checks the API contract) |

---

## 6. Phasing

Update the Status column as phases start and finish.

| Phase | Status | Scope | Why this order |
| --- | --- | --- | --- |
| 0 | In progress (seam S1, PHPUnit fakes and fixture, coverage config done; Playwright scaffold and CI pinning not started) | Seam S1, `EwayFixture`, `FakeEwayHttp`, move SettlementSync helpers, fix coverage config. Playwright scaffold, **CMS-agnostic from the start**: package, config, `cms.ts`, `global-setup.ts`, `base.ts`, `civi.ts`, stub extension and `page.route()` hosted-page stub, setup scripts for all four CMSs (including the Joomla groups and access rules), `frontend-tests.yml` (WordPress), one smoke spec. | Everything else depends on these. Building `cms.ts` first means no spec ever hardcodes a WordPress path, which is far cheaper than retrofitting. Also proves the CI wiring early. |
| 1 | In progress (PU-D, PU-E, PU-F written; PU-B and the Playwright suites not started) | PU-B, PU-D, PU-E, PU-F, plus PW-F1, PW-F2, PW-F5, PW-B-cron | Money and schedule logic. A regression here charges or fails to charge real donors. |
| 2 | Not started | PU-A, PU-C, PU-G, PU-H, PU-I, PU-J, PU-N, PU-O, plus PW-B-settings, PW-B-failed, PW-B-recurforms, PW-B-jobs, PW-B-processor | Admin features and hook behaviour. |
| 3 | Not started | PU-K, PU-L, PU-M, UP (adds `tests/upgrade/` and `upgrade-tests.yml`), plus PW-F3, PW-F4, PW-F6, PW-B-backoffice, PW-B-status, PW-B-permissions, PW-S | Remaining journeys, hardening, upgrade safety. |
| 4 | Not started | Sandbox project (PW-X), delete `E2ETest.php`, accessibility pass (axe) on contribution page, confirm page, failed list | Drift detection and clean-up. |

Phase 5 is separate because it depends on environments rather than test code:

| Phase | Status | Scope | Why this order |
| --- | --- | --- | --- |
| 5 | Not started | Cross-CMS runs: stand up Drupal, Joomla and Standalone sites, run the Phase 1 to 3 suites on each, fix CMS-specific selectors and URLs, record results in the CMS table, and add the per-CMS workflows and shared composite action from decision 7 to `agileware/ci-workflows` so they run in CI, starting with the image and site-build check | Needs the suites stable on WordPress first, so a failure on another CMS points at a CMS difference rather than a flaky test. |

A reasonable stopping point after Phase 1 is already a large improvement: the only logic that
moves money would be under test.

### Definition of done for each phase

- New tests pass locally (docker for PHPUnit, DDEV or docker for Playwright) and in CI.
- No flaky test: run the Playwright suite three times in CI (WordPress) before merging. For
  Phase 5, run each other CMS three times locally.
- Every case in scope is `done` here, tagged in the test, and the section 0.1 counts are refreshed.
- [TESTING.md](TESTING.md) updated; `tests/playwright/README.md` written (same structure as the sibling
  repos); [LOCAL_DEV.md](LOCAL_DEV.md) extended with the Playwright commands.
- Suspected defects (DEF-1 to DEF-12) in scope each either fixed with an agreed change, or pinned by a
  characterisation test with a comment that names the open question.

---

## 7. Open decisions

Record the outcome and date when a decision is made.

| # | Status | Decision | Outcome |
| --- | --- | --- | --- |
| 1 | Decided | **Seam S1.** Is a small production refactor (single client factory with an overridable HTTP service) acceptable? Without it, the recurring cron, payment and token code cannot be tested without the real sandbox. This is the main dependency of the plan. | 2026-11-14: yes. Add the client factory with an overridable HTTP service (seam S1) as a behaviour-preserving refactor, in Phase 0. Unblocks the 67 `blocked` cases. |
| 2 | Decided | **Sandbox secrets.** Are `EWAY_SANDBOX_KEY` / `EWAY_SANDBOX_PASSWORD` available as repository secrets for the nightly `@sandbox` project? The hardcoded sandbox credentials currently in `E2ETest.php` should be moved to environment variables, and rotated if they turn out to be live. | 2026-11-14: secrets are needed only for the nightly sandbox project (Phase 4). Before then, check whether the credentials hardcoded in `E2ETest.php` are live, rotate them if so, and move them to environment variables. |
| 3 | Decided | **CMS matrix.** Which CMSs do the frontend tests cover? | 2026-11-14: the same as the elections extension, namely WordPress, Drupal, Joomla and Standalone. See section 2.5. The elections Playwright code implements the first three; Standalone support is new work. |
| 4 | Decided | **CiviCRM version.** `info.xml` declared 5.82 at the time. Which CiviCRM version do the tests run on? | 2026-11-14: **CiviCRM 6.16**, for PHPUnit, Playwright and the upgrade tests, on every CMS. Revised the same day from an earlier "5.82 plus current stable" outcome. `info.xml` compatibility was updated to 6.16 to match (see change log). The shared workflows must be able to install a pinned version (see follow-ups). |
| 5 | Decided | **Defects DEF-1 to DEF-9.** Which should be fixed as part of this work, and which only pinned? | 2026-11-14: pin all nine with characterisation tests first (Phase 1; three more, DEF-10 to DEF-12, were found while writing the first suites and are pinned too), then fix confirmed ones in separate pull requests. DEF-4 and DEF-5 are the lowest-risk fixes; DEF-1 to DEF-3 are the ones that could affect donors. Defect statuses in section 3 move as each is pinned and confirmed. |
| 6 | Decided | **Mail capture.** Receipt assertions need outbound mail redirected to the database. Confirm this is fine in the test site settings. | 2026-11-14: yes. The test sites redirect outbound mail to the database (as the elections setup script does), so receipt assertions need no mail server. |
| 7 | Decided | **CI for non-WordPress CMSs.** The shared `playwright-tests.yml` builds WordPress only. Add a `CMS` input to it, add a separate workflow per CMS, or keep those three as local-only runs? | 2026-11-14: separate workflow per CMS (Drupal, Joomla, Standalone) in `agileware/ci-workflows`, with the shared steps in a composite action and the existing WordPress workflow left unchanged. See section 2.5. Still to do: confirm container images exist and scope each site build. |
| 8 | Decided | **Joomla personas.** The elections suite only has an administrator session on Joomla, so permission-based cases skip there. Script Joomla access levels so those cases can run, or accept the skips? | 2026-11-14: script them. All cases run on Joomla. See section 2.5. |
| 9 | Decided | **Upgrade baseline.** Which release is the last one the upgrade must support (`FROM_REF` for the `UP` suite)? | 2026-11-14: **2.8.1** for the whole `UP` suite, revised the same day from a policy ("oldest release any live site still runs"). A second baseline (2.6.1) was considered for UP-07, which would have exercised `upgrade_20601`; UP-07 was then dropped as not needed, so there is a single baseline. |

### Follow-up actions from the decisions

| Action | Phase | Related decision |
| --- | --- | --- |
| ~~Implement seam S1 (client factory, overridable HTTP service)~~ Done: `Utils::setHttpServiceFactory()`, with `FakeEwayHttp` in `tests/phpunit/CRM/eWAYRecurring/Test/` | 0 | 1 |
| Pin CiviCRM 6.16 in the test environments: check whether the shared workflows (PHPUnit, Playwright, upgrade tests) can install a given version, and add a version input in `ci-workflows` if not; pin it in the DDEV setups for the other CMSs and in the local docker-compose | 0 | 4 |
| ~~Decide whether `info.xml` compatibility (5.82) still reflects what is supported~~ Done: set to 6.16 | 0 | 4 |
| Check the credentials in `E2ETest.php`; rotate if live; move to environment variables | before 4 | 2 |
| Add `EWAY_SANDBOX_KEY` and `EWAY_SANDBOX_PASSWORD` repository secrets | 4 | 2 |
| Set outbound mail to "redirect to database" in the test setup scripts | 0 | 6 |
| ~~Confirm on a real site that upgrading a fresh 2.6.1 install runs `upgrade_20601`, then write UP-07~~ Dropped with UP-07 | n/a | 9 |
| Add `upgrade-tests.yml` with `FROM_REF: 2.8.1` and write `tests/upgrade/` (seed, upgrade, verify) | 3 | 9 |
| Check that Drupal, Joomla and Standalone container images exist; scope each site build | 5 | 7 |
| Confirm the Joomla permission-rules format and the Standalone login selectors | 0 | 3, 8 |

---

## 8. Change log

Newest first. One line per change to coverage or to the plan itself.

| Date | Change |
| --- | --- |
| 2026-11-14 | Phase 0 and Phase 1 started on branch `phase0-testing-foundations`: seam S1 implemented, `FakeEwayHttp` / `FakeEwayResponses` / `EwayFixture` added, coverage config restricted to the extension's code, suites PU-D (22 tests), PU-E (13) and PU-F (33) written and passing locally on CiviCRM 6.16.5 (full suite: 106 tests). Cases are `wip` until the pull request passes CI. New case PU-F24; new defects DEF-10 to DEF-12 found (DEF-10: failure and success activities are never created); DEF-3 extended; DEF-4 not reproduced. |
| 2026-11-14 | UP-07 dropped (`n/a`) at the maintainer's request: the 2.6.1 upgrade test is not needed. Recorded in place with its original description. Single upgrade baseline (2.8.1) remains. Cases defined 243, to do 175, dropped 1. |
| 2026-11-14 | UP-07 now uses `FROM_REF: 2.6.1` as a second baseline (code review: 2.6.1 is the first release containing `upgrade_20601`, and a fresh install stores schema version 20200, so the step should still run on upgrade). Not yet confirmed on a real site. UP-07 back to `todo`. |
| 2026-11-14 | Decision 9 settled: upgrade baseline is release 2.8.1. UP-07 set to `blocked` (needs an older baseline), UP-09 added (menu `is_ajax` flags after upgrade). 243 cases. |
| 2026-11-14 | `info.xml` compatibility changed from 5.82 to 6.16 to match the tested version (decision 4); user docs requirements line updated. |
| 2026-11-14 | Decision 4 revised: tests run on CiviCRM 6.16 only (replaces "5.82 plus current stable"). Follow-up actions updated. |
| 2026-11-14 | Decisions 1, 2, 4, 5, 6 and 9 recorded as decided (9 as a policy; the actual `FROM_REF` tags are still to be chosen). Added the follow-up actions table to section 7. No decisions remain open. |
| 2026-11-14 | Reviewed against `ci-workflows` `b52e7bd`. Added the `UP` upgrade suite (8 cases, 242 total) using the new `civicrm-extension-upgrade-tests.yml`, decision 9, the `WP_THEME` and writable-checkout notes in 2.3 and 2.5, and `UP` in the count commands. The PHPUnit workflow change (empty test-data placeholders) needs no plan change. |
| 2026-11-14 | Decision 7: CI for Drupal, Joomla and Standalone will use a separate workflow per CMS plus a shared composite action in `ci-workflows`, instead of a `CMS` input on the existing workflow. Section 2.5, Phase 5 and the decision table updated. |
| 2026-11-14 | Decision 8: Joomla access levels will be scripted, so the 9 cases previously planned to skip on Joomla now run there. The `no-joomla` CMS value is removed; every case is `all` except the real-sandbox cases (`wp-only`). Joomla persona script added to Phase 0 scope and flagged as a risk. |
| 2026-11-14 | Frontend scope widened to WordPress, Drupal, Joomla and Standalone (decision 3). Added section 2.5, a CMS column on every Playwright case, per-CMS status table, Phase 5, decisions 7 and 8, and cases PW-F1-13, PW-F6-06, PW-F6-07, PW-B-settings-08. Hosted-page stub moved to `page.route()` and the stub activation to a test-support extension so both are CMS-neutral. 234 cases. |
| 2026-11-14 | Plan created from a review of version 2.8.2. 230 cases defined, none implemented. Existing coverage recorded: `SettlementSyncTest` (38 tests), `E2ETest` (2 tests, not run in CI). |
