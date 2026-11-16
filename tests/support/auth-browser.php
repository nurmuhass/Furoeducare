<?php

declare(strict_types=1);

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
    foreach (array_diff(glob($directory . '/*.eml'), $ignore) as $file) {
        $message = file_get_contents($file);
        if (str_starts_with($message, "To: $email\r\n")
            && preg_match('~' . preg_quote($route, '~') . '\?token=([0-9a-f]{64})~', $message, $result) === 1) {
            return [$result[1], $file];
        }
    }
    throw new RuntimeException("No test message for $route.");
}

function verifiedAccount(AuthBrowser $browser, string $mail, string $role, string $email, string $password, string $school = ''): void
{
    redirectIs($browser->submit('/register', ['email' => $email]), '/login');
    [$token] = tokenFile($mail, $email, '/verify-email');
    redirectIs($browser->submit('/verify-email', [
        'token' => $token, 'role' => $role, 'school_name' => $school,
        'password' => $password, 'password_confirmation' => $password,
    ], '/verify-email?token=' . $token), '/login');
}

