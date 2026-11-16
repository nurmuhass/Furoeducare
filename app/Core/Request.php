<?php

declare(strict_types=1);

namespace Furoeducare\Core;

final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $remoteAddress,
        private readonly array $post,
        private readonly array $query,
        private readonly array $files,
    ) {
    }

    public static function fromServer(array $server, array $post, array $query = [], array $files = []): self
    {
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $path = explode('?', $server['REQUEST_URI'] ?? '/', 2)[0];
        if (!str_starts_with($path, '/')) {
            throw new HttpException(400, "Invalid request path.\n");
        }

        if ($method === 'POST' && array_key_exists('_method', $post)) {
            $override = $post['_method'];
            if (!is_string($override) || !in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
                throw new HttpException(400, "Invalid form method override.\n");
            }
            $method = strtoupper($override);
        }

        return new self($method, $path, $server['REMOTE_ADDR'] ?? '', $post, $query, $files);
    }

    public function form(string $key): string
    {
        $value = $this->post[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    public function formArray(string $key): array
    {
        $value = $this->post[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    public function uploadedFile(string $key): array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : [];
    }

    public function query(string $key): string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
