<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Parent;

use Furoeducare\Core\Request;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthMailer;
use Furoeducare\Modules\Auth\AuthService;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\ProfileRegistration;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Auth\Role;

final class ParentRegistration extends ProfileRegistration
{
    public function __construct(
        View $view, AuthSession $session, AuthAccess $access, AuthService $accounts,
        AuthMailer $mailer, RateLimiter $limits, ParentRepository $parents,
    ) {
        parent::__construct(
            $view, $session, $access, $accounts, $mailer, $limits, Role::Parent,
            static fn (Request $request): array => ParentInput::profile($request, registration: true),
            $parents->create(...), __DIR__ . '/Views/registration.php',
            'Parent and guardian registration', 'Verify your parent account email', 'Your email is verified. Your parent account is ready.',
        );
    }
}
