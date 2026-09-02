<?php

declare(strict_types=1);

namespace Modulon\Core;

final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $input,
        private readonly array $query,
        private readonly array $cookies,
        private readonly array $headers = [],
    ) {
    }

    /**
     * Erstellt ein Request-Objekt aus den PHP-Globals.
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $query = is_array($_GET) ? $_GET : [];
        $input = [];
        $cookies = is_array($_COOKIE) ? $_COOKIE : [];

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            if (str_contains($contentType, 'application/json')) {
                $raw = file_get_contents('php://input');
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $input = is_array($decoded) ? $decoded : [];
            } else {
                $input = is_array($_POST) ? $_POST : [];
            }
        }

        return new self($method, $path === '' ? '/' : $path, $input, $query, $cookies, self::headersFromGlobals());
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Gibt einen Wert aus der Query-String zurück.
     */
    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : $default;
    }

    /**
     * Gibt einen Wert aus dem Request-Body zurück.
     */
    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->input[$key] ?? $default;
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : $default;
    }

    public function inputRaw(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    /**
     * Gibt einen Header-Wert unabhängig von der Schreibweise des Header-Namens zurück.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $headerName => $value) {
            if (is_string($headerName) && strcasecmp($headerName, $name) === 0 && is_string($value)) {
                return $value;
            }
        }

        return $default;
    }

    public function contentType(): string
    {
        return strtolower(trim((string) $this->header('Content-Type', '')));
    }

    /**
     * Erkennt JSON- und AJAX-Anfragen für maschinenlesbare Fehlerantworten.
     */
    public function expectsJson(): bool
    {
        return str_contains(strtolower((string) $this->header('Accept', '')), 'application/json')
            || str_contains($this->contentType(), 'application/json')
            || strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Gibt einen Cookie-Wert zurück.
     */
    public function cookie(string $key, ?string $default = null): ?string
    {
        $value = $this->cookies[$key] ?? $default;
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, string>
     */
    private static function headersFromGlobals(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $nativeHeaders = getallheaders();
            if (is_array($nativeHeaders)) {
                foreach ($nativeHeaders as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[$name] = $value;
                    }
                }
            }
        }

        foreach ($_SERVER as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($name, 5));
                $headers[$headerName] = $value;
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[str_replace('_', '-', $name)] = $value;
            }
        }

        return $headers;
    }
}
