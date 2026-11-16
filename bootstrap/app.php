<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Router;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthController;
use Furoeducare\Modules\Auth\AuthMailer;
use Furoeducare\Modules\Auth\AuthService;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Site\Controllers\SiteController;
use Furoeducare\Modules\Teacher\TeacherController;
use Furoeducare\Modules\Teacher\TeacherDocuments;
use Furoeducare\Modules\Teacher\TeacherRepository;

return static function (Config $config): Router {
    $root = dirname(__DIR__);
    $view = new View($root . '/resources/views/layout.php', $config->name);
    $site = new SiteController($view);
    $router = new Router();

    $routes = require $root . '/config/routes.php';
    $routes($router, $site);

    $connection = null;
    $database = static function () use ($config, &$connection): PDO {
        return $connection ??= Database::connect($config);
    };
    $session = new AuthSession(
        $config->authSessionDirectory,
        $config->environment === 'production' || str_starts_with($config->baseUrl, 'https://')
    );
    $accounts = new AuthService($database);
    $limits = new RateLimiter($database);
    $mailer = new AuthMailer($config, $config->authMailDirectory);
    $access = new AuthAccess($session, $accounts);
    $auth = new AuthController($view, $session, $accounts, $limits, $mailer, $access);

    $profiles = new TeacherRepository($database);
    $documents = new TeacherDocuments($profiles, $config->teacherDocumentDirectory, $root . '/public');
    $teacher = new TeacherController($view, $session, $accounts, $access, $limits, $mailer, $profiles, $documents);

    $authRoutes = require $root . '/config/auth-routes.php';
    $authRoutes($router, $auth);
    $teacherRoutes = require $root . '/config/teacher-routes.php';
    $teacherRoutes($router, $teacher);
    return $router;
};
