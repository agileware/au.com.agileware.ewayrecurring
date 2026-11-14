# Testing

## Running Tests

Tests use PHPUnit 9 with CiviCRM's headless test framework. Run the full suite from the extension root:

```bash
phpunit9
```

Or a single file:

```bash
phpunit9 tests/phpunit/CRM/eWAYRecurring/SettlementSyncTest.php
```

The bootstrap file (`tests/phpunit/bootstrap.php`) uses `cv php:boot` to initialise CiviCRM. Run tests from a directory where `cv` can locate your CiviCRM installation.

## Test Directory Structure

```
tests/phpunit/
  CRM/
    eWAYRecurring/
      SettlementSyncTest.php  ← headless (default run)
      E2ETest.php             ← @group e2e (excluded by default - see below)
```

## End-to-end tests

`E2ETest.php` drives real HTTP requests against a live, network-reachable
CiviCRM site and the actual eWAY sandbox gateway - it doesn't run under
`CIVICRM_UF=UnitTests` like the headless tests do, and needs a properly
configured site plus live eWAY sandbox credentials. `phpunit.xml.dist`
excludes `@group e2e` from the default `phpunit9` run; run it explicitly
in a suitable environment:

```bash
phpunit9 --group e2e
```

## Upgrade tests

`tests/upgrade/` checks that a site can upgrade from an older release without losing or changing data.
It is run on GitHub by `.github/workflows/upgrade-tests.yml`, which uses the shared workflow in
`agileware/ci-workflows`: it installs release 2.8.1 on a WordPress and CiviCRM site, seeds it
(`seed-v2.8.1.php`), replaces it with the commit under test, upgrades, and checks the result
(`verify-v3.php`). Every check is tagged with its ID from the UP suite in [TEST_PLAN.md](TEST_PLAN.md).

To rehearse it on a disposable local site that has `cv`, take a database snapshot first, because the
script seeds data and upgrades the site. Install the old release in the extension folder, copy the
version under test somewhere outside the site, and run the script from inside the extension folder:

```bash
EXTENSION_KEY=au.com.agileware.ewayrecurring \
EXTENSION_DIR=<the extension folder, holding release 2.8.1> \
NEW_VERSION_DIR=<a copy of the version under test> \
bash <NEW_VERSION_DIR>/tests/upgrade/ci-upgrade-test.sh
```

Restore the snapshot and the extension folder afterwards. When the seed data needs to change, edit
`seed-v2.8.1.php` and `verify-v3.php` together.

## Test plan and coverage status

[TEST_PLAN.md](TEST_PLAN.md) lists every planned test case with a stable ID and a status, and
records what is covered today. When you add or change a test, tag it with its plan ID (PHPUnit:
`@testPlan PU-F8` in the docblock; Playwright: start the test title with the ID), update the
status in the plan in the same pull request, and refresh its counts.

## Writing New Tests

New test classes should follow the pattern in `tests/phpunit/CRM/eWAYRecurring/SettlementSyncTest.php`:

- Place in `tests/phpunit/CRM/eWAYRecurring/` (match PSR-0 class path)
- Class name: `CRM_eWAYRecurring_YourClassTest`
- Extend `\PHPUnit\Framework\TestCase`
- Implement `HeadlessInterface, HookInterface, TransactionalInterface`
- Use `setUpHeadless()` to install the extension (not `setUpBeforeClass`)
- Use `Civi\Api4\*` for all database interactions
- `TransactionalInterface` handles rollback automatically, so no manual cleanup is needed
