<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Admin\AdminController;

return static function (Router $router, AdminController $controller): void {
    $router->add('GET', AdminController::ROOT, $controller->overview(...));
    $router->add('GET', AdminController::ROOT . '/reports/export', $controller->exportReports(...));
    foreach (['search' => 'search', 'accounts' => 'accounts', 'accounts/view' => 'account', 'cv-requests' => 'cvRequests', 'listings' => 'listings', 'email' => 'email', 'reports' => 'reports', 'audit' => 'audit'] as $path => $method) {
        $router->add('GET', AdminController::ROOT . '/' . $path, $controller->{$method}(...));
    }
    $router->add('POST', AdminController::ROOT . '/accounts/action', $controller->accountAction(...));
    foreach (['staff', 'roles', 'settings'] as $action) {
        foreach (['GET', 'POST'] as $method) $router->add($method, AdminController::ROOT . '/' . $action, $controller->{$action}(...));
    }
};
