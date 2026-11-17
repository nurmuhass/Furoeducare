<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Parent\AdminTutoringController;
use Furoeducare\Modules\Parent\ParentController;
use Furoeducare\Modules\Parent\ParentRegistration;

return static function (Router $router, ParentRegistration $signup, ParentController $parent, AdminTutoringController $staff): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, '/register/parent', $signup->register(...));
        $router->add($method, '/verify-parent', $signup->verify(...));
        $router->add($method, AdminTutoringController::ROOT, $staff->handle(...));
    }

    $router->add('GET', '/dashboard/parent', $parent->dashboard(...));
    foreach (['profile', 'requests', 'requests/submit'] as $action) {
        $router->add('POST', '/dashboard/parent/' . $action, $parent->dashboard(...));
    }
};
