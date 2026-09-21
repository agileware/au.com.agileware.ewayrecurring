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

## Writing New Tests

New test classes should follow the pattern in `tests/phpunit/CRM/eWAYRecurring/SettlementSyncTest.php`:

- Place in `tests/phpunit/CRM/eWAYRecurring/` (match PSR-0 class path)
- Class name: `CRM_eWAYRecurring_YourClassTest`
- Extend `\PHPUnit\Framework\TestCase`
- Implement `HeadlessInterface, HookInterface, TransactionalInterface`
- Use `setUpHeadless()` to install the extension (not `setUpBeforeClass`)
- Use `Civi\Api4\*` for all database interactions
- `TransactionalInterface` handles rollback automatically — no manual cleanup needed
