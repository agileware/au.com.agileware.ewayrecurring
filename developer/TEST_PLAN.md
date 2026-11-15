# Test plan and coverage status: eWay Recurring Payment Processor

| | |
| --- | --- |
| Extension version | 2.8.2 |
| Status last updated | 2026-11-15 |
| Owner | Extension maintainers (update this file in the same pull request as any test change) |
| Related | [TESTING.md](TESTING.md) (how to run tests), [LOCAL_DEV.md](LOCAL_DEV.md) (local environment), [FEATURES.md](FEATURES.md) (planned features) |

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

Cases defined: **287**. Done: **160**. In progress: **112**. Blocked: **0**. To do: **10**. Dropped (`n/a`): **5** (UP-07, PU-K4 to PU-K6, PW-F6-02).

| Suite | Layer | Home | Cases | Done | WIP | Blocked | Todo | N/A | Needs |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| PU-A | PHPUnit | `ProcessorBasicsTest` | 11 | 11 | 0 | 0 | 0 | 0 | none |
| PU-B | PHPUnit | `DoPaymentTest` | 18 | 17 | 1 | 0 | 0 | 0 | none (S1 done; PU-B18 passes locally, `done` after a CI run) |
| PU-C | PHPUnit | `ReturnUrlsTest` | 3 | 3 | 0 | 0 | 0 | 0 | none |
| PU-D | PHPUnit | `ValidateContributionTest` | 12 | 12 | 0 | 0 | 0 | 0 | none (S1 done) |
| PU-E | PHPUnit | `PendingTransactionsTest` | 12 | 12 | 0 | 0 | 0 | 0 | none (S1 done) |
| PU-F | PHPUnit | `RecurringProcessingTest` | 28 | 28 | 0 | 0 | 0 | 0 | none (S1 done) |
| PU-G | PHPUnit | `PaymentTokenTest` | 12 | 12 | 0 | 0 | 0 | 0 | none (S1 done) |
| PU-H | PHPUnit | `HooksAndManagedTest` | 15 | 13 | 2 | 0 | 0 | 0 | none (PU-H14 and PU-H15 pass locally, `done` after a CI run) |
| PU-I | PHPUnit | `PermissionsTest` | 4 | 4 | 0 | 0 | 0 | 0 | none |
| PU-J | PHPUnit | `SettingsFormTest` | 10 | 7 | 3 | 0 | 0 | 0 | none (PU-J8 to J10 pass locally, `done` after a CI run) |
| PU-K | PHPUnit | `PagesTest` | 6 | 3 | 0 | 0 | 0 | 3 | none (refactor done); PU-K4 to K6 dropped with the deleted page |
| PU-L | PHPUnit | `SchemaAndUpgradeTest` | 10 | 10 | 0 | 0 | 0 | 0 | none |
| PU-M | PHPUnit | `SettlementSync gaps` | 4 | 4 | 0 | 0 | 0 | 0 | none |
| PU-N | PHPUnit | `SubscriptionManagementTest` | 9 | 9 | 0 | 0 | 0 | 0 | none |
| PU-O | PHPUnit | `FailedRecurringSearchTest` | 5 | 5 | 0 | 0 | 0 | 0 | none |
| UP | Upgrade (CI) | `tests/upgrade/` | 11 | 10 | 0 | 0 | 0 | 1 | none; passing on GitHub; UP-07 dropped |
| PW-INF | Playwright | `specs/infrastructure/` | 6 | 0 | 6 | 0 | 0 | 0 | Pass on WordPress (GitHub and local); `done` once Drupal, Joomla and Standalone have run them |
| PW-F1 | Playwright | `specs/frontend/` | 13 | 0 | 13 | 0 | 0 | 0 | Pass on WordPress (GitHub and local); `done` once Drupal, Joomla and Standalone have run them |
| PW-F2 | Playwright | `specs/frontend/` | 6 | 0 | 6 | 0 | 0 | 0 | Pass on WordPress (GitHub); `done` once Drupal, Joomla and Standalone have run them |
| PW-F3 | Playwright | `specs/frontend/` | 6 | 0 | 6 | 0 | 0 | 0 | Pass on WordPress (GitHub); `done` once Drupal, Joomla and Standalone have run them |
| PW-F4 | Playwright | `specs/frontend/` | 7 | 0 | 7 | 0 | 0 | 0 | Pass on WordPress (GitHub); `done` once Drupal, Joomla and Standalone have run them |
| PW-F5 | Playwright | `specs/frontend/` | 4 | 0 | 4 | 0 | 0 | 0 | Pass on WordPress (GitHub); `done` once Drupal, Joomla and Standalone have run them |
| PW-F6 | Playwright | `specs/frontend/` | 7 | 0 | 6 | 0 | 0 | 1 | Pass on WordPress (GitHub); `done` once Drupal, Joomla and Standalone have run them; PW-F6-02 dropped with the deleted page |
| PW-B-settings | Playwright | `specs/backend/` | 8 | 0 | 8 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them |
| PW-B-processor | Playwright | `specs/backend/` | 4 | 0 | 4 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them |
| PW-B-jobs | Playwright | `specs/backend/` | 5 | 0 | 5 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them |
| PW-B-cron | Playwright | `specs/backend/` | 8 | 0 | 8 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them; the schedule check in PW-B-cron-07 is pinned by `test.fail()` (DEF-58) |
| PW-B-failed | Playwright | `specs/backend/` | 12 | 0 | 12 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them; PW-B-failed-01 (the list for `finance`, DEF-62) and a second PW-B-failed-11 test (DEF-46) are pinned by `test.fail()` |
| PW-B-status | Playwright | `specs/backend/` | 3 | 0 | 3 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them |
| PW-B-recurforms | Playwright | `specs/backend/` | 5 | 0 | 5 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them |
| PW-B-backoffice | Playwright | `specs/backend/` | 10 | 0 | 10 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them; one test each for PW-B-backoffice-03 (DEF-63) and PW-B-backoffice-08 (DEF-65) is pinned by `test.fail()` |
| PW-B-permissions | Playwright | `specs/backend/` | 3 | 0 | 3 | 0 | 0 | 0 | Pass on WordPress (local only, GitHub run pending); `done` once Drupal, Joomla and Standalone have run them |
| PW-S | Playwright | `specs/security/` | 5 | 0 | 0 | 0 | 5 | 0 | Phase 0 infra |
| PW-X | Playwright | `specs/sandbox/` | 5 | 0 | 0 | 0 | 5 | 0 | Phase 0 infra, S1 stub |

#### Playwright status by CMS

A Playwright case is `done` only when it passes on **every CMS listed in its CMS column**.
This table shows progress per CMS while that is not yet true. Cell values: `none`, `partial`,
`full`, or `n/a` (cannot run there). Update by hand from the latest CI or local run, and put the
run date in the change log.

| Playwright group | WordPress | Drupal | Joomla | Standalone |
| --- | --- | --- | --- | --- |
| Infrastructure (`cms.ts`, setup scripts, stub, smoke spec) | full (GitHub and local) | none | none | none |
| Frontend (PW-F1 to PW-F6) | partial (PW-F1 to PW-F6 green on GitHub) | none | none | none |
| Backend (PW-B-*) | partial (PW-B-settings, PW-B-processor and PW-B-permissions green on GitHub; PW-B-jobs, PW-B-cron, PW-B-status, PW-B-recurforms, PW-B-failed and PW-B-backoffice green locally only) | none | none | none |
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
| Settlement Sync (`SettlementSync.php`, `EwaySettlement.Sync`) | Covered | See the table above. Gaps are PU-M. |
| Payment processor class, recurring cron, verification queue, upgrade steps | Covered by PHPUnit | PU-A to PU-O are done (green on GitHub, 423 tests). Nothing is blocked. |
| Update billing, retry and recovery of Failed records | Covered by PHPUnit | PU-N5 to PU-N9, with the donor-path fixes DEF-13 and DEF-14. |
| Upgrade from 2.8.1 | Covered in CI | `tests/upgrade/` and PU-L, 57 checks green on GitHub. |
| Hooks, settings form, permissions, Search Kit action | Covered by PHPUnit | PU-H, PU-I, PU-J and PU-O are done. The browser side (`js/eway.js`, the Search Kit screens) is only reachable with Playwright. |
| Front end | Started | Back office: PW-B-settings, PW-B-processor and PW-B-permissions are green on GitHub on WordPress; PW-B-jobs, PW-B-cron, PW-B-status and PW-B-recurforms are written and green locally on WordPress (`wip` until GitHub and the other CMSs). PW-B-failed and PW-B-backoffice are written and green locally on WordPress too (`wip`), with DEF-62 (the Failed list does not load for non-administrators) open. Playwright PW-F1 (once-off contribution page), PW-F2 (recurring set-up) and PW-F3 (events) have all 25 cases green on WordPress, on GitHub, and so do PW-F4 (update billing) and PW-F5 (verification queue). The other front-end groups are still to do. The legacy `E2ETest.php` (real sandbox, not run in CI) is still to be replaced and deleted. |
| CI | PHPUnit, upgrade and Playwright | `phpunit-tests.yml`, `upgrade-tests.yml` and `frontend-tests.yml` call the shared workflows in `agileware/ci-workflows`. They run on pushes to master, on pull requests and on demand. Playwright runs on WordPress only. |
| Coverage config | Restricted | `phpunit.xml.dist` includes only `CRM/` and `api/` (the generated DAO files are excluded). |

For the exact state use the status summary in 0.1 (case counts per suite), the CMS table under it, and the change
log in section 8. This section is a summary and goes stale: when you finish a suite, refresh it.

### Feature inventory and coverage

"Coverage now" is the current state. Update it when the linked suites reach `done`.

| # | Feature (from [user docs](../docs/README.md)) | Main code | Headless suites | Playwright suites | Coverage now |
| --- | --- | --- | --- | --- | --- |
| FEAT-1 | Once-off payment via Responsive Shared Page | `CRM_Core_Payment_eWAYRecurring::doPayment`, `VerifyPayment`, `Utils::validateContribution` | PU-B, PU-C, PU-D | PW-F1 | Headless: good (PU-B, PU-C, PU-D done). Front end: PW-F1 all thirteen cases green on WordPress (`wip` until the other CMSs run) |
| FEAT-2 | Recurring set-up (token saved, schedule started) | `Utils::updateRecurringContribution`, `updateCustomerDetails` | PU-D | PW-F2 | Headless: good (PU-D done). Front end: PW-F2 all six cases green on WordPress (`wip` until the other CMSs run) |
| FEAT-3 | Recurring processing by cron, with retry of failures | `ProcessTrait::process_recurring_payments` and helpers | PU-F | PW-B-cron | Headless: good (PU-F, 26 of 28). Front end: PW-B-cron all eight cases green locally on WordPress (`wip`); DEF-58 (a retry skips a month) is open |
| FEAT-4 | Saved-card ("stored credit card") back-office payments | `doPayment` back-office branch, `js/eway.js`, `PaymentToken::createToken/saveToken` | PU-B, PU-G | PW-B-backoffice | Headless: good (PU-B and PU-G done, PU-B18 `wip`). Front end: PW-B-backoffice all ten cases green locally on WordPress (`wip`); DEF-63 and DEF-65 are open and pinned, DEF-64 is core's, DEF-66 (a warning on every saved-card payment) was found and fixed |
| FEAT-5 | Update billing details for a recurring contribution (staff form) | `updateSubscriptionBillingInfo` (`VerifyUpdateToken` was unreachable and has been deleted, DEF-13) | PU-N, PU-K | PW-F4 | Headless: good (PU-N, PU-C done; PU-K blocked). Front end: PW-F4 all seven cases green on WordPress (`wip` until the other CMSs run) |
| FEAT-6 | Pending transaction verification queue and job | `EwayContributionTransactions` API, `Utils::validatePendingTransactions` | PU-E | PW-F5 | Headless: good (PU-E, 12 of 12). Front end: PW-F5 all four cases green on WordPress (`wip` until the other CMSs run) |
| FEAT-7 | Token metadata backfill | `PaymentToken::fillTokensMeta`, `EwayRecurring.fillTokensMeta` | PU-G | PW-B-jobs | Headless: good (PU-G done, including the backfill errors). Front end: PW-B-jobs-03 and PW-B-cron-08 green locally on WordPress (`wip`) |
| FEAT-8 | Settlement Sync | `SettlementSync`, `EwaySettlement.Sync` | existing `SettlementSyncTest`, PU-M | PW-B-settings | Headless: good. Front end: none |
| FEAT-9 | Settings form | `Form/Settings.php`, `settings/eWAYRecurring.setting.php` | PU-J | PW-B-settings | Headless: good (PU-J1 to J7 done, J8 to J10 wip). Front end: PW-B-settings all eight cases green locally on WordPress (`wip`) |
| FEAT-10 | Failed Recurring Contributions listing and Reactivate action | `ang/`, `managed/`, `searchKitTasks` hook | PU-O | PW-B-failed | Headless: good (PU-O done). Front end: PW-B-failed all twelve cases green locally on WordPress (`wip`); DEF-62 (the list does not load for users who are not administrators) is open and pinned |
| FEAT-11 | System status check for stuck failures | `ewayrecurring_civicrm_check` | PU-H | PW-B-status | Headless: good (PU-H11, H12 done). Front end: PW-B-status all three cases green locally on WordPress (`wip`) |
| FEAT-12 | Cancel / Update Subscription form behaviour | `buildForm` hook, `getEditableRecurringScheduleFields`, `eway.js` | PU-H, PU-N | PW-B-recurforms | Headless: good (PU-H, PU-N done). Front end: PW-B-recurforms all five cases green locally on WordPress (`wip`); DEF-59 fixed, DEF-60 open |
| FEAT-13 | Permissions (`view/edit payment tokens`) | `APIWrapper::authorize` | PU-I | PW-B-permissions | Headless: good (PU-I done). Front end: PW-B-permissions all three cases green locally on WordPress (`wip`) |
| FEAT-14 | Managed entities, install, enable, upgrade | `ewayrecurring_civicrm_managed`, `Upgrader`, civix schema | PU-H, PU-L, UP | PW-B-jobs, PW-B-processor | Headless: good (PU-H, PU-L done and the upgrade workflow, UP 10 of 11). Front end: PW-B-jobs and PW-B-processor green locally on WordPress (`wip`) |
| FEAT-15 | Payment processor admin validation | `ewayrecurring_civicrm_validateForm` | PU-H | PW-B-processor | Headless: good (PU-H done). Front end: PW-B-processor all four cases green locally on WordPress (`wip`) |

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
  playwright.config.ts          chromium; one worker (the stub is shared), CI: 60s timeout, retries 1, trace on failure
  tsconfig.json
  README.md
  fixtures/
    cms.ts                      CMS=wordpress|drupal|joomla|standalone: frontUrl(), adminUrl(), CIVI_CV
    global-setup.ts             logs each persona in once per CMS and saves the session (storageState)
    base.ts                     personas as pre-authenticated pages, problem collector, cms-skip helper
    civi.ts                     cv api4 / cv ev / cv scr wrappers via CIVI_EXEC_PREFIX and CIVI_CV
    eway-stub.ts                scripts the stub and logs what it was asked; hosted-page-server.ts serves the fake hosted page
    test-users.json             usernames, passwords, roles (single source of truth)
    test-data.json              names of seeded processors, pages, contacts, tokens
    setup-environment.sh        WordPress: roles, users, pages; then common steps below
    setup-accounts-drupal.sh    Drupal: roles and users with drush (not written yet)
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

CI: `.github/workflows/frontend-tests.yml` is identical in shape to the sibling repos (and so are its triggers: pushes to
master, pull requests and on demand, as in the Elections and Event Locations repos; the PHPUnit and upgrade workflows
use the same triggers), calling
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
   fake host (for example `http://eway-stub.test:4580/pay?AccessCode=...`). The browser is told to
   resolve that host to the machine running the tests (`--host-resolver-rules`), and a small web
   server started by `global-setup.ts` answers with a page with a card form and "Pay" / "Cancel"
   buttons that sends the browser on to the `RedirectUrl` / `CancelUrl` with the `AccessCode`
   appended, as eWAY's page does. It is identical on every CMS and needs nothing installed on the
   site. (Playwright's `page.route()` was tried first and does not work: it does not see the
   redirect from the site to another host, which is how the extension sends the donor there.)

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
| `finance` | `access_civicrm`, `view_all_contacts`, `access_civicontribute`, `edit_contributions`, `view_payment_tokens`, `edit_payment_tokens` | Failed list, back-office payments, tokens |
| `tokenviewer` | `access_civicrm`, `view_all_contacts`, `view_payment_tokens` | read-only token access |
| `limited` | `access_civicrm` only | negative permission tests |
| anonymous | none | public contribution page, event registration, direct endpoint hits |

`finance` and `tokenviewer` also have `view all contacts`. Without it CiviCRM's contact access control hides every
saved card from them: a token read returns an empty list and a delete removes nothing, with no error (found by
PW-B-permissions-01). A real finance user needs it anyway to open a contact's page.

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
`Confirmed bug`, `Intended` (documented as deliberate, test kept as a guard), `Fixed` (link the commit or release in the change log). While the fix is only on the release
branch the status reads `Fixed on `release-3.0.0``; change it to the release number once it ships.

