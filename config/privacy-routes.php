<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Disclosure\PrivacyController;

return static function (Router $router, PrivacyController $controller): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, PrivacyController::CONTROLS, $controller->handle(...));
        $router->add($method, PrivacyController::STAFF, $controller->handle(...));
    }
};
