<?php

namespace Civi\Ewayactions\Actions;

use Civi\ActionProvider\Parameter\ParameterBagInterface;
use Civi\ActionProvider\Parameter\SpecificationBag;
use Civi\ActionProvider\Action\AbstractAction;
use Civi\ActionProvider\Parameter\Specification;
use CRM_eWAYRecurring_ExtensionUtil as E;
use CRM_eWAYRecurring_PaymentToken;

class FillPaymentTokenMeta extends AbstractAction {

  /**
   * Run the action
   *
   * @param   ParameterBagInterface  $parameters
   *   The parameters to this action.
   * @param   ParameterBagInterface  $output
   *   The parameters this action can send back
   *
   * @return void
   */
  protected function doAction(ParameterBagInterface $parameters, ParameterBagInterface $output) {
    try {
      $payment_processor_id = $this->configuration->getParameter('payment_processor_id');
      $payment_token_id = $parameters->getParameter('payment_token_id');

      CRM_eWAYRecurring_PaymentToken::fillTokenMetaSingle($payment_processor_id,$payment_token_id);
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Returns the specification of the configuration options for the actual action.
   *
   * @return SpecificationBag
   */
  public function getConfigurationSpecification() {
    return new SpecificationBag;
  }

  /**
   * Returns the specification of the parameters of the actual action.
   *
   * @return SpecificationBag
   */
  public function getParameterSpecification() {
    return new SpecificationBag([
      new Specification('payment_processor_id', 'Integer', E::ts('Payment Processor'), TRUE, NULL, NULL, NULL, FALSE),
      new Specification('payment_token_id', 'Integer', E::ts('Payment Token ID'), TRUE, NULL, NULL, NULL, FALSE),
    ]);
  }

  /**
   * Returns the specification of the output parameters of this action.
   *
   * This function could be overridden by child classes.
   *
   * @return SpecificationBag
   */
  public function getOutputSpecification() {
    return new SpecificationBag([
      new Specification('execution', 'Boolean', E::ts('Execution Success')),
    ]);
  }
}
