#!/usr/bin/env bash
#
# Upgrade test: install the old release, seed it with data, replace it with the
# version being tested, upgrade, and check nothing was lost or changed.
#
# Run by the shared workflow civicrm-extension-upgrade-tests.yml in
# agileware/ci-workflows, inside the WordPress container, as www-data. See
# .github/workflows/upgrade-tests.yml and developer/TEST_PLAN.md (the UP suite).
#
# Given by the workflow:
#   EXTENSION_KEY    the extension's key
#   EXTENSION_DIR    where the extension is installed from, holding the OLD version
#   NEW_VERSION_DIR  the version being tested (read-only)

set -euo pipefail

: "${EXTENSION_KEY:?EXTENSION_KEY is required}"
: "${EXTENSION_DIR:?EXTENSION_DIR is required}"
: "${NEW_VERSION_DIR:?NEW_VERSION_DIR is required}"

HERE="${NEW_VERSION_DIR}/tests/upgrade"
export EWAYRECURRING_UPGRADE_STATE="${EWAYRECURRING_UPGRADE_STATE:-/tmp/ewayrecurring-upgrade-state.json}"
rm -f "${EWAYRECURRING_UPGRADE_STATE}"

# cv must run from inside the site, and NEW_VERSION_DIR is outside it.
cd "${EXTENSION_DIR}"

echo "== 1. Install the old version"
# Install as a CMS user: release 2.8.1's enable hook asks APIv4 to check permissions, which fails with
# nobody logged in (DEF-15, fixed in 3.0.0). The workflow's WordPress administrator is "admin".
CV_USER="${UPGRADE_TEST_USER:-admin}"
cv --user="${CV_USER}" ext:enable "${EXTENSION_KEY}"
echo "Installed version:     $(cv ev "echo CRM_Extension_System::singleton()->getMapper()->keyToInfo('${EXTENSION_KEY}')->version;")"
echo "Stored schema version: $(cv ev "echo CRM_Core_BAO_Extension::getSchemaVersion('${EXTENSION_KEY}');")"

echo "== 2. Seed the old version with data"
cv php:script "${HERE}/seed-v2.8.1.php"

echo "== 3. Replace the old version with the version being tested"
# Work from a folder that survives the delete, then come back.
cd /
find "${EXTENSION_DIR}" -mindepth 1 -delete
cp -r "${NEW_VERSION_DIR}/." "${EXTENSION_DIR}/"
cd "${EXTENSION_DIR}"
echo "New version files in place: $(cv ev "echo CRM_Extension_System::singleton()->getMapper()->keyToInfo('${EXTENSION_KEY}')->version;")"

echo "== 4. Upgrade"
cv flush
# cv has deprecated ext:upgrade-db in favour of updb; use whichever this cv offers.
if cv ext:upgrade-db --help >/dev/null 2>&1; then
  cv ext:upgrade-db
else
  cv updb
fi
cv flush

echo "== 5. Check the result"
cv php:script "${HERE}/verify-v3.php"

echo "== Upgrade test passed"
