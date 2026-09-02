<?php

declare(strict_types=1);

use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Database;
use Modulon\Core\Env;
use Modulon\Core\RecoveryManager;
use Modulon\Core\RecoveryMigrationService;
use Modulon\Core\SecurityHeaders;
use Modulon\Core\Session;
use Modulon\Modules\Auth\AuthRateLimiter;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\Auth\RecoveryCodeRepository;
use Modulon\Modules\Auth\RememberTokenRepository;
use Modulon\Modules\Auth\UserRepository;
use Modulon\Modules\Auth\WebAuthnCredentialRepository;

$basePath = __DIR__;
Env::load($basePath . '/.env');
Session::configureCookieSecurity((string) Env::get('APP_ENV', 'production'), (string) Env::get('SESSION_COOKIE_SECURE', 'auto'), (string) Env::get('SESSION_COOKIE_SAMESITE', 'Lax'));
$session = new Session(); $session->start(); $csrf = new CsrfTokenManager($session); $recovery = new RecoveryManager($basePath);
SecurityHeaders::apply(); header('Content-Type: text/html; charset=UTF-8');
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$field = static fn (string $token): string => '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
$page = static function (string $title, string $body) use ($e): never { echo '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title></head><body style="font-family:system-ui,sans-serif;max-width:760px;margin:3rem auto;line-height:1.5">' . $body . '</body></html>'; exit; };
if ($recovery->current() === null) { $page('Recovery nicht aktiv', '<h1>Kein Recovery-Vorgang aktiv</h1>'); }
try {
    $databaseConfig = require $basePath . '/app/Config/database.php'; $authConfig = require $basePath . '/app/Config/auth.php'; $pdo = Database::connect($databaseConfig);
    $users = new UserRepository($pdo); $auth = new AuthService($users, new RememberTokenRepository($pdo), new WebAuthnCredentialRepository($pdo), new RecoveryCodeRepository($pdo), $session, $authConfig, $csrf);
    $limiter = new AuthRateLimiter($basePath . '/storage/rate-limits/auth.json', (int) ($authConfig['auth_rate_limit_max_attempts'] ?? 5), (int) ($authConfig['auth_rate_limit_window_seconds'] ?? 900));
} catch (Throwable) { $page('Recovery', '<h1>Recovery-Modus</h1><p>Administrator-Anmeldung ist nicht verfügbar. Bitte Datenbankverbindung und internes Recovery-Log prüfen.</p>'); }
$error = ''; $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    if (!$csrf->validate(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) { http_response_code(419); $page('Ungültige Anfrage', '<h1>Ungültige Anfrage</h1><p>Bitte erneut versuchen.</p>'); }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'login') {
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        if (!$limiter->consume('recovery_login', $ip, $identifier)) { $error = 'Zu viele Versuche. Bitte später erneut versuchen.'; }
        elseif (!in_array($auth->attemptLogin($identifier, (string) ($_POST['password'] ?? ''), false, ['source' => 'recovery']), [AuthService::LOGIN_SUCCESS, AuthService::LOGIN_2FA_REQUIRED], true)) { $error = 'Anmeldung nicht möglich.'; }
        else { $limiter->reset('recovery_login', $ip, $identifier); }
    } elseif ($action === 'totp' || $action === 'recovery_code') {
        $subject = (string) (($auth->pendingUser()['id'] ?? 'unknown'));
        if (!$limiter->consume('recovery_2fa', $ip, $subject)) { $error = 'Zu viele Versuche. Bitte später erneut versuchen.'; }
        elseif (!($action === 'totp' ? $auth->completePendingLoginWithTotp((string) ($_POST['code'] ?? '')) : $auth->completePendingLoginWithRecoveryCode((string) ($_POST['code'] ?? '')))) { $error = 'Bestätigung nicht möglich.'; }
        else { $limiter->reset('recovery_2fa', $ip, $subject); }
    } elseif ($auth->isAdmin()) {
        $service = new RecoveryMigrationService($pdo, $basePath, $recovery);
        if ($action === 'safe_repair') {
            try {
                $result = $service->repair((string) ($_POST['migration_key'] ?? ''), (string) ($_POST['stored_checksum'] ?? ''));
                $recovery->resolve();
                $page('Recovery abgeschlossen', '<h1>Recovery erfolgreich abgeschlossen</h1><p>Die Datenbank wurde geprüft und sicher repariert. ModulNest kann wieder normal verwendet werden.</p><p>Backup: ' . $e($result['backup_path']) . '<br>Recovery-ID: ' . $e($result['recovery_id']) . '</p>');
            } catch (Throwable) { $error = 'Die sichere Reparatur wurde nicht durchgeführt. Der Recovery-Modus bleibt aktiv.'; }
        }
        if ($action === 'reevaluate') {
            try {
                $completion = $service->completeVerifiedPendingMigrations();
                if ($service->isConsistent()) { $recovery->resolve(); $hint = is_array($completion) ? '<p>Backup: ' . $e($completion['backup_path']) . '</p>' : ''; $page('Recovery abgeschlossen', '<h1>Recovery erfolgreich abgeschlossen</h1><p>Die Datenbank wurde geprüft. ModulNest kann wieder normal verwendet werden.</p>' . $hint); }
                $error = 'Der Systemzustand ist noch nicht konsistent.';
            } catch (Throwable) { $error = 'Die erneute Prüfung konnte Recovery nicht sicher abschließen.'; }
        }
    }
}
if ($auth->hasPendingLogin()) {
    $page('Recovery-Anmeldung', '<h1>Zwei-Faktor-Bestätigung erforderlich</h1><p>' . $e($error) . '</p><form method="post">' . $field($csrf->token()) . '<input type="hidden" name="action" value="totp"><label>TOTP-Code <input name="code" inputmode="numeric" required></label><button>Bestätigen</button></form><form method="post">' . $field($csrf->token()) . '<input type="hidden" name="action" value="recovery_code"><label>Recovery-Code <input name="code" required></label><button>Recovery-Code verwenden</button></form>');
}
if (!$auth->isAuthenticated()) { $page('Recovery-Anmeldung', '<h1>ModulNest befindet sich im Recovery-Modus</h1><p>Administrator-Anmeldung erforderlich.</p><p>' . $e($error) . '</p><form method="post">' . $field($csrf->token()) . '<input type="hidden" name="action" value="login"><p><label>Benutzername oder E-Mail<br><input name="identifier" autocomplete="username" required></label></p><p><label>Passwort<br><input type="password" name="password" autocomplete="current-password" required></label></p><button>Anmelden</button></form>'); }
if (!$auth->isAdmin()) { $auth->logout(); http_response_code(403); $page('Kein Zugriff', '<h1>Kein Zugriff</h1><p>Nur Administrator-Konten dürfen Recovery-Daten einsehen.</p>'); }
$state = $recovery->current() ?? []; $service = new RecoveryMigrationService($pdo, $basePath, $recovery); $diagnosis = $service->diagnose(); $items = '';
foreach ($diagnosis as $row) {
    $details = ''; foreach (($row['deviations'] ?? []) as $deviation) { $details .= '<li>' . $e($deviation) . '</li>'; }
    $button = '';
    if (($row['classification'] ?? '') === RecoveryMigrationService::SAFE_AUTOMATIC_REPAIR) {
        $button = '<p>Bewertung: Diese Abweichungen können sicher repariert werden. Es werden ausschließlich fehlende Indizes erstellt; bestehende Datensätze bleiben unverändert. Vorher wird ein Datenbank-Backup erstellt.</p><form method="post">' . $field($csrf->token()) . '<input type="hidden" name="action" value="safe_repair"><input type="hidden" name="migration_key" value="' . $e($row['key']) . '"><input type="hidden" name="stored_checksum" value="' . $e($row['stored_checksum']) . '"><button>Sichere Reparatur durchführen</button></form>';
    } elseif (($row['classification'] ?? '') === RecoveryMigrationService::METADATA_ONLY_REPAIR) {
        $button = '<p>Bewertung: Das Schema stimmt bereits. Eine reine Metadatenreparatur ist sicher möglich und erstellt vorher ein Datenbank-Backup.</p><form method="post">' . $field($csrf->token()) . '<input type="hidden" name="action" value="safe_repair"><input type="hidden" name="migration_key" value="' . $e($row['key']) . '"><input type="hidden" name="stored_checksum" value="' . $e($row['stored_checksum']) . '"><button>Migrationsmetadaten reparieren</button></form>';
    }
    $items .= '<li><strong>' . $e($row['key']) . '</strong><p>' . $e($row['summary'] ?? '') . '</p><ul>' . $details . '</ul>' . $button . '</li>';
}
$page('Recovery', '<h1>Recovery erforderlich</h1><p>' . $e($error) . '</p><dl><dt>Recovery-ID</dt><dd>' . $e($state['recovery_id'] ?? '') . '</dd><dt>Zeitpunkt</dt><dd>' . $e($state['created_at'] ?? '') . '</dd><dt>Fehler</dt><dd>' . $e($state['error_code'] ?? '') . '</dd><dt>Migration</dt><dd>' . $e($state['migration_key'] ?? '') . '</dd><dt>Backup</dt><dd>' . $e($state['backup_path'] ?? 'Keines') . '</dd><dt>Letzter Schritt</dt><dd>' . $e($state['last_successful_step'] ?? '') . '</dd></dl><h2>Migrationsdiagnose</h2><ul>' . ($items !== '' ? $items : '<li>Keine Checksum-Abweichung erkannt.</li>') . '</ul><form method="post">' . $field($csrf->token()) . '<input type="hidden" name="action" value="reevaluate"><button>Recovery erneut bewerten</button></form><p>Interne Ereignisse: ' . $e($state['log_path'] ?? 'storage/logs/recovery-YYYY-MM-DD.log') . '</p>');
