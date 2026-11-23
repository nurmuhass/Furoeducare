<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Email\EmailPreferencesController;

return static function (Router $router, EmailPreferencesController $controller): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, EmailPreferencesController::ROOT, $controller->handle(...));
    }
};
