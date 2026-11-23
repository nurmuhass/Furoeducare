<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Admin;

use Closure;
use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Verification\VerificationEvents;

final class StaffGate
{
    public function __construct(private readonly AuthAccess $accounts, private readonly StaffAccess $access, private readonly Closure $database)
    {
    }

    public function check(Request $request): ?Response
    {
        if ($request->path !== '/dashboard/admin' && !str_starts_with($request->path, '/dashboard/admin/')) {
            return null;
        }
        $viewer = $this->accounts->viewer();
        if ($viewer === null) {
            return new Response('', 303, ['Location' => '/login', 'Cache-Control' => 'no-store']);
        }
        $required = StaffRoutes::required($request);
        $allowed = in_array(Role::Admin, $viewer['roles'], true) && $required !== null;
        $permissions = $allowed ? $this->access->permissions($viewer['id']) : [];
        foreach ($required ?? [] as $permission) {
            $allowed = $allowed && in_array($permission, $permissions, true);
        }
        if (!$allowed) {
            VerificationEvents::audit(($this->database)(), $viewer['id'], 'admin.access_denied',
                ['path' => $request->path, 'method' => $request->method, 'required' => $required]);
            return Response::text("Your staff permissions do not allow this management action.\n", 403, ['Cache-Control' => 'private, no-store']);
        }
        return null;
    }
}
