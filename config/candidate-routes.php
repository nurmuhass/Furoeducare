<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Disclosure\CandidateController;

return static function (Router $router, CandidateController $controller): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, CandidateController::ROOT, $controller->owner(...));
        $router->add($method, CandidateController::STAFF, $controller->admin(...));
    }
    $router->add('GET', CandidateController::ROOT . '/profile', $controller->profile(...));
    $router->add('POST', CandidateController::ROOT . '/download', $controller->download(...));
};
