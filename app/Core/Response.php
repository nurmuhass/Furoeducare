<?php

declare(strict_types=1);

namespace Furoeducare\Core;

final class Response
{
    public function __construct(
        public readonly string $body,
        public readonly int $status,
        public readonly array $headers,
    ) {
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8'] + $headers);
    }

    public static function json(array $data): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function send(bool $head = false): void
    {
        http_response_code($this->status);
        SecurityHeaders::apply();
        $privateSession = session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['account']);
        if ($privateSession) {
            // Public pages now include account appearance and account controls.
            header('Cache-Control: private, no-store');
            header('Vary: Cookie');
        }
        foreach ($this->headers as $name => $value) {
            if ($privateSession && strtolower($name) === 'cache-control') continue;
            header($name . ': ' . $value);
        }

        if (!$head) {
            echo $this->body;
        }
    }
}
