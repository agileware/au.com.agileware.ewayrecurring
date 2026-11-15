<?php

/**
 * Test support for the Playwright tests of au.com.agileware.ewayrecurring.
 *
 * Every eWAY client the extension builds goes through CRM_eWAYRecurring_Utils::getEWayClient(), which asks the
 * factory set here for the HTTP service to use. The service in Stub/Http.php answers from a queue the tests write
 * into a CiviCRM setting. No request leaves the server, with one exception: the test-mode record of the processor
 * named "Playwright eWAY Sandbox", used only by the real-sandbox cases (PW-X), is given the real HTTP service.
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
    // The real eWAY sandbox cases (tests/playwright/specs/sandbox/) use a processor of this name. Its test-mode record
    // talks to eWAY's sandbox for real, with the credentials the spec saved on it; every other processor, and this
    // one in live mode (which must never reach eWAY), is answered by the stub.
    if (($paymentProcessor['name'] ?? '') === 'Playwright eWAY Sandbox' && !empty($paymentProcessor['is_test'])) {
      return new \Eway\Rapid\Service\Http(
        $paymentProcessor['user_name'],
        $paymentProcessor['password'],
        \Eway\Rapid\Contract\Client::ENDPOINT_SANDBOX
      );
    }
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
