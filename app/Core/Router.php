<?php

declare(strict_types=1);

namespace Modulon\Core;

final class Router
{
    /**
     * @var array<string, array<string, array{handler:callable(Request): Response, access:string, csrf:'protect'|'exempt'}>>
     */
    private array $routes = [];
    /**
     * @var array<string, array<int, array{prefix:string, handler:callable(Request): Response, access:string, csrf:'protect'|'exempt'}>>
     */
    private array $wildcardRoutes = [];
    /**
     * @var null|callable(Request, string): ?Response
     */
    private $accessGuard = null;
    /** @var null|callable(Request, 'protect'|'exempt'): ?Response */
    private $csrfGuard = null;

    public function get(string $path, callable $handler, string $access = 'public', ?string $csrfPolicy = null): void
    {
        $this->addRoute('GET', $path, $handler, $access, $csrfPolicy);
    }

    public function post(string $path, callable $handler, string $access = 'public', ?string $csrfPolicy = null): void
    {
        $this->addRoute('POST', $path, $handler, $access, $csrfPolicy);
    }

    public function put(string $path, callable $handler, string $access = 'public', ?string $csrfPolicy = null): void
    {
        $this->addRoute('PUT', $path, $handler, $access, $csrfPolicy);
    }

    public function patch(string $path, callable $handler, string $access = 'public', ?string $csrfPolicy = null): void
    {
        $this->addRoute('PATCH', $path, $handler, $access, $csrfPolicy);
    }

    public function delete(string $path, callable $handler, string $access = 'public', ?string $csrfPolicy = null): void
    {
        $this->addRoute('DELETE', $path, $handler, $access, $csrfPolicy);
    }

    public function addRoute(string $method, string $path, callable $handler, string $access = 'public', ?string $csrfPolicy = null): void
    {
        $method = strtoupper($method);
        $access = $this->normalizeAccess($access);
        $csrf = $this->normalizeCsrfPolicy($csrfPolicy, $method);
        $normalizedPath = $this->normalizePath($path);

        if ($this->isWildcardPath($normalizedPath)) {
            $prefix = $this->wildcardPrefix($normalizedPath);
            $this->wildcardRoutes[$method][] = [
                'prefix' => $prefix,
                'handler' => $handler,
                'access' => $access,
                'csrf' => $csrf,
            ];
            return;
        }

        $this->routes[$method][$normalizedPath] = [
            'handler' => $handler,
            'access' => $access,
            'csrf' => $csrf,
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
     * Setzt den zentralen CSRF-Guard. Er wird nach dem Zugriffsguard ausgeführt.
     *
     * @param callable(Request, 'protect'|'exempt'): ?Response $guard
     */
    public function setCsrfGuard(callable $guard): void
    {
        $this->csrfGuard = $guard;
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

        if ($this->csrfGuard !== null) {
            $guardResponse = ($this->csrfGuard)($request, $route['csrf']);
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

    /**
     * @return 'protect'|'exempt'
     */
    private function normalizeCsrfPolicy(?string $policy, string $method): string
    {
        if ($policy === null) {
            return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'protect' : 'exempt';
        }

        $normalized = strtolower(trim($policy));
        if (in_array($normalized, ['protect', 'protected'], true)) {
            return 'protect';
        }
        if (in_array($normalized, ['exempt', 'none'], true)) {
            return 'exempt';
        }

        throw new \InvalidArgumentException('Ungültige CSRF-Policy: ' . $policy);
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
     * @return array{handler:callable(Request): Response, access:string, csrf:'protect'|'exempt'}|null
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
