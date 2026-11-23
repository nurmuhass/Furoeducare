<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

try {
    $root = dirname(__DIR__);
    $work = $root . '/storage/local-preview/security-core-' . bin2hex(random_bytes(6));
    mkdir($work, 0700, true);
    serve($root, ['APP_ENV' => 'development', 'APP_LOG_FILE' => $work . '/app.log'], $work . '/server.log', static function (string $base): void {
        foreach (['/', '/login', '/privacy-policy', '/missing-security-route'] as $path) {
            [$status, $headers] = request($base, $path);
            expect($status === ($path === '/missing-security-route' ? 404 : 200), 'Route responds normally.');
            expect(($headers['x-frame-options'] ?? '') === 'DENY', 'HTML/error routes prevent framing.');
            $policy = $headers['content-security-policy'] ?? '';
            foreach (["default-src 'self'", "script-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", "form-action 'self'"] as $rule) expect(str_contains($policy, $rule), 'CSP includes ' . $rule);
            expect(($headers['referrer-policy'] ?? '') === 'no-referrer', 'Account link query strings are not sent as browser referrers.');
            expect(!isset($headers['x-powered-by']), 'PHP version header removed.');
        }
        foreach (['/.env', '/.env.example', '/.git/config', '/composer.lock', '/storage/mail/message.eml', '/storage/teacher-documents/example.pdf', '/vendor/autoload.php'] as $path) {
            [$status, , $body] = request($base, $path);
            expect($status === 404 && !str_contains($body, 'DB_PASSWORD'), 'Nonpublic paths are not served by the development entry point.');
        }
    });
    serve($root, ['APP_ENV' => 'production', 'APP_LOG_FILE' => $work . '/failure.log', 'DB_PORT' => 'bad-secret-port'], $work . '/failure-server.log', static function (string $base): void {
        [$status, $headers, $body] = request($base, '/');
        expect($status === 500 && !str_contains($body, 'bad-secret-port') && !str_contains($body, 'Stack trace'), 'Production configuration failure stays generic.');
        expect(($headers['x-frame-options'] ?? '') === 'DENY' && isset($headers['content-security-policy']) && ($headers['referrer-policy'] ?? '') === 'no-referrer', 'Bootstrap failures receive security headers too.');
    });
    $unsafeSessions = $root . '/public/security-session-fixture-' . bin2hex(random_bytes(6));
    try {
        serve($root, ['APP_ENV' => 'development', 'AUTH_SESSION_DIR' => $unsafeSessions, 'APP_LOG_FILE' => $work . '/session-error.log'], $work . '/session-server.log', static function (string $base): void {
            [$status] = request($base, '/login');
            expect($status === 500, 'A public session storage configuration fails closed.');
        });
        expect(!is_dir($unsafeSessions), 'Misconfigured session storage is rejected before any file is created.');
    } finally {
        // This fixed-name isolated fixture can contain only this test's guest session.
        if (realpath(dirname($unsafeSessions)) === realpath($root . '/public') && is_dir($unsafeSessions)) {
            foreach (glob($unsafeSessions . '/sess_*') as $fixture) if (is_file($fixture)) unlink($fixture);
            rmdir($unsafeSessions);
        }
    }
    echo "PASS restrictive browser headers, private path refusal and production error privacy.\n";
} catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL); exit(1); }
