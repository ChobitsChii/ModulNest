<?php

declare(strict_types=1);

namespace Modulon\Core;

final class Session
{
    private const META_STARTED_AT = '_session_started_at';
    private const META_LAST_ACTIVITY_AT = '_session_last_activity_at';

    /**
     * Setzt die Cookie-Parameter zentral vor dem ersten session_start().
     * Produktive Installationen verwenden immer Secure-Cookies; lokale HTTP-
     * Entwicklung bleibt mit SESSION_COOKIE_SECURE=auto funktionsfähig.
     *
     * @param array<string,mixed>|null $server
     */
    public static function configureCookieSecurity(
        string $appEnvironment,
        string $secureSetting = 'auto',
        string $sameSite = 'Lax',
        ?array $server = null,
    ): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = self::shouldUseSecureCookie($appEnvironment, $secureSetting, $server);
        $sameSite = self::normalizeSameSite($sameSite);

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', $sameSite);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    /**
     * @param array<string,mixed>|null $server
     */
    public static function shouldUseSecureCookie(string $appEnvironment, string $setting = 'auto', ?array $server = null): bool
    {
        $environment = strtolower(trim($appEnvironment));
        if (!in_array($environment, ['development', 'dev', 'testing', 'test', 'local'], true)) {
            return true;
        }

        return match (strtolower(trim($setting))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => self::isHttps($server),
        };
    }

    public static function normalizeSameSite(string $sameSite): string
    {
        return match (strtolower(trim($sameSite))) {
            'strict' => 'Strict',
            'none' => 'None',
            default => 'Lax',
        };
    }

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
                [
                    'expires' => time() - 42000,
                    'path' => (string) ($params['path'] ?? '/'),
                    'domain' => (string) ($params['domain'] ?? ''),
                    'secure' => (bool) ($params['secure'] ?? false),
                    'httponly' => (bool) ($params['httponly'] ?? true),
                    'samesite' => self::normalizeSameSite((string) ($params['samesite'] ?? 'Lax')),
                ],
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

    /**
     * @param array<string,mixed>|null $server
     */
    private static function isHttps(?array $server): bool
    {
        $server ??= $_SERVER;
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        $forwarded = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return in_array('https', array_map('trim', explode(',', $forwarded)), true);
    }
}
