<?php

declare(strict_types=1);

use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Session;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\Auth\RecoveryCodeRepository;
use Modulon\Modules\Auth\RememberTokenRepository;
use Modulon\Modules\Auth\UserRepository;
use Modulon\Modules\Auth\WebAuthnCredentialRepository;
use RobThree\Auth\Providers\Qr\GoogleChartsQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function auth_security_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: SQLite PDO driver is not available.\n");
    exit(0);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('auth-security-smoke-' . bin2hex(random_bytes(4)));

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    username TEXT NULL,
    email TEXT NOT NULL,
    timezone TEXT NOT NULL DEFAULT "UTC",
    dashboard_auto_refresh_enabled INTEGER NOT NULL DEFAULT 1,
    dashboard_auto_refresh_interval_minutes INTEGER NOT NULL DEFAULT 30,
    password_hash TEXT NOT NULL,
    is_blocked INTEGER NOT NULL DEFAULT 0,
    totp_secret TEXT NULL,
    totp_enabled INTEGER NOT NULL DEFAULT 0,
    webauthn_enabled INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NULL
)');
$pdo->exec('CREATE TABLE remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE webauthn_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    credential_id TEXT NOT NULL,
    public_key TEXT NOT NULL,
    sign_count INTEGER NULL,
    transports TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TEXT NULL
)');
$pdo->exec('CREATE TABLE recovery_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    used_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec("INSERT INTO users (id, name, email, password_hash) VALUES
    (1, 'No Codes', 'no-codes@example.test', 'x'),
    (2, 'Existing Codes', 'existing@example.test', 'x')");

$session = new Session();
$service = new AuthService(
    new UserRepository($pdo),
    new RememberTokenRepository($pdo),
    new WebAuthnCredentialRepository($pdo),
    new RecoveryCodeRepository($pdo),
    $session,
    ['totp_issuer' => 'ModulNest Test'],
    new CsrfTokenManager($session),
);
$totp = new TwoFactorAuth(new GoogleChartsQrCodeProvider(), 'ModulNest Test');

$setup = $service->startTotpSetup(1);
$result = $service->confirmTotpSetup(1, $totp->getCode($setup['secret']));
auth_security_assert(is_array($result), 'TOTP-Aktivierung ohne Recovery Codes liefert kein Ergebnis.');
auth_security_assert($result['recovery_codes_created'] === true, 'Ohne aktive Recovery Codes wurden keine neuen Codes markiert.');
auth_security_assert(count($result['recovery_codes']) === 8, 'Ohne aktive Recovery Codes wurden nicht 8 Codes erzeugt.');
auth_security_assert($service->recoveryCodeCount(1) === 8, 'Neue Recovery Codes sind nicht aktiv gespeichert.');

$existingCodes = $service->regenerateRecoveryCodes(2);
auth_security_assert(count($existingCodes) === 8, 'Vorbereitete Recovery Codes fehlen.');
$service->pullGeneratedRecoveryCodes(2);
$beforeRows = $pdo->query('SELECT code_hash FROM recovery_codes WHERE user_id = 2 AND used_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

$setup = $service->startTotpSetup(2);
$result = $service->confirmTotpSetup(2, $totp->getCode($setup['secret']));
auth_security_assert(is_array($result), 'TOTP-Aktivierung mit bestehenden Recovery Codes liefert kein Ergebnis.');
auth_security_assert($result['recovery_codes_created'] === false, 'Bestehende Recovery Codes wurden fälschlich neu erzeugt.');
auth_security_assert($result['recovery_codes'] === [], 'Bei bestehenden Recovery Codes dürfen keine Klartext-Codes zurückgegeben werden.');
auth_security_assert($service->pullGeneratedRecoveryCodes(2) === [], 'Bei bestehenden Recovery Codes wurden Klartext-Codes in der Session hinterlegt.');
$afterRows = $pdo->query('SELECT code_hash FROM recovery_codes WHERE user_id = 2 AND used_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
auth_security_assert($beforeRows === $afterRows, 'Bestehende Recovery-Code-Hashes wurden durch TOTP-Aktivierung verändert.');

$freshCodes = $service->regenerateRecoveryCodes(2);
$freshRows = $pdo->query('SELECT code_hash FROM recovery_codes WHERE user_id = 2 AND used_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
auth_security_assert(count($freshCodes) === 8 && $beforeRows !== $freshRows, 'Explizites Neu-Generieren ersetzt Recovery Codes nicht.');

fwrite(STDOUT, "Auth security recovery smoke test passed.\n");
