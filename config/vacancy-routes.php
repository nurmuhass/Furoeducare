<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Vacancy\VacancyController;

return static function (Router $router, VacancyController $vacancies): void {
    $router->add('GET', VacancyController::PUBLIC, $vacancies->browse(...));
    $router->add('GET', VacancyController::PUBLIC . '/view', $vacancies->publicDetail(...));
    $router->add('POST', VacancyController::PUBLIC . '/apply', $vacancies->apply(...));
    $router->add('POST', '/dashboard/teacher/saved-jobs', $vacancies->saveJob(...));
    $router->add('POST', '/dashboard/teacher/applications/withdraw', $vacancies->withdraw(...));
    $router->add('GET', VacancyController::STAFF . '/applications', $vacancies->staffApplications(...));
    foreach (['GET', 'POST'] as $method) {
        $router->add($method, VacancyController::SCHOOL, $vacancies->school(...));
        $router->add($method, VacancyController::STAFF, $vacancies->staff(...));
        $router->add($method, VacancyController::STAFF . '/application', $vacancies->staffApplication(...));
    }
};
