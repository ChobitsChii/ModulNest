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

        return new self($method, $path === '' ? '/' : $path, $input, $query, $cookies);
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
}
