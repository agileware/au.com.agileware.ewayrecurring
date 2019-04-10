<?php
use _ExtensionUtil as E;
/**
 * EwayContributionTransactions.create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_eway_contribution_transactions_create_spec(&$spec) {
}
/**
 * EwayContributionTransactions.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_eway_contribution_transactions_create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}
/**
 * EwayContributionTransactions.delete API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_eway_contribution_transactions_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}
/**
 * EwayContributionTransactions.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_eway_contribution_transactions_get($params) {
  return _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}
/**
 * EwayContributionTransactions.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_eway_contribution_transactions_validate($params) {
  $eWayUtils = new CRM_eWAYRecurring_eWAYRecurringUtils();
  $response = $eWayUtils->validatePendingTransactions($params);
  return civicrm_api3_create_success($response, $params, 'EwayContributionTransactions', 'validate');
}