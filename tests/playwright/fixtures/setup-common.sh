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
EXT_DIR="${EXT_DIR%/}"

# On the shared GitHub workflow the extension under test is bind mounted into this folder, so Docker creates the
# folder as root and the web user cannot add a second extension beside it ("Permission denied"). In that case
# load extensions from a writable sibling folder instead, holding links to the ones that are already there plus the
# stub. PLAYWRIGHT_FORCE_EXT_FALLBACK=1 takes this route on any site, to try it.
if [ ! -w "${EXT_DIR}" ] || [ -n "${PLAYWRIGHT_FORCE_EXT_FALLBACK:-}" ]; then
  echo "The extensions folder ${EXT_DIR} is not writable here; loading extensions from a writable sibling instead"
  ALT_DIR="$(EXT_APPLY='' ${CV} scr "${HERE}/extension-dir.php" | tail -n 1)"
  ALT_DIR="${ALT_DIR%/}"
  mkdir -p "${ALT_DIR:?}"
  for existing in "${EXT_DIR}"/*/; do
    [ -d "${existing}" ] || continue
    name="$(basename "${existing}")"
    [ -e "${ALT_DIR:?}/${name:?}" ] || ln -s "${EXT_DIR}/${name}" "${ALT_DIR:?}/${name:?}"
  done
  EXT_APPLY=1 ${CV} scr "${HERE}/extension-dir.php" >/dev/null
  echo "Extensions are now loaded from ${ALT_DIR}"
  EXT_DIR="${ALT_DIR}"
fi

rm -rf "${EXT_DIR:?}/${STUB_KEY:?}"
cp -r "${HERE}/stub-extension" "${EXT_DIR:?}/${STUB_KEY:?}"
${CV} flush
${CV} ext:enable "${STUB_KEY}"

echo "== Seed the test data"
${CV} scr "${HERE}/seed-data.php"

echo "== Common setup done"
