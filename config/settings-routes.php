<?php
declare(strict_types=1);
use Furoeducare\Core\Router;
use Furoeducare\Modules\Settings\SettingsController;
return static function (Router $router, SettingsController $controller): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, '/settings/account', $controller->account(...));
        $router->add($method, '/settings/email-change', $controller->emailChange(...));
    }
};
