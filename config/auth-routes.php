<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Auth\AuthController;
use Furoeducare\Modules\Auth\Role;

return static function (Router $router, AuthController $auth): void {
    foreach (['/login', '/register', '/resend-verification', '/verify-email', '/forgot-password', '/reset-password'] as $path) {
        $router->add('GET', $path, $auth->show(...));
        $router->add('POST', $path, $auth->submit(...));
    }
    $router->add('POST', '/logout', $auth->submit(...));

    $router->add('GET', '/dashboard', static fn () => $auth->dashboard(null));
    foreach (Role::cases() as $role) {
        if ($role->hasProfileRegistration()) {
            continue;
        }
        $router->add('GET', $role->dashboard(), static fn () => $auth->dashboard($role));
    }
};
