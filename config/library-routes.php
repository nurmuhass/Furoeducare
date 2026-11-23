<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Library\LibraryController;

return static function (Router $router, LibraryController $controller): void {
    $router->add('GET', LibraryController::ROOT, $controller->catalogue(...));
    $router->add('GET', LibraryController::ROOT . '/resource', $controller->resource(...));
    $router->add('GET', LibraryController::ROOT . '/saved', $controller->saved(...));
    $router->add('GET', LibraryController::ROOT . '/history', $controller->history(...));
    $router->add('POST', LibraryController::ROOT . '/save', $controller->bookmark(...));
    foreach (['GET', 'HEAD'] as $method) {
        $router->add($method, LibraryController::ROOT . '/cover', $controller->cover(...));
        $router->add($method, LibraryController::ROOT . '/download', $controller->download(...));
        $router->add($method, LibraryController::STAFF . '/file', $controller->preview(...));
    }
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, LibraryController::STAFF, $controller->staff(...));
    }
};
