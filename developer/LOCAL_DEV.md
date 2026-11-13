# Local development environment

A `docker-compose.yml` for running this extension's PHPUnit suite locally,
mirroring the `civicrm-phpunit-tests` reusable workflow in
[agileware/ci-workflows](https://github.com/agileware/ci-workflows) so
results match CI.

## Setup

Requires Docker Hub access to the private `agilewareprojects/wordpress`
image:

```bash
docker login
```

Then, from the extension root:

```bash
docker compose up -d
./docker/setup.sh
```

`setup.sh` installs WordPress and CiviCRM, creates the dedicated headless
test database that `Civi\Test` (`HeadlessInterface` tests) requires, and
installs `cv`/`phpunit9`. It's safe to re-run.

The site itself is at http://localhost:8080 (admin/admin), with the
extension mounted live from your working copy.

## Running tests

```bash
./docker/run-tests.sh                                                    # full suite
./docker/run-tests.sh tests/phpunit/CRM/eWAYRecurring/SettlementSyncTest.php  # one file
```

Edits to the extension's PHP files take effect immediately (bind-mounted),
no rebuild needed.

## Tearing down

```bash
docker compose down -v
```
