<?php

declare(strict_types=1);

namespace Furoeducare\Core;

use LogicException;

final class Router
{
    private array $routes = [];
    private array $guards = [];

    public function before(callable $guard): void
    {
        $this->guards[] = $guard;
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        if (isset($this->routes[$path][$method])) {
            throw new LogicException("Duplicate route for $method $path.");
        }
        $this->routes[$path][$method] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $routes = $this->routes[$request->path] ?? [];
        if ($routes === []) {
            return Response::text("Not Found\n", 404);
        }

        $handler = $routes[$request->method] ?? null;
        if ($handler === null && $request->method === 'HEAD') {
            $handler = $routes['GET'] ?? null;
        }
        if ($handler !== null) {
            foreach ($this->guards as $guard) {
                $response = $guard($request);
                if ($response instanceof Response) {
                    return $response;
                }
            }
            return $handler($request);
        }

        $allowed = array_keys($routes);
        if (isset($routes['GET']) && !isset($routes['HEAD'])) {
            $allowed[] = 'HEAD';
        }
        return Response::text("Method Not Allowed\n", 405, ['Allow' => implode(', ', $allowed)]);
    }
}
