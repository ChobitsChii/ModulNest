<?php

declare(strict_types=1);

namespace Modulon\Core;

final class Router
{
    /**
     * @var array<string, array<string, array{handler:callable(Request): Response, access:string}>>
     */
    private array $routes = [];
    /**
     * @var array<string, array<int, array{prefix:string, handler:callable(Request): Response, access:string}>>
     */
    private array $wildcardRoutes = [];
    /**
     * @var null|callable(Request, string): ?Response
     */
    private $accessGuard = null;

    public function get(string $path, callable $handler, string $access = 'public'): void
    {
        $this->addRoute('GET', $path, $handler, $access);
    }

    public function post(string $path, callable $handler, string $access = 'public'): void
    {
        $this->addRoute('POST', $path, $handler, $access);
    }

    public function addRoute(string $method, string $path, callable $handler, string $access = 'public'): void
    {
        $method = strtoupper($method);
        $access = $this->normalizeAccess($access);
        $normalizedPath = $this->normalizePath($path);

        if ($this->isWildcardPath($normalizedPath)) {
            $prefix = $this->wildcardPrefix($normalizedPath);
            $this->wildcardRoutes[$method][] = [
                'prefix' => $prefix,
                'handler' => $handler,
                'access' => $access,
            ];
            return;
        }

        $this->routes[$method][$normalizedPath] = [
            'handler' => $handler,
            'access' => $access,
        ];
    }

    /**
     * Setzt einen Zugriffsguard. Bei Rückgabe einer Response wird Dispatch abgebrochen.
     *
     * @param callable(Request, string): ?Response $guard
     */
    public function setAccessGuard(callable $guard): void
    {
        $this->accessGuard = $guard;
    }

    /**
     * Löst den passenden Handler auf und liefert eine Response.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $this->normalizePath($request->path());
        $route = $this->resolveRoute($method, $path);

        if ($route === null && $method === 'HEAD') {
            // HEAD soll wie GET geroutet werden (RFC-konformes Verhalten für Healthchecks/Monitoring).
            $route = $this->resolveRoute('GET', $path);
        }

        if ($route === null) {
            return new Response(View::render('errors/404', [
                'title' => '404 Not Found',
                'current_path' => $request->path(),
            ]), 404);
        }

        if ($this->accessGuard !== null) {
            $guardResponse = ($this->accessGuard)($request, $route['access']);
            if ($guardResponse instanceof Response) {
                return $guardResponse;
            }
        }

        $handler = $route['handler'];
        $response = $handler($request);

        if (!$response instanceof Response) {
            return new Response(View::render('errors/500', [
                'title' => '500 Internal Server Error',
                'current_path' => $request->path(),
            ]), 500);
        }

        return $response;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '//' ? '/' : $path;
    }

    private function normalizeAccess(string $access): string
    {
        $normalized = strtolower(trim($access));
        if (in_array($normalized, ['public', 'user', 'admin'], true)) {
            return $normalized;
        }

        return 'public';
    }

    private function isWildcardPath(string $path): bool
    {
        return str_ends_with($path, '/*');
    }

    private function wildcardPrefix(string $path): string
    {
        $prefix = substr($path, 0, -2);
        return $prefix === '' ? '/' : $prefix;
    }

    /**
     * @return array{handler:callable(Request): Response, access:string}|null
     */
    private function resolveRoute(string $method, string $path): ?array
    {
        $route = $this->routes[$method][$path] ?? null;
        if ($route !== null) {
            return $route;
        }

        $bestWildcardRoute = null;
        $bestPrefixLength = -1;
        foreach ($this->wildcardRoutes[$method] ?? [] as $wildcardRoute) {
            $prefix = $wildcardRoute['prefix'];
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $prefixLength = strlen($prefix);
                if ($prefixLength > $bestPrefixLength) {
                    $bestPrefixLength = $prefixLength;
                    $bestWildcardRoute = $wildcardRoute;
                }
            }
        }

        return $bestWildcardRoute;
    }
}
