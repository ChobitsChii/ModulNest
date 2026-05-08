<?php

declare(strict_types=1);

namespace Modulon\Core;

final class Session
{
    private const META_STARTED_AT = '_session_started_at';
    private const META_LAST_ACTIVITY_AT = '_session_last_activity_at';

    /**
     * Startet die Session falls noch nicht aktiv.
     */
    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function regenerateId(bool $deleteOldSession = true): void
    {
        $this->start();
        session_regenerate_id($deleteOldSession);
    }

    public function has(string $key): bool
    {
        $this->start();
        return array_key_exists($key, $_SESSION);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function flash(string $key, string $message): void
    {
        $this->start();
        $_SESSION['_flash'][$key] = $message;
    }

    public function pullFlash(string $key, string $default = ''): string
    {
        $this->start();
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);

        return is_string($value) ? $value : $default;
    }

    /**
     * Beendet die Session inklusive Cookie.
     */
    public function invalidate(): void
    {
        $this->start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true),
            );
        }

        session_destroy();
    }

    /**
     * Aktualisiert Session-Zeitstempel und prüft Inaktivität/Maximaldauer.
     * Gibt true zurück, wenn die Session weiterhin gültig ist.
     */
    public function enforceLifetime(int $idleTimeoutSeconds, int $absoluteLifetimeSeconds): bool
    {
        $this->start();
        $now = time();

        $startedAt = $_SESSION[self::META_STARTED_AT] ?? $now;
        $lastActivityAt = $_SESSION[self::META_LAST_ACTIVITY_AT] ?? $now;

        if (!is_int($startedAt)) {
            $startedAt = $now;
        }

        if (!is_int($lastActivityAt)) {
            $lastActivityAt = $now;
        }

        $isIdleExpired = $idleTimeoutSeconds > 0 && ($now - $lastActivityAt) > $idleTimeoutSeconds;
        $isAbsoluteExpired = $absoluteLifetimeSeconds > 0 && ($now - $startedAt) > $absoluteLifetimeSeconds;

        if ($isIdleExpired || $isAbsoluteExpired) {
            $this->invalidate();
            $this->start();
            return false;
        }

        $_SESSION[self::META_STARTED_AT] = $startedAt;
        $_SESSION[self::META_LAST_ACTIVITY_AT] = $now;

        return true;
    }
}
