<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Notification\NotificationController;

return static function (Router $router, NotificationController $controller): void {
    $router->add('GET', '/notifications', $controller->feed(...));
    $router->add('GET', '/notifications/count', $controller->count(...));
    $router->add('GET', '/notifications/open', $controller->open(...));
    $router->add('POST', '/notifications/read', $controller->read(...));
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, '/settings/notifications', $controller->preferences(...));
    }
};
