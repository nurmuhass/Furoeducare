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
use Furoeducare\Modules\Directory\AdminDirectoryController;
use Furoeducare\Modules\Directory\DirectoryController;
use Furoeducare\Modules\Directory\DirectoryInteractions;
use Furoeducare\Modules\Directory\DirectoryRepository;
use Furoeducare\Modules\Directory\PublicDirectory;
use Furoeducare\Modules\Disclosure\CandidateController;
use Furoeducare\Modules\Disclosure\CandidateRepository;
use Furoeducare\Modules\Disclosure\DisclosurePolicy;
use Furoeducare\Modules\Disclosure\DisclosureRepository;
use Furoeducare\Modules\Disclosure\PrivacyController;
use Furoeducare\Modules\Disclosure\PrivacyRepository;
use Furoeducare\Modules\Site\Controllers\SiteController;
use Furoeducare\Modules\Parent\ParentController;
use Furoeducare\Modules\Parent\ParentRegistration;
use Furoeducare\Modules\Parent\ParentRepository;
use Furoeducare\Modules\Parent\AdminTutoringController;
use Furoeducare\Modules\Parent\TutoringRepository;
use Furoeducare\Modules\Placement\PlacementController;
use Furoeducare\Modules\Placement\PlacementRepository;
use Furoeducare\Modules\Placement\PlacementSources;
use Furoeducare\Modules\School\SchoolController;
use Furoeducare\Modules\School\SchoolRegistration;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Teacher\TeacherController;
use Furoeducare\Modules\Teacher\Cv\CvJobs;
use Furoeducare\Modules\Teacher\TeacherDocuments;
use Furoeducare\Modules\Teacher\TeacherRepository;
use Furoeducare\Modules\Vacancy\ApplicationRepository;
use Furoeducare\Modules\Vacancy\VacancyController;
use Furoeducare\Modules\Vacancy\VacancyRepository;
use Furoeducare\Modules\Verification\VerificationController;
use Furoeducare\Modules\Verification\TeacherEligibility;
use Furoeducare\Modules\Verification\VerificationEvents;
use Furoeducare\Modules\Verification\VerificationRepository;

return static function (Config $config): Router {
    $root = dirname(__DIR__);
    $view = new View($root . '/resources/views/layout.php', $config->name);
    $site = new SiteController($view);
    $router = new Router();
    $disclosurePolicy = new DisclosurePolicy($config);

    $routes = require $root . '/config/routes.php';
    $routes($router, $site, $disclosurePolicy);

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
    $schools = new SchoolRepository($database);
    $vacancies = new VacancyRepository($database, $schools);
    $eligibility = new TeacherEligibility($database, $profiles);
    $applications = new ApplicationRepository($database, $vacancies, $eligibility);
    $documents = new TeacherDocuments($profiles, $config->teacherDocumentDirectory, $root . '/public');
    $verification = new VerificationRepository($database, $profiles);
    $events = new VerificationEvents($database);
    $teacher = new TeacherController($view, $session, $accounts, $access, $limits, $mailer, $profiles, $documents,
        new CvJobs($database), $verification, $events, $applications, $vacancies);

    $authRoutes = require $root . '/config/auth-routes.php';
    $authRoutes($router, $auth);
    $teacherRoutes = require $root . '/config/teacher-routes.php';
    $teacherRoutes($router, $teacher);
    $schoolRoutes = require $root . '/config/school-routes.php';
    $schoolRoutes($router, new SchoolRegistration($view, $session, $access, $accounts, $mailer, $limits, $schools),
        new SchoolController($view, $session, $access, $schools, $events));
    $verificationRoutes = require $root . '/config/verification-routes.php';
    $verificationRoutes($router, new VerificationController($view, $session, $access, $verification, $schools, $documents));
    $parents = new ParentRepository($database);
    $publicDirectory = new PublicDirectory($database);
    $tutoring = new TutoringRepository($database, $publicDirectory);
    $parentRoutes = require $root . '/config/parent-routes.php';
    $parentRoutes($router, new ParentRegistration($view, $session, $access, $accounts, $mailer, $limits, $parents),
        new ParentController($view, $session, $access, $parents, $tutoring),
        new AdminTutoringController($view, $session, $access, $tutoring, $publicDirectory));
    $directoryRoutes = require $root . '/config/directory-routes.php';
    $directoryRoutes($router,
        new DirectoryController($view, $session, $access, $publicDirectory, new DirectoryInteractions($database, $publicDirectory, $parents, $schools)),
        new AdminDirectoryController($view, $session, $access, new DirectoryRepository($database)));
    $candidateCases = new CandidateRepository($database, $publicDirectory, $schools, $parents);
    $placements = new PlacementRepository($database, new PlacementSources($candidateCases, $publicDirectory), $candidateCases, $eligibility);
    $placementRoutes = require $root . '/config/placement-routes.php';
    $placementRoutes($router, new PlacementController($view, $session, $access, $placements));
    $candidateRoutes = require $root . '/config/candidate-routes.php';
    $candidateRoutes($router, new CandidateController($view, $session, $access, $candidateCases,
        new DisclosureRepository($database, $candidateCases, $disclosurePolicy, $profiles, $documents), $disclosurePolicy));
    $privacyRoutes = require $root . '/config/privacy-routes.php';
    $privacyRoutes($router, new PrivacyController($view, $session, $access, new PrivacyRepository($database), $disclosurePolicy));
    $vacancyRoutes = require $root . '/config/vacancy-routes.php';
    $vacancyRoutes($router, new VacancyController($view, $session, $access, $vacancies, $applications, $schools, $eligibility));
    return $router;
};
