<?php

declare(strict_types=1);

use Modulon\Core\ErrorHandler;
use Modulon\Core\SecurityHeaders;
use Modulon\Core\Session;
use Modulon\Modules\Auth\AuthRateLimiter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function securityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

securityAssert(Session::shouldUseSecureCookie('production', 'false', []) === true, 'Produktion muss Secure-Session-Cookies erzwingen.');
securityAssert(Session::shouldUseSecureCookie('development', 'auto', ['HTTPS' => 'off']) === false, 'Lokales HTTP darf bei auto ohne Secure-Cookie laufen.');
securityAssert(Session::shouldUseSecureCookie('development', 'auto', ['HTTPS' => 'on']) === true, 'HTTPS muss Secure-Cookies aktivieren.');
securityAssert(Session::normalizeSameSite('strict') === 'Strict', 'SameSite Strict muss normalisiert werden.');
securityAssert(Session::normalizeSameSite('invalid') === 'Lax', 'Ungültiges SameSite muss sicher auf Lax fallen.');

$headers = SecurityHeaders::defaults();
securityAssert(($headers['X-Content-Type-Options'] ?? '') === 'nosniff', 'nosniff-Header fehlt.');
securityAssert(($headers['X-Frame-Options'] ?? '') === 'SAMEORIGIN', 'Frame-Schutz fehlt.');
securityAssert(isset($headers['Referrer-Policy'], $headers['Permissions-Policy'], $headers['Content-Security-Policy']), 'Globale Security-Headers sind unvollständig.');

$sanitize = new ReflectionMethod(ErrorHandler::class, 'sanitize');
securityAssert($sanitize->invoke(null, 'session-value', 'PHPSESSID') === '***', 'PHPSESSID darf nicht in Fehlerlogs erscheinen.');
securityAssert($sanitize->invoke(null, 'csrf-value', '_csrf') === '***', 'CSRF-Token darf nicht in Fehlerlogs erscheinen.');
securityAssert($sanitize->invoke(null, 'password-value', 'password') === '***', 'Passwort darf nicht in Fehlerlogs erscheinen.');
securityAssert($sanitize->invoke(null, 'recovery-code', 'code') === '***', 'TOTP-/Recovery-Code darf nicht in Fehlerlogs erscheinen.');
securityAssert($sanitize->invoke(null, 'remember-value', 'modulon_remember') === '***', 'Remember-Me-Cookie darf nicht in Fehlerlogs erscheinen.');
securityAssert($sanitize->invoke(null, 'remember-value', 'modulnest_remember') === '***', 'ModulNest Remember-Me-Cookie darf nicht in Fehlerlogs erscheinen.');
$sanitizeUri = new ReflectionMethod(ErrorHandler::class, 'sanitizeUri');
securityAssert($sanitizeUri->invoke(null, '/login?PHPSESSID=session-value&_csrf=csrf-value&view=public') === '/login?PHPSESSID=***&_csrf=***&view=public', 'Sensible Query-Werte müssen im Fehlerlog maskiert werden.');

$path = sys_get_temp_dir() . '/modulon-auth-rate-limit-' . bin2hex(random_bytes(6)) . '.json';
$now = 1_700_000_000;
$limiter = new AuthRateLimiter($path, 3, 60, static function () use (&$now): int {
    return $now;
});

securityAssert($limiter->consume('password', '203.0.113.10', 'alice@example.test'), 'Erster Versuch muss erlaubt sein.');
securityAssert($limiter->consume('password', '203.0.113.10', 'alice@example.test'), 'Zweiter Versuch muss erlaubt sein.');
securityAssert($limiter->consume('password', '203.0.113.10', 'alice@example.test'), 'Dritter Versuch muss erlaubt sein.');
securityAssert(!$limiter->consume('password', '203.0.113.10', 'alice@example.test'), 'Limit muss nach der definierten Anzahl greifen.');
securityAssert($limiter->consume('password', '203.0.113.11', 'alice@example.test'), 'Andere IP darf nicht unnötig mitlimitiert werden.');
securityAssert($limiter->consume('password', '203.0.113.10', 'bob@example.test'), 'Andere Identität darf nicht unnötig mitlimitiert werden.');
$state = (string) file_get_contents($path);
securityAssert(!str_contains($state, 'alice@example.test') && !str_contains($state, '203.0.113.10'), 'Limiter-Zustand darf keine Rohidentitäten oder IPs speichern.');
$limiter->reset('password', '203.0.113.10', 'alice@example.test');
securityAssert($limiter->consume('password', '203.0.113.10', 'alice@example.test'), 'Erfolg-Reset muss einen neuen Versuch erlauben.');
$now += 61;
securityAssert($limiter->consume('password', '203.0.113.10', 'alice@example.test'), 'Zeitfenster-Ablauf muss einen neuen Versuch erlauben.');
@unlink($path);

$authServiceSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Auth/AuthService.php');
$authControllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Auth/AuthController.php');
$dashboardSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Dashboard/DashboardController.php');
securityAssert(!str_contains($authServiceSource, "'session_id',"), 'Auth-Log-Sanitizer darf keine Session-ID erlauben.');
securityAssert(!str_contains($authServiceSource, 'token_hash_prefix'), 'Auth-Logs dürfen keine Remember-Token-Hashfragmente speichern.');
securityAssert(!str_contains($authControllerSource, "'session_id' => session_id()"), 'LoginController darf keine Session-ID loggen.');
securityAssert(!str_contains($dashboardSource, "'request_uri' => (string) (\$_SERVER['REQUEST_URI']"), 'Dashboard-Logs dürfen keine Query-Parameter unverändert speichern.');

echo "Security hardening smoke test passed.\n";
