<?php

declare(strict_types=1);

use Furoeducare\Core\Request;
use Furoeducare\Modules\Admin\StaffPermissions;
use Furoeducare\Modules\Admin\StaffRoutes;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

expect(StaffRoutes::required(Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard/admin/library'], [])) === ['library.view'], 'Library reads require their scope.');
expect(StaffRoutes::required(Request::fromServer(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/dashboard/admin/library'], [])) === ['library.manage'], 'Library writes require management scope.');
expect(StaffRoutes::required(Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard/admin/verification/documents/download'], [])) === ['documents.view'], 'Private files require explicit document permission.');
expect(StaffRoutes::required(Request::fromServer(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/dashboard/admin/candidate-requests'], ['action' => 'decide'])) === ['cv_access.manage'], 'Disclosure decisions need a separate permission.');
expect(StaffRoutes::required(Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard/admin/unmapped'], [])) === null, 'Unknown management routes fail closed.');
expect(count(StaffPermissions::navigation(['library.view'])) === 1, 'Navigation does not expose unrelated management modules.');
echo "PASS scoped read/write, private-file and CV decision permissions and filtered navigation.\n";
