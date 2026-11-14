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

## Playwright tests

The browser tests live in `tests/playwright/`. They need a site that has CiviCRM and this extension, and they
change that site: the setup script adds roles, users, a stub extension and seed data. **Never run them against a
site you care about.** Use a disposable site, and snapshot it first so you can put it back exactly as it was.

The README in that folder lists every option and explains how the fake eWAY works. This section is the safe way to
run them on a development machine. The commands use DDEV and a WordPress site as the example. On another setup,
replace the snapshot, copy and run-inside-the-site commands with your own; the steps stay the same.

### Before you start

- A disposable WordPress site with CiviCRM and this extension installed. It does not need to be pristine, but
  nothing on it should be precious.
- Node 20 or later, and Chromium for Playwright: `cd tests/playwright && npm ci && npx playwright install chromium`.
- A CiviCRM administrator login for the tests. Create a dedicated one rather than using a real account:
  `ddev exec "wp user create pwadmin pwadmin@example.test --role=administrator --user_pass='<a password>'"`.

### 1. Snapshot the site and check it worked

```bash
cd /path/to/ddev-project
ddev snapshot --name pw-run
echo "snapshot exit status: $?"
ddev snapshot --list
```

Stop if the exit status is not 0 or the snapshot is not listed. Without a snapshot there is no way back, and a
failed snapshot gives no other sign of failing. Everything below assumes this one exists.

### 2. Put your working copy on the site

Keep a copy of the extension folder the site had, then copy yours over it. Leave `vendor` alone, since the site's
own Composer install is the one that matches its PHP.

```bash
EXT=/path/to/ddev-project/wp-content/plugins/civicrm/civicrm/ext/au.com.agileware.ewayrecurring
cp -a "$EXT" /tmp/ext-backup-pw
rsync -a --delete --exclude=.git --exclude=vendor --exclude=developer --exclude=.github \
  --exclude=node_modules --exclude=playwright-report --exclude=test-results ./ "$EXT"/
```

### 3. Run the setup script inside the site

```bash
ddev exec -d /var/www/html/wp-content/plugins/civicrm/civicrm/ext/au.com.agileware.ewayrecurring \
  "bash tests/playwright/fixtures/setup-environment.sh"
```

It installs the stub extension (the fake eWAY), creates the test roles and users, and seeds the processors, the
once-off contribution page and a donor with a saved card. It is safe to run twice.

### 4. Make the site behave like the CI site

A site you have used for development has defaults that a fresh GitHub site does not. A test that only passes
because of them fails in CI, and that has already happened once (a missing default country). Before you run,
clear what you know differs:

```bash
ddev exec "cv api4 Setting.set '{\"values\":{\"defaultContactCountry\":null}}'"
```

Look for other settings you changed on this site that the setup script does not set, and undo them for the run.

### 5. Run the tests

The tests run `cv` inside the site through `CIVI_EXEC_PREFIX`. The script `tests/playwright/ddev-exec.sh` does that
for DDEV and quotes each argument, which a plain `ddev exec` does not. `CIVI_EXEC_PREFIX` is split on spaces, so the
script's path must not contain any; if your checkout is in a folder with spaces in its name, link it first:
`ln -s "$PWD/tests/playwright/ddev-exec.sh" ~/bin/ddev-exec.sh`.

```bash
cd tests/playwright
BASE_URL=https://my-project.ddev.site \
WP_ADMIN_USER=pwadmin WP_ADMIN_PASS='<the password>' \
DDEV_PROJECT_DIR=/path/to/ddev-project \
CIVI_EXEC_PREFIX=/path/to/ddev-exec.sh \
PERSONAS=admin \
npx playwright test
```

`PERSONAS=admin` signs in only the administrator, for a site that does not have the other test users. If another
extension on the site prints PHP warnings, list them in `IGNORE_PROBLEMS` (see the README) for this run only. Never
set it on CI, where a warning is a real finding.

### 6. Put everything back

Do this even when the tests failed, and check each step worked.

```bash
cd /path/to/ddev-project
ddev snapshot restore pw-run
find "$EXT" -mindepth 1 -delete && rsync -a /tmp/ext-backup-pw/ "$EXT"/
find . -maxdepth 8 -type d \( -name ewayrecurring_playwright_stub -o -name ext-playwright \) -print
ddev snapshot --cleanup --name pw-run --yes
rm -rf /tmp/ext-backup-pw
```

The setup script puts the stub extension in the site's extensions folder, not in your extension's folder, so
restoring the database and your folder does not remove it. The `find` line lists what to delete: the folder
`ewayrecurring_playwright_stub`, and the folder `ext-playwright` if the setup used its fallback (the fallback is for
sites where the web server cannot write to the usual extensions folder, so it rarely appears on a development
site). Look at each path it prints, then remove it with `rm -rf`.

After a restore, look in the database container for a leftover `pw-run-mariadb_10.11.gz` **directory** (the restore unpacks the snapshot there). It is not the snapshot and not the live data, and the next `ddev snapshot --name pw-run` fails with "Is a directory" until it is deleted. Look at it, then remove exactly that path:

```bash
ddev exec -s db "ls /var/tmp"
ddev exec -s db "rm -rf /var/tmp/pw-run-mariadb_10.11.gz"
```

Then check the site: the contact, contribution page and processor counts should be what they were, and the test
users should be gone. If `ddev snapshot --list` still shows `pw-run`, or its file is left under `/var/tmp` in the
database container, delete that too.

### 7. Run it on GitHub before calling a case done

A green local run is not enough. The CI site is fresh: no default country, a different PHP version, and an extensions
folder the web server cannot write to. Push the branch and run the workflow on demand (see
[Testing](TESTING.md#running-the-workflows-on-github)), then look at the failures, if any, in the logs and the
screenshots that the run uploads.
