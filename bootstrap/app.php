<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Router;
use Furoeducare\Core\View;
use Furoeducare\Modules\Admin\AdminController;
use Furoeducare\Modules\Admin\AdminManagement;
use Furoeducare\Modules\Admin\AdminReadModel;
use Furoeducare\Modules\Admin\StaffAccess;
use Furoeducare\Modules\Admin\StaffGate;
use Furoeducare\Modules\Library\LibraryController;
use Furoeducare\Modules\Library\LibraryFiles;
use Furoeducare\Modules\Library\LibraryRepository;
use Furoeducare\Modules\Library\LibraryService;
use Furoeducare\Modules\Training\TrainingCatalogue;
use Furoeducare\Modules\Training\TrainingController;
use Furoeducare\Modules\Training\TrainingRequests;
use Furoeducare\Modules\Notification\NotificationController;
use Furoeducare\Modules\Notification\NotificationInbox;
use Furoeducare\Modules\Notification\NotificationPreferences;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthController;
use Furoeducare\Modules\Auth\AuthMailer;
use Furoeducare\Modules\Auth\AuthService;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Auth\Role;
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
use Furoeducare\Modules\Messaging\MessageContext;
use Furoeducare\Modules\Messaging\MessageRepository;
use Furoeducare\Modules\Messaging\MessagingController;
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
    $mailer = new AuthMailer($config, $database);
    $access = new AuthAccess($session, $accounts);
    $appearance = new \Furoeducare\Modules\Settings\AppearancePreferences($database);
    $settingsRoutes = require $root . '/config/settings-routes.php';
    $settingsRoutes($router, new \Furoeducare\Modules\Settings\SettingsController($view, $session, $access, $limits, $mailer,
        new \Furoeducare\Modules\Settings\AccountSettings($database, $config), $appearance));
    $staffAccess = new StaffAccess($database);
    $adminManagement = new AdminManagement($database);
    $staffGate = new StaffGate($access, $staffAccess, $database);
    $router->before($staffGate->check(...));
    $adminRoutes = require $root . '/config/admin-routes.php';
    $adminRoutes($router, new AdminController($view, $session, $access, $staffAccess, new AdminReadModel($database), $adminManagement,
        new \Furoeducare\Modules\Admin\OperationalReports($database)));
    $notificationInbox = new NotificationInbox($database);
    $view->share(static function (array $data) use ($access, $session, $appearance, $notificationInbox, $staffAccess, $adminManagement): array {
        if (!($data['signedIn'] ?? false) && !isset($_COOKIE['FUROEDUCARE_SESSION'])) {
            return ['notificationUnread' => 0, 'accountAppearance' => null];
        }
        $viewer = $access->viewer();
        $shared = ['notificationUnread' => $viewer === null ? 0 : $notificationInbox->unread($viewer['id']),
            'accountAppearance' => $viewer === null ? null : $appearance->get($viewer['id'])];
        if ($viewer !== null) {
            $shared += ['signedIn' => true, 'csrf' => $session->csrf(),
                'appearanceReturn' => \Furoeducare\Modules\Settings\SettingsInput::returnPath($_SERVER['REQUEST_URI'] ?? '/settings/account')];
        }
        $path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
        if ($viewer !== null) {
            $effectiveRole = ($data['role'] ?? null) instanceof Role ? $data['role'] : null;
            if (($data['parentTutoring'] ?? false) === true
                && ($path === '/find-teachers' || str_starts_with($path, '/find-teachers/'))) $effectiveRole = Role::Parent;
            $context = \Furoeducare\UI\DashboardNavigation::context($viewer['roles'], $path, $session->dashboardContext(), $effectiveRole);
            if ($context !== null) {
                $session->dashboardContext($context);
                $permissions = $context === Role::Admin ? $staffAccess->permissions($viewer['id']) : [];
                $navigationPath = $path;
                $navigationQuery = $_GET;
                // Form validation and profile-section routes render the selected tab
                // at a POST URL; use the controller's tab, not untrusted query state.
                if ($context !== Role::Admin && isset($data['tab'])
                    && ($path === $context->dashboard() || str_starts_with($path, $context->dashboard() . '/'))) {
                    $tab = $data['tab'];
                    if (is_string($tab) || $tab instanceof \BackedEnum) {
                        $navigationPath = $context->dashboard();
                        $navigationQuery['tab'] = $tab instanceof \BackedEnum ? $tab->value : $tab;
                    }
                }
                $shared += ['dashboardWorkspace' => true, 'dashboardRole' => $context,
                    'dashboardRoles' => $viewer['roles'], 'dashboardEmail' => $viewer['email'],
                    'dashboardNavigation' => \Furoeducare\UI\DashboardNavigation::items($context, $permissions),
                    'dashboardPath' => $navigationPath, 'dashboardQuery' => $navigationQuery, 'dashboardCsrf' => $session->csrf()];
            }
        }
        if ($viewer !== null && in_array(Role::Admin, $viewer['roles'], true)
            && ($path === '/dashboard/admin' || str_starts_with($path, '/dashboard/admin/'))) {
            $settings = $adminManagement->settings();
            $permissions = $staffAccess->permissions($viewer['id']);
            $writePermissions = \Furoeducare\Modules\Admin\StaffRoutes::required(\Furoeducare\Core\Request::fromServer(
                ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $path], [],
            ));
            $shared += ['adminWorkspace' => true, 'adminPermissions' => $permissions,
                'adminRoles' => array_column($staffAccess->roles($viewer['id']), 'name'), 'adminPath' => $path,
                'adminNotice' => $settings['admin_notice'], 'adminSupportEmail' => $settings['support_email'],
                'adminCanManage' => $writePermissions === null || array_diff($writePermissions, $permissions) === [],
                'adminCanDisclose' => in_array('cv_access.manage', $permissions, true),
                'adminType' => in_array($_GET['type'] ?? '', ['teacher', 'school', 'parent'], true) ? $_GET['type'] : 'teacher'];
        }
        return $shared;
    });
    $notificationRoutes = require $root . '/config/notification-routes.php';
    $notificationRoutes($router, new NotificationController($view, $session, $access, $notificationInbox, new NotificationPreferences($database)));
    $libraryFiles = new LibraryFiles($config->libraryStorageDirectory, $root . '/public');
    $library = new LibraryRepository($database, $libraryFiles);
    $libraryRoutes = require $root . '/config/library-routes.php';
    $libraryRoutes($router, new LibraryController($view, $session, $access, $library, new LibraryService($library, $libraryFiles)));
    $trainingRoutes = require $root . '/config/training-routes.php';
    $trainingRoutes($router, new TrainingController($view, $session, $access, new TrainingCatalogue($database), new TrainingRequests($database)));
    $messages = new MessageRepository($database, new MessageContext($database), $limits);
    $messageRoutes = require $root . '/config/messaging-routes.php';
    $messageRoutes($router, new MessagingController($view, $session, $access, $messages));
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
