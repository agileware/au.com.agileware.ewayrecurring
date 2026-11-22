#!/usr/bin/env bash
#
# Test environment setup for a WordPress site: roles and users for the personas, then the steps every CMS shares.
#
# This is the SETUP_SCRIPT of the shared playwright-tests workflow. It runs inside the WordPress container as
# www-data, with the extension's folder as its working directory, after WordPress, CiviCRM and the extension
# are up. It is safe to run again.
#
# The personas and their capabilities are in test-users.json. On WordPress a CiviCRM permission is the
# lower case form with underscores, for example "view payment tokens" is the capability view_payment_tokens.
# The other CMSs have their own account scripts (see developer/TEST_PLAN.md section 2.5); only WordPress is done.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
USERS="${HERE}/test-users.json"

# A field of a persona in test-users.json. Capabilities come back as a space separated list of WordPress capabilities.
persona_field() {
  php -r '
    $users = json_decode(file_get_contents($argv[1]), TRUE);
    $p = $users[$argv[2]];
    if ($argv[3] === "capabilities") {
      echo implode(" ", array_map(function ($c) { return strtolower(str_replace(" ", "_", $c)); }, $p["capabilities"]));
    } else {
      echo $p[$argv[3]];
    }
  ' "${USERS}" "$1" "$2"
}

for persona in finance tokenviewer limited; do
  echo "== Persona ${persona}"
  username="$(persona_field "${persona}" username)"
  password="$(persona_field "${persona}" password)"
  email="$(persona_field "${persona}" email)"
  capabilities="$(persona_field "${persona}" capabilities)"

  if ! wp role exists "${persona}" 2>/dev/null; then
    wp role create "${persona}" "eWay test ${persona}"
  fi
  # shellcheck disable=SC2086
  wp cap add "${persona}" ${capabilities}

  if ! wp user get "${username}" >/dev/null 2>&1; then
    wp user create "${username}" "${email}" --role="${persona}" --user_pass="${password}"
  else
    wp user update "${username}" --user_pass="${password}" >/dev/null
  fi
done

bash "${HERE}/setup-common.sh"
