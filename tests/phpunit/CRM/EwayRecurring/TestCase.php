<?php

class CRM_EwayRecurring_TestCase extends PHPUnit\Framework\TestCase {

  /**
   * @var int
   */
  protected $priceSetID;

  protected $eventFeeBlock;

  protected $_ids;

  private $_apiversion = 3;

  /**
   * Create a paid event.
   *
   * @param array $params
   *
   * @return array
   */
  protected function eventCreatePaid($params) {
    $event = $this->eventCreate($params);
    $eor_params = [
      'qfKey' => '331093f85d7c82716db04ed34a7338ef_3459',
      'entryURL' => 'http://localhost:8080/wp-admin/admin.php?page=CiviCRM&amp;q=civicrm%2Fevent%2Fmanage%2Fregistration&amp;page=CiviCRM&amp;reset=1&amp;action=update&amp;id=7&amp;component=event&amp;qfKey=331093f85d7c82716db04ed34a7338ef_3459',
      'is_online_registration' => '1',
      'registration_link_text' => 'Register Now',
      'registration_start_date' => '',
      'registration_end_date' => '',
      'is_multiple_registrations' => '1',
      'max_additional_participants' => '9',
      'allow_same_participant_emails' => '1',
      'dedupe_rule_group_id' => '',
      'expiration_time' => '',
      'allow_selfcancelxfer' => '',
      'selfcancelxfer_time' => '0',
      'intro_text' => '',
      'footer_text' => '',
      'custom_pre_id' => '12',
      'custom_post_id' => '',
      'additional_custom_pre_id' => '',
      'additional_custom_post_id' => '',
      'confirm_title' => 'Confirm Your Registration Information',
      'confirm_text' => '',
      'confirm_footer_text' => '',
      'is_email_confirm' => '1',
      'confirm_email_text' => '',
      'cc_confirm' => '',
      'bcc_confirm' => '',
      'confirm_from_name' => 'Event Template Dept.',
      'confirm_from_email' => 'event_templates@example.org',
      'thankyou_title' => 'Thanks for Registering!',
      'thankyou_text' => '',
      'thankyou_footer_text' => '',
      'cancelURL' => 'http://localhost:8080/wp-admin/admin.php?page=CiviCRM&amp;q=civicrm%2Fevent%2Fmanage&amp;reset=1',
      '_qf_default' => 'Registration:upload',
      'MAX_FILE_SIZE' => '2097152',
      '_qf_Registration_upload' => 'Save',
      'is_template' => '0',
      'id' => $event['id'],
      'is_confirm_enabled' => FALSE,
      'requires_approval' => FALSE,
    ];

    CRM_Event_BAO_Event::add($eor_params);

    $this->priceSetID = $this->eventPriceSetCreate(55, 0, 'Radio');
    CRM_Price_BAO_PriceSet::addTo('civicrm_event', $event['id'], $this->priceSetID);
    $priceSet = CRM_Price_BAO_PriceSet::getSetDetail($this->priceSetID, TRUE, FALSE);
    $priceSet = CRM_Utils_Array::value($this->priceSetID, $priceSet);
    $this->eventFeeBlock = CRM_Utils_Array::value('fields', $priceSet);
    return $event;
  }

  /**
   * Create an Event.
   *
   * @param array $params
   *   Name-value pair for an event.
   *
   * @return array
   * @throws \Exception
   */
  public function eventCreate($params = []) {
    // if no contact was passed, make up a dummy event creator
    if (!isset($params['contact_id'])) {
      $params['contact_id'] = $this->_contactCreate([
        'contact_type' => 'Individual',
        'first_name' => 'Event',
        'last_name' => 'Creator',
      ]);
    }

    // set defaults for missing params
    $params = array_merge([
      'start_date' => date('Ymd', strtotime('+1 year')),
      'end_date' => date('Ymd', strtotime('+1 year 1 day')),
      'title' => 'eWay testing',
      'event_title' => 'eWay testing',
      'summary' => 'For testing eWay process.',
      'event_description' => '',
      'event_type_id' => '1',
      'participant_listing_id' => '1',
      'is_public' => '1',
      'is_online_registration' => '1',
      'event_full_text' => 'This event is currently full.',
      'is_monetary' => '1',
      'financial_type_id' => '4',
      'is_map' => '0',
      'is_active' => '1',
      'fee_label' => 'Event Fee',
      'is_show_location' => '1',
      'default_role_id' => '1',
      'confirm_title' => 'Confirm Your Registration Information',
      'is_email_confirm' => '1',
      'confirm_from_name' => 'Event Template Dept.',
      'confirm_from_email' => 'event_templates@example.org',
      'thankyou_title' => 'Thanks for Registering!',
      'is_pay_later' => '0',
      'pay_later_text' => 'I will send payment by check',
      'is_partial_payment' => '0',
      'is_multiple_registrations' => '1',
      'max_additional_participants' => '0',
      'allow_same_participant_emails' => '1',
      'has_waitlist' => '0',
      'allow_selfcancelxfer' => '0',
      'selfcancelxfer_time' => '0',
      'templete_id' => '6',
      'is_template' => '0',
      'template_title' => 'Paid Conference with Online Registration',
      'currency' => 'USD',
      'is_share' => '1',
      'is_confirm_enabled' => '1',
      'is_billing_required' => '0',
      'contribution_type_id' => '4',
    ], $params);

    return $this->callAPISuccess('Event', 'create', $params);
  }

