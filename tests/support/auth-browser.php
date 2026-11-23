<?php

declare(strict_types=1);

require __DIR__ . '/parent-fixtures.php';

final class AuthBrowser
{
    public string $cookie = '';

    public function __construct(private readonly string $base)
    {
    }

    public function send(string $path, string $method = 'GET', array $fields = []): array
    {
        $response = request($this->base, $path, $method, http_build_query($fields), $this->cookie);
        if (isset($response[1]['set-cookie'])) {
            $this->cookie = explode(';', $response[1]['set-cookie'], 2)[0];
        }
        return $response;
    }

    public function form(string $path): string
    {
        [$status, , $body] = $this->send($path);
        expect($status === 200, "Expected an HTML form at $path, received $status.");
        expect(preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $body, $token) === 1, "No CSRF field at $path.");
        return $token[1];
    }

    public function submit(string $path, array $fields, ?string $form = null): array
    {
        return $this->send($path, 'POST', $fields + ['_csrf' => $this->form($form ?? $path)]);
    }
}

function redirectIs(array $response, string $target): void
{
    expect($response[0] === 303 && ($response[1]['location'] ?? '') === $target, "Expected redirect to $target, received HTTP {$response[0]}.");
}

function tokenFile(string $directory, string $email, string $route, array $ignore = []): array
{
    drainTestMail($directory);
    foreach (array_diff(glob($directory . '/*.eml'), $ignore) as $file) {
        $message = file_get_contents($file);
        if (preg_match('/^To:\s*(?:<)?' . preg_quote($email, '/') . '>?(?:\r)?$/mi', $message) === 1
            && preg_match('~' . preg_quote($route, '~') . '\?token=([0-9a-f]{64})~', $message, $result) === 1) {
            return [$result[1], $file];
        }
    }
    throw new RuntimeException("No test message for $route.");
}

/** Execute the actual queue outside the HTTP request before reading a local test delivery. */
function drainTestMail(string $directory): void
{
    $root = dirname(__DIR__, 2);
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() !== false) {
        array_push($command, '-c', php_ini_loaded_file());
    }
    array_push($command, $root . '/bin/email-worker.php', '1000');
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root,
        array_replace(getenv(), ['APP_ENV' => 'development', 'MAIL_TRANSPORT' => 'file', 'AUTH_MAIL_DIR' => $directory]));
    expect(is_resource($process), 'Start the local test mail worker.');
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process) === 0, 'Local test mail worker failed. ' . $error . $result);
}

function verifiedParent(AuthBrowser $browser, string $mail, string $email, string $password): void
{
    redirectIs($browser->submit('/register', ['email' => $email]), '/login');
    [$token] = tokenFile($mail, $email, '/verify-email');
    $legacy = '/verify-email?token=' . $token;
    $confirmation = '/verify-parent?token=' . $token;
    redirectIs($browser->submit('/verify-email', ['token' => $token, 'role' => 'parent'], $legacy), $confirmation);
    redirectIs($browser->submit('/verify-parent', ['token' => $token] + parentFields($email, $password), $confirmation), '/dashboard/parent');
    redirectIs($browser->submit('/logout', [], '/dashboard/parent'), '/login');
}
