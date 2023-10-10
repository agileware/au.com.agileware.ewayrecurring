<?php

namespace Civi\Ewayactions;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use CRM_eWAYRecurring_ExtensionUtil as E;

class CompilerPass implements CompilerPassInterface {

  /**
   * @inheritDoc
   */
  public function process( ContainerBuilder $container ) {
    if ($container->hasDefinition('action_provider')) {
      $actionProviderDefinition = $container->getDefinition('action_provider');
      $actionProviderDefinition->addMethodCall('addAction', [
        'EwayactionsFillPaymentTokenMeta',
        'Civi\Ewayactions\Actions\FillPaymentTokenMeta',
        E::ts('EWAY: Fill Payment Token Metadata'),
        []]);
    }
  }
}
