<?php

declare(strict_types=1);

use Furoeducare\Modules\Email\EmailTemplates;
use Furoeducare\Modules\Notification\NotificationInput;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

// Every accepted shared navigation destination must survive deferred mail rendering.
foreach (['/dashboard', '/notifications', '/messages?id=7', '/candidate-requests', '/placements?id=4',
    '/dashboard/school/vacancies?id=8', '/dashboard/admin/training/requests?id=2', '/settings/account'] as $path) {
    $approved = NotificationInput::path($path);
    foreach (EmailTemplates::names() as $template) {
        if (EmailTemplates::category($template) === null) {
            continue;
        }
        $rendered = EmailTemplates::render($template, ['path' => $approved], 'https://example.test');
        expect(str_contains($rendered['text'], 'https://example.test' . $path), 'An accepted notification path renders for optional email: ' . $path);
    }
}
echo "PASS central and deferred email navigation accept the same approved destinations.\n";
