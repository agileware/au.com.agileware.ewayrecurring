#!/usr/bin/env bash
#
# Run a command inside a DDEV site, from the extension's folder, for the Playwright tests.
#
# The tests run cv (and a few other commands) through CIVI_EXEC_PREFIX. `ddev exec` joins its arguments back into one
# string for the container's shell, so an argument with spaces or quotes (a JSON parameter, say) would be split. This
# script quotes every argument first.
#
#   CIVI_EXEC_PREFIX=/full/path/to/ddev-exec.sh npx playwright test
#
# CIVI_EXEC_PREFIX is split on spaces, so the path to this script must not contain any. If your checkout is in a
# folder with spaces in its name, link the script from somewhere that has none:
#
#   ln -s "$PWD/ddev-exec.sh" ~/bin/ddev-exec.sh
#
# Settings, as environment variables:
#
#   DDEV_PROJECT_DIR  (required) the DDEV project folder on this machine, the one that holds .ddev
#   DDEV_EXT_DIR      the extension's folder inside the container. The default is where a WordPress site keeps it:
#                     /var/www/html/wp-content/plugins/civicrm/civicrm/ext/au.com.agileware.ewayrecurring

set -euo pipefail

if [ -z "${DDEV_PROJECT_DIR:-}" ]; then
  echo "ddev-exec.sh: set DDEV_PROJECT_DIR to the DDEV project folder" >&2
  exit 2
fi

EXT_DIR="${DDEV_EXT_DIR:-/var/www/html/wp-content/plugins/civicrm/civicrm/ext/au.com.agileware.ewayrecurring}"

cd "$DDEV_PROJECT_DIR"
exec ddev exec -d "$EXT_DIR" "$(printf '%q ' "$@")"
