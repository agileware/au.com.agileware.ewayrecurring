# Testing

## Running Tests

Tests use PHPUnit 9 with CiviCRM's headless test framework. Run from the extension root:

```bash
phpunit9 tests/phpunit/CRM/eWAYRecurring/SettlementSyncTest.php
```

Or to run all test suites (using `phpunit.xml.dist`):

```bash
phpunit9
```

The bootstrap file (`tests/phpunit/bootstrap.php`) uses `cv php:boot` to initialise CiviCRM. Run tests from a directory where `cv` can locate your CiviCRM installation.

## Test Directory Structure

```
tests/phpunit/
  CRM/
    eWAYRecurring/         ← Active tests (current pattern)
      SettlementSyncTest.php
    EwayRecurring/         ← Legacy tests (currently broken — see below)
      MyTest.php
      E2ETest.php
      TestCase.php
```

## Known Issues: Legacy Tests

The tests in `tests/phpunit/CRM/EwayRecurring/` are currently broken and not part of the active test suite:

- **Wrong base class:** They extend `CiviUnitTestCase`, which is no longer compatible with this extension's test bootstrap. The correct pattern uses `PHPUnit\Framework\TestCase` with `HeadlessInterface`, `HookInterface`, and `TransactionalInterface`.
- **Syntax error:** `MyTest.php` line 75 has a mismatched quote in a string literal (`'name => "eWay test'`), causing a parse error.
- **Wrong directory casing:** The `EwayRecurring` directory name doesn't match the PSR-0 convention for classes in the `CRM_eWAYRecurring_` namespace.

These tests should be migrated to the `CRM/eWAYRecurring/` directory and rewritten to use the current pattern. This is tracked as a separate piece of work.

## Writing New Tests

New test classes should follow the pattern in `tests/phpunit/CRM/eWAYRecurring/SettlementSyncTest.php`:

- Place in `tests/phpunit/CRM/eWAYRecurring/` (match PSR-0 class path)
- Class name: `CRM_eWAYRecurring_YourClassTest`
- Extend `\PHPUnit\Framework\TestCase`
- Implement `HeadlessInterface, HookInterface, TransactionalInterface`
- Use `setUpHeadless()` to install the extension (not `setUpBeforeClass`)
- Use `Civi\Api4\*` for all database interactions
- `TransactionalInterface` handles rollback automatically — no manual cleanup needed