  /**
   * Private helper function for calling civicrm_contact_add.
   *
   * @param array $params
   *   For civicrm_contact_add api function call.
   *
   * @return int
   *   id of Household created
   * @throws \Exception
   *
   */
  private function _contactCreate($params) {
    $result = $this->callAPISuccess('contact', 'create', $params);
    if (!empty($result['is_error']) || empty($result['id'])) {
      throw new \Exception('Could not create test contact, with message: ' . \CRM_Utils_Array::value('error_message', $result) . "\nBacktrace:" . \CRM_Utils_Array::value('trace', $result));
    }
    return $result['id'];
  }

  /**
   * wrap api functions.
   * so we can ensure they succeed & throw exceptions without litterering the
   * test with checks
   *
   * @param string $entity
   * @param string $action
   * @param array $params
   * @param mixed $checkAgainst
   *   Optional value to check result against, implemented for getvalue,.
   *   getcount, getsingle. Note that for getvalue the type is checked rather
   *   than the value for getsingle the array is compared against an array
   *   passed in - the id is not compared (for better or worse )
   *
   * @return array|int
   */
  public function callAPISuccess($entity, $action, $params, $checkAgainst = NULL) {
    $params = array_merge([
      'version' => $this->_apiversion,
      'debug' => 1,
    ],
      $params
    );
    switch (strtolower($action)) {
      case 'getvalue':
        return $this->callAPISuccessGetValue($entity, $params, $checkAgainst);
      case 'getsingle':
        return $this->callAPISuccessGetSingle($entity, $params, $checkAgainst);
      case 'getcount':
        return $this->callAPISuccessGetCount($entity, $params, $checkAgainst);
    }
    $result = $this->civicrm_api($entity, $action, $params);
    $this->assertAPISuccess($result, "Failure in api call for $entity $action");
    return $result;
  }

  /**
   * This function exists to wrap api getValue function & check the result
   * so we can ensure they succeed & throw exceptions without litterering the
   * test with checks There is a type check in this
   *
   * @param string $entity
   * @param array $params
   * @param string $type
   *   Per http://php.net/manual/en/function.gettype.php possible types.
   *   - boolean
   *   - integer
   *   - double
   *   - string
   *   - array
   *   - object
   *
   * @return array|int
   */
  public function callAPISuccessGetValue($entity, $params, $type = NULL) {
    $params += [
      'version' => $this->_apiversion,
      'debug' => 1,
    ];
    $result = $this->civicrm_api($entity, 'getvalue', $params);
    if ($type) {
      if ($type == 'integer') {
        // api seems to return integers as strings
        $this->assertTrue(is_numeric($result), "expected a numeric value but got " . print_r($result, 1));
      }
      else {
        $this->assertType($type, $result, "returned result should have been of type $type but was ");
      }
    }
    return $result;
  }

  /**
   * A stub for the API interface. This can be overriden by subclasses to
   * change how the API is called.
   *
   * @param $entity
   * @param $action
   * @param array $params
   *
   * @return array|int
   */
  public function civicrm_api($entity, $action, $params) {
    return civicrm_api($entity, $action, $params);
  }

  /**
   * This function exists to wrap api getsingle function & check the result
   * so we can ensure they succeed & throw exceptions without litterering the
   * test with checks
   *
   * @param string $entity
   * @param array $params
   * @param array $checkAgainst
   *   Array to compare result against.
   *   - boolean
   *   - integer
   *   - double
   *   - string
   *   - array
   *   - object
   *
   * @return array|int
   * @throws Exception
   */
  public function callAPISuccessGetSingle($entity, $params, $checkAgainst = NULL) {
    $params += [
      'version' => $this->_apiversion,
      'debug' => 1,
    ];
    $result = $this->civicrm_api($entity, 'getsingle', $params);
    if (!is_array($result) || !empty($result['is_error']) || isset($result['values'])) {
      throw new Exception('Invalid getsingle result' . print_r($result, TRUE));
    }
    if ($checkAgainst) {
      // @todo - have gone with the fn that unsets id? should we check id?
      $this->checkArrayEquals($result, $checkAgainst);
    }
    return $result;
  }

