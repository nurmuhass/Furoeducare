<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Verification\VerificationController;

return static function (Router $router, VerificationController $controller): void {
    foreach (['', '/teachers', '/documents', '/documents/download', '/corrections'] as $path) {
        $router->add('GET', VerificationController::ROOT . $path, $controller->handle(...));
    }
    foreach (['/teachers/review', '/documents/review'] as $path) {
        $router->add('POST', VerificationController::ROOT . $path, $controller->handle(...));
    }
};
