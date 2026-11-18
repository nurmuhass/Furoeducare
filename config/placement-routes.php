<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Placement\PlacementController;

return static function (Router $router, PlacementController $controller): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, PlacementController::ROOT, $controller->participant(...));
        $router->add($method, PlacementController::STAFF, $controller->staff(...));
    }
};
