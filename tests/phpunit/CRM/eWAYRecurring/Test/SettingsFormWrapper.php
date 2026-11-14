<?php

/**
 * Core's FormWrapper, with the form it built made readable.
 *
 * The wrapper keeps the form in a protected property and only lets tests call
 * a few methods, which is enough to submit a form but not to look at the
 * elements it built. The settings form tests need to see the options of a
 * multi-select, so this subclass hands the form back.
 *
 * Test plan suite PU-J: developer/TEST_PLAN.md.
 */
class CRM_eWAYRecurring_Test_SettingsFormWrapper extends \Civi\Test\FormWrapper {

  /**
   * @return \CRM_Core_Form
   */
  public function form(): CRM_Core_Form {
    return $this->form;
  }

}
