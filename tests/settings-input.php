<?php
declare(strict_types=1);

use Furoeducare\Modules\Settings\SettingsInput;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
try {
    expect(SettingsInput::email(' Person@Example.test ') === 'person@example.test', 'Email normalized.');
    expect(SettingsInput::phone('0801 234 5678') === '+2348012345678', 'Phone normalized.');
    expect(SettingsInput::returnPath('/dashboard/teacher?tab=documents') === '/dashboard/teacher?tab=documents', 'Appearance returns to the current internal page.');
    foreach (['//evil.test', 'https://evil.test', '/%2f%2fevil.test', "/contact\r\nX-Test: value", '/settings/email-change?token=secret', '/reset-password?%74oken=secret'] as $path) {
        expect(SettingsInput::returnPath($path) === '/settings/account', 'Appearance redirects reject unsafe or token-bearing destinations.');
    }
    foreach ([['password', 'short', 'short'], ['email', 'bad'], ['phone', 'x'], ['reason', ''], ['name', "<x>\0"]] as $case) {
        [$method, $value] = $case;
        try { SettingsInput::$method($value, ...array_slice($case, 2)); throw new RuntimeException('Accepted invalid ' . $method); }
        catch (\Furoeducare\Modules\Settings\SettingsValidation $error) { expect($error->errors !== [], 'Field error available.'); }
    }
    expect(SettingsInput::password('Settings synthetic password 2026!', 'Settings synthetic password 2026!') === 'Settings synthetic password 2026!', 'Secure password accepted.');
    echo "PASS settings email/phone normalization, password rules and bounded names/reasons.\n";
} catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL); exit(1); }
