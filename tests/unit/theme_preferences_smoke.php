<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;
use Modulon\Core\ThemePreference;
use Modulon\Core\View;
use Modulon\Modules\Auth\UserRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function theme_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
theme_assert(ThemePreference::modes() === ['system', 'light', 'dark'], 'Theme-Allowlist ist nicht stabil.');
theme_assert(ThemePreference::normalize('dark') === 'dark' && ThemePreference::normalize('evil') === 'system', 'Theme-Normalisierung ist unsicher.');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, username TEXT NULL,
        email TEXT NOT NULL, timezone TEXT NOT NULL DEFAULT "UTC",
        theme_mode TEXT NOT NULL DEFAULT "system", theme_switcher_visible INTEGER NOT NULL DEFAULT 1,
        dashboard_auto_refresh_enabled INTEGER NOT NULL DEFAULT 1,
        dashboard_auto_refresh_interval_minutes INTEGER NOT NULL DEFAULT 30,
        password_hash TEXT NOT NULL, is_blocked INTEGER NOT NULL DEFAULT 0,
        totp_secret TEXT NULL, totp_enabled INTEGER NOT NULL DEFAULT 0,
        webauthn_enabled INTEGER NOT NULL DEFAULT 0, updated_at TEXT NULL
    )');
    $pdo->exec("INSERT INTO users (id, name, email, password_hash) VALUES (1, 'Theme User', 'theme@example.test', 'x')");
    $users = new UserRepository($pdo);
    $user = $users->findById(1);
    theme_assert(($user['theme_mode'] ?? null) === 'system' && (int) ($user['theme_switcher_visible'] ?? 0) === 1, 'Bestehende Benutzer erhalten nicht die sicheren Theme-Defaults.');
    $users->updateThemeMode(1, 'dark');
    theme_assert((string) $pdo->query('SELECT theme_mode FROM users WHERE id = 1')->fetchColumn() === 'dark', 'Header-Theme wurde nicht serverseitig gespeichert.');
    $users->updateSettings(1, 'Europe/Berlin', true, 30, 'light', false);
    $stored = $pdo->query('SELECT theme_mode, theme_switcher_visible FROM users WHERE id = 1')->fetch();
    theme_assert(is_array($stored) && $stored['theme_mode'] === 'light' && (int) $stored['theme_switcher_visible'] === 0, 'Profil speichert Theme und Switcher-Sichtbarkeit nicht gemeinsam.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('theme-preference-smoke-' . bin2hex(random_bytes(4)));
$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$router->post('/profil/theme', static fn (Request $request): Response => new Response('saved'), 'user');

$dispatch = static function (array $headers = []) use ($router): int {
    $response = $router->dispatch(new Request('POST', '/profil/theme', ['theme_mode' => 'dark'], [], [], $headers));
    ob_start();
    $response->send();
    ob_end_clean();
    return http_response_code();
};
theme_assert($dispatch(['Accept' => 'application/json']) === 419, 'Theme-fetch ist ohne CSRF-Token nicht geschützt.');
theme_assert($dispatch(['Accept' => 'application/json', 'X-CSRF-Token' => $token]) === 200, 'Theme-fetch akzeptiert den zentralen Header-Token nicht.');

$guestHtml = View::render('errors/404', ['theme_mode' => 'system', 'theme_switcher_visible' => true]);
theme_assert(str_contains($guestHtml, 'data-theme-mode="system"') && str_contains($guestHtml, 'data-theme-authenticated="false"'), 'Gast-Layout startet nicht im Systemmodus.');
theme_assert(strpos($guestHtml, 'theme-init.js') < strpos($guestHtml, 'bootstrap@5.3.8'), 'Early-Theme-Script läuft nicht vor den Stylesheets.');
theme_assert(str_contains($guestHtml, 'data-theme-switcher'), 'Gast-Switcher ist nicht sichtbar.');
$guestUtilityHtml = View::render('errors/404', [
    'theme_mode' => 'system',
    'theme_switcher_visible' => true,
    'public_registration_enabled' => true,
]);
theme_assert(
    strpos($guestUtilityHtml, 'data-theme-switcher') < strpos($guestUtilityHtml, 'href="/internal/register"')
    && strpos($guestUtilityHtml, 'href="/internal/register"') < strpos($guestUtilityHtml, 'href="/login"'),
    'Gast-Utility-Reihenfolge ist nicht Theme, Registrieren, Login.',
);
$userUtilityHtml = View::render('errors/404', [
    'auth' => ['is_authenticated' => true, 'is_admin' => true, 'user_name' => 'Theme User'],
    'admin_nav_items' => [],
    'user_nav_items' => [['url' => '/profil', 'label' => 'Profil', 'is_active' => false]],
    'theme_mode' => 'system',
    'theme_switcher_visible' => true,
]);
theme_assert(
    strpos($userUtilityHtml, 'id="admin-nav-dropdown"') < strpos($userUtilityHtml, 'data-theme-switcher')
    && strpos($userUtilityHtml, 'data-theme-switcher') < strpos($userUtilityHtml, 'id="user-nav-dropdown"')
    && strpos($userUtilityHtml, 'id="user-nav-dropdown"') < strpos($userUtilityHtml, 'action="/logout"'),
    'User-Utility-Reihenfolge ist nicht Admin, Theme, User-Menü, Logout.',
);
$hiddenHtml = View::render('errors/404', [
    'auth' => ['is_authenticated' => true, 'is_admin' => false, 'user_name' => 'Theme User'],
    'theme_mode' => 'dark',
    'theme_switcher_visible' => false,
]);
theme_assert(str_contains($hiddenHtml, 'data-theme="dark"') && !str_contains($hiddenHtml, 'data-theme-switcher'), 'Servermodus oder ausgeblendeter User-Switcher ist falsch.');

$themeJs = (string) file_get_contents($root . '/public/assets/js/theme-init.js');
$appJs = (string) file_get_contents($root . '/public/assets/js/app.js');
$appCss = (string) file_get_contents($root . '/public/assets/css/app.css');
$userModule = (string) file_get_contents($root . '/app/Modules/User/UserModule.php');
$userController = (string) file_get_contents($root . '/app/Modules/User/UserController.php');
theme_assert(str_contains($themeJs, "'modulon_guest_theme'") && str_contains($themeJs, "media.addEventListener('change'"), 'Gastpersistenz oder Live-Systemmodus fehlt.');
theme_assert(str_contains($themeJs, 'root.dataset.bsTheme = resolved'), 'Bootstrap erhält nicht den aufgelösten Color-Mode.');
theme_assert(str_contains($appJs, "fetch('/profil/theme'") && str_contains($appJs, "'X-CSRF-Token'"), 'Header-Switcher speichert nicht sicher per fetch.');
theme_assert(str_contains($appJs, 'theme.setMode(previousMode, false)'), 'Fehlgeschlagenes Speichern setzt die sichtbare Auswahl nicht zurück.');
theme_assert(str_contains($appCss, ':root[data-theme="dark"]') && str_contains($appCss, ':root:not([data-theme])'), 'Explizites und System-Dark-Theme sind nicht getrennt.');
theme_assert(str_contains($userModule, "'/profil/theme'") && str_contains($userController, 'ThemePreference::isValid'), 'Theme-Route oder serverseitige Allowlist fehlt.');

fwrite(STDOUT, "Theme preferences smoke test passed.\n");
