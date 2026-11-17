<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Teacher\TeacherController;

return static function (Router $router, TeacherController $teacher): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, '/register/teacher', $teacher->register(...));
        $router->add($method, '/verify-teacher', $teacher->verify(...));
    }
    $router->add('GET', '/dashboard/teacher', $teacher->onboarding(...));
    $router->add('GET', '/dashboard/teacher/cv', $teacher->onboarding(...));
    $router->add('GET', '/dashboard/teacher/documents/download', $teacher->onboarding(...));
    foreach (['setup', 'profile', 'cv', 'documents/upload', 'documents/remove', 'verification/submit'] as $action) {
        $router->add('POST', '/dashboard/teacher/' . $action, $teacher->onboarding(...));
    }
};
