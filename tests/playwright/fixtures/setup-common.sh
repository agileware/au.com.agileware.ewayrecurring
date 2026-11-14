#!/usr/bin/env bash
#
# Test environment setup that every CMS shares: install the eWAY stub extension and seed the data.
#
# Run it from inside the site (the extension's folder is fine), as the web user, after the CMS-specific
# account setup. setup-environment.sh (WordPress) calls it.
#
#   CIVI_CV   the cv command, default cv (the Joomla development site uses jcv)

set -euo pipefail

CV="${CIVI_CV:-cv}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STUB_KEY="ewayrecurring_playwright_stub"

echo "== Install the eWAY stub extension"
# The folder CiviCRM loads extensions from: its extensionsDir setting, or the default under the CiviCRM files.
EXT_DIR="$(${CV} ev '$dir = Civi::settings()->get("extensionsDir") ?: "[civicrm.files]/ext"; echo Civi::paths()->getPath($dir);')"
mkdir -p "${EXT_DIR:?}"
rm -rf "${EXT_DIR:?}/${STUB_KEY:?}"
cp -r "${HERE}/stub-extension" "${EXT_DIR:?}/${STUB_KEY:?}"
${CV} flush
${CV} ext:enable "${STUB_KEY}"

echo "== Seed the test data"
${CV} scr "${HERE}/seed-data.php"

echo "== Common setup done"
