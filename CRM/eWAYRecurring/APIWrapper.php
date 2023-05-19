<?php

use Civi\API\Event\AuthorizeEvent;

class CRM_eWAYRecurring_APIWrapper {

  /**
   * React to authorization requests to allow overriding permissions on API calls
   */
  public static function authorize(AuthorizeEvent $event) {
    $callback = [ self::class, 'authorize_' . $event->getEntityName() ];

    if (is_callable($callback)) {
      call_user_func($callback, $event);
    }
  }


  protected static function authorize_PaymentToken(AuthorizeEvent $event) {
    $requiredPermission = ($event->getActionName() == 'get') ? 'view payment tokens' : 'edit payment tokens';

    if (
      CRM_Core_Permission::check('access CiviContribute', $event->getUserID())
      || CRM_Core_Permission::check($requiredPermission, $event->getUserID())
    ) {
      $event->authorize();
    }
  }

}
