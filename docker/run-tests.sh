#!/usr/bin/env bash
# Runs the extension's PHPUnit suite inside the local docker-compose
# environment. Run ./docker/setup.sh once first.
#
# Usage:
#   ./docker/run-tests.sh                                            # full suite
#   ./docker/run-tests.sh tests/phpunit/CRM/eWAYRecurring/SettlementSyncTest.php
set -euo pipefail

EXTENSION_DIR="/var/www/html/wp-content/uploads/civicrm/ext/au.com.agileware.ewayrecurring"

# PWD must match -w explicitly: tests/phpunit/bootstrap.php shells out to
# `cv` and re-`cd`s to $PWD itself (a workaround for phpunit/codeception
# changing the process's cwd), and docker compose exec doesn't populate
# PWD from -w on its own.
docker compose exec \
  -u www-data \
  -w "$EXTENSION_DIR" \
  -e PWD="$EXTENSION_DIR" \
  wordpress phpunit9 "$@"