  /**
   * This function exists to wrap api getValue function & check the result
   * so we can ensure they succeed & throw exceptions without litterering the
   * test with checks There is a type check in this
   *
   * @param string $entity
   * @param array $params
   * @param null $count
   *
   * @return array|int
   * @throws Exception
   */
  public function callAPISuccessGetCount($entity, $params, $count = NULL) {
    $params += [
      'version' => $this->_apiversion,
      'debug' => 1,
    ];
    $result = $this->civicrm_api($entity, 'getcount', $params);
    if (!is_int($result) || !empty($result['is_error']) || isset($result['values'])) {
      throw new Exception('Invalid getcount result : ' . print_r($result, TRUE) . " type :" . gettype($result));
    }
    if (is_int($count)) {
      $this->assertEquals($count, $result, "incorrect count returned from $entity getcount");
    }
    return $result;
  }

  /**
   * Check that api returned 'is_error' => 0.
   *
   * @param array $apiResult
   *   Api result.
   * @param string $prefix
   *   Extra test to add to message.
   */
  public function assertAPISuccess($apiResult, $prefix = '') {
    if (!empty($prefix)) {
      $prefix .= ': ';
    }
    $errorMessage = empty($apiResult['error_message']) ? '' : " " . $apiResult['error_message'];
    if (!empty($apiResult['debug_information'])) {
      $errorMessage .= "\n " . print_r($apiResult['debug_information'], TRUE);
    }
    if (!empty($apiResult['trace'])) {
      $errorMessage .= "\n" . print_r($apiResult['trace'], TRUE);
    }
    $this->assertEquals(0, $apiResult['is_error'], $prefix . $errorMessage);
  }

  /**
   * Create a price set for an event.
   *
   * @param int $feeTotal
   * @param int $minAmt
   * @param string $type
   *
   * @return int
   *   Price Set ID.
   */
  protected function eventPriceSetCreate($feeTotal, $minAmt = 0, $type = 'Text') {
    // creating price set, price field
    $paramsSet['title'] = 'Price Set';
    $paramsSet['name'] = CRM_Utils_String::titleToVar('Price Set');
    $paramsSet['is_active'] = TRUE;
    $paramsSet['extends'] = 1;
    $paramsSet['min_amount'] = $minAmt;
    $result = civicrm_api3('PriceSet', 'get', [
      'sequential' => 1,
      'name' => "Price_Set",
    ]);
    if ($result['count'] > 0) {
      $priceSet = CRM_Price_BAO_PriceSet::findById($result['id']);
    }
    else {
      $priceSet = CRM_Price_BAO_PriceSet::create($paramsSet);
    }
    $this->_ids['price_set'] = $priceSet->id;

    $result = civicrm_api3('PriceField', 'get', [
      'sequential' => 1,
      'label' => "Price Field",
    ]);
    if ($result['count'] <= 0) {
      $paramsField = [
        'label' => 'Price Field',
        'name' => CRM_Utils_String::titleToVar('Price Field'),
        'html_type' => $type,
        'price' => $feeTotal,
        'option_label' => ['1' => 'Price Field'],
        'option_value' => ['1' => $feeTotal],
        'option_name' => ['1' => $feeTotal],
        'option_weight' => ['1' => 1],
        'option_amount' => ['1' => 1],
        'is_display_amounts' => 1,
        'weight' => 1,
        'options_per_line' => 1,
        'is_active' => ['1' => 1],
        'price_set_id' => $this->_ids['price_set'],
        'is_enter_qty' => 1,
        'financial_type_id' => $this->getFinancialTypeId('Event Fee'),
      ];
      if ($type === 'Radio') {
        $paramsField['is_enter_qty'] = 0;
        $paramsField['option_value'][2] = $paramsField['option_weight'][2] = $paramsField['option_amount'][2] = 100;
        $paramsField['option_label'][2] = $paramsField['option_name'][2] = 'hundy';
      }
      CRM_Price_BAO_PriceField::create($paramsField);
      $fields = $this->callAPISuccess('PriceField', 'get', ['price_set_id' => $this->_ids['price_set']]);
      $this->_ids['price_field'] = array_keys($fields['values']);
      $fieldValues = $this->callAPISuccess('PriceFieldValue', 'get', ['price_field_id' => $this->_ids['price_field'][0]]);
      $this->_ids['price_field_value'] = array_keys($fieldValues['values']);
    }

    return $this->_ids['price_set'];
  }


  /**
   * Return financial type id on basis of name
   *
   * @param string $name Financial type m/c name
   *
   * @return int
   */
  public function getFinancialTypeId($name) {
    return CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialType', $name, 'id', 'name');
  }
}