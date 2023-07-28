<?php

function civicrm_api3_eway_recurring_fillTokensMeta($params) {
  $response = CRM_eWAYRecurring_PaymentToken::fillTokensMeta();

  return civicrm_api3_create_success($response, $params);
}
