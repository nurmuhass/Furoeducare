<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Messaging\MessagingController;

return static function (Router $router, MessagingController $controller): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, MessagingController::ROOT, $controller->owner(...));
        $router->add($method, MessagingController::STAFF, $controller->staff(...));
    }
    $router->add('POST', MessagingController::ROOT . '/read', $controller->readOwner(...));
    $router->add('POST', MessagingController::STAFF . '/read', $controller->readStaff(...));
};
