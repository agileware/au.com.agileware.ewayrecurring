<?php

/**
 * Test support for the Playwright tests of au.com.agileware.ewayrecurring.
 *
 * Every eWAY client the extension builds goes through CRM_eWAYRecurring_Utils::getEWayClient(), which asks the
 * factory set here for the HTTP service to use. The service in Stub/Http.php answers from a queue the tests write
 * into a CiviCRM setting. No request leaves the server.
 */

/**
 * Implements hook_civicrm_config().
 */
function ewayrecurring_playwright_stub_civicrm_config(&$config) {
  if (!class_exists('CRM_eWAYRecurring_Utils') || !method_exists('CRM_eWAYRecurring_Utils', 'setHttpServiceFactory')) {
    // The extension under test is not enabled yet, or is too old to have the seam.
    return;
  }
  CRM_eWAYRecurring_Utils::setHttpServiceFactory(function ($paymentProcessor) {
    require_once __DIR__ . '/Stub/Http.php';
    return new \Civi\EwayRecurringStub\Http();
  });
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * Tells CiviCRM where this extension's setting definitions are (civix-built extensions get this from generated
 * code; this one is not).
 */
function ewayrecurring_playwright_stub_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  $metaDataFolders[] = __DIR__ . '/settings';
}