| # | Status | Where | Observation | Test that settles it |
| --- | --- | --- | --- | --- |
| DEF-1 | Fixed on `release-3.0.0` | `CRM_Core_Payment_eWAYRecurring::getInstance()` | `$mode = empty($paymentProcessor['is_test']) ? 'test' : 'live'` looks inverted (a live processor gets mode `test`). `Utils::validateEwayContribution` then filters contributions by `is_test` from that mode. `VerifyPayment` builds the object with the correct mapping. Whether core ever routes through `getInstance` for these paths needs confirming. | PU-A5, PU-D12 |
| DEF-2 | Fixed on `release-3.0.0` | `doPayment()`, repeat-submit branch | `$priorAccessCode` is the access code **string**, but is then read as `$priorAccessCode['status']` and `['failed_message']`. That is never a valid lookup, so the "already succeeded / already failed" shortcuts cannot work and, on PHP 8, throw. | PU-B12, PW-F1-07 |
| DEF-3 | Fixed on `release-3.0.0` | `ewayrecurring_civicrm_check()` and `get_scheduled_failed_contributions()` | The check and the Failed Recurring list look for recurring status `Failed`/`Failing`, but `mark_recurring_contribution_failed()` deliberately does not set that status (CIVIEWAY-125). It is not clear what ever moves a recurring contribution to Failed once retries are exhausted, so the status check and the "Failed" listing may never show the contributions they exist for. | PU-F14, PU-H12, PW-B-failed-02 **Update:** PU-F14 and PU-F15 confirm it, and show more: the code that restores a recurring contribution and records a success activity after retries is unreachable, because it needs status Failed while the retry list only selects In Progress. **Fix proposed on branch `release-3.0.0`:** it marks the record Failed when `failure_count` reaches the retry limit and makes the success-after-retry branch reachable. **Playwright:** the back office is where this shows, so PW-B-cron-06, PW-B-cron-07, PW-B-failed-11, PW-B-failed-12, PW-B-status-03 and PW-B-recurforms-05 cover it (the last checks the CIVIEWAY-125 "UI melts down" concern on real screens, which PHPUnit cannot). On the front end only PW-F4-06 applies: the update-billing page is the one place a donor touches a Failed record, and what it does there is DEF-13. The contribution page, event, verification queue and endpoint specs are not affected. |
| DEF-4 | Fixed on `release-3.0.0` (defensive, not reproduced) | `Utils::validatePendingTransactions()` | `require_once 'vendor/autoload.php'` is relative to the current working directory, so it can fail when the job runs from another directory. | PU-E12 **Update:** PU-E12 runs the job from another directory and it succeeds in the test environment, so this may only fail where PHP's include path does not cover the extension. Low priority. |
| DEF-5 | Fixed on `release-3.0.0` | `VerifyPayment::run()` | `$response` is undefined if the call throws; duplicated `catch (CRM_Core_Exception)` blocks. Harmless today but fragile. | PU-K3 |
| DEF-6 | Fixed on `release-3.0.0` | `process_recurring_payments()` amount handling | `$amount_in_cents` is derived with a regex that strips the decimal point, which is only right for amounts with exactly two decimals ("10.5" would become 105 cents). | PU-F8 **Update:** the database stores amounts with exactly two decimals, so the only effect is a leading zero for amounts under a dollar ("099"). PU-F8. |
| DEF-7 | Fixed on `release-3.0.0` (dead code removed) | `ProcessTrait::send_receipt_email` | Unused (the only call site is commented out). Dead code. | none, flag only |
| DEF-8 | Fixed on `release-3.0.0` (robustness: a fresh install already ended at the newest step) | `ewayrecurring_civicrm_postInstall()` | Sets schema version 20200 while upgrade steps run to 20601. Check a fresh install does not replay or skip steps. | PU-L2 |
| DEF-9 | Fixed on `release-3.0.0` | `saveToken()` | `$cid` is parsed from the eWAY customer `Reference` with a regex and used with no check that it exists, and `queryTransaction(...)->Transactions[0]` is dereferenced with no check. Malformed or hostile responses cause a fatal. | PU-G9, PW-S-02 |
| DEF-10 | Fixed on `release-3.0.0` | `ProcessTrait::mark_recurring_contribution_failed()` and the success branch of `process_recurring_payments()` | The "eWay Transaction Failed" and "eWay Transaction Succeeded" activities are never created on CiviCRM 6.16: the code passes the option's label to `Activity.create`, which only accepts the name (`eWay_Transaction_Failed`), so the call is rejected and the exception is caught and logged. Found while writing PU-F13. | PU-F13, PU-F15 |
| DEF-11 | Fixed on `release-3.0.0` | `ProcessTrait::process_payment()` | The guard against two consecutive responses with the same TransactionID uses `static $prev_response; $prev_response = &$eWayResponse;`. PHP does not keep a reference assigned to a static between calls, so the guard never fires. CiviCRM's own duplicate check catches it later (the second charge ends Pending). | PU-F20 |
| DEF-12 | Fixed on `release-3.0.0` | `process_recurring_payments()` | A contact with no address at all makes the job read `$billing_address['id']` on null, raising a PHP warning. The charge still proceeds. | PU-F24 |
| DEF-13 | Fixed on `release-3.0.0` | `updateSubscriptionBillingInfo()` and `VerifyUpdateToken` | Found while planning the DEF-3 Playwright cases, from reading the code only. Core's Update Billing form (the staff form, reached from the contact's Recurring Contributions tab) saves the new token and billing address but does not reset `failure_count`, `failure_retry_date` or a Failed status, so a Failed record stays Failed after the card is updated until staff use Reactivate. The code that does reset them, `VerifyUpdateToken` (its comment says updating billing details should fix failed contributions), is never reached: its URL is only built when `getSuccessfulPaymentReturnUrl()` is given a recurring record, and its only caller never passes one. If it were reached it would reset the count but leave the retry date set, which stops the record being picked by either cron list. **Decision (2026-11-14):** the donor path should recover a Failed record. Expected: updating billing on a Failed record sets it to In Progress, `failure_count` 0 and clears `failure_retry_date`, leaving the schedule date alone (the same result as Reactivate). Not yet decided: whether to delete the unused `VerifyUpdateToken` page, and what a record that is still being retried should do (today it keeps retrying, now with the new token). | PU-N5, PW-F4-06 **Fix:** `updateSubscriptionBillingInfo()` now sets a Failed record to In Progress with `failure_count` 0 and the retry date cleared, leaving the schedule date; `VerifyUpdateToken`, its route and the dead `UpdateBilling` branches in the return-URL functions were deleted. | PU-N5, PW-F4-06 |
| DEF-14 | Fixed on `release-3.0.0` | `updateSubscriptionBillingInfo()` | Found while answering whether an updated card is verified; from reading the code only. The token id comes from the request (`contact_payment_token`) and is looked up by id alone: nothing checks that the token belongs to the recurring record's contact, belongs to the same payment processor, or has not expired. The browser script only offers the contact's own unexpired tokens, but the server does not enforce it. A user who can open the form for their own recurring record could post another contact's token id and have that card charged by the cron. Token ids are sequential. Two related observations, not defects: adding a card makes no authorisation call with the issuer (a bad card is found at the next charge, PW-X-05), and the stored-card list is loaded with the PaymentToken API, which needs staff permission, so a donor with only "make online contributions" may see "No cards found." (PW-F4-07). | PU-N6, PU-N7, PW-S-05 **Fix:** the chosen card is now loaded and checked before anything is saved: it must exist, belong to the recurring record's contact and processor, and not be expired, otherwise the update is refused and nothing changes (the billing address used to be saved before the card was even looked up). |
| DEF-15 | Fixed on `release-3.0.0` | `ewayrecurring_civicrm_enable()` | The enable hook updates the processor type through APIv4 with permission checks on, so enabling the extension with nobody logged in (`cv ext:enable`, an installer or a deployment script) fails the whole install with "Authorization failed". Found when the upgrade test installed release 2.8.1 with `cv`; present in 2.8.1 and 2.8.2. The fix turns the permission check off for that call. Installing from the admin screens was never affected. | PU-H3, PU-H13, UP-01 |
| DEF-16 | Fixed on `release-3.0.0` | `CRM_Core_Payment_eWAYRecurring::validateContribution()` | The method sets `$this->_is_test`, which the class never declared. PHP 8.2 deprecates creating a property on the fly, and the notice printed on the donor's thank you page wherever PHP errors are displayed. Core also reads the property in some return-address code, where it could be undefined. Found by the first PW-F1 run, which fails a test on PHP output. The class now declares it, defaulting to false. | PU-A6, PW-F1-05 |
| DEF-17 | Fixed on `release-3.0.0` | `ProcessTrait::process_recurring_payments()`, `Utils::validateEwayAccessCode()`, `CRM_Core_Payment_eWAYRecurring::doPayment()` | A declined answer without a ResponseMessage is split with `explode(', ', null)`, which PHP 8.1 deprecates. Seen in the PHPUnit output. The three calls now cast the message to a string, which is what older PHP did. | PU-F28 |
| DEF-18 | Fixed on `release-3.0.0` | `CRM_Core_Payment_eWAYRecurring::getEWayClientDetailsArray()` | The customer sent to eWAY is built with `substr()` on the billing street, city, state, postcode and email, any of which can be null (a donor with no state or email is common); PHP 8.1 deprecates that. Seen in the PHPUnit output of the update billing tests. The values are cast to strings. | PU-N9 |
| DEF-19 | Fixed on `release-3.0.0` | `doPayment()`, back-office branch | With no card selected the token id is empty, and APIv3 ignores a NULL filter, so `PaymentToken.getsingle` returns the only token on the site when there is exactly one, and that token is charged whoever owns it. The branch now refuses an empty id. Not fixed: the branch still does not check that the token belongs to the contact or the processor (staff only, the same gap as DEF-14). | PU-B14 |
| DEF-20 | Fixed on `release-3.0.0` | `doPayment()`, back-office branch | `$result['is_error']` was read from a `getsingle` result, which never carries that key: an undefined key warning on every back-office payment. Found by the first GitHub run of the PU-B back-office tests. | PU-B13, PU-B15 to B17 |
| DEF-21 | Fixed on `release-3.0.0` | `ewayrecurring_civicrm_check()` | The check built `CRM_Utils_Check_message` (lower case `m`). On a case-sensitive file system the class is not found, so the system status check crashed whenever there were stuck failed recurring contributions. Found by the first GitHub run of the PU-H tests. | PU-H11, PU-H12 |
| DEF-22 | Fixed on `release-3.0.0` | `ewayrecurring_civicrm_preProcess()` (Thank You page) | `$validated[$invoiceID]` was read but never set, so eWAY was asked on every build of the page instead of once per invoice. | PU-H10 |
| DEF-23 | Fixed on `release-3.0.0` | `CRM_eWAYRecurring_PaymentToken::fillTokensMeta()` | Per-token errors were collected into `$result` but the function returns `$results`, so `errors` and `is_error` never came back to the caller. | PU-G5 |
| DEF-24 | Fixed on `release-3.0.0` | `CRM_eWAYRecurring_PaymentToken::createToken()` | `Contact.getsingle` throws for an unknown contact, so the "no contact found" answer was unreachable. The lookup now catches the exception and answers with the JSON error. | PU-G10 |
| DEF-25 | Fixed on `release-3.0.0` | `CRM_eWAYRecurring_Upgrader::upgrade_7()` | The step ran `ALTER TABLE ... ADD is_email_receipt` unconditionally, so it throws if run twice and on a site whose table already has the column. It now checks for the column first. | PU-L3 |
| DEF-26 | Fixed on `release-3.0.0` | `changeSubscriptionAmount()` | Read `next_scheduled_date` and `next_scheduled_date_time` without checking they exist: an undefined key warning on PHP 8 when the form is saved without the schedule fields. | PU-N1 |
| DEF-27 | Fixed on `release-3.0.0` | `ewayrecurring_civicrm_config()`, `APIWrapper::authorize()` | The token permission listener ran at the same priority as core's own check, after it. Core's APIv3 check throws as soon as it fails (for PaymentToken it asks for `administer CiviCRM`), so a user with only `view payment tokens`, `edit payment tokens` or `access CiviContribute` was denied on APIv3 (APIv4 worked). The listener now runs just ahead of core and stops the event once it has authorised. Denials are unchanged. Found by the first GitHub run (six APIv3 allowed cases failed before the fix). | PU-I4 |
| DEF-28 | Fixed on `release-3.0.0` | `doPayment()`, back-office branch | The card id comes from the request and was looked up by id alone: nothing checked that the card belonged to the contact being charged, was saved for this processor, or had not expired. Staff only, but the same gap as DEF-14 on the update-billing path. The branch now refuses a card that fails any of those checks, and one it cannot check because the contact id is missing. | PU-B14 |
| DEF-29 | Fixed on `release-3.0.0` (dead code removed) | `doPayment()`, shared-page branch | The transaction type was `MOTO` when `isBackOffice()` and the `cvv_backoffice_required` setting was off. That branch could never run: back office payments take the saved card branch above, and the extension never registered the setting. The type is now plainly PURCHASE. | PU-B4 |
| DEF-30 | Fixed on `release-3.0.0` (dead code removed) | `doPayment()` | The cents were computed from `$params['amount']` with the commas and spaces stripped, but core's `PropertyBag`, built first, refuses a non-numeric amount, so the stripping never ran. The cents now come from the property bag. | PU-B2 |
| DEF-31 | Fixed on `release-3.0.0` | `Utils::sanitizeToAscii()` | `empty($string)` is true for the string `"0"`, so a description or reference of exactly 0 was replaced by an empty string. It now only treats NULL, FALSE and the empty string as empty. | PU-A8 |
| DEF-32 | Fixed on `release-3.0.0` (testability) | `EwaySettlement.Sync` API, `CRM_eWAYRecurring_SettlementSync` | The API and the scheduled job build their own sync with a real HTTP client, so a scoped call with an eligible contribution could only be tested against the real gateway. `SettlementSync::setHttpClientFactory()` now lets a test replace the client the sync builds when none is passed (NULL in production). | PU-M1 |
| DEF-33 | Intended (not a defect) | `updateSubscriptionBillingInfo()`, `PaymentToken::createToken()` | Adding a card makes no authorisation call with the card issuer, so a bad card is only found at the next charge (which then goes through the retry and Failed path). The extension was never meant to check a card when it is added, so this is a missing feature, not a defect. It is planned as feature FR-3 in [FEATURES.md](FEATURES.md), with what eWAY's documentation offers (an `Authorise` pre-authorisation on a token) and the research notes. Kept as a record so the ID is not reused. | PW-X-05 |
| DEF-34 | Intended (not a defect) | `js/eway.js`, `PaymentToken` API permissions | The donor update-billing page loads the stored card list through the PaymentToken API, which needs a staff permission, so a donor with only "make online contributions" would see "No cards found." The function is not offered to donors today: a donor-facing update-card page is the planned feature FR-1 (see [FEATURES.md](FEATURES.md)), which will give donors a way to read only their own cards. Kept as a record so the ID is not reused, and so FR-1 starts from it. | PW-F4-07 |
| DEF-35 | Fixed on `release-3.0.0` | `ewayrecurring_civicrm_validateForm()` | The hook read the `entity_id` of the managed record for the processor type with `->first()['entity_id']`, on every form on the site. Where that record is missing (a site whose processor type pre-dates managed entities) the read is on null and prints "Warning: Trying to access array offset on null" to the donor, on any form that re-displays after a validation error. Found by the first PW-F1-02 server-side run on a local site. The hook now looks the processor type up by name and only on the processor admin form. | PU-H5, PW-F1-02 |
| DEF-36 | Fixed on `release-3.0.0` | `Utils::updateCustomerDetails()` | The dedupe rule for saved cards (same contact and payment processor with the same token or the same masked card number is the same card) was only applied by the browser-side `saveToken()`. On the contribution page path a new recurring record has no card, so a token row was always created: a returning donor with a saved card got a second identical row each time they set up a recurring payment, and staff saw duplicate cards. The function now applies the same rule before creating a row. Found by PW-F2-06, whose plan row expected one row. | PU-D10, PW-F2-06 |
| DEF-37 | Open | `Utils::deleteRecurringContribution()`, `Utils::validateContribution()` | A declined first payment of a recurring contribution leaves the new recurring record behind in status Pending, with the placeholder `processor_id` and no card. The function written to delete it, `deleteRecurringContribution()`, is private and never called (dead code). The cron ignores the record (it needs a completed payment), so no one is charged, but the record is clutter on the contact's Recurring Contributions tab and is easy to mistake for a live schedule. Decision needed on the fix: delete it, as the dead function intended, or mark it Cancelled or Failed so the history is kept. Found by PW-F2-05. | PU-D, PW-F2-05 |
| DEF-38 | Open | `Utils::completeEWayTransaction()`, `CRM_Core_Payment_eWAYRecurring::validateContribution()` | The verification queue row is set to status 1 (`STATUS_SUCCESS`) for every transaction that eWAY has answered, including a declined one, because `completeEWayTransaction()` is called on the failure path too. The status name says success while the contribution is Failed, which misleads anyone reading the queue table or building a report on it. The behaviour is the same on the once-off, recurring and event paths. Decision needed: rename the meaning (processed), add a separate failed status, or set the row by the outcome. Found while writing PW-F2-05. | PU-D11, PW-F2-05 |
| DEF-39 | Open | `doPayment()` and core's duplicate-invoice check | A donor who presses Back from eWAY's hosted page and then Continue on the Confirm page is shown core's raw message "Duplicate error - existing contribution record(s) have a matching Transaction ID or Invoice Reference". Nothing is charged twice, but the message is meant for staff and gives the donor no way forward except starting again. Found by PW-F1-07. | PW-F1-07 |
| DEF-40 | Open | `ewayrecurring_civicrm_preProcess()` (Confirm step) | A donor or registrant who goes Back to the Confirm step after eWAY already has their payment is refused by a thrown `PaymentProcessorException` ("Payment already completed for this Contribution" or "...Registration"). CiviCRM answers with its fatal error page and an HTTP 500. The refusal is correct and deliberate, but a 500 error page is not a message a donor should see, it shows in monitoring as a server error, and the donor is not told what to do next (check email for the receipt, or continue to Thank You). Found by PW-F1-07 and PW-F3-04. | PW-F1-07, PW-F3-04 |
| DEF-41 | Fixed on `release-3.0.0` | `updateSubscriptionBillingInfo()` | When the Update Billing form is opened as a full page, pressing Save ended on "Missing contribution id and token id." and changed nothing. The method read `crid` from the request, but the form's POST does not carry it (core strips the query string from the form action). The dialog on the contact's tab posts to its own loaded address, so it worked, and PHPUnit missed it because those tests set `$_REQUEST['crid']`. Core already passes the id as `$params['contributionRecurID']`, which is now used when `crid` is absent. Found by PW-F4-02. | PW-F4-02 |
| DEF-42 | Fixed on `release-3.0.0` | `getPaymentFormFieldsMetadata()` | The `add_credit_card` field had no `is_required`, and core 6.16.5 reads it unguarded when it builds the payment form: "Undefined array key is_required" printed on every page showing the form (Update Billing and the back-office credit card form). The field now says `is_required => FALSE`. Found by PW-F4-01. | PW-F4-01 |
| DEF-43 | Fixed on `release-3.0.0` | `js/eway.js`, `CRM.eway.updateOptions()` | After a card is added on the form opened as a full page, `savetoken` answers with the API result object, which was passed to `updateOptions()` and looped over as if it were the card list: "values is not iterable". The list was still reloaded by a separate call, so staff saw nothing wrong. `updateOptions()` now ignores anything that is not a list. Found by PW-F4-02. | PW-F4-02 |
| DEF-44 | Fixed on `release-3.0.0` | `js/eway.js`, `CRM.eway.updateOptions()` | The card list was written with `$select.find('option').replaceWith(html)`, which puts the whole list in place of every option already in the select. A select that already held the cards (as on a fresh site) showed each card twice, with two options both selected-looking, so staff could not tell which card was current. It now replaces the options once with `html()`. Found on GitHub by PW-F4-02 (the local site started with a single placeholder option, which hid it). | PW-F4-02 |
| DEF-45 | Open | Contact tab (core) and the extension's links | Staff cannot reach the Update Billing form for a Failed recurring record from the contact's Recurring Contributions tab. Core offers Change Billing Details only on the Active rows; Failed, Cancelled and Completed records are under Inactive with a View link only. The recovery in DEF-13 (updating the card puts a Failed record back In Progress) is therefore only reachable by typing the form's address. Decision needed: have the extension add the link for Failed records (the Failed Recurring Contributions list already has a Reactivate action), or document that Reactivate is the staff route. Found by PW-F4-06. | PW-F4-04, PW-F4-06 |
| DEF-46 | Open | `ProcessTrait::process_recurring_payments()` | The first failed cron run already moves `next_sched_contribution_date` one interval ahead, so a record that ends up Failed has skipped the payment it failed on. After the card is updated the record is In Progress with the schedule date unchanged, so the next charge only happens when that date arrives, not on the next cron run. This is a choice on how a recovered record should behave (decision D3 of FR-1) and the plan row for PW-F4-06 now says so, but it is surprising for staff who update a card to fix a failure and expect a charge. Found by PW-F4-06. See also DEF-58 (a retry that succeeds then skips a month). | PW-F4-06 |
| DEF-47 | Fixed on `release-3.0.0` (security, high) | `Utils::validateContribution()`, `validateEwayAccessCode()`, the `verifypayment` return page | The return page takes the invoice id and the access code from the address and never compared eWAY's transaction with the contribution: only its approved or declined status was read. Any approved access code, including a small payment of the visitor's own or one paid earlier, completed any Pending contribution whose invoice id the visitor held, for any amount, and a declined code from another payment failed it. Confirmed by completing a Pending $25 contribution with an approved $1 access code. Overlaps PW-S-02. The transaction's InvoiceReference must now equal the contribution's invoice id and its TotalAmount the contribution's amount in cents, otherwise the return is refused (400) and nothing changes. A response with no InvoiceReference (an error answer, or the stub) has nothing to compare and is left to the other checks. Found by PW-F6-01. | PU-D7, PW-F6-01, PW-S-02 |
| DEF-48 | Fixed on `release-3.0.0` | `verifypayment` return page, `PaymentToken::getPaymentProcessorById()` | The processor id in the address was not checked to be an eWAY processor. Naming another type (the seeded Dummy one) built an eWAY client with no credentials, printed two PHP warnings with the server's file path, treated the missing client as a declined payment, marked the Pending contribution Failed and redirected to its cancel step. The id must now name an eWAY processor (`Utils::isEwayProcessor()`), otherwise the return answers 400 and `createtoken` and `savetoken` answer "no payment processor found". Found by PW-F6-01 and PW-F6-04. | PU-D12, PW-F6-01, PW-F6-04 |
| DEF-49 | Fixed on `release-3.0.0` | `PaymentToken::createToken()` | An unknown or empty country or state id made the lookup result null and the code read a key from it, printing "Trying to access array offset on null" in front of the JSON answer. The lookups now fall back to an empty value and the "Missing field" answer follows. Found by PW-F6-04. | PW-F6-04 |
| DEF-50 | Fixed on `release-3.0.0` | `CRM_Core_Payment_eWAYRecurring::validateContribution()` | The return page marked the verification queue row done whatever eWAY answered, including "not processed yet" (the visitor returned before eWAY had a result). The verification job only looks at rows still waiting, so the contribution or registration stayed Pending for good although eWAY later approved the payment. A worse case of DEF-38. The row is now only settled when eWAY has an answer. Found by PW-F6-06. | PU-D11, PW-F6-06 |
| DEF-51 | Fixed on `release-3.0.0` | `verifypayment` return page | A processor id that was not a whole number (letters, a blank, `7 OR 1=1`) reached the CiviCRM API, which threw, so the visitor got CiviCRM's own fatal page ("id is not a valid integer", HTTP 200) and, for a list, an internal PHP message from the database layer. The id must now be digits only, otherwise 400 with an empty body. Found by PW-F6-01. | PW-F6-01 |
| DEF-52 | Fixed on `release-3.0.0` | `Form/Settings.php` `validate()` | The retry limit and the retry delay were checked with `is_numeric()`, so -1, 2.5 and 1e2 were accepted and saved exactly as typed (and " 5" with its space). A count of retries or days is a whole number of zero or more. The check now requires digits only (spaces around them are allowed and trimmed on save); the message is unchanged. Found by PW-B-settings-03. | PU-J8, PW-B-settings-03 |
| DEF-53 | Fixed on `release-3.0.0` | `ewayrecurring_civicrm_enable()`, the managed jobs | Disabling the extension makes core switch its managed scheduled jobs off, and enabling it again did not switch them back on: core reconciles the managed records after the enable has finished, when the extension no longer counts as being enabled, and a job is then left as it is. The recurring payments, transaction verification and token metadata jobs stayed off for good with nothing to say so (no payments charged, no pending payments verified). The enable hook now switches back on the jobs the extension declares active; the settlement sync job is shipped off and stays off. Found by PW-B-processor-04. | PU-H14, PW-B-processor-04 |
| DEF-54 | Fixed on `release-3.0.0` | `Form/Settings.php` `postProcess()` | Saving the settings redirected straight back to the page with no message, so an administrator could not tell it had worked. It now shows "The eWay Recurring settings have been saved." Found by PW-B-settings-04. | PU-J9, PW-B-settings-04 |
| DEF-55 | Fixed on `release-3.0.0` | `Form/Settings.php` | The form set `$this->_submittedValues` without declaring it, a "Creation of dynamic property" deprecation on PHP 8.2 and later, printed in front of the redirect on every save. Declared now. Seen in the PHPUnit output while writing PU-J8. | PU-J10 |
| DEF-56 | Pinned | `ewayrecurring_civicrm_validateForm()` | The processor admin form always requires the LIVE API Key and API Password for an eWay Recurring processor. A processor with only test credentials (for the eWAY sandbox, say) is refused, although CiviCRM's own check says at least the test or live section must be filled. A processor saved with live credentials only also gets a test row with empty credentials. Decision needed: allow test-only credentials, or keep live required. PW-B-processor-02 records today's behaviour. | PW-B-processor-02 |
| DEF-57 | Open | `ewayrecurring_civicrm_managed()`, upgrade | A site whose eWay Recurring processor type was created before the extension managed it (the local development site) has no managed record for it. Every cache flush then logs "Unable to create managed entity eWay_Recurring: DB Error: already exists", and disabling the extension does not deactivate the type. The processor admin hook already has a comment about such sites. Decision needed: an upgrade step that links an existing type to the managed record. Not seen on a fresh site; check the CI run. Found while writing PW-B-processor-04. | PW-B-processor-04 |
| DEF-58 | Open | `ProcessTrait::process_recurring_payments()` | A recurring record that fails and then succeeds on a retry ends with its next payment two intervals after the due date. The first failed run already moves `next_sched_contribution_date` one interval on (DEF-46), and the retry's success moves it on again from that date, so a monthly record due on 14 November that failed and was charged on a retry is next due on 14 January: one month's payment is skipped. A record charged first time moves one month. It needs the same decision as DEF-46 (whether a failure should move the date at all): taking the second move out of the retry branch alone would be wrong for a record that arrives in the retry state some other way (PU-F15 seeds one). Found by PW-B-cron-07, pinned with `test.fail()`. | PW-B-cron-07 |
| DEF-59 | Fixed on `release-3.0.0` | `ewayrecurring_civicrm_buildForm()` | The Cancel Recurring Contribution form for an eWAY record printed four PHP warnings ("Undefined array key send_cancel_request", "Trying to access array offset on null", twice each) in core's `Field.tpl`: the hook removed the form element, but core's template still draws a row for every entity field. The hook now takes the field out of the assigned list too. Found by PW-B-recurforms-01 (the first run of the case failed on the warnings). | PU-H15, PW-B-recurforms-01 |
| DEF-60 | Open | `js/eway.js` `CRM.eway.modifyUpdateSubscriptionForm()`, `changeSubscriptionAmount()` | Dead code from before the Update Subscription form was handed back to core (CIVIEWAY-266). Nothing calls the script function, and core now draws the next date itself as `next_sched_contribution_date`, so the `next_scheduled_date` and `next_scheduled_date_time` parameters that `changeSubscriptionAmount()` reads are never sent. Harmless, but it misleads anyone reading the extension to see how the date is saved (core saves it). Decision needed: remove both, with their PU-N1 tests. Found by PW-B-recurforms-03 and 04. | PU-N1, PW-B-recurforms-03, PW-B-recurforms-04 |
| DEF-61 | Fixed on `release-3.0.0` | `ang/ewayrecurring/crmSearchTaskResetRecur.ctrl.js` | Opening the Reactivate dialog on the Failed Recurring Contributions list threw "Cannot set properties of undefined (setting 'statusLabel')" in the browser every time: the callback that looks up the "In Progress" label was a plain function inside strict mode, so `this` was undefined. The dialog still worked (the label stays at the English default), but the console error hid real ones. It is an arrow function now. Found by PW-B-recurforms-05; PW-B-failed-05 would have hit it too. | PW-B-recurforms-05 |
| DEF-62 | Open | `managed/SavedSearch_Failed_Recurring_Contributions.mgd.php`, `ang/afsearchFailedRecurringContributions.aff.php` | The Failed Recurring Contributions page and its menu entry need only "edit contributions", but the list's data request fails with HTTP 500 ("Invalid field ...PaymentProcessor_payment_processor_id_01.payment_processor_type_id" in the CiviCRM log) for every user who is not an administrator, `finance` included. The saved search joins the payment processor to keep only eWAY records, and APIv4 silently drops a join to an entity the user may not read (PaymentProcessor needs "administer CiviCRM"), so the field it filters on does not exist for them. The page opens, shows its Status box and no rows. Decision needed: require "administer CiviCRM" for the page and menu entry, or filter eWAY records without that join (for example a field the extension adds to ContributionRecur), or let the extension authorise that one read (not recommended: the entity holds the API credentials). Not fixed here because each option changes who can reach the list. Found by PW-B-failed-01, pinned with `test.fail()`. | PW-B-failed-01 |
| DEF-63 | Open | `js/eway.js` `CRM.eway.toggleCreditCardFields()` | On the back-office card form the "No contact selected" notice can never be seen: the loop over the card list sets it, and the loop over the inputs that follows overwrites it with the billing message or clears it. With no contact chosen and billing details complete (typed in by hand) the Add Credit Card button is on and the notice is empty, and pressing it asks eWAY's page for a contact id of 0. Not fixed because "no contact" cannot be told from "contact known only through the form's own variables" (`addCreditCard()` falls back to `CRM.vars.coreForm.contact_id`), so a change could switch the button off where it works today. Found by PW-B-backoffice-03, pinned with `test.fail()`. | PW-B-backoffice-03 |
| DEF-64 | Open | CiviCRM core (`CRM_Contribute_Form_Contribution::postProcess()`) | After a payment processor refuses a back-office card payment, core calls `CRM_Core_Error::statusBounce()` with the bare query string "action=add&cid=...&mode=live" as the redirect address, so the browser is sent to a broken address (`/wp-admin/action=add&cid=...`, a 404 page on WordPress) instead of back to the form. The message is kept and shows on the next CiviCRM screen, and the Failed contribution is recorded. Seen on CiviCRM 6.16.5 and not specific to this extension (any processor that throws would do the same). Decision needed: report it to CiviCRM; nothing to change here. Found by PW-B-backoffice-07, which allows the 404. | PW-B-backoffice-07 |
| DEF-65 | Open | `getPaymentFormFieldsMetadata()`, `js/eway.js` | The back-office card form opened for a recurring record (`crid` in the address) preselects that record's card only when eWAY is the processor the form starts on. When eWAY is chosen afterwards in the Payment Processor box (any site with more than one back-office processor), core asks for the card block without `crid`, so the script gets no record and selects the newest card instead, and staff can charge a different card than the record's without noticing. Decision needed: whether `crid` should survive a processor change (the block's request would have to carry it), or the record's card is advisory only. Found by PW-B-backoffice-08, pinned with `test.fail()`. | PW-B-backoffice-08 |
| DEF-66 | Fixed on `release-3.0.0` | `doPayment()`, back-office branch | A saved-card payment from the back-office form read `$params['is_recur']` unguarded, but core leaves that key out unless the Recurring box is ticked, so every one-off saved-card payment printed "Undefined array key is_recur" (an exception under PHPUnit's settings, a warning printed into the page on a site that shows warnings). `contributionRecurID` is read with the same guard. Found on the local site by reading the web server's PHP log after PW-B-backoffice-06 (that site hides warnings). | PU-B18, PW-B-backoffice-06 |

---

## 4. PHPUnit plan

All tests use the fakes from 2.2. IDs are grouped by test class.

### PU-A `ProcessorBasicsTest` (no gateway needed)

| ID | Status | Case |
| --- | --- | --- |
| PU-A1 | done | `checkConfig()` returns null with key and password, one message each when either is missing, both joined when both missing |
| PU-A2 | done | `getPaymentFormFields()` is empty on the front end, `contact_payment_token` and `add_credit_card` in back office |
| PU-A3 | done | `getPaymentFormFieldsMetadata()` shape: select is required, class `eway_credit_card_field`, button `onclick` calls `CRM.eway.addCreditCard()` |
| PU-A4 | done | `getBillingAddressFields()` maps each field with the billing location id |
| PU-A5 | done | `getInstance()` caches per `domain/id`, returns the same object twice, and sets `_mode` correctly for live and test processors (DEF-1, fixed: a live processor used to get mode `test`) |
| PU-A6 | done | `supportsBackOffice()` true; `getEditableRecurringScheduleFields()` returns the six documented fields |
| PU-A7 | done | `__serialize()` omits `eWayClient` and `instances`, and the object survives `serialize()` after a client was created |
| PU-A8 | done | `Utils::sanitizeToAscii()` data provider: empty, null, plain ASCII, accented Latin (transliterated), emoji, CJK, control characters, very long string |
| PU-A9 | done | `Utils::contribution_status_id()` for contribution and recurring variants |
| PU-A10 | done | `getEWayClientDetailsArray()`: field truncation (Reference 16, names 50, postcode 30), country given as ISO code, as name, as `country_id`, as billing location key, missing country throws 9007, `TokenCustomerID` included only when `subscriptionId` is set |
| PU-A11 | done | The test-mode flag `_is_test` is a declared property of the payment class (DEF-16, fixed: PHP 8.2 printed a deprecation about creating it on the fly on the donor's thank you page) |

### PU-B `DoPaymentTest` (needs S1)

| ID | Status | Case |
| --- | --- | --- |
| PU-B1 | done | Zero amount returns Completed without calling eWAY |
| PU-B2 | done | Amount parsing: `"10"`, `"0.05"`, `1234.50` become the right cents. Reworded: `"1,234.50"` never reached the conversion, because core's `PropertyBag` refuses a non-numeric amount first (a separate test pins that); the dead comma stripping was removed (DEF-30) |
| PU-B3 | done | Front-end payload: `RESPONSIVE_SHARED`, `SaveCustomer`, `Capture`, `CustomerReadOnly`, `Options.ContributionID`, `TransactionType` PURCHASE |
| PU-B4 | done | Back office is always MOTO and the contribution page is always PURCHASE. Reworded: the `cvv_backoffice_required` branch was unreachable, so "with the setting uses PURCHASE" cannot be written; the dead branch was removed (DEF-29) |
| PU-B5 | done | Invoice number is the first 12 characters of the invoice id; reference 50, description 64; blank description falls back to `Invoice ID: ...`; non-ASCII stripped |
| PU-B6 | done | `alterPaymentProcessorParams` hook runs and can change the payload |
| PU-B7 | done | Duplicate invoice (`checkDupe`) fails with the documented message and sets status Failed |
| PU-B8 | done | Gateway returns errors: `PaymentProcessorException` containing each translated message, params status Failed |
| PU-B9 | done | Null client response: failure message, no queue row |
| PU-B10 | done | Success creates one `EwayContributionTransactions` row (access code, contribution, processor, `is_email_receipt` defaulting to 1) and redirects to `SharedPaymentUrl` |
| PU-B11 | done | Session stores the serialised response under the `qfKey` |
| PU-B12 | done | Repeat submit: while the transaction is still open the hosted page is reused with no new eWAY call; an already successful payment is not charged again; an already failed one explains why; an existing transaction from another session is refused (DEF-2, fixed: this used to throw a TypeError) |
| PU-B13 | done | Back office: valid token sends `TokenCustomerID` (first 16 characters) and MOTO |
| PU-B14 | done | Back office: missing or unknown token fails with "valid credit card token" (DEF-19: a missing token used to charge the only token on the site; DEF-28: a card of another contact, another processor or an expired card is refused) |
| PU-B15 | done | Back office with `contributionRecurID`: `processor_id` and `payment_token_id` written to the recurring record |
| PU-B16 | done | Back office with `is_recur` but no recurring id: the "update the credit card manually" status message |
| PU-B17 | done | Back office success sets `payment_status_id` Completed and `trxn_id`; declined transaction fails with the translated message |
| PU-B18 | wip | Back office one-off payment: core leaves `is_recur` out of the parameters unless the Recurring box is ticked, and the payment runs with no warning and no "update the card by hand" reminder (DEF-66). Passes locally, `done` after a CI run |

### PU-C `ReturnUrlsTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-C1 | done | Success URL: custom `successUrl`, contribution page, default verify-payment URL carrying invoice id, qfKey, processor id, component |
| PU-C2 | done | Cancel URL: custom, contribution page, event register, back-office default |
| PU-C3 | done | `isFromContributionPage()` true and false |

### PU-D `ValidateContributionTest` (`Utils::validate*`, `completeEWayTransaction`)

| ID | Status | Case |
| --- | --- | --- |
| PU-D1 | done | `validateEwayAccessCode`: approved, declined (message prefixed "Your payment was declined"), eWAY errors (joined with comma), no response (error code 1001), transaction id empty means not processed yet **Note:** the "no response (error code 1001)" branch cannot be reached: the SDK always returns a response object, and a connection failure arrives as an error response, which is what is tested. |
| PU-D2 | done | `validatingUpdateToken = TRUE` skips the transaction status checks |
| PU-D3 | done | Approved pending contribution is completed via `completetransaction` with the transaction id and processor id |
| PU-D4 | done | Only Pending contributions are touched; an already Completed one is left alone |
| PU-D5 | done | Receipt flag comes from the queue row (`is_email_receipt` 0 and 1) |
| PU-D6 | done | Declined: contribution set to Failed with `trxn_id`, `is_email_receipt` 0 |
| PU-D7 | done | Not processed yet: nothing changes |
| PU-D8 | done | Recurring: token id read from either response location (`TokenCustomerID` at top level, or under `Transactions[0]`) **Note:** only the nested location can occur through the SDK (the response model has no top-level `TokenCustomerID`); the top-level branch is tested directly. |
| PU-D9 | done | Recurring approved: recurring record becomes In Progress, `processor_id` and `payment_token_id` set, `next_sched_contribution_date` computed from interval and unit, `trxn_id` stored |
| PU-D10 | done | `updateCustomerDetails`: creates a new `PaymentToken` (masked number, expiry as last day of month, billing name), updates the existing token when `payment_token_id` is set, returns null when the customer has no card details |
| PU-D11 | done | `completeEWayTransaction` marks the queue row success, silently ignores an unknown access code |
| PU-D12 | done | `validateEwayContribution` ignores non-eWAY processors, zero-amount contributions, unknown invoice ids, and respects live/test (`is_test`) |

### PU-E `PendingTransactionsTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-E1 | done | `EwayContributionTransactions` create / get / delete round trip, defaults (`status` 0, `tries` 0, `is_email_receipt` 1) |
| PU-E2 | done | Deleting the contribution or processor cascades to the queue row (FK) |
| PU-E3 | done | `validate`: success path, `tries` incremented, status Success, summary counts correct |
| PU-E4 | done | Failure path: status Failed, `failed_message` stored |
| PU-E5 | done | Failure on a recurring contribution calls `mark_recurring_contribution_failed` |
| PU-E6 | done | Not-processed-yet leaves status 0 and still increments `tries` |
| PU-E7 | done | Missing contribution or processor deletes the queue row and counts `deleted` **Note:** the processor case is removed by the foreign key before the job runs, so the job's own clean-up is tested with a contribution that is no longer Pending. |
| PU-E8 | done | Rows with `tries >= MAX_TRIES (7)` mark the contribution Failed and the row Failed |
| PU-E9 | done | Rows already Success or Failed are not re-queried |
| PU-E10 | done | `EwayContributionTransactions.validate` returns the four counters via the API |
| PU-E11 | done | Mixed batch of every outcome in one run |
| PU-E12 | done | Runs when the current directory is not the extension root (DEF-4: the autoloader is now loaded by absolute path) **Note:** the job did not fail from another working directory in the test environment, so this is a guard rather than a reproduction of the original problem. |

### PU-F `RecurringProcessingTest` (the highest-value suite)

Selection (`get_scheduled_contributions`, `get_scheduled_failed_contributions`):

| ID | Status | Case |
| --- | --- | --- |
| PU-F1 | done | Due In Progress and Pending recurring contributions are picked; future-dated ones are not |
| PU-F2 | done | Cancelled, Completed, Failed-status ones are not picked |
| PU-F3 | done | Recurring with `failure_retry_date` set is excluded from the normal list and included in the retry list when due |
| PU-F4 | done | Retry list honours `eway_recurring_contribution_max_retry` and requires `failure_count > 0` |
| PU-F5 | done | Excluded when no Completed contribution exists yet (CIVIEWAY-124) |
| PU-F6 | done | Excluded when a contribution already exists on or after the schedule date or retry date (no double charge) |
| PU-F7 | done | Only the current processor's records are processed when two eWAY processors exist |

Processing (`process_recurring_payments`, `process_payment`):

| ID | Status | Case |
| --- | --- | --- |
| PU-F8 | done | Amount to cents conversion (`10.00`, `10.50`, `1234.00`, tax inclusive), asserted as exact strings (DEF-6, fixed) **Note:** an amount under a dollar used to be sent with a leading zero ("099"); 0.99 is now sent as "99". |
| PU-F9 | done | Next schedule advances by interval and unit for day, week, month, year **Note:** CiviCRM core replaces the extension's date with payment date plus one interval when the date is under 48 hours ahead, so daily schedules always end one day after the payment. The test encodes that rule. |
| PU-F10 | done | Installments: schedule continues while installments remain, last installment marks the recurring contribution Completed with an end date, unlimited (`0`) never completes |
| PU-F11 | done | Success: new Completed contribution linked to the recurring record, `trxn_id`, source prefix "eWay Recurring ...", financial type, campaign, page id, `is_test` copied from the first contribution; `failure_count` reset, `failure_retry_date` cleared **Note:** a successful charge keeps the source copied from the first contribution; the "eWay Recurring" prefix is only applied when the charge fails (PU-F13). |
| PU-F12 | done | Receipt sent only when `eway_recurring_keep_sending_receipts` is on |
| PU-F13 | done | Decline: Failed contribution, Transaction Error note with eWAY messages, `failure_count` incremented, `failure_retry_date` set to today plus the configured delay at midnight, and an "eWay Transaction Failed" activity (DEF-10, fixed: the activity was never created before) |
| PU-F14 | done | Retries exhausted: the last failed attempt marks the recurring contribution Failed and keeps its retry date; earlier failures leave it In Progress; a record already at the maximum is not charged again and is in neither cron list (DEF-3 fix, decision 11) |
| PU-F15 | done | Success after earlier failures: recurring record In Progress with `failure_count` 0 and the retry date cleared, schedule updated, and an "eWay Transaction Succeeded" activity with the retry count (DEF-3 and DEF-10 fixes) |
| PU-F16 | done | Exception after a gateway response: contribution left Pending, queue row created with the transaction id as access code |
| PU-F17 | done | Exception before a gateway response: contribution Failed, recurring marked failed, Contribution Error note |
| PU-F18 | done | Token resolution order: `payment_token_id` token, fallback to legacy `processor_id`, missing token raises the "No eWAY token found" error and fails cleanly |
| PU-F19 | done | Race lock: second concurrent run exits early with a logged warning and returns false **Note:** the test releases the lock by hand: core defers `RELEASE_LOCK` while a transaction is open, which is always true in these tests. |
| PU-F20 | done | Two charges returning the same TransactionID in one run: the second is recognised as a duplicate by the extension's own guard and ends Failed with a note; consecutive refused charges (which have no TransactionID) are not mistaken for duplicates (DEF-11, fixed) |
| PU-F21 | done | Non-ASCII source or financial type text is sanitised in the eWAY payload |
| PU-F22 | done | `handlePaymentCron` and the `Job.run_payment_cron` API reach `process_recurring_payments` for processor `eWay_Recurring` **Note:** core runs the live and then the test instance of the processor type and fails if either is missing, so the test creates both. |
| PU-F23 | done | `mark_recurring_contribution_failed` still updates the recurring record when the activity create fails |
| PU-F24 | done | A contact with no address at all is charged without a PHP warning (DEF-12, fixed) |
| PU-F25 | done | A recurring contribution whose payment token row was changed in place (new `token` value, `expiry_date` and `masked_account_number` cleared, which is how a website-built update-card page replaces a card) is charged with the new token value, and `payment_token_id` is unchanged |
| PU-F26 | done | A Failed recurring contribution reset the way such a page resets it (In Progress, `failure_count` 0, retry date cleared) is charged on the first run on or after its schedule date: not before when the schedule date was moved into the future, and on the next run when the schedule was restarted from today (the two schedule choices a site can make) |
| PU-F27 | done | A recurring contribution cancelled while it is being retried (failures above 0, retry date due) is not retried, makes no gateway call and is left untouched, while one that is still being retried is charged in the same run (CIVIEWAY-125) |
| PU-F28 | done | A declined charge whose answer has no ResponseMessage raises no PHP deprecation (DEF-17, fixed: the message was split with explode() on null) |

### PU-G `PaymentTokenTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-G1 | done | `fillTokensMeta`: fills missing masked number and expiry for eWAY tokens only (other processor types untouched) |
| PU-G2 | done | Expiry becomes the last day of the month, year offset by 2000 |
| PU-G3 | done | Tokens that already have both fields are not queried |
| PU-G4 | done | Login failure for a processor skips its tokens without error |
| PU-G5 | done | Per-token query error is collected into `errors` with the token id and does not stop the batch |
| PU-G6 | done | Processor lookups are cached across tokens (one `PaymentProcessor.get` per processor) |
| PU-G7 | done | `getPaymentProcessorById`: found, unknown id returns null |
| PU-G8 | done | `saveToken` dedupe rule: same contact and processor with same token **or** same masked number updates the existing row, otherwise creates one |
| PU-G9 | done | `saveToken` refuses without a fatal and with nothing sent to eWAY: no `pp_id` (it only closes its own window), no access code, an unknown processor (documented JSON errors), no transaction, no token, an unknown customer, a customer reference that is not a contact, and no card details (DEF-9, fixed). **Note:** the no-`pp_id` exit was changed from `die()` to `CRM_Utils_System::civiExit()` so a test can catch it |
| PU-G10 | done | `createToken` rejects each missing billing field with `Missing field: ...`, unknown processor, unknown contact |
| PU-G11 | done | `fillTokensMeta` picks up a token whose expiry and masked number were cleared by an in-place update, and refills them from eWAY using the new token value |
| PU-G12 | done | `saveToken` stores the card for the contact named in the eWAY customer reference, with the processor, token, masked number and expiry (DEF-9) |

(`createToken` and `saveToken` call `die()` and `CRM_Utils_JSON::output()`. The JSON output throws
under the test framework, so the guard paths are testable as they are (PU-G9). Where `die()` still blocks
headless testing, either refactor the body into a testable method that returns an array and keep
the menu callback as a thin wrapper, or cover it through Playwright PW-B-backoffice and PW-S.)

### PU-H `HooksAndManagedTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-H1 | done | Managed entities exist after install: processor type `eWay_Recurring` (class `Payment_eWAYRecurring`, labels API Key / API Password, `is_recur`), activity types "eWay Transaction Failed" and "eWay Transaction Succeeded", four scheduled jobs with documented frequencies and active flags |
| PU-H2 | done | Settlement Sync job exists and is **disabled** by default (`Sync.mgd.php`) |
| PU-H3 | done | `hook_enable` reactivates the processor type if it had been deactivated |
| PU-H4 | done | `buildForm`: `send_cancel_request` removed for eWAY processors on `CancelSubscription`, kept for others |
| PU-H5 | done | `validateForm`: API Key and API Password required on the processor admin form for eWAY type, not required for other types |
| PU-H6 | done | `hook_permission` declares both token permissions with labels |
| PU-H7 | done | `navigationMenu` adds "eWay Recurring Settings" under Administer, permission `administer CiviCRM` |
| PU-H8 | done | `searchKitTasks` registers `ContributionRecur.reset_recur_status` with the module and dialog template |
| PU-H9 | done | `coreResourceList` adds the script and the `agilewareEwayExtension.paymentProcessorId` list of eWAY processor ids, and quietly does nothing when none exist |
| PU-H10 | done | `preProcess` Thank You: validates once per invoice (DEF-22), hides line items for quick-config price sets |
| PU-H11 | done | `preProcess` Confirm (contribution and event): already-paid access code throws the right message per component, failed prior transaction shows the warning and clears the session response, not-processed-yet continues |
| PU-H12 | done | `hook_check`: no message at zero failures, message with count and link when failures exist past the 4-hour threshold, ignores test recurring records, honours a disabled `StatusPreference`, honours the `statusNames` filter (DEF-3: only recurring contributions marked Failed are counted) |
| PU-H13 | done | `hook_enable` works when nobody is logged in (no permissions): the processor type is reactivated and nothing throws "Authorization failed" (DEF-15, fixed) |
| PU-H14 | wip | `hook_enable` switches the three active scheduled jobs back on and leaves the Settlement Sync job off (DEF-53) |
| PU-H15 | wip | `buildForm` on the cancel form: for an eWAY processor `send_cancel_request` is taken out of the template's list of entity fields as well as removed as an element (no PHP warnings on the screen, DEF-59); for other processors the list is left alone |

### PU-I `PermissionsTest` (`APIWrapper`)

| ID | Status | Case |
| --- | --- | --- |
| PU-I1 | done | `PaymentToken.get` allowed with `view payment tokens` or `access CiviContribute`, denied otherwise |
| PU-I2 | done | `PaymentToken.create/update/delete` need `edit payment tokens` or `access CiviContribute`; `view payment tokens` alone is denied |
| PU-I3 | done | Other entities are not affected by the listener |
| PU-I4 | done | Both APIv3 and APIv4 paths honour it with `checkPermissions` on |

### PU-J `SettingsFormTest` (`Civi\Test\FormWrapper`)

| ID | Status | Case |
| --- | --- | --- |
| PU-J1 | done | Defaults: retries 3, delay 4, receipts off, window 10, processors empty |
| PU-J2 | done | Validation: blank and non-numeric retries and delay rejected; window rejected at 0 and 91, accepted at 1 and 90 |
| PU-J3 | done | Save round trip for every setting |
| PU-J4 | done | Multi-select deselect-all stores an empty array (not the previous value) |
| PU-J5 | done | Legacy delimited string for processors still pre-populates |
| PU-J6 | done | Processor options list only live eWAY processors |
| PU-J7 | done | The setting key used by the cron (`eway_recurring_contribution_max_retry`) is the one the form writes, despite the differing `name` field in the settings file |
| PU-J8 | wip | Retries and delay must be whole numbers of zero or more: -1, 2.5, 0.5, 1e2, +3 and -0 refused; 0, 7 and padded numbers accepted and trimmed on save (DEF-52) |
| PU-J9 | wip | Saving shows a "settings have been saved" status message; a refused submission shows none (DEF-54) |
| PU-J10 | wip | The form declares its `_submittedValues` property (no PHP 8.2 dynamic property deprecation, DEF-55) |

### PU-K `PagesTest` (`VerifyPayment`)

These pages exit through `die()` and redirects, so first extract the decision logic to a method
returning a redirect URL or status (pure refactor), then test it. Until then cover through
Playwright (PW-F6).

| ID | Status | Case |
| --- | --- | --- |
| PU-K1 | done | `VerifyPayment`: missing invoice id, processor id or component returns 400 |
| PU-K2 | done | Unknown processor returns 400 |
| PU-K3 | done | Approved: redirect to event Thank You (with `qfKey`) or contact summary; declined: failure redirect; exception path does not hit an undefined variable (DEF-5, now covered: `run()` calls `resolve()`, which returns the outcome instead of ending in `die()`; the failure redirect is caught as the test framework's exit exception) |
| PU-K4 | n/a | `VerifyUpdateToken`: no recurring id redirects to the CiviCRM home. **Dropped:** the page was unreachable and has been deleted (DEF-13) |
| PU-K5 | n/a | `VerifyUpdateToken` approved path. **Dropped:** page deleted; the recovery it was meant to do is now in `updateSubscriptionBillingInfo()` and covered by PU-N5 (DEF-13) |
| PU-K6 | n/a | `VerifyUpdateToken` declined path. **Dropped:** page deleted (DEF-13) |

### PU-L `SchemaAndUpgradeTest`

Headless checks of a fresh install and of individual upgrade steps. Real upgrades from a released
version with seeded data are covered by the `UP` suite below.

| ID | Status | Case |
| --- | --- | --- |
| PU-L1 | done | Fresh install creates `civicrm_eway_contribution_transactions` with all columns and both FKs |
| PU-L2 | done | Stored schema version after install matches the latest upgrader step (DEF-8, fixed: robustness, a fresh install already ended at the newest step) |
| PU-L3 | done | Each `upgrade_*` step is idempotent when run twice |
| PU-L4 | done | `upgrade_20601` rewrites legacy class names on processor type and live processors only |
| PU-L5 | done | DAO and BAO metadata (`entityType.php`) match the table |
| PU-L6 | done | `upgrade_30000` marks a recurring contribution Failed when it is for an eWAY processor, In Progress, has a retry date and has `failure_count` above 0 and at or above the configured retry limit (the stuck state left by releases up to 2.8.2); its failure count, retry date and schedule date are unchanged (FR-2) |
| PU-L7 | done | `upgrade_30000` leaves alone: a record below the limit, a record with no retry date, records that are Completed, Cancelled or already Failed, other processors' records, test-mode records and Pending records (FR-2 Rule 1, decisions U2 and U3). A saved retry limit of 0 does not select records that have no failures |
| PU-L8 | done | `upgrade_30000` is idempotent (a second run changes nothing), uses the retry limit saved at the time it runs, reports the number of records changed, does not send mail or create activities, and fires no recurring contribution hooks (U1: the change is a single SQL statement) (FR-2) |
| PU-L9 | done | `upgrade_30000` Rule 2: an In Progress eWAY record with a retry date and `failure_count` 0 is marked Failed and keeps its failure count of 0, its retry date and its next scheduled date; nobody is charged, and it then shows in the Failed Recurring Contributions list (FR-2) |
| PU-L10 | done | `upgrade_30000` Rule 2 leaves alone: records with failures below the limit (still being retried), records with no retry date, other statuses, other processors, test-mode records and Pending records; a second run changes nothing; the count is reported separately from Rule 1's (FR-2, decisions U2 and U3) |

### PU-M `SettlementSync` gaps (add to existing class or a sibling)

| ID | Status | Case |
| --- | --- | --- |
| PU-M1 | done | `EwaySettlement.Sync` API wrapper: no params, invalid id, zero id. Plus a scoped call with an eligible contribution through an injected client (DEF-32) |
| PU-M2 | done | Sync respects the form-saved settings (end-to-end from `Form_Settings::saveSettings`) |
| PU-M3 | done | `SettlementSync` logs and continues when a processor has bad credentials (HTTP 401) |
| PU-M4 | done | Large settlement set across multiple pages with a mix of matched and unmatched trxn ids |

### PU-N `SubscriptionManagementTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-N1 | done | `changeSubscriptionAmount` writes `next_sched_contribution_date` from date and time parts; no change when absent |
| PU-N2 | done | `cancelSubscription` returns true and does not call eWAY |
| PU-N3 | done | `updateSubscriptionBillingInfo`: throws 9000 without `crid` or token; refuses Completed and Cancelled recurring records with the documented messages |
| PU-N4 | done | Success: recurring `payment_token_id` and `processor_id` updated, `credit_card_number` and expiry placed in params for the email, billing address created or updated (country, state, postcode optional) |
| PU-N5 | done | `updateSubscriptionBillingInfo` on a Failed recurring record sets it back to In Progress with `failure_count` 0 and `failure_retry_date` cleared, as well as saving the token and billing address; `next_sched_contribution_date` is unchanged, so the next cron run charges it. A record still being retried keeps its status and retry state (DEF-13, fixed) |
| PU-N6 | done | A card that belongs to a different contact, or that does not exist, is refused with a payment processor exception; the token, status, retry state and billing address are all left unchanged (DEF-14, fixed) |
| PU-N7 | done | A card saved for a different payment processor is refused, changing nothing (DEF-14, fixed) |
| PU-N8 | done | An expired card is refused, changing nothing (DEF-14, fixed) |
| PU-N9 | done | Updating billing for a donor with no state or no email address raises no PHP deprecation (DEF-18, fixed: the customer builder cut those values with substr() on null) |

### PU-O `FailedRecurringSearchTest`

| ID | Status | Case |
| --- | --- | --- |
| PU-O1 | done | Saved search returns only non-test recurring records on eWAY processors **that have a payment token**; other processors, test records and token-less records are excluded |
| PU-O2 | done | Search display columns and sort match the managed definition |
| PU-O3 | done | The Reactivate payload (status In Progress, `failure_count` 0, `failure_retry_date` empty, optional `next_sched_contribution_date`) applied through `ContributionRecur.update` resets all four fields and leaves others alone |
| PU-O4 | done | Reactivated records are picked up by the next `get_scheduled_contributions` once the date has passed (ties F3 to the UI) |
| PU-O5 | done | Reactivate on a non-eWAY recurring record changes only its status fields |

### UP upgrade tests in CI (`tests/upgrade/`)

Uses the shared `civicrm-extension-upgrade-tests.yml` workflow in `agileware/ci-workflows`. It
installs an older release (`FROM_REF`) on a WordPress + CiviCRM site, then runs
`tests/upgrade/ci-upgrade-test.sh` as `www-data`, which must seed the old version, replace it with
the commit under test (`NEW_VERSION_DIR`), run `cv ext:upgrade-db`, check the result, and exit
non-zero on any problem. The elections extension is the reference implementation
(`tests/upgrade/ci-upgrade-test.sh`, `seed-v1.php`, `verify-v2.php`, `upgrade-from-v1.sh`, and an
`upgrade-tests.yml` that passes `FROM_REF`). It is implemented here as `tests/upgrade/ci-upgrade-test.sh`, `seed-v2.8.1.php` and `verify-v3.php`, and `.github/workflows/upgrade-tests.yml` (on pull requests, pushes to master and on demand). The script installs the old release as a CMS user, because release 2.8.1's enable hook fails when nobody is logged in (DEF-15). The workflow passes on GitHub (57 checks, none failing) and a rehearsal against a local site passes too. The database upgrade is independent of the CMS, so
this suite runs on WordPress only. Pick `FROM_REF` as the last release the upgrade must support
(decision 9: tag `2.8.1`); each `FROM_REF` is one workflow job. Note that between 2.8.1 and 2.8.2 the extension changed only the menu XML (`is_ajax` flags on `createtoken` and `savetoken`), and the upgrader has no step after `upgrade_20601` until `upgrade_30000` is added (FR-2; UP-10). From the 2.8.1 baseline the suite is therefore chiefly a guard that an in-place upgrade keeps data, settings and managed entities and rebuilds the menu, and it will exercise real upgrade steps only once a release adds one. A second, older baseline (2.6.1) would exercise real upgrade steps (`upgrade_20300` and `upgrade_20601`) and the switch of upgrader class; that case, UP-07, was dropped as not needed.

| ID | Status | Case |
| --- | --- | --- |
| UP-01 | done | Upgrading from `FROM_REF` with `cv ext:upgrade-db` succeeds, no upgrade is left pending, and the stored schema version equals the latest upgrader step (also settles DEF-8) |
| UP-02 | done | A seeded eWAY processor (live and test, credentials, class name, active flag) is unchanged after the upgrade |
| UP-03 | done | Seeded recurring contributions in each state (except the stuck ones that UP-10 covers), payment tokens and `civicrm_eway_contribution_transactions` rows (including `is_email_receipt`, `tries`, `failed_message`) keep their values, and the foreign keys still exist |
| UP-04 | done | Managed scheduled jobs: jobs an administrator had rescheduled or disabled (eWay Transaction Verifications, fill missing tokens metadata, Settlement Sync) keep those changes (`update: never`), the Settlement Sync job stays disabled, and the "eWay Recurring Payments" job still exists, is active and runs always. **Note:** the plan first said the `update: always` job is reconciled; in a rehearsal an administrator's change to it was not put back by the upgrade and cache flush, so that is not asserted. The Settlement Sync job already exists in 2.8.1, so it is not "added" by the upgrade |
| UP-05 | done | Saved settings (retries, delay, receipts, window, processor allowlist) keep their values, and settings introduced after `FROM_REF` take their defaults |
| UP-06 | done | Managed Search Kit entities (Failed Recurring Contributions saved search, display, navigation item) are created or updated, and a locally modified copy is left alone (`update: unmodified`) |
| UP-07 | n/a | **Dropped 2026-11-14: not needed.** Originally: A second job with `FROM_REF: 2.6.1` (decision 9): seed a processor with the legacy class name `au.com.agileware.ewayrecurring`, upgrade, and check it is rewritten to `Payment_eWAYRecurring` on the type and on live processors only (`upgrade_20601`; the current step skips test processors). Also covers the move from the legacy `CRM_eWAYRecurring_Upgrader` to `AutomaticUpgrader` (switched between 2.7.0 and 2.7.5).. Kept for the record; it would need a second job with `FROM_REF: 2.6.1`, and was judged not worth the cost. The legacy class-name rewrite (`upgrade_20601`) and the switch of upgrader class are therefore not covered by an automated upgrade test |
| UP-08 | done | After the upgrade the three scheduled jobs (eWay Recurring Payments, eWay Transaction Verifications, fill missing tokens metadata) run through `Job.execute` without error on the seeded data (nothing is due, so no gateway call), and leave every seeded recurring contribution unchanged. **Note:** `Job.run_payment_cron` itself needs a `processor_name` parameter, so the job record is run instead |
| UP-09 | done | After the upgrade `civicrm/ewayrecurring/createtoken` is flagged `is_ajax` true and `savetoken` false (core keeps the flag in the menu's `module_data`), so a plain GET navigation to `createtoken` is refused (CIVI-SA-2026-49); the removed `verifyupdatetoken` route is gone |
| UP-10 | done | Seed recurring contributions in the stuck state left by `FROM_REF` (In Progress, retry date set, `failure_count` at the retry limit) alongside ones that must not change; after the upgrade the stuck ones are Failed, appear in the Failed Recurring Contributions list and are counted by System Status once their retry date is more than four hours old, and the others are untouched (FR-2) |
| UP-11 | done | Seed In Progress recurring contributions with a retry date and `failure_count` 0 (the second stuck state) alongside ones that must not change; after the upgrade the stuck ones are Failed with their failure count, retry date and schedule date unchanged, they appear in the Failed Recurring Contributions list, and the others are untouched (FR-2 Rule 2) |

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

### 5.0 Infrastructure, `specs/infrastructure/`

Checks of the test machinery itself (the site, the personas, the fake eWAY and the helpers), so the specs that
build on it can trust it. They pass on WordPress, on GitHub and on a local site. Their CMS column says all, so they
stay `wip` until Drupal, Joomla and Standalone have run them too (section 0.1 records the progress per CMS).

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-INF-01 | wip | all | The site is up and the administrator persona's saved session reaches the CiviCRM dashboard |
| PW-INF-02 | wip | all | The extension and the stub extension are enabled, and the seeded live and test eWAY processors exist |
| PW-INF-03 | wip | all | A response scripted through `stub.respond()` reaches the real extension code (`getEWayClient()` then `queryCustomer()`), and the call and its arguments are logged |
| PW-INF-04 | wip | all | A gateway call nothing was queued for is logged as unexpected and throws, so a test fails loudly on one it did not expect |
| PW-INF-05 | wip | all | The shared payment page address the stub returns (through the real SDK's `createTransaction()`) points at the fake hosted page and carries the access code, return and cancel addresses, amount and reference |
| PW-INF-06 | wip | all | The fake hosted page sends the browser back with the access code appended, with `?` when the return address has no query string and `&` when it has, for Pay and for Cancel |

### 5.1 Front end (donors), `specs/frontend/`

**PW-F1 once-off contribution page** (`contribution-once-off.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F1-01 | wip | all | Page renders with no card fields (card entry happens on eWAY); processor selectable and pre-selected when the only one |
| PW-F1-02 | wip | all | Required billing fields are enforced client and server side before leaving the site **Found:** the server only enforces email and first and last name; street, city, state and postcode are not required server-side (CiviCRM core behaviour), so only the browser enforces them |
| PW-F1-03 | wip | all | Confirm page shows the correct amount, billing address and email, and a Continue button (there is no "Make Contribution" wording) |
| PW-F1-04 | wip | all | Continue redirects to the stub hosted page, which shows the right amount, invoice reference and customer name; the extension asked eWAY for that amount and a return address on the thank you step (eWAY sends the donor back to the thank you step, which checks the payment as it loads) |
| PW-F1-05 | wip | all | Approve on the hosted page: land on Thank You, contribution is Completed in DB with `trxn_id`, queue row status Success, receipt flag respected, contact has a token only for recurring (none for once-off check) |
| PW-F1-06 | wip | all | Decline on the hosted page: friendly "payment was declined" message with the eWAY text, contribution Failed, no token created, form can be resubmitted |
| PW-F1-07 | wip | all | Hosted page "Cancel": back on the contribution form with data kept; browser Back after Confirm; double submit; re-posting the Thank You URL shows the "already completed" message instead of charging twice (covers DEF-2) **Found:** re-opening the Thank You URL after paying shows CiviCRM's "session has expired" and a fresh form (no second charge); the "Payment already completed" message is what a donor sees going Back to Confirm after paying (CiviCRM answers with an HTTP 500 error page, which the test expects). Back from the hosted page then Continue shows core's raw "Duplicate error" message, safe but unfriendly (observation, not a defect). The DEF-2 branch of `doPayment()` cannot be reached through the UI because core's duplicate-invoice check fires first |
| PW-F1-08 | wip | all | Customer never returns to the site: contribution stays Pending, then `EwayContributionTransactions.validate` completes it (see PW-F5) The stub answers by method and order, not access code, so the test parks other waiting queue rows first (`setOtherQueueRowsAside`); PW-F5 needs the same |
| PW-F1-09 | wip | all | Non-ASCII names, addresses and descriptions (accents, apostrophes, emoji) complete and arrive at the stub sanitised |
| PW-F1-10 | wip | all | Test-mode contribution page (`mode=test`) uses the test processor and marks the contribution `is_test` Test mode on CiviCRM is `action=preview`, not `mode=test` |
| PW-F1-11 | wip | all | Zero-amount (free price option) completes without any call to eWAY |
| PW-F1-12 | wip | all | Mobile viewport (375 px) completes the journey; no horizontal overflow **Found:** on a fresh CI site the donation form is about 450px wide on a 375px screen (CiviCRM core's billing fieldset inside Astra; the extension adds no CSS). The journey and the confirm and thank-you steps fit and are tested; a second test pins the form width with `test.fail()` until it is fixed (not an extension defect) |
| PW-F1-13 | wip | all | A declined payment and the thank-you page show their messages visibly inside the CMS theme (Astra, Olivero, Cassiopeia, Standalone): not hidden, unstyled or pushed off screen WordPress and Astra only so far |

**PW-F2 recurring contribution** (`contribution-recurring.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F2-01 | wip | all | Recurring option and interval controls shown; unticked behaves as once-off |
| PW-F2-02 | wip | all | Approve: recurring record is In Progress, `processor_id` and `payment_token_id` set, `next_sched_contribution_date` is today plus the interval at 00:00, token row has masked number and expiry end of month |
| PW-F2-03 | wip | all | Each interval unit (week, month, year) and interval greater than 1 give the right next date |
| PW-F2-04 | wip | all | Installments limit stored on the recurring record |
| PW-F2-05 | wip | all | Decline on the first payment: recurring record does not become In Progress; no token saved **Found:** a declined first payment leaves the recurring record Pending (not deleted, not In Progress), with the placeholder `processor_id` and no `payment_token_id`; the cron ignores it. `Utils::deleteRecurringContribution()` is private and never called (dead code) |
| PW-F2-06 | wip | all | Returning donor with an existing token on the same contact does not create a duplicate token row (dedupe rule) **Found (DEF-36, fixed):** the dedupe rule lived only in the browser-side `saveToken`, so the contribution page path added a second identical token row for a returning donor; `updateCustomerDetails()` now applies the same rule |

**PW-F3 event registration** (`event-registration.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F3-01 | wip | all | Paid event registration to hosted page to Thank You: participant Registered, contribution Completed |
| PW-F3-02 | wip | all | Decline: participant not registered, message shown, can retry **Found:** the declined participant stays "Pending from incomplete transaction", and a retry creates a new participant |
| PW-F3-03 | wip | all | Hosted page cancel returns to the Confirm step with qfKey intact **Found:** Continue after cancelling reuses the same hosted page (one setup call); on the contribution page the equivalent case gives core's "Duplicate error" (PW-F1-07) |
| PW-F3-04 | wip | all | Returning to Confirm after payment shows "Payment already completed for this Registration" **Found:** like the contribution page, CiviCRM answers with an HTTP 500 error page, which the test expects |
| PW-F3-05 | wip | all | Multiple participants, correct total sent Core's own `DisplayProfile.tpl` prints a PHP warning on the Thank You step of a multi-person registration (core 6.16.5, not this extension), which the test allows |
| PW-F3-06 | wip | all | Free event (no payment) does not use eWAY |

**PW-F4 update billing details, staff form** (`update-billing.spec.ts`)

The page at `civicrm/contribute/updatebilling` is CiviCRM core's own form. This extension supplies its
saved-card controls ("Stored Credit Card" and "Add Credit Card", the same as the back-office form,
PW-B-backoffice) and `updateSubscriptionBillingInfo()`, which saves the chosen token and the billing
address. In practice it is a staff tool: it is reached from "Change Billing Details" on the contact's
Recurring Contributions tab, and its saved-card list needs staff-level permission. Donors do not use it.
A site that wants donors to update their own card builds its own page outside this extension, which
replaces the stored card in place; the extension's side of that arrangement is covered by PU-F25,
PU-F26, PU-G11 and PW-B-cron-08. The extension adds no link to the form and sends no failure email.
An older page, `civicrm/ewayrecurring/verifyupdatetoken`, was unreachable since 2.2.5 and has been
deleted (DEF-13). Whether a user with only "make online contributions" can complete the form is
checked by PW-F4-07.

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F4-01 | wip | all | A staff user opens "Change Billing Details" on a recurring contribution: `civicrm/contribute/updatebilling` shows the Stored Credit Card list, the Add Credit Card button and the current billing address The form is opened from the contact's Recurring Contributions tab, in a dialog (a full page works too). It printed a PHP warning on every load until DEF-42 was fixed |
| PW-F4-02 | wip | all | As staff, add a new card through the stub hosted window, select it and submit: the recurring record's `payment_token_id` and `processor_id` point at the new token, the billing address is updated, the success message shows, the old token row is kept Covers the dialog and the full-page form. The full page could not save until DEF-41 was fixed; the card list showed each card twice on a fresh site until DEF-44, and a script error followed an added card until DEF-43 |
| PW-F4-03 | wip | all | Add Credit Card abandoned or refused on the stub hosted page: no new token, recurring record unchanged, form still usable |
| PW-F4-04 | wip | all | Completed and Cancelled recurring records cannot have billing updated: the message "Attempted to update billing details for a completed (or cancelled) contribution" is shown **Found:** the messages are separate ("completed" and "cancelled"). Core offers Change Billing Details only on Active rows; Failed, Cancelled and Completed records have a View link only, so the tests open the form on an active record, change its status in the database, then press Save (DEF-45) |
| PW-F4-05 | wip | all | Wrong user, or a bad checksum, cannot open another contact's recurring record (core's access rules, kept as a guard) **Found:** a bad checksum, or another contact's, is sent to the site home page; signed-in `finance`, `tokenviewer` and `limited` get "You do not have permission" (an HTTP 500 page on a fresh site) because none holds "make online contributions" |
| PW-F4-06 | wip | all | Staff update the card on a Failed record: seed the failure by running the cron until the record is marked Failed, then submit the update-billing form with a new card. The record is In Progress with `failure_count` 0 and no retry date, the schedule date is unchanged, and the next cron run charges the new token and creates a Completed contribution (DEF-13) **Found:** the form is not reachable by link for a Failed record (DEF-45), and the first failed cron run already moved the schedule date one interval on, so after the card update nothing is charged until that date arrives (DEF-46); the test moves the date and then asserts one charge on the new token |
| PW-F4-07 | wip | all | Who can use the form: a user with only "make online contributions" (for example arriving by a checksum link) sees either the saved cards or "No cards found." (the list is loaded with the PaymentToken API, which needs staff permission). Record which; the expectation is that the form is effectively staff-only. A staff user on the contact's tab is the control case (DEF-14 note) **Found:** a donor arriving with a valid checksum gets the form with their name and address, but the stored-card list is refused (HTTP 403) so it stays "No cards found.", and Save either says a card is required or sends the donor to the login page. The form is effectively staff only; the donor update-card page is FR-1 |

**PW-F5 verification queue effects** (`verification-queue.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F5-01 | wip | all | Abandoned approved payment is completed by the verification job and the donor's receipt is queued The receipt is a mail in the mailing spool only when the queue row has `is_email_receipt` set; the tries counter goes up on every run that asks eWAY, including the one that settles it |
| PW-F5-02 | wip | all | Abandoned declined payment becomes Failed |
| PW-F5-03 | wip | all | A payment still processing stays Pending and is retried on the next run |
| PW-F5-04 | wip | all | After seven unresolved tries the contribution is marked Failed |

**PW-F6 public endpoints** (`endpoints.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-F6-01 | wip | all | `civicrm/ewayrecurring/verifypayment` with no, partial or bogus parameters returns 400, no PHP output, no stack trace **Found:** a non-numeric `paymentProcessorID` reached the CiviCRM API (DEF-51) and a non-eWAY processor id printed PHP warnings and failed the contribution (DEF-48); both fixed, the return is now 400 |
| PW-F6-02 | n/a | `verifyupdatetoken` with nothing redirects to the home page. **Dropped:** the route was removed with the unused page (DEF-13) |
| PW-F6-03 | wip | all | `savetoken` without `pp_id` returns the window-close script only |
| PW-F6-04 | wip | all | `createtoken` as anonymous is refused; as `finance` with missing billing fields returns `Missing field: ...` JSON **Found:** an unknown country or state id printed a PHP warning in front of the JSON (DEF-49, fixed). The refusal of an anonymous visitor is 403 on the local site and a 500 page on a fresh site (the test accepts both). A missing `contact_id` or `pp_id` gives the site page with core's "Could not find valid value" message at status 200 |
| PW-F6-05 | wip | all | `createtoken` via plain GET navigation (no XHR header) is refused (`is_ajax`, CIVI-SA-2026-49) The refusal comes from core: the local core (6.16.5) has no `is_ajax` check and gives its permission page, while the CI core says "SECURITY ALERT: Ajax requests can only be issued by javascript clients"; the test accepts either |
| PW-F6-06 | wip | all | Return URL as the CMS builds it (clean path on WordPress, Drupal and Standalone; `index.php?option=com_civicrm&task=...` on Joomla; query form on WordPress without pretty permalinks) accepts the access code appended by eWAY with either `?` or `&`, and the payment verifies The extension always appends the access code with `&`; only events return through `verifypayment`; WordPress without pretty permalinks is untested. **Found:** DEF-47 (security): the return page never checked that the eWAY transaction was for that contribution, so an approved access code for a small payment could complete a larger Pending contribution; fixed, it now needs a matching invoice reference and amount, and the return is a 400 otherwise. DEF-50 (the queue row was settled before eWAY had a result) is also from here |
| PW-F6-07 | wip | all | `createtoken` and `savetoken` addressed through `CRM.url(..., 'front')` from a back-office page work on each CMS for a permitted user and are refused for anonymous On WordPress an anonymous visitor is refused `createtoken` but not `savetoken` (it only prints the window-close script), and the `finance` persona is refused `savetoken` |

### 5.2 Back office, `specs/backend/`

**PW-B-settings** (`settings.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-settings-01 | wip | all | Page loads at `civicrm/ewayrecurring/settings` for admin with defaults 3, 4, No, 10 and an empty processor list **Found:** the page's heading is "eWay Recurring Contributions Settings" (the menu entry is "eWay Recurring Settings"). The case reverts the settings first, so it checks the defaults and not what the site had stored. |
| PW-B-settings-02 | wip | all | Menu item "eWay Recurring Settings" appears under Administer and navigates there **Found:** the entry sits directly under Administer, not in one of its groups. CiviCRM's menu opens on a click; hovering does not open it in a driven browser. |
| PW-B-settings-03 | wip | all | Blank or non-numeric retries and delay show the field errors; window 0 and 91 rejected, 1 and 90 accepted **Found:** blank and letters were refused as planned, but negative, fractional and exponent numbers for retries and delay (-1, 2.5, 1e2) were accepted and saved as typed (DEF-52, fixed: whole numbers of zero or more only, spaces trimmed). A blank or lettered window counts as 0 and is refused with the window message. Two tests carry this ID (retries and delay, then the window). |
| PW-B-settings-04 | wip | all | Saving persists and survives reload; page redirects back to itself **Found:** a save gave the administrator no confirmation at all (DEF-54, fixed: a "Settings saved" message) and printed a PHP 8.2 deprecation for a property the form never declared (DEF-55, fixed). The case also saves a processor choice and the receipts switch and reads everything back after a reload and a fresh visit. |
| PW-B-settings-05 | wip | all | Processor multi-select lists only live eWAY processors (test and non-eWAY processors absent) **Found:** the box lists one entry per live, active eWAY processor, by title and valued by id. The seeded live and test processors share a title, so the case tells them apart by id. An inactive live eWAY processor, made and removed by the case, is not offered. On a site with other eWAY processors the list is longer; the case compares with the database, not a fixed list. |
| PW-B-settings-06 | wip | all | Selecting then deselecting every processor clears the setting (DB check) **Found:** as planned. The case also checks the stored value is an empty list, and that the box is empty on the next visit. |
| PW-B-settings-07 | wip | all | `finance` and `limited` users are refused (administer CiviCRM only) **Found:** the refusal is an HTTP 403 page reading "You do not have permission to access this page.", with no form. On WordPress core prints a "Cannot set response code - headers already sent" warning after it, which the case allows as core noise. On a fresh GitHub site the same refusal page comes with HTTP 500 instead of 403 (core's own permission page, as in PW-F6-04), so the case accepts either. A post made without the form is refused with 403 (or 500) as well and changes nothing, and the Administer menu does not offer the entry to these users. |
| PW-B-settings-08 | wip | all | The settings page is reachable from the CMS's own admin navigation (WordPress admin menu, Drupal toolbar, Joomla administrator menu, Standalone navigation) via the "eWay Recurring Settings" entry **Found:** WordPress's own admin sidebar has a CiviCRM entry (and CiviCRM's Settings) but nothing from this extension. The extension's entry is in CiviCRM's menu bar, which WordPress shows on every CiviCRM screen, so the case goes sidebar, CiviCRM, Administer, "eWay Recurring Settings". Only WordPress is scripted; the case skips on the other CMSs until their navigation is. |

**PW-B-processor** (`payment-processor.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-processor-01 | wip | all | "eWay Recurring" is offered as a processor type with API Key / API Password labels **Found:** both the live and the test section are relabelled (API Key, API Password, Site URL, Recurring Payments URL) and the Signature, API URL and Button URL fields are removed. Choosing a type reloads the credential section from the server (`pp=<id>`); the Dummy type keeps User Name, Site URL and Recurring Payments URL. |
| PW-B-processor-02 | wip | all | Saving without key or password shows both required-field errors; other processor types are unaffected **Found:** the extension always asks for the LIVE key and password: credentials in the test section alone are refused, although CiviCRM's own message says "at least the test or live section" (DEF-56, pinned, decision needed). Other types are untouched: a Dummy processor with nothing filled in gets only core's message, and saves with a user name and no password. |
| PW-B-processor-03 | wip | all | A valid new processor saves and appears in the contribution page processor list **Found:** after Save, CiviCRM opens the new processor for editing with a "has been saved" message. Only the live section was filled, so CiviCRM also made a test row with empty credentials. The contribution page's Amounts tab lists the processor, unticked. On this site core prints "Undefined array key weight" from the page's tab header, which the case allows as core noise. The case deletes what it makes. |
| PW-B-processor-04 | wip | all | Deactivating and re-enabling the extension leaves the processor type active **Found:** Disable and Enable are CiviCRM's own full-page confirmations. Enabling did not switch the extension's scheduled jobs back on: core reconciles the managed jobs after the enable has finished, so all four stayed off (DEF-53, fixed in the enable hook; the settlement sync job stays off as shipped). On a site whose processor type was created before the extension managed it (this local site) disabling does not deactivate the type, so the case makes it inactive itself before enabling, to exercise the hook everywhere; the type's state while disabled is recorded as a test annotation (DEF-57, open). |

**PW-B-jobs** (`scheduled-jobs.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-jobs-01 | wip | all | Scheduled Jobs page lists the four eWAY jobs with the documented frequencies and active states **Found:** as planned. Each job is listed once, for example "eWay Recurring Payments (Always)" with API entity Job, action run_payment_cron and parameters processor_name=eWay_Recurring; verifications (Always, EwayContributionTransactions.validate) and fill missing tokens metadata (Hourly, EwayRecurring.fillTokensMeta) are Yes, settlement sync (Daily, EwaySettlement.Sync) is No. Every row has an Execute link, and no other job name starts with "eWay". |
| PW-B-jobs-02 | wip | all | eWay Settlement Sync job is present and disabled **Found:** as planned: listed as Daily and No, its row offers Enable (not Disable), and the database shows the other three on. |
| PW-B-jobs-03 | wip | all | Run "fill missing tokens metadata" from the UI with the stub: token with null expiry and masked number is filled; result message shows the count **Found:** Execute asks "Are you sure you would like to execute ...", then the job list shows only "Scheduled Job has been executed. See the log for details." The count of cards filled in is not in that message but in the job's log ("Success (a:1:{s:5:"count";i:N;})"), which is where the case reads it. A card with no expiry and no masked number gets both from eWAY (the expiry is the last day of its month). The case compares the count with the number of cards on the site that lack a detail, so earlier cases' leftovers do not matter. A second run fills nothing and does not call eWAY. |
| PW-B-jobs-04 | wip | all | Run "eWay Transaction Verifications" from the UI: pending items resolved (ties to PW-F5) **Found:** as planned. The job log reads "result: Success"; the contribution is Completed, the queue row is closed and the donor's Contributions tab shows Completed; a second run makes no call to eWAY. |
| PW-B-jobs-05 | wip | all | Run "eWay Recurring Payments" with a due recurring contribution: new contribution appears on the contact's Contributions tab and recurring page **Found:** as planned, with the stub charge checked (token and amount in cents). The new payment is listed on the contact as a Completed recurring one, and the Recurring Contributions tab and the record's own page show the next date a month on. Core prints "Undefined array key invoice_id" twice on the record's own page for every recurring record (core noise, allowed by the case). |

**PW-B-cron recurring processing visible in the UI** (`recurring-cron.spec.ts`)
(uses `civi.ts` helpers to back-date `next_sched_contribution_date`, then runs the job through
`cv api3 Job.run_payment_cron` and checks the screens, in the same way elections moves stages.)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-cron-01 | wip | all | Due recurring contribution: contact summary shows the new Completed contribution and the recurring row's next date moved forward **Found:** as planned. The next date is the due date plus one month at midnight; the row stays In Progress with Edit available. No failure activity is made. |
| PW-B-cron-02 | wip | all | Declined charge: Failed contribution with a "Transaction Error" note visible on the contribution, "eWay Transaction Failed" activity on the contact, recurring row shows failures and a retry date **Found:** the contribution's View page shows the eWAY message (D4401, "Refer to Issuer") as its "Note"; the note's subject "Transaction Error" is not shown there, so the case also reads the note from the database. The Recurring Contributions tab shows neither the failure count nor the retry date; the record's own page shows "Failure Count"; the retry date (today plus the delay, at midnight) is only visible on the Failed Recurring Contributions list, and a record only gets there once its retries run out (PW-B-cron-04). The failure also moved the next date a month on (DEF-46, open). |
| PW-B-cron-03 | wip | all | Retry after the delay succeeds: recurring row back to normal, "Transaction Succeeded after N retries" activity **Found:** as planned. Before the retry date the job does nothing; after it the retry charges, the record is In Progress with no failures and no retry date, and the activity reads "Transaction Succeeded after 1 retries" (on the activity's View page). Three contributions: the first Completed, the Failed one and the new Completed one. |
| PW-B-cron-04 | wip | all | Retries exhausted: nothing further charged; where the contribution appears in the failed list (DEF-3 fix; PW-B-cron-06 covers the Failed status) **Found:** run with the default limit of three. After the third failure the record is Failed, the contact's Contributions tab lists three Failed payments, the Recurring Contributions tab has it under "Inactive Recurring Contributions" with View only, and the Failed Recurring Contributions list shows it with failure count 3, its retry date and a Reactivate button. A further run with both the next payment and the retry due calls eWAY not at all. |
| PW-B-cron-05 | wip | all | Receipt email recorded (outbound mail redirected to the database) only when "Send receipt for all recurring contributions" is on **Found:** as planned. With the setting off the payment completes and no mail is spooled; with it on exactly one receipt mail is spooled for the donor (checked in the outbound mail table). |
| PW-B-cron-06 | wip | all | Retries exhausted ends in Failed. With the retry limit set to 2: the first failed charge leaves the recurring record In Progress with `failure_count` 1 and a retry date; the second marks it Failed, visible on the contact's Recurring Contributions tab. A further cron run charges nothing and creates no contribution. **Found:** as planned, checked on the contact's tab at each stage (In Progress, then Failed under "Inactive Recurring Contributions"). A further run, with the retry and next payment both made due, makes no call to eWAY and no contribution. |
| PW-B-cron-07 | wip | all | Success after retries: a failed charge, then the retry (once the delay has passed) succeeds. The record stays In Progress throughout and is never shown as Failed; `failure_count` back to 0, retry date cleared, next scheduled date moved forward, and the contact has an "eWay Transaction Succeeded" activity reading "Transaction Succeeded after N retries". **Found:** as planned for the state: two failures leave the record In Progress (never Failed) on the tab, the third attempt succeeds, failure count 0, no retry date, three contributions Completed/Failed/Failed/Completed in order, activity "Transaction Succeeded after 2 retries", and the record is not on the Failed list. **Found:** the next date after a failure and a successful retry is two intervals after the due date, so a month is skipped (DEF-58, open). The test that checks the schedule is a second one with this ID, pinned with test.fail(). |
| PW-B-cron-08 | wip | all | Replace the stored card in place through the API (new token value, expiry and masked number cleared, as a website-built update-card page does): the next cron run charges the new token value at the stub and the contribution appears on the contact; running the fill-missing-metadata job (PW-B-jobs-03) then restores the card's masked number and expiry. The expiry reminder date moving afterwards is behaviour of the separate expiredcreditcards extension and is tested there **Found:** as planned. The charge uses the new token value and the recurring record keeps pointing at the same card row; the Scheduled Jobs screen then runs the fill-in job, which restores the masked number and the expiry. Not covered here: the expiry reminder date (the separate expiredcreditcards extension). |

**PW-B-failed** (`failed-recurring.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-failed-01 | wip | all | `civicrm/contribute/failedrecurring` loads for `finance`; Contributions menu shows "Failed Recurring Contributions"; `limited` is refused **Found:** the entry sits directly under Contributions and opens the list's page, which loads for `finance` (the page needs only "edit contributions"). The list inside it does not: its data request answers HTTP 500 for anyone who is not an administrator, because the saved search joins the payment processor, which only "administer CiviCRM" may read (DEF-62, open). The test that expects `finance` to see rows is a second one with this ID, pinned by `test.fail()`. `limited` is refused with the permission page (HTTP 403, or 500 on a fresh site), no table and no menu entry. Three tests carry this ID. |
| PW-B-failed-02 | wip | all | Default status filter shows Failed and Failing only; columns, sort, 50 per page, contact link work (DEF-3 check) **Found:** as planned. The Status box starts on Failed and Failing and the rows come in status order (Failed before Failing), then retry date oldest first. Every column sorts except Frequency, and the contact's name links to the contact's summary page. Dates are checked as present, not as text, because their format follows the site's date setting. 50 per page is a second test with this ID: it makes 52 records and deletes them, and sorts by ID first because ties on the default sort have no fixed order across pages. |
| PW-B-failed-03 | wip | all | Seeded non-eWAY, test, and token-less recurring records are not listed **Found:** as planned, for Failed and for Failing records. A record on another processor that still has its saved card is left out (the processor decides), as are test-mode records and records with no saved card. |
| PW-B-failed-04 | wip | all | Reactivate button appears only on Failing and Failed rows **Found:** as planned. Rows of other statuses only appear after the Status box is widened, and they have no button. Nothing in the extension ever sets the status Failing (the cron job marks a record Failed), so Failing rows only exist when made by hand, as here. |
| PW-B-failed-05 | wip | all | Row Reactivate: dialog text singular ("1 selected recurring contribution"), optional date left blank, confirm: success alert, DB shows failures 0, retry date cleared, status In Progress, schedule date unchanged **Found:** as planned. The dialog reads "Clear the failures on 1 selected recurring contribution and reset its status to "In Progress"", the date box is blank ("Leave blank for no change.") and the buttons are Cancel and Reactivate Recurring Contributions. Success shows "Successfully reactivated 1 Recurring Contributions." titled "Reactivation Complete", the row leaves the list, and eWAY is not called. The dialog opens without the script error that DEF-61 fixed. |
| PW-B-failed-06 | wip | all | Reactivate with a chosen date: schedule date set exactly **Found:** as planned. The date is chosen by clicking in the date picker (its box follows the site's date format, so nothing is typed); the record's next scheduled date becomes that day at midnight and the other fields are as in PW-B-failed-05. |
| PW-B-failed-07 | wip | all | Select several rows, bulk Reactivate: plural wording, progress shown, all updated **Found:** as planned. The toolbar's Reactivate button is off until a row is ticked. The dialog reads "... on 3 selected recurring contributions and reset their statuses to "In Progress""; once confirmed it shows "Reactivating Recurring Contributions...", a progress bar and "Processing records 1 - 3" with the buttons off (the case holds the update call back for two seconds so this can be seen; the call is unchanged), then "Successfully reactivated 3 Recurring Contributions.". The row that was not ticked is untouched and is the only one left on the list. |
| PW-B-failed-08 | wip | all | Cancel in the dialog changes nothing **Found:** as planned. Cancel closes the dialog with no alert and no change, even with a date picked; the dialog opens again with a blank date. |
| PW-B-failed-09 | wip | all | Reactivated record is charged by the next cron run once due **Found:** as planned. While the record is Failed the job leaves it alone even though its payment is due; after Reactivate (schedule date unchanged) the next run charges its card once, the contribution is Completed and the contact's Contributions tab shows it. |
| PW-B-failed-10 | wip | all | Error from the API shows the "error occurring while attempting to reactivate" alert and closes cleanly (force with a permission-denied user via the update action) **Found:** no user can open the list and then be refused the update (the page, the list and the update all need "edit contributions" and "access CiviContribute" together), so the refusal is made the way the server makes it: the update call, and only that call, is answered with an HTTP 403 and an API permission message. The alert is titled "Reactivation Error" and reads "An error occurring while attempting to reactivate 1 Recurring Contributions." (a grammar slip in the extension's wording, left as is); the dialog closes, the record is untouched and the list still works. |
| PW-B-failed-11 | wip | all | Full recovery loop, seeded by the cron and not by editing the status: charges fail up to the limit, the record appears in the Failed Recurring list as Failed with its retry date, Reactivate returns it to In Progress with failures 0 and no retry date, the next cron run charges it and the contact summary shows the new Completed contribution. Extends PW-B-failed-05 and 09, which seed Failed directly. **Found:** the loop works with a limit of two failed charges: Failed, listed with its failure count and retry date, Reactivate returns it to In Progress with no failures and no retry date. It is not charged by the very next run, because the failed runs had already moved the schedule date a month on (DEF-46, open); once the date arrives the run charges it and the contact shows the new Completed payment. The second test with this ID expects the next run after Reactivate to charge it and is pinned by `test.fail()` (DEF-46). |
| PW-B-failed-12 | wip | all | A record that has failed but still has retries left (status In Progress) is not in the default list. It appears only after the last permitted retry fails. Guards against the DEF-3 fix marking Failed too early **Found:** as planned, with the default limit of three: after the first and second failed charges the record is In Progress and not on the list; the third makes it Failed and it appears with failure count 3. |

**PW-B-status** (`system-status.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-status-01 | wip | all | With stuck failures, System Status shows "eWAYRecurring - failed recurring contributions" with the right count and a working link to the failed list **Found:** the check's title on the System Status page is "eWAYRecurring - failed recurring contributions" and the message reads "Found N failed recurring contributions that may be stuck ...", with a Hide choice like any other message. The link opens the Failed Recurring Contributions list, which shows every stuck record. The case first makes the Failed records earlier cases left behind look freshly failed (and puts them back afterwards), because the check counts every one on the site. |
| PW-B-status-02 | wip | all | With none, no message; with the check disabled in status preferences, no message **Found:** as planned. The System Status page has no control for the "disabled" flag the check reads (its Hide choice only hides a message for a time or for good, and the check still runs), so the case disables the check by creating a status preference with is_active off through the API, then removes it and the message is back. |
| PW-B-status-03 | wip | all | A record that used up its retries through the cron is counted in the System Status message once its retry date is more than four hours old (back-date it with the `civi.ts` helper), and not before. Records still being retried and test records are not counted. **Found:** as planned, with a limit of two retries. After the cron marks the record Failed its retry date is days ahead, so it is not counted; three hours old it is still not counted; five hours old it is. A record that has failed once and is still being retried (In Progress, retry date days old) and a test-mode Failed record are not counted, and cancelling the stuck record takes it out. |

**PW-B-recurforms** (`recurring-forms.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-recurforms-01 | wip | all | Cancel Subscription form for an eWAY recurring record has no "send cancellation request" option; cancelling sets Cancelled and does not contact eWAY **Found:** on every eWAY record the cancel form printed PHP warnings ("Undefined array key send_cancel_request" and "Trying to access array offset on null", twice each) because the extension removes the element but core's template still draws a row for it (DEF-59, fixed: the field is taken out of the template's list too, PU-H15). Cancelling then sets Cancelled with the reason saved, makes no call to eWAY, and the tab lists the record under Inactive. |
| PW-B-recurforms-02 | wip | all | Same form for a non-eWAY recurring record still shows the option **Found:** as planned. A record on the seeded Dummy processor shows "Send cancellation request to Test Processor ?" with No and Yes, Yes selected. The case closes the dialog with Not Now and the record is untouched. |
| PW-B-recurforms-03 | wip | all | Update Subscription form allows amount, installments, interval, unit, next scheduled date and end date; the injected "next scheduled date" row from `eway.js` appears once with a working datepicker **Found:** the "next scheduled date" row is core's own field, "Next Scheduled Contribution Date" (it is a field the extension lists as editable), shown once with a working date picker; the row that `eway.js` can build (`modifyUpdateSubscriptionForm`, "next_scheduled_date") is never added because nothing calls it (DEF-60, open, dead code). The form has no time box. The other five fields are present with the record's values. |
| PW-B-recurforms-04 | wip | all | Saving changes persists each field, including the next scheduled date and time **Found:** all six fields persist (amount, installments, interval, unit, next date, end date). The next date is a date only, stored at midnight, because the form has no time box (the `next_scheduled_date_time` the extension reads is never sent). Core's success message states the old frequency ("every 1 month(s)") although the new one was saved, and when the form is reopened it shows the next date but leaves the end date box empty even though it is saved (both are core). Nothing is sent to eWAY. |
| PW-B-recurforms-05 | wip | all | A recurring record in Failed status, reached through the cron, shows without errors everywhere it appears: the contact's Recurring Contributions tab lists it as inactive with View only (core treats Failed as inactive, decision 11), the recurring contribution view opens, Update Subscription and Cancel Subscription open by direct address and work (cancelling sets Cancelled), reactivating from the Failed Recurring list returns it to In Progress and the tab then offers Edit, Cancel and Change Billing Details again, and the status filter in contribution search finds it. No PHP notice and no JavaScript console error. This is the direct check of the CIVIEWAY-125 note that a Failed recurring record breaks the UI. two tests carry this ID. **Found:** the Reactivate dialog threw a script error every time it opened ("Cannot set properties of undefined (setting 'statusLabel')", DEF-61, fixed). A Failed record is under "Inactive Recurring Contributions" with View only; its own page, the update form (which saves) and the cancel form open by address with no PHP output; contribution search by the recurring contribution's status ("Failed") and its processor id finds it as one row; Reactivate from the Failed list puts it In Progress with no failures and the tab offers View, Edit, Cancel and Change Billing Details again. The second test cancels a cron-made Failed record from the cancel form: it becomes Cancelled, eWAY is not called, and it leaves the Failed list. |

**PW-B-backoffice back-office saved-card payments** (`backoffice-token-payment.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-backoffice-01 | wip | all | Record Contribution (credit card) with the eWAY processor shows "Stored Credit Card" and "Add Credit Card" instead of card fields **Found:** the form is "New Credit Card Contribution", reached from "Submit Credit Card Contribution" on the contact's Contributions tab (it opens in a dialog). CiviCRM's own test processor is the default, so the case chooses the eWAY processor in the Payment Processor box, which reloads the card block. With eWAY chosen the card number, security code and expiry boxes are gone, and "Stored Credit Card" (required) with the PCI notice and an "Add Credit Card" button replace them; the billing name and address stay. |
| PW-B-backoffice-02 | wip | all | "Add Credit Card" is disabled with the billing-incomplete message until every billing field is completed, enabled after **Found:** as planned. With no billing address the name boxes come from the contact and the button is off with the red notice "The Billing Details section must be completed before a Credit Card can be added". It turns on only once street, city, post code, country and state are all filled in, and clearing one switches it off again. |
| PW-B-backoffice-03 | wip | all | Choosing a contact loads that contact's unexpired tokens, formatted `1111 - 12/2031`, newest expiry first; expired tokens excluded; "No cards found." when none; changing contact reloads **Found:** as planned for the list: the contact's unexpired cards, newest expiry first, "No cards found." for a contact with none, reloaded each time the contact is changed on the stand-alone form (the expected labels are worked out from the database). With no contact chosen the card list is switched off, but the notice "No contact selected" never shows: the billing check that runs after it replaces its message, so even with billing details complete the button is on and says nothing (DEF-63, open). That second case is a test with this ID pinned by `test.fail()`. |
| PW-B-backoffice-04 | wip | all | Legacy token without masked number shows `MM/YYYY` only **Found:** as planned: a card with no masked number is listed as month and year only (for example `06/2033`), among cards that show the last four digits. |
| PW-B-backoffice-05 | wip | all | Add Credit Card: stub hosted window opens in a new tab, "Click Ok to update the card list" confirmation, after Ok the new card appears in the list and the "credit card list has been updated" alert shows **Found:** Add Credit Card opens eWAY's page (the stub's) in a new tab and asks "Click Ok to update the card list.". The card is saved as soon as Pay is pressed in that tab (the tab then closes itself); Ok reloads the list, and the new card, which expires last, leads it with no duplicate. The "The credit card list has been updated." alert only shows when the contact already had a card: the first card added for a contact appears without it. Two tests carry this ID. |
| PW-B-backoffice-06 | wip | all | Submit with a stored token: contribution Completed using MOTO, `trxn_id` stored **Found:** as planned. eWAY is asked once: the card's token (first 16 characters), the amount in cents and a MOTO transaction. The contribution is Completed with eWAY's transaction id, belongs to no recurring record, and the donor's recurring record is untouched. |
| PW-B-backoffice-07 | wip | all | Submit declined: error shown, contribution not completed **Found:** a declined card leaves a Failed contribution for the amount (nothing is Completed), and eWAY's message ("Refer to Issuer" for D4401) appears under "Payment Processor Error" on the next CiviCRM screen. Straight after Save core sends the browser to a broken address (a 404 page in WordPress, not the form) because it hands the form's query string to its redirect as if it were a whole address (DEF-64, open, core's own code; the case allows the 404). |
| PW-B-backoffice-08 | wip | all | Record a contribution against an existing recurring record (`crid`): token preselected, recurring record keeps its token **Found:** `crid` only preselects the record's card: the form cannot attach a payment to the existing recurring record, so charging the preselected card takes a one-off payment and the record keeps its card, processor id and status. The preselection works when eWAY is the form's processor from the start (the case makes it the default for the run), but is lost when eWAY is chosen from the Payment Processor box afterwards, which asks for the card block without `crid`: the newest card is selected instead (DEF-65, open). That second case is a test with this ID pinned by `test.fail()`. |
| PW-B-backoffice-09 | wip | all | Contributions made on a token for a different payment processor are not offered **Found:** as planned. A card saved for another processor (here the Dummy one) is in the database but not in the list. |
| PW-B-backoffice-10 | wip | all | `tokenviewer` can see tokens but cannot create; `limited` sees none **Found:** `tokenviewer` and `limited` are not given the form at all, because the form needs "edit contributions": the viewer lands on the contact's summary page (no Contributions tab) and `limited` gets core's "necessary permission to view this contact" message. Add Credit Card's own route (createtoken) answers both with an HTTP error and eWAY is not asked for a page. From a CiviCRM page the viewer can read the contact's cards through the API and cannot create one (HTTP 403); `limited` cannot read them (HTTP 403). `finance` opens the form and sees the cards. |

**PW-B-permissions** (`token-permissions.spec.ts`)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-B-permissions-01 | wip | all | APIv4 Explorer as `tokenviewer`: PaymentToken get allowed, create/delete denied **Found:** the APIv4 Explorer page opens for these users but its Action list is empty unless the user is an administrator, so the case calls the same ajax route the Explorer uses with `CRM.api4()` from a signed-in CiviCRM page. Reads work; create, update and delete answer HTTP 403. The personas needed "view all contacts": without it core's contact access control hides every token (a read returned an empty list, and `finance`'s delete removed nothing, with no error), so `finance` and `tokenviewer` now have it in `test-users.json`. |
| PW-B-permissions-02 | wip | all | `finance` (has `access CiviContribute`) allowed everything **Found:** create, update and delete all work for `finance`, checked in the database. Needs the persona's "view all contacts" (see PW-B-permissions-01). |
| PW-B-permissions-03 | wip | all | `limited` denied **Found:** every call is refused with HTTP 403, a read included, and nothing changes. |

### 5.3 Security, `specs/security/`

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-S-01 | todo | all | Script and markup payloads (`<img onerror>`, `"><script>`, `<svg onload>`) in billing name, address, contribution source, description, and eWAY customer fields appear as text on the confirm, thank-you, recurring and failed-list pages. No script runs, no element injection, no `javascript:` links. Modelled on the elections `xss.spec.ts` |
| PW-S-02 | todo | all | Tampering: swapped `AccessCode`, another contribution's invoice id, `paymentProcessorID` of a different processor, replay of a completed return URL, SQL meta-characters in `contributionInvoiceID`. No contribution state changes, no fatal, no PHP output (covers DEF-9) |
| PW-S-03 | todo | all | Direct access without login to every `civicrm/ewayrecurring/*` route returns the documented permission result |
| PW-S-04 | todo | all | Stored API password never appears in page source, AJAX responses, or the processor list for non-admins |
| PW-S-05 | todo | all | Tampered `contact_payment_token` on the update-billing form: another contact's token id, a token from a different processor, an expired token, a non-numeric value and a missing value. Each shows an error and changes nothing, with no fatal (DEF-14 fix; PU-N6 to N8 cover the same rules without a browser) |

### 5.4 Real sandbox, `specs/sandbox/` (PW-X, nightly)

| ID | Status | CMS | Case |
| --- | --- | --- | --- |
| PW-X-01 | todo | wp-only | Once-off payment through the real eWAY sandbox hosted page (`@sandbox`) |
| PW-X-02 | todo | wp-only | Recurring set-up through the sandbox, then one cron run against the saved token |
| PW-X-03 | todo | wp-only | Add Credit Card from the back office through the sandbox |
| PW-X-04 | todo | wp-only | Settlement search call against the sandbox returns the documented empty/not-ready shape (the extension excludes test processors from the sync, so this just checks the API contract) |
| PW-X-05 | todo | wp-only | Add a card through the sandbox, then charge it with a sandbox amount that eWAY declines. Records that adding a card does not verify it with the issuer (or that it does), so the behaviour is documented. Not a defect on its own (DEF-14 note) |

---

## 6. Phasing

Update the Status column as phases start and finish.

| Phase | Status | Scope | Why this order |
| --- | --- | --- | --- |
| 0 | In progress (seam S1, PHPUnit fakes and fixture, coverage config done; Playwright scaffold written and passing on WordPress in CI and locally; the other CMSs' account scripts and CI pinning not started) | Seam S1, `EwayFixture`, `FakeEwayHttp`, move SettlementSync helpers, fix coverage config. Playwright scaffold, **CMS-agnostic from the start**: package, config, `cms.ts`, `global-setup.ts`, `base.ts`, `civi.ts`, stub extension and `page.route()` hosted-page stub, setup scripts for all four CMSs (including the Joomla groups and access rules), `frontend-tests.yml` (WordPress), one smoke spec. | Everything else depends on these. Building `cms.ts` first means no spec ever hardcodes a WordPress path, which is far cheaper than retrofitting. Also proves the CI wiring early. |
| 1 | In progress (PU-D, PU-E and PU-F done and passing in CI; PU-B has only PU-B12; the Playwright suites are not started) | PU-B, PU-D, PU-E, PU-F, plus PW-F1, PW-F2, PW-F5, PW-B-cron | Money and schedule logic. A regression here charges or fails to charge real donors. |
| 2 | In progress (PU-A5, PU-G9, PU-G12 and PU-N5 to N8 done or started; the rest and all the Playwright suites not started) | PU-A, PU-C, PU-G, PU-H, PU-I, PU-J, PU-N, PU-O, plus PW-B-settings, PW-B-failed, PW-B-recurforms, PW-B-jobs, PW-B-processor | Admin features and hook behaviour. |
| 3 | In progress (PU-L2, PU-L6 to PU-L10 and the UP suite done; the rest not started) | PU-K, PU-L, PU-M, UP (adds `tests/upgrade/` and `upgrade-tests.yml`), plus PW-F3, PW-F4, PW-F6, PW-B-backoffice, PW-B-status, PW-B-permissions, PW-S | Remaining journeys, hardening, upgrade safety. |
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
- Suspected defects (DEF-1 to DEF-18) in scope each either fixed with an agreed change, or pinned by a
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
| 10 | Decided | **Donor update-billing on a Failed recurring record.** Should the donor's update-billing form recover the record, or is staff Reactivate the only route? | 2026-11-14: the donor path should recover it (DEF-13 confirmed as a bug). Updating billing on a Failed record sets In Progress, failure count 0, retry date cleared. PW-F4-06 and PU-N5 assert that and are `blocked` until the fix lands. Fixed on `release-3.0.0` together with DEF-14. Later finding: donors do not use this form (see the PW-F4 introduction), so the fix applies to the staff form. |
| 11 | Decided | **Status when retries run out (DEF-3).** `Failed` or `Failing`? | 2026-11-14: **Failed.** Core 6.16 treats Failed as an inactive status, so the contact's Recurring Contributions tab offers only View for these records, with no Edit, Cancel or Change Billing Details. The Failed Recurring Contributions list with Reactivate is the way back, and the Cancel Subscription and Update Subscription pages still open by direct address. The original CIVIEWAY-125 report (2019) was that a failed transaction kept being retried after the contribution had been cancelled; cancelling was not possible because the record had been marked Failed, so the fix kept the status In Progress while retries were pending, and cancelled records are never loaded for processing (PU-F2, PU-F27). Marking Failed only once retries are used up keeps that: cancelling still works throughout the retries. `Failing` stays active in core and would keep those links even afterwards, but needs changes to the System Status check, the staff-form recovery and the upgrade step; it was considered and not chosen. PW-B-recurforms-05 checks the consequences. |

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
| 2026-11-15 | PW-B-failed (12) and PW-B-backoffice (10) written for WordPress by a Playwright agent on the local site (snapshot, run, restore, all checked) and green locally: 16 and 13 tests, four of them pinned by `test.fail()` (PW-B-failed-01 for `finance` and a second PW-B-failed-11, PW-B-backoffice-03 and a second PW-B-backoffice-08). The whole suite ran: 166 tests, 149 passed first time and the other 17 (PW-F1 and PW-F2 contribution pages) failed only because another extension on that site prints PHP warnings on the thank you page, and passed on a rerun of those files with `IGNORE_PROBLEMS`. Not yet run on GitHub, so all 22 cases are `wip`. The specs avoid locale dependent date text (dates are picked in the picker or checked as present). Defects: DEF-62 (the Failed list's data request is HTTP 500 for any user who is not an administrator, open), DEF-63 (the "No contact selected" notice is never shown, open), DEF-64 (core sends the browser to a broken address after a declined back-office card payment, open, core's), DEF-65 (`crid` preselection lost when eWAY is chosen from the processor list, open), DEF-66 (every saved-card back-office payment read an undefined `is_recur`, fixed with PU-B18, found by reading the PHP log because the local site hides warnings). New PHPUnit case PU-B18 (`wip` until a CI run). 287 cases. |
| 2026-11-15 | PW-B-jobs (5), PW-B-cron (8), PW-B-status (3) and PW-B-recurforms (5) written for WordPress by a Playwright agent on the local site (snapshot, run, restore, all checked) and green locally (22 tests; PW-B-cron-07 has a second test pinned by `test.fail()`). Not yet run on GitHub, so all 21 cases are `wip`. Plan rows corrected from real behaviour (see each **Found:**). New PHPUnit case PU-H15 (`wip`). Defects found: DEF-58 (a failure then a successful retry skips a month, open, pinned, same decision as DEF-46), DEF-59 (the cancel form printed PHP warnings for every eWAY record, fixed with PU-H15), DEF-60 (dead code in `eway.js` and `changeSubscriptionAmount()`, open), DEF-61 (script error each time the Reactivate dialog opened, fixed). 286 cases: 160 done, 89 in progress, 32 to do. |
| 2026-11-15 | The backend cases are green on GitHub at `024203d`: PHPUnit 452 tests, Playwright 114 passed (WordPress), upgrade tests green at `788eef0`. Two CI-only failures on the first run were fixed in the tests: the settlement sync tests set the settings form's protected property by reflection, and PW-B-settings-07 accepts core's HTTP 500 permission page (fresh site) as well as 403. |
| 2026-11-15 | Back office started: PW-B-settings (8 cases), PW-B-processor (4) and PW-B-permissions (3) written for WordPress by a Playwright agent on the local site (snapshot, run, restore, all checked) and green locally (16 tests; the whole suite also ran, 97 passed, the 17 failures being PHP warnings printed by another extension on that site, which pass with `IGNORE_PROBLEMS=scheduledccrecipients`, and one `test.fail()` that passes on this site's theme). Not yet run on GitHub, so all fifteen are `wip`. Plan rows corrected from real behaviour (see each **Found:**). Defects found: DEF-52 (settings form accepted negative, fractional and exponent retries and delay), DEF-53 (re-enabling the extension left its scheduled jobs off), DEF-54 (saving the settings gave no confirmation) and DEF-55 (PHP 8.2 dynamic property deprecation on save), all fixed with new PHPUnit cases PU-J8 to PU-J10 and PU-H14 (green locally, `wip` until CI runs them); DEF-56 pinned (live credentials always required for an eWAY processor) and DEF-57 open (a site whose processor type pre-dates managed entities logs an error on every flush). The `finance` and `tokenviewer` personas gained `view all contacts`: without it core hides every saved card from them. New helper `fixtures/backend.ts`. 285 cases: 160 done, 67 in progress, 53 to do, 5 dropped. |
| 2026-11-15 | PW-F6 (public endpoints, 6 cases, PW-F6-02 stays `n/a`) written for WordPress and green on GitHub (Playwright run on `2873340`, PHPUnit 440 tests on `d003484`). All six are `wip`. Defects found and fixed: DEF-47 (security: the return page did not check the eWAY transaction against the contribution's invoice and amount), DEF-48 (non-eWAY processor id on the return and token routes), DEF-49 (unknown country or state in `createtoken` printed PHP warnings), DEF-50 (queue row settled before eWAY had a result), DEF-51 (non-numeric processor id gave core's fatal page). 281 cases: 160 done, 48 in progress, 68 to do, 5 dropped. |
| 2026-11-14 | PW-F4 (update billing, 7 cases) and PW-F5 (verification queue, 4 cases) written for WordPress and green on GitHub (Playwright 67 tests passed, PHPUnit 434, workflow runs on `6e95d40`). All eleven are `wip`. Defects found and fixed: DEF-41 (full-page Save lost the recurring id), DEF-42 (PHP warning from the Add Credit Card field), DEF-43 (script error after adding a card), DEF-44 (each card listed twice in the dropdown, found only on the CI site). Logged open for a decision: DEF-45 (no link to the form for a Failed record) and DEF-46 (a failure already moves the schedule date on). A test of mine for DEF-32 was racy (a contribution dated exactly on the window cutoff) and now uses twelve hours back. First CI run of the PW-F4 specs failed four tests for CI-only reasons (the duplicate cards, the HTTP 500 permission page, a cancelled Search Kit request, the checksum-only donor reaching the login page), each handled. 74 cases to do, all Playwright. |
| 2026-11-14 | Four more defects logged as open, from what the Playwright rounds showed: DEF-37 (a declined first payment leaves a Pending recurring record, and the function meant to delete it is never called), DEF-38 (a declined transaction sets the queue row to the success status), DEF-39 (a donor going Back from the hosted page sees core's raw "Duplicate error" message) and DEF-40 (the "payment already completed" refusal is an HTTP 500 error page). Each needs a decision on the behaviour before it is fixed. |
| 2026-11-14 | PW-F2 (recurring set-up) and PW-F3 (event registration) written for WordPress by a Playwright agent on the local site (snapshot, run, restore, all checked) and green on GitHub with PHPUnit (Playwright 45 tests passed, PHPUnit 434 tests, workflow runs on `a9308e6`). All twelve are `wip` (CMS column is all). PW-F2-06 found DEF-36 (a returning donor got a duplicate saved-card row because the dedupe rule only ran in `saveToken()`); fixed in `updateCustomerDetails()` with three PU-D10 tests, and PW-F2-06 now asserts one row. Plan rows corrected from real behaviour: PW-F2-05, PW-F3-02 to PW-F3-05. Local note: the db container's `/var/tmp` keeps a `pw-run-mariadb_10.11.gz` directory after every `ddev snapshot restore`, which blocks the next snapshot until it is deleted (`LOCAL_DEV.md` step 6 now needs to say so). 85 cases to do, all Playwright. |
| 2026-11-14 | PW-F1 completed for WordPress: PW-F1-02 and 07 to 13 written by a Playwright agent on the local site (snapshot, run, restore, all checked) and green on GitHub (28 tests passed, workflow run on `17b4f72`). All 13 PW-F1 cases are `wip` (CMS column is all). Three CI-only differences were handled: the first click on the amount radio could be dropped at phone width (the helper now retries until it sticks); CiviCRM answers the "payment already completed" refusal with an HTTP 500 page, which PW-F1-07 now expects; and on a fresh CI site core's billing fieldset inside Astra is about 450px wide on a 375px screen, so PW-F1-12 is split into the journey (passes) and a form-width test marked `test.fail()` until that is fixed (not an extension defect). DEF-35 found and fixed (the validate hook's null read, covered by PU-H5). PHPUnit green at 431 tests. 97 cases to do, all Playwright. |
| 2026-11-14 | Known gaps logged and fixed: DEF-28 (back-office card ownership, processor and expiry check), DEF-29 and DEF-30 (two unreachable branches in `doPayment()` removed), DEF-31 (`sanitizeToAscii("0")` kept), DEF-32 (settlement sync client injectable, with a new PU-M1 test of a scoped call through the API). DEF-33 (no issuer authorisation when a card is added) was logged and then reclassified as intended, because it is a new feature (FR-3) and not a defect. DEF-34 (a donor cannot list their own cards) was logged and then reclassified as intended, because the function is not offered to donors and FR-1 is the feature that adds it. PHPUnit green on GitHub at `fcf5227` (430 tests). |
| 2026-11-14 | PU-K1 to K3 done. `VerifyPayment::run()` now reads the request and calls a new `resolve()`, which returns `['redirect' => URL]` or `['status' => 400]`; `run()` sends the 400 header and `die()` or the redirect. No second seam was needed: the failure redirect inside `validateEwayContribution()` is the framework's exit exception in tests, which `PagesTest` catches. PHPUnit green on GitHub at `b0696f7` (423 tests). 160 cases done, none blocked. |
| 2026-11-14 | PHPUnit round: 79 cases written by three parallel agents on separate test files and made green on GitHub (PHPUnit 412 tests, upgrade 57 checks, both green on `release-3.0.0` at `1ae8113`): PU-A (nine more), PU-B (all but B12 already done), PU-C, PU-F25 and F26, PU-G, PU-H, PU-I, PU-J, PU-L1, L3 to L5, PU-M, PU-N1 to N4, PU-O. 157 cases done. The first run failed 22 tests, which found DEF-20 and DEF-21 and confirmed DEF-27; the rest were test fixes. Defects found and fixed along the way: DEF-19 to DEF-27. Cases reworded because the code under test is dead or changed: PU-B2 (the comma amount never reaches the conversion), PU-B4 (the `cvv_backoffice_required` branch is unreachable), PU-C1 and C2 (the UpdateBilling variants went with `VerifyUpdateToken`), PU-M1 (a scoped call would reach the real gateway). PU-K1 to K3 stay blocked: the refactor would extract a `resolve()` method from `VerifyPayment::run()`, and the failure redirect in `validateContribution()` needs a second seam. |
| 2026-11-14 | Plan housekeeping. Section 1 and the feature inventory's "Coverage now" column were out of date (they still said there were no tests and PHPUnit-only CI) and are rewritten from the case table. Found by the tagging check in section 0: the plan had two cases with the ID PU-A6. The test for the `_is_test` property (DEF-16) is now PU-A11 and its test is tagged to match; PU-A6 is again only `supportsBackOffice()` and is `todo`. Earlier change log lines that say PU-A6 mean this case, now PU-A11. No counts change. 281 cases. |
| 2026-11-14 | Playwright re-run on GitHub after the country fix: all 11 cases green (six PW-INF, five PW-F1) on a fresh WordPress site, with no PHP output flagged. PW-F1-01, 03, 04, 05 and 06 stay `wip` because their CMS column is all and Drupal, Joomla and Standalone have not run them. 281 cases. |
| 2026-11-14 | First GitHub run of the three workflows on `release-3.0.0` (started on demand): PHPUnit green (150 tests), upgrade green (57 checks, 0 failures), Playwright red for the new cases only. PU-A6, PU-F28 and PU-N9 are `done` (they ran in the green PHPUnit run). PW-F1-03 to 06 failed on GitHub because the fresh CI site has no default country, so the Country box stayed on "- select -" and the form refused to continue; the local site defaulted to Australia and hid it. `fillDonation()` now chooses the country and state explicitly. With the default country cleared locally all 11 Playwright cases pass; the fix is not yet pushed, so PW-F1 stays `wip` until a GitHub run is green. 281 cases. |
| 2026-11-14 | PW-F1 started: `specs/frontend/contribution-once-off.spec.ts` with PW-F1-01, 03, 04, 05 and 06 (`wip`: all pass on the local WordPress site, not yet in CI). The seed now creates a once-off contribution page with an eWAY processor and a price set. The scaffold changed under it: the fake hosted page is served by a small local web server with the fake host mapped to this machine, because Playwright's page.route() does not see the redirect from the site to the hosted page; the tests run on one worker always, since the stub is shared. The collector, which fails a test on PHP output, found three real defects the first time it ran against the thank you page: DEF-16 (`_is_test` created on the fly, printed on the donor's thank you page), DEF-17 and DEF-18 (null passed to `explode()` and `substr()`), all fixed with tests PU-A6, PU-F28 and PU-N9. A fixed transaction id broke the second run (CiviCRM refuses a duplicate), so the specs use a fresh id each time. 281 cases. |
| 2026-11-14 | Workflow triggers matched to the Elections and Event Locations repos: pushes to master, pull requests and on demand (they ran on every push before), for the PHPUnit, upgrade and frontend workflows. A branch gets CI when a pull request is open or when someone runs a workflow on demand, for example `gh workflow run upgrade-tests.yml --ref release-3.0.0` (tried and working: GitHub already knows the workflow from its earlier runs, and the run uses the file on that branch). Also prepared, on a local branch `playwright-writable-extensions-dir` of `ci-workflows` and not pushed, a one-step change that hands `wp-content/uploads/civicrm/ext` to www-data so a setup script can install a helper extension beside the one under test; once it is merged `setup-common.sh`'s fallback is no longer needed on GitHub (it stays for other hosts). |
| 2026-11-14 | The Playwright workflow passes on GitHub: all six PW-INF checks green on a fresh WordPress site, through the extensions-folder fallback. Infrastructure is `full` for WordPress in the CMS table; PW-INF-01 to 06 stay `wip` because their CMS column is all and Drupal, Joomla and Standalone have not run them. |
| 2026-11-14 | The fallback's first version failed on GitHub too: the CI site's `extensionsDir` is an absolute path, not the `[civicrm.files]/ext` placeholder the script assumed. `fixtures/extension-dir.php` now swaps a trailing `/ext` for `/ext-playwright` in either form (and in the URL setting), and stops with the raw values if they do not fit. Tried locally with absolute settings like CI's and the fallback forced: all six PW-INF checks pass. |
| 2026-11-14 | First GitHub run of the Playwright workflow failed in the setup script: the extensions folder is created as root by the bind mount, so the stub extension could not be copied beside the extension under test ("Permission denied"). `setup-common.sh` now falls back to a writable sibling folder, holding links to the existing extensions plus the stub, and points `extensionsDir` and `extensionsURL` at it. Tried on the local site with the fallback forced: all six PW-INF checks pass. PW-INF stays `wip` until a GitHub run passes. |
| 2026-11-14 | Playwright scaffold started in `tests/playwright/`: package and lockfile, config, `cms.ts` (all four CMSs, WordPress run), `civi.ts`, `eway-stub.ts`, `global-setup.ts`, `base.ts` with the problem collector, personas, a WordPress setup script, the shared setup and seed, and a test-support extension that fakes eWAY's HTTP service. `.github/workflows/frontend-tests.yml` added. New group PW-INF (6 cases, `wip`): all six passed against a local WordPress site with all four personas signed in. The collector found real browser noise on first run (an unload permissions-policy console error, now ignored) and flagged a made-up return path that returned 404. Not yet run on GitHub; the account scripts for Drupal, Joomla and Standalone are not written. 278 cases. |
| 2026-11-14 | PU-G9 is `done`: CI passed with the new tests (PHPUnit green; the upgrade workflow repeated 57 passing checks and none failing). No case is `wip` now. |
| 2026-11-14 | PU-G9 now covers every refusal path of `saveToken` (no `pp_id`, no access code, unknown processor added); `wip` until CI runs them. The no-`pp_id` exit uses `civiExit()` instead of `die()` so it can be tested. |
| 2026-11-14 | The UP suite is `done`: the corrected upgrade workflow passed on GitHub with 57 checks and none failing (UP-01 to UP-06 and UP-08 to UP-11; UP-07 stays dropped). |
| 2026-11-14 | PU-H3 and PU-H13 are `done` (CI passed). The first green GitHub run of the upgrade test was a false pass: the verifier counted failures in a variable that was local to the function cv runs the script in, so it printed FAIL lines yet exited 0 (two UP-09 checks failed because that core version stores the menu flag as 1 and 0, not "true" and "false"). Both fixed; a broken record now makes the verifier exit 1, checked locally. The UP cases stay `wip` until a corrected run passes on GitHub. |
| 2026-11-14 | DEF-15 found by the first GitHub run of the upgrade test and fixed: the enable hook failed with "Authorization failed" when the extension was enabled with nobody logged in (cv). New case PU-H13 and first tests for PU-H3 (both `wip`). The upgrade script now installs release 2.8.1 as a CMS user, since that release has the same bug. 272 cases. |
| 2026-11-14 | UP suite started: `tests/upgrade/` (script, seed for 2.8.1, verifier) and `.github/workflows/upgrade-tests.yml` added, covering UP-01 to UP-06 and UP-08 to UP-11, `wip` until a GitHub run passes. A rehearsal against a local site passed every check. UP-04, UP-08 and UP-09 reworded to what the checks do (the Settlement Sync job already exists in 2.8.1; the `update: always` job is not asserted; jobs run through `Job.execute`; the menu flag lives in `module_data`). |
| 2026-11-14 | PU-F27 is `done`: CI passed on `release-3.0.0`. |
| 2026-11-14 | New case PU-F27 (a recurring contribution cancelled while being retried is not retried), from the CIVIEWAY-125 ticket text; the code already behaved this way. Decision 11 now quotes the ticket's original problem. 271 cases. |
| 2026-11-14 | Decision 11: the status given when retries run out is `Failed`. DEF-3 no longer awaits a maintainer check. PW-B-recurforms-05 reworded to check what core does with an inactive Failed record (View only on the contact tab; Edit and Cancel by direct address; Reactivate restores the links). |
| 2026-11-14 | PU-L6 to PU-L10 are `done`: CI passed on `release-3.0.0` with `upgrade_30000`. UP-10 and UP-11 are still `todo`. |
| 2026-11-14 | `upgrade_30000` built (feature FR-2). PU-L6 to PU-L10 are `wip` until CI passes on the release branch. UP-10 and UP-11 are not written yet. |
| 2026-11-14 | FR-2 decision U4 changed to option C: Rule 2 marks records with a retry date and no failures Failed instead of clearing the retry date. PU-L9, PU-L10 and UP-11 reworded to match. 270 cases. |
| 2026-11-14 | FR-2 decision U4 settled (the upgrade step also clears the retry date of records that have one but no failures). New cases PU-L9, PU-L10 and UP-11; PU-L6 and PU-L7 now require `failure_count` above 0 for Rule 1. 270 cases. |
| 2026-11-14 | FR-2 decisions U2, U3 and U6 settled (test-mode and Pending records left alone; saved retry limit used). PU-L7 updated to name them. |
| 2026-11-14 | FR-2 decision U1 settled: the upgrade step updates with a single SQL statement, so no hooks or rules fire. PU-L8 now also checks that no recurring contribution hook fires. |
| 2026-11-14 | Planned an upgrade step, `upgrade_30000` (feature FR-2 in [FEATURES.md](FEATURES.md)), that marks recurring contributions left stuck by releases up to 2.8.2 (In Progress, retry date set, failure count at the limit) as Failed. New cases PU-L6 to PU-L8 and UP-10; UP-03 excludes the stuck records. 267 cases. |
| 2026-11-14 | Statuses brought up to date after CI passed on `release-3.0.0`: every case with a tagged test is `done` (PU-A5, PU-B12, PU-D, PU-E, PU-F, PU-G12, PU-L2, PU-N5 to N8) except PU-G9, which is `wip` because some of its paths are not yet tested. DEF-1 to DEF-12 are now `Fixed on release-3.0.0` (DEF-3 awaiting maintainer check). PU-G8 and PU-G10 are `todo`, not `blocked`: JSON output throws under the test framework, so no `die()` refactor is needed. The test that was tagged PU-G8 was retagged to the new case PU-G12, because it checked the contact reference, not the dedupe rule. Case text that described pre-fix behaviour was rewritten. |
| 2026-11-14 | Link to the new [FEATURES.md](FEATURES.md) added to the header. |
| 2026-11-14 | PW-F4 reworded as the staff-side update billing form (donors do not use it; sites that want donor self-service build their own page that replaces the stored card in place). New cases for the extension's side of that arrangement: PU-F25, PU-F26, PU-G11, PW-B-cron-08. 262 cases. |
| 2026-11-14 | DEF-13 and DEF-14 fixed on `release-3.0.0` and the unused `VerifyUpdateToken` page, route and dead return-URL branches deleted. New suite `SubscriptionManagementTest`: PU-N5 to PU-N8 (PU-N8, expired card, is new) are `wip` until CI passes. PU-K4 to K6 and PW-F6-02 dropped (`n/a`, the page is gone). PW-B-cron-06 and 07, PW-B-failed-11, PW-B-status-03, PW-B-recurforms-05 and PW-F4-06 are no longer `blocked`: the fixes they needed are on this branch. 258 cases. Fixed-status of DEF-1 to DEF-12 still to be updated. |
| 2026-11-14 | DEF-14 added (suspected security issue: `updateSubscriptionBillingInfo()` accepts any token id, found from reading the code). New cases PU-N6 and PU-N7 (characterise it), PW-S-05 (tampered token id), PW-F4-07 (can a donor use the form at all) and PW-X-05 (adding a card is not verified with the issuer). 257 cases. |
| 2026-11-14 | Delivery changed to one long-lived branch, `release-3.0.0`, pushed to the remote and tested on GitHub, with a single pull request to `master` when testing is done. The foundations and the fixes for DEF-1 to DEF-12 are on it; the per-defect pull requests were closed. Plan references to individual pull requests removed. |
| 2026-11-14 | Decision 10: the donor update-billing path should recover a Failed recurring record. DEF-13 is now a confirmed bug. PU-N5 and PW-F4-06 changed from characterisation to asserting the recovery, both `blocked` until the fix lands (PU-N5 needs the DEF-13 fix; PW-F4-06 needs DEF-3 and DEF-13). 16 blocked, 187 to do. |
| 2026-11-14 | Playwright coverage for the DEF-3 fix added. Back office: PW-B-cron-06 and 07, PW-B-failed-11 and 12, PW-B-status-03, PW-B-recurforms-05. Front end: PW-F4-06 only. Six are `blocked` until the fix merges; PW-B-failed-12 is `todo`. New case PU-N5 and new suspected defect DEF-13 (the donor update-billing path does not recover a Failed record; `VerifyUpdateToken` is unreachable). PW-F4-01 to 06 and PU-K5 and K6 rewritten after checking the code: they had assumed `VerifyUpdateToken` was the live page. 252 cases. |
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
