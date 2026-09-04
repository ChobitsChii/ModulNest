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

function auth_remember_assert(bool $condition, string $message): void
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
session_id('auth-remember-smoke-' . bin2hex(random_bytes(4)));

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    username TEXT NULL,
    email TEXT NOT NULL,
    timezone TEXT NOT NULL DEFAULT "UTC",
    theme_mode TEXT NOT NULL DEFAULT "system",
    theme_switcher_visible INTEGER NOT NULL DEFAULT 1,
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

$passwordHash = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
auth_remember_assert(is_string($passwordHash), 'Passwort-Hash konnte nicht erzeugt werden.');

$totp = new TwoFactorAuth(new GoogleChartsQrCodeProvider(), 'ModulNest Test');
$totpSecret = $totp->createSecret();
$pdo->prepare('INSERT INTO users (id, name, email, password_hash, totp_secret, totp_enabled) VALUES
    (1, :name1, :email1, :password_hash, NULL, 0),
    (2, :name2, :email2, :password_hash, :totp_secret, 1),
    (3, :name3, :email3, :password_hash, NULL, 0)')
    ->execute([
        'name1' => 'Password Only',
        'email1' => 'password@example.test',
        'name2' => 'TOTP User',
        'email2' => 'totp@example.test',
        'name3' => 'Remember Auto',
        'email3' => 'remember-auto@example.test',
        'password_hash' => $passwordHash,
        'totp_secret' => $totpSecret,
    ]);

$session = new Session();
$csrfTokenManager = new CsrfTokenManager($session);
$tokenBeforePasswordLogin = $csrfTokenManager->token();
$service = new AuthService(
    new UserRepository($pdo),
    new RememberTokenRepository($pdo),
    new WebAuthnCredentialRepository($pdo),
    new RecoveryCodeRepository($pdo),
    $session,
    ['totp_issuer' => 'ModulNest Test'],
    $csrfTokenManager,
);

$result = $service->attemptLogin('password@example.test', 'correct horse battery staple', true);
auth_remember_assert($result === AuthService::LOGIN_SUCCESS, 'Passwort-Login ohne 2FA war nicht erfolgreich.');
auth_remember_assert(!$csrfTokenManager->validate($tokenBeforePasswordLogin), 'Passwort-Login rotiert den CSRF-Token nicht.');
$tokenAfterPasswordLogin = $csrfTokenManager->token();
auth_remember_assert($csrfTokenManager->validate($tokenAfterPasswordLogin), 'Der CSRF-Token der angemeldeten Session ist ungültig.');
auth_remember_assert((int) $pdo->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = 1')->fetchColumn() === 1, 'Passwort-Login ohne 2FA hat keinen Remember-Token erstellt.');

$result = $service->attemptLogin('totp@example.test', 'correct horse battery staple', true);
auth_remember_assert($result === AuthService::LOGIN_2FA_REQUIRED, 'Passwort-Login mit TOTP hat keinen Pending-2FA-Status geliefert.');
auth_remember_assert((int) $pdo->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = 2')->fetchColumn() === 0, 'Remember-Token wurde vor erfolgreicher 2FA erstellt.');
$tokenBeforeTotpCompletion = $csrfTokenManager->token();
auth_remember_assert($service->completePendingLoginWithTotp($totp->getCode($totpSecret)), 'TOTP-Abschluss war nicht erfolgreich.');
auth_remember_assert(!$csrfTokenManager->validate($tokenBeforeTotpCompletion), 'TOTP-Abschluss rotiert den CSRF-Token nicht.');
auth_remember_assert((int) $pdo->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = 2')->fetchColumn() === 1, 'Remember-Wunsch wurde nach erfolgreicher 2FA nicht übernommen.');

$loginView = file_get_contents(dirname(__DIR__, 2) . '/app/Views/auth/login.php');
auth_remember_assert(is_string($loginView) && str_contains($loginView, 'remember_me:document.getElementById'), 'Passkey-Login reicht den Remember-Me-Wunsch nicht aus der Login-View weiter.');

$reflection = new ReflectionMethod(AuthService::class, 'finishWebAuthnLogin');
auth_remember_assert($reflection->getNumberOfParameters() >= 2, 'WebAuthn-Finalisierung unterstützt keinen Remember-Me-Parameter.');

$rawRememberToken = bin2hex(random_bytes(32));
$oldRememberHash = hash('sha256', $rawRememberToken);
$pdo->prepare('INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (3, :token_hash, :expires_at)')
    ->execute([
        'token_hash' => $oldRememberHash,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);

auth_remember_assert($service->tryRememberLogin($rawRememberToken), 'Gültiger Remember-Cookie stellt keine Session her.');
$currentUser = $service->currentUser();
auth_remember_assert(is_array($currentUser) && (int) $currentUser['id'] === 3, 'Remember-Auto-Login setzt nicht den erwarteten Benutzer.');
$oldTokenStatement = $pdo->prepare('SELECT COUNT(*) FROM remember_tokens WHERE token_hash = :token_hash');
$oldTokenStatement->execute(['token_hash' => $oldRememberHash]);
auth_remember_assert((int) $oldTokenStatement->fetchColumn() === 0, 'Alter Remember-Token wurde nach Auto-Login nicht rotiert.');
auth_remember_assert((int) $pdo->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = 3')->fetchColumn() === 1, 'Remember-Auto-Login stellt keinen rotierten Token aus.');

$tokenBeforeLogout = $csrfTokenManager->token();
$service->logout();
auth_remember_assert(!$csrfTokenManager->validate($tokenBeforeLogout), 'Logout invalidiert den CSRF-Token nicht.');

fwrite(STDOUT, "Auth remember-me smoke test passed.\n");
