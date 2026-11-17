<?php

declare(strict_types=1);

namespace Furoeducare\Modules\School;

use Furoeducare\Core\Request;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthMailer;
use Furoeducare\Modules\Auth\AuthService;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\ProfileRegistration;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Auth\Role;

final class SchoolRegistration extends ProfileRegistration
{
    public function __construct(
        View $view, AuthSession $session, AuthAccess $access, AuthService $accounts,
        AuthMailer $mailer, RateLimiter $limits, SchoolRepository $schools,
    ) {
        parent::__construct(
            $view, $session, $access, $accounts, $mailer, $limits, Role::School,
            static fn (Request $request): array => SchoolInput::profile($request, registration: true),
            $schools->create(...), __DIR__ . '/Views/registration.php',
            'School and institution registration', 'Verify your school email', 'Your email is verified. Your school is pending review.',
        );
    }
}
