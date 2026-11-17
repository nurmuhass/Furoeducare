<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\ConfigurationException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\Router;

require __DIR__ . '/support/http.php';

$root = dirname(__DIR__);
$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'furoeducare-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700);
$serverLog = $scratch . '/server.log';
$appLog = $scratch . '/app.log';
$dotenv = $scratch . '/.env';
$healthyEnvironment = [
    'APP_ENV' => 'development',
    'APP_NAME' => 'Furoeducare <test>',
    'APP_LOG_FILE' => $appLog,
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'foundation_test',
    'DB_USERNAME' => 'foundation_test',
    'DB_PASSWORD' => 'unused-test-password',
];

$exitCode = 0;
try {
    serve($root, $healthyEnvironment, $serverLog, function (string $base): void {
        [$status, $headers, $body] = request($base, '/');
        expect($status === 200, "Home route must return HTTP 200, received $status.");
        expect(str_contains($headers['content-type'], 'text/html'), 'Home must be HTML.');
        expect(str_contains($body, 'Furoeducare &lt;test&gt;') && !str_contains($body, '<test>'), 'Layout must escape the configured name.');


        $publicPages = [
            '/about' => 'About us',
            '/services' => 'Our services',
            '/digital-library' => 'Digital library',
            '/training-workshops' => 'Training and workshops',
            '/contact' => 'Contact us',
            '/login' => 'Log in',
            '/register' => 'Create an account',
            '/register/teacher' => 'Teacher and NYSC registration',
            '/register/school' => 'School and institution registration',
            '/register/parent' => 'Parent and guardian registration',
            '/privacy-policy' => 'Privacy policy',
            '/terms' => 'Terms of use',
        ];
        foreach ($publicPages as $path => $heading) {
            [$pageStatus, , $page] = request($base, $path);
            expect($pageStatus === 200, "$path must be a public route.");
            expect(preg_match('/<h1\b[^>]*>\s*' . preg_quote($heading, '/') . '\s*<\/h1>/', $page) === 1, "$path must render its own main heading.");
            expect(str_contains($page, 'aria-label="Primary"') && str_contains($page, 'aria-label="Footer"'), "$path must use the shared navigation and footer.");
            if (in_array($path, ['/privacy-policy', '/terms'], true)) {
                expect(str_contains($page, 'Draft awaiting legal review'), "$path must disclose its draft status.");
            }
        }
        foreach (['/digital-library', '/training-workshops'] as $path) {
            [, , $page] = request($base, $path);
            expect(str_contains($page, 'data-empty-state'), "$path must offer an intentional empty state.");
        }
        foreach (['/contact'] as $path) {
            [$postStatus] = request($base, $path, 'POST', 'preview=1');
            expect($postStatus === 405, "$path must not accept backend submissions.");
        }

        foreach (['/login', '/register', '/register/teacher', '/verify-teacher', '/register/school', '/verify-school', '/register/parent', '/verify-parent', '/logout'] as $path) {
            [$csrfStatus] = request($base, $path, 'POST', 'email=example%40example.test');
            expect($csrfStatus === 403, "$path must reject a POST without a valid CSRF token.");
        }

        [$status, $headers, $body] = request($base, '/health?probe=1');
        expect($status === 200 && json_decode($body, true) === ['status' => 'ok'], 'Health must return a minimal liveness response.');
        expect(str_contains($headers['content-type'], 'application/json'), 'Health must be JSON.');
        expect($headers['cache-control'] === 'no-store', 'Health response must not be cached.');

        [$status, , $body] = request($base, '/health', 'HEAD');
        expect($status === 200 && $body === '', 'HEAD must use the GET route without a body.');
        [$status] = request($base, '/not-found');
        expect($status === 404, 'Unknown routes must return HTTP 404.');
        [$status, $headers] = request($base, '/health', 'POST');
        expect($status === 405 && $headers['allow'] === 'GET, HEAD', 'Known paths with the wrong method must return HTTP 405 and Allow.');
        [$status] = request($base, '/health', 'POST', '_method%5B%5D=DELETE');
        expect($status === 400, 'An array-valued form override must return HTTP 400.');
        [$status] = request($base, '/health?_method=DELETE');
        expect($status === 200, 'A query value must not change the request method.');

        [$status, $headers, $body] = request($base, '/assets/app.css');
        expect($status === 200 && str_contains($headers['content-type'], 'text/css') && $body !== '', 'The web server must serve CSS.');

        foreach (['classroom.jpg', 'educators.jpg'] as $image) {
            [$assetStatus, $assetHeaders, $assetBody] = request($base, '/assets/' . $image);
            expect($assetStatus === 200 && $assetHeaders['content-type'] === 'image/jpeg' && str_starts_with($assetBody, "\xFF\xD8"), "$image must be served as a JPEG.");
        }
        foreach (['/.env', '/.env.example', '/composer.json', '/storage/logs/app.log'] as $privatePath) {
            [$status] = request($base, $privatePath);
            expect($status === 404, "The document root must keep $privatePath private.");
        }
    });
    echo "PASS public routes, legal labels, empty states, preview POST rejection, image assets, health and HTTP foundation.\n";

    $invalidEnvironment = array_replace($healthyEnvironment, [
        'APP_ENV' => 'production',
        'DB_PORT' => 'private-config-sentinel',
    ]);
    serve($root, $invalidEnvironment, $serverLog, function (string $base) use ($appLog): void {
        [$status, $headers, $body] = request($base, '/health');
        expect($status === 500, 'Invalid production configuration must return HTTP 500.');
        expect($body === "Internal Server Error\n", 'The production response must be generic.');
        $requestId = $headers['x-request-id'] ?? '';
        expect(preg_match('/^[a-f0-9]{16}$/', $requestId) === 1, 'An error response must include its request ID.');
        expect(is_file($appLog), 'A bootstrap error must write an application log.');
        $log = file_get_contents($appLog);
        $record = json_decode(trim($log), true, flags: JSON_THROW_ON_ERROR);
        expect($record['request_id'] === $requestId && $record['level'] === 'error', 'Log must match the response request ID.');
        expect($record['message'] === 'DB_PORT must be an integer from 1 to 65535.', 'Log must name the configuration problem.');
        expect(!str_contains($log, 'private-config-sentinel'), 'Log must omit the invalid configuration value.');
    });
    echo "PASS safe production configuration failure and correlated application log.\n";

    require $root . '/vendor/autoload.php';
    $router = new Router();
    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $router->add($method, '/resource', static fn (Request $request): Response => Response::text($request->method));
        $direct = Request::fromServer(['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/resource'], []);
        expect($router->dispatch($direct)->body === $method, "Direct $method must dispatch.");
    }
    foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
        $overridden = Request::fromServer(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/resource'],
            ['_method' => strtolower($method)]
        );
        expect($router->dispatch($overridden)->body === $method, "POST override must dispatch $method.");
    }
    $get = Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/resource'], ['_method' => 'DELETE']);
    expect($router->dispatch($get)->body === 'GET', 'Only a POST body can override the method.');
    echo "PASS direct verbs and POST form overrides for PUT, PATCH and DELETE.\n";

    $previousEnvironment = [];
    try {
        foreach (array_replace($healthyEnvironment, ['APP_NAME' => null]) as $key => $value) {
            $previousEnvironment[$key] = getenv($key, true);
            putenv($value === null ? $key : $key . '=' . $value);
        }
        file_put_contents($dotenv, "APP_ENV=production\nAPP_NAME=\"School ; # & Friends\"\n");
        $config = Config::load($scratch);
        expect($config->environment === 'development', 'Process settings must override the .env file.');
        expect($config->name === 'School ; # & Friends', 'Quoted dotenv values must preserve punctuation.');
        file_put_contents($dotenv, "APP_NAME=\"unterminated\n");
        try {
            Config::load($scratch);
            throw new RuntimeException('Malformed dotenv input must fail configuration.');
        } catch (ConfigurationException) {
            // Expected safe configuration error.
        }
    } finally {
        foreach ($previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
    echo "PASS dotenv loading, environment precedence and malformed file handling.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL ' . $exception->getMessage() . PHP_EOL);
    if (is_file($serverLog)) {
        fwrite(STDERR, file_get_contents($serverLog));
    }
    $exitCode = 1;
} finally {
    foreach ([$dotenv, $appLog, $serverLog] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
    rmdir($scratch);
}

exit($exitCode);
