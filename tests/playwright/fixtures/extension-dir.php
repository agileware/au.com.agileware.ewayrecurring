<?php
/**
 * Works out the writable sibling folder "ext-playwright" next to the folder CiviCRM loads extensions from, and
 * with EXT_APPLY=1 points CiviCRM at it. Prints the new folder's path. Run with `cv scr` by setup-common.sh.
 *
 * Both the folder setting (extensionsDir) and the URL setting (extensionsURL) are either an absolute value or
 * a [civicrm.files]/... placeholder, and must end in /ext for the swap to be possible. It stops with the raw
 * values if they do not.
 */
$dirRaw = (string) (Civi::settings()->get('extensionsDir') ?: '[civicrm.files]/ext');
$urlRaw = (string) (Civi::settings()->get('extensionsURL') ?: '[civicrm.files]/ext');

$swap = function (string $value): ?string {
  $new = preg_replace('#/ext/?$#', '/ext-playwright', $value, 1, $count);
  return $count ? $new : NULL;
};
$newDir = $swap($dirRaw);
$newUrl = $swap($urlRaw);
if ($newDir === NULL || $newUrl === NULL) {
  fwrite(STDERR, "Cannot work out a sibling of the extensions folder. extensionsDir is \"$dirRaw\" and extensionsURL is \"$urlRaw\"; both must end in /ext.\n");
  exit(1);
}

if (getenv('EXT_APPLY')) {
  Civi::settings()->set('extensionsDir', $newDir);
  Civi::settings()->set('extensionsURL', $newUrl);
}
echo Civi::paths()->getPath($newDir), "\n";
