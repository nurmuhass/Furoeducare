<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Directory\AdminDirectoryController;
use Furoeducare\Modules\Directory\DirectoryController;

return static function (Router $router, DirectoryController $directory, AdminDirectoryController $admin): void {
    $root = DirectoryController::ROOT;
    $router->add('GET', $root, $directory->index(...));
    $router->add('GET', $root . '/profile', $directory->profile(...));
    $router->add('GET', $root . '/photo', $directory->photograph(...));
    $router->add('GET', $root . '/requests', $directory->myRequests(...));
    $router->add('GET', DirectoryController::ADMIN_REQUESTS, $directory->staffRequests(...));
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, $root . '/request', $directory->request(...));
        $router->add($method, $root . '/saved', $directory->saved(...));
        $router->add($method, AdminDirectoryController::ROOT, $admin->handle(...));
    }
};
