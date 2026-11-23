<?php

declare(strict_types=1);

use Furoeducare\Modules\Notification\NotificationInput;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

expect(NotificationInput::path('/dashboard/school/training?id=42') === '/dashboard/school/training?id=42', 'Accept typed internal dashboard links.');
expect(NotificationInput::path('/dashboard') === '/dashboard', 'Accept the authorised role dashboard redirect.');
foreach (['https://example.test', '//example.test', '/\\example.test', '/dashboard/../private', '/dashboard%2fadmin', '/logout', '/messages?id=1&owner=2', '/dashboard#fragment'] as $path) {
    try {
        NotificationInput::path($path);
        throw new RuntimeException('Unsafe notification navigation accepted.');
    } catch (DomainException $error) {
        expect($error->getCode() === 422, 'Invalid paths fail validation.');
    }
}
echo "PASS typed notification navigation rejects external, encoded and unrelated destinations.\n";
