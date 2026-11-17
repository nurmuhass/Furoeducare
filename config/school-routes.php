<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\School\SchoolController;
use Furoeducare\Modules\School\SchoolRegistration;

return static function (Router $router, SchoolRegistration $signup, SchoolController $school): void {
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, '/register/school', $signup->register(...));
        $router->add($method, '/verify-school', $signup->verify(...));
    }

    $router->add('GET', '/dashboard/school', $school->dashboard(...));
    foreach (['profile', 'drafts', 'vacancies/publish', 'recruitment/submit'] as $action) {
        $router->add('POST', '/dashboard/school/' . $action, $school->dashboard(...));
    }
    foreach (['teachers/restricted', 'shortlists'] as $feature) {
        $router->add('GET', '/dashboard/school/' . $feature, $school->dashboard(...));
    }
    $router->add('GET', '/dashboard/admin/schools', $school->review(...));
    $router->add('POST', '/dashboard/admin/schools/review', $school->review(...));
};
