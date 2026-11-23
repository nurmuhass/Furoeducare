<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Training\TrainingController;

return static function (Router $router, TrainingController $controller): void {
    $router->add('GET', TrainingController::ROOT, $controller->catalogue(...));
    $router->add('GET', TrainingController::ROOT . '/module', $controller->module(...));
    $router->add('GET', TrainingController::ROOT . '/package', $controller->package(...));
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, TrainingController::SCHOOL, $controller->school(...));
        $router->add($method, TrainingController::STAFF, $controller->staff(...));
        $router->add($method, TrainingController::STAFF . '/requests', $controller->staffRequests(...));
    }
};
