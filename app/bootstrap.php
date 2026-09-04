<?php

declare(strict_types=1);

use Modulon\Core\Application;
use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\Database;
use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Env;
use Modulon\Core\HealthCheckProviderInterface;
use Modulon\Core\HealthCheckRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleLoader;
use Modulon\Core\NativeModuleMigrationService;
use Modulon\Core\Request;
use Modulon\Core\RecoveryManager;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;
use Modulon\Core\SystemHealthCheck;
use Modulon\Core\ThemePreference;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Core\View;
use Modulon\Modules\Admin\AdminController;
use Modulon\Modules\Admin\AppSettingRepository;
use Modulon\Modules\Auth\AuthController;
use Modulon\Modules\Auth\AuthRateLimiter;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\Auth\RecoveryCodeRepository;
use Modulon\Modules\Auth\RememberTokenRepository;
use Modulon\Modules\Auth\UserRepository;
use Modulon\Modules\Auth\WebAuthnCredentialRepository;
use Modulon\Modules\Modules\ModuleRepository;
use Modulon\Modules\Pages\PagesRepository;

// Projektpfad bestimmen und Konfiguration laden.
$basePath = dirname(__DIR__);
Env::load($basePath . '/.env');
(new RecoveryManager($basePath))->migrateLegacyLogs();

// Session früh starten, damit Auth/Flash in allen Routen nutzbar ist.
Session::configureCookieSecurity(
    (string) Env::get('APP_ENV', 'production'),
    (string) Env::get('SESSION_COOKIE_SECURE', 'auto'),
    (string) Env::get('SESSION_COOKIE_SAMESITE', 'Lax'),
);
$session = new Session();
$session->start();
$csrfTokenManager = new CsrfTokenManager($session);

// Datenbankkonfiguration aus app/Config einlesen.
$databaseConfig = require $basePath . '/app/Config/database.php';
$authConfig = require $basePath . '/app/Config/auth.php';
$versionConfig = require $basePath . '/app/Config/version.php';
$publicRegistrationEnabled = (bool) ($authConfig['public_registration_enabled'] ?? true);
$showPublicHealthCheck = strtolower(trim((string) Env::get('APP_ENV', 'production'))) === 'development'
    || Env::getBool('APP_DEBUG', false);
$pdo = null;

try {
    $pdo = Database::connect($databaseConfig);
} catch (\RuntimeException) {
    // App startet auch ohne aktive DB, falls lokale Werte noch nicht gesetzt sind.
}

if ($pdo !== null) {
    try {
        $appVersion = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($versionConfig['version'] ?? 'unknown')) ?: 'unknown';
        $migrationFlag = $basePath . '/storage/migrations/' . $appVersion . '.done';
        if (!is_file($migrationFlag)) {
            $packageModules = null;
            $packagePath = $basePath . '/modulnest-package.json';
            if (is_file($packagePath)) {
                $package = json_decode((string) file_get_contents($packagePath), true);
                if (is_array($package) && is_array($package['modules'] ?? null)) {
                    $packageModules = [];
                    foreach ($package['modules'] as $module) {
                        if (!is_array($module)) {
                            continue;
                        }
                        $directory = (string) ($module['directory'] ?? '');
                        if ($directory !== '' && (!empty($module['required']) || !empty($module['default_enabled']))) {
                            $packageModules[] = $directory;
                        }
                    }
                }
            }

            $runner = new MigrationRunner($pdo, $basePath);
            $runner->run($packageModules);
            $migrationDir = dirname($migrationFlag);
            if (!is_dir($migrationDir)) {
                @mkdir($migrationDir, 0775, true);
            }
            @file_put_contents($migrationFlag, gmdate(DATE_ATOM));
        }
    } catch (\Throwable $throwable) {
        $migrationKey = preg_match('/Migration-Checksum stimmt nicht mehr: ([A-Za-z0-9_.-]+)/', $throwable->getMessage(), $matches) === 1 ? $matches[1] : '';
        (new RecoveryManager($basePath))->requireRecovery([
            'source' => 'bootstrap', 'phase' => 'migration_verification',
            'error_code' => $migrationKey !== '' ? 'migration_checksum_mismatch' : 'migration_failed',
            'migration_key' => $migrationKey, 'migrations_started' => true,
            'operator_hint' => 'Im geschützten Recovery-Bereich Migrationen prüfen und erneut bewerten.',
        ]);
        error_log('ModulNest migration bootstrap failed; recovery state recorded.');
        \Modulon\Core\SecurityHeaders::apply();
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="de"><head><meta charset="UTF-8"><title>Migration-Recovery erforderlich</title></head><body><h1>503</h1><p>Eine notwendige Migration ist fehlgeschlagen. Die Anwendung bleibt im Wartungsmodus. Administratoren können den geschützten Recovery-Bereich verwenden.</p></body></html>';
        exit;
    }
}
$healthCheckRegistry = new HealthCheckRegistry();
$healthCheck = new SystemHealthCheck($basePath, $pdo, $healthCheckRegistry);
$adminNavigationRegistry = new AdminNavigationRegistry();
$adminNavigationRegistry->registerCoreItem('modules', 'Modulverwaltung', '/admin/modules', 10, 'Module verwalten');
$adminNavigationRegistry->registerCoreItem('users', 'Benutzerverwaltung', '/admin/users', 20, 'Benutzer verwalten');
$moduleSubnavigationRegistry = new ModuleSubnavigationRegistry();
$userNavigationRegistry = new UserNavigationRegistry();

// Auth-Bausteine nur aktivieren, wenn DB verfügbar ist.
$authService = null;
$moduleRepository = null;
$userRepository = null;
$appSettingRepository = null;
if ($pdo !== null) {
    $userRepository = new UserRepository($pdo);
    $appSettingRepository = new AppSettingRepository($pdo);
    $publicRegistrationEnabled = $appSettingRepository->getBool('public_registration_enabled', $publicRegistrationEnabled);

    $authService = new AuthService(
        $userRepository,
        new RememberTokenRepository($pdo),
        new WebAuthnCredentialRepository($pdo),
        new RecoveryCodeRepository($pdo),
        $session,
        $authConfig,
        $csrfTokenManager,
    );
    $moduleRepository = new ModuleRepository($pdo);
    try {
        $moduleRepository->ensureBuiltinNativeModules();
        $activatedPackageModules = $moduleRepository->syncPackageDefaultModules($basePath);
        if ($activatedPackageModules !== []) {
            error_log('ModulNest package modules activated: ' . implode(', ', $activatedPackageModules));
        }
    } catch (\Throwable) {
        // Runtime-Migration best effort: App soll auch ohne Schema-Update weiter starten.
    }
}

$authRateLimiter = new AuthRateLimiter(
    $basePath . '/storage/rate-limits/auth.json',
    (int) ($authConfig['auth_rate_limit_max_attempts'] ?? 5),
    (int) ($authConfig['auth_rate_limit_window_seconds'] ?? 900),
);
$authController = new AuthController($authService, $session, $publicRegistrationEnabled, $authRateLimiter);
$moduleContext = new ModuleContext(
    $basePath,
    $pdo,
    $session,
    [
        'authService' => $authService,
        'moduleRepository' => $moduleRepository,
        'userRepository' => $userRepository,
        'appSettingRepository' => $appSettingRepository,
        'healthCheck' => $healthCheck,
        'healthCheckRegistry' => $healthCheckRegistry,
    ],
    [
        'authConfig' => $authConfig,
        'app_version' => (string) ($versionConfig['version'] ?? '0.0.0'),
        'app_channel' => (string) ($versionConfig['channel'] ?? 'alpha'),
        'product_name' => (string) ($versionConfig['product_name'] ?? 'Modulon'),
    ],
);
$nativeModules = NativeModuleLoader::createActiveModules($basePath, $moduleContext);
$pagesModuleActive = isset($nativeModules['pages']);
$pagesHeaderLinks = [];
$pagesFooterLinks = [];
if ($pagesModuleActive && $pdo !== null) {
    try {
        $pagesRepo = new PagesRepository($pdo);
        $pagesHeaderLinks = $pagesRepo->listPublicHeaderPages();
        $pagesFooterLinks = $pagesRepo->listPublicFooterPages();
    } catch (\Throwable) {
        $pagesHeaderLinks = [];
        $pagesFooterLinks = [];
    }
}
$nativeModuleBindings = [];
foreach (NativeModuleLoader::discover($basePath) as $moduleClass) {
    $module = $moduleClass::create($moduleContext);
    if ($module !== null) {
        $nativeModuleBindings[$module->routePrefix()] = $module->nativeBinding();
    }
}
foreach ($nativeModules as $nativeModule) {
    $nativeModule->registerNavigation($moduleSubnavigationRegistry, $adminNavigationRegistry, $userNavigationRegistry);
    if ($nativeModule instanceof HealthCheckProviderInterface) {
        $nativeModule->registerHealthChecks($healthCheckRegistry);
    }
}
$activeNativePrefixes = array_keys($nativeModules);
$moduleFeatures = [
    'profile_settings_available' => $userNavigationRegistry->hasItem('profil', 'settings'),
    'profile_security_available' => $userNavigationRegistry->hasItem('profil', 'security'),
];
$adminController = new AdminController(
    $moduleRepository,
    $userRepository,
    $appSettingRepository,
    $session,
    $authService,
    $basePath,
    $adminNavigationRegistry,
    $nativeModuleBindings,
    $pdo instanceof PDO ? new NativeModuleMigrationService($pdo, $basePath) : null,
);

$accessibleModulesForUser = static function (?array $user, bool $isAdmin, string $placement = 'all', string $currentPath = '/') use ($moduleRepository, $moduleSubnavigationRegistry, $activeNativePrefixes): array {
    if ($moduleRepository === null) {
        return [];
    }

    $modules = [];
    foreach ($moduleRepository->listActive() as $module) {
        $prefix = trim((string) ($module['route_prefix'] ?? ''), '/');
        if ($prefix === '') {
            continue;
        }

        $handler = strtolower((string) ($module['handler'] ?? 'placeholder'));
        if ($handler === 'native' && !in_array($prefix, $activeNativePrefixes, true)) {
            continue;
        }

        $access = strtolower((string) ($module['access_level'] ?? 'public'));
        if ($access === 'admin' && !$isAdmin) {
            continue;
        }
        if ($access === 'user' && $user === null) {
            continue;
        }

        $modules[] = [
            'name' => (string) ($module['name'] ?? $prefix),
            'description' => (string) ($module['description'] ?? ''),
            'prefix' => $prefix,
            'access' => $access,
            'url' => '/' . $prefix . '/',
            'children' => $placement === 'header' ? $moduleSubnavigationRegistry->itemsFor($prefix, $currentPath) : [],
            'show_in_header' => (int) ($module['show_in_header'] ?? 1) === 1,
            'show_on_home' => (int) ($module['show_on_home'] ?? 1) === 1,
        ];
    }

    if ($placement === 'header') {
        return array_values(array_filter($modules, static fn (array $module): bool => (bool) ($module['show_in_header'] ?? true)));
    }
    if ($placement === 'home') {
        return array_values(array_filter($modules, static fn (array $module): bool => (bool) ($module['show_on_home'] ?? true)));
    }

    return $modules;
};

View::setComposer(static function (array $data) use ($authService, $accessibleModulesForUser, $publicRegistrationEnabled, $adminNavigationRegistry, $userNavigationRegistry, $moduleFeatures, $versionConfig, $pagesModuleActive, $pagesHeaderLinks, $pagesFooterLinks, $csrfTokenManager): array {
    $currentPath = (string) ($data['current_path'] ?? '/');
    $user = $authService?->currentUser();
    $isAdmin = $authService?->isAdmin() ?? false;
    $themeMode = ThemePreference::normalize($user['theme_mode'] ?? ThemePreference::SYSTEM);
    $themeSwitcherVisible = $user === null || (int) ($user['theme_switcher_visible'] ?? 1) === 1;
    $pagesNavUngrouped = [];
    $pagesNavGroups = [];
    if ($pagesModuleActive) {
        foreach ($pagesHeaderLinks as $page) {
            $title = trim((string) ($page['title'] ?? ''));
            $slug = trim((string) ($page['slug'] ?? ''));
            $group = trim((string) ($page['menu_group'] ?? ''));
            if ($title === '' || $slug === '') {
                continue;
            }
            $item = [
                'title' => $title,
                'url' => '/pages/' . $slug,
            ];
            if ($group === '') {
                $pagesNavUngrouped[] = $item;
                continue;
            }
            if (!isset($pagesNavGroups[$group])) {
                $pagesNavGroups[$group] = [];
            }
            $pagesNavGroups[$group][] = $item;
        }
    }

    return [
        'current_path' => $currentPath,
        'auth' => [
            'is_authenticated' => $user !== null,
            'is_admin' => $isAdmin,
            'user_name' => (string) ($user['name'] ?? ''),
        ],
        'nav_modules' => $accessibleModulesForUser($user, $isAdmin, 'header', $currentPath),
        'admin_nav_items' => $isAdmin ? $adminNavigationRegistry->items($currentPath) : [],
        'user_nav_items' => $user !== null ? $userNavigationRegistry->items($currentPath) : [],
        'module_features' => $moduleFeatures,
        'theme_mode' => $themeMode,
        'theme_switcher_visible' => $themeSwitcherVisible,
        'public_registration_enabled' => $publicRegistrationEnabled,
        'app_version' => (string) ($versionConfig['version'] ?? '0.0.0'),
        'product_meta' => $versionConfig,
        'pages_module_active' => $pagesModuleActive,
        'pages_header_ungrouped' => $pagesNavUngrouped,
        'pages_header_groups' => $pagesNavGroups,
        'pages_footer_links' => $pagesModuleActive ? array_values(array_map(static function (array $page): array {
            return [
                'title' => (string) ($page['title'] ?? ''),
                'url' => '/pages/' . (string) ($page['slug'] ?? ''),
                'slug' => (string) ($page['slug'] ?? ''),
            ];
        }, $pagesFooterLinks)) : [],
        'csrf_token' => $csrfTokenManager->token(),
    ];
});

// Router mit Basis- und Auth-Routen aufsetzen.
$router = new Router();
$router->setAccessGuard(function (Request $request, string $access) use ($authService, $session): ?Response {
    if ($access === 'public') {
        return null;
    }

    if ($authService === null || !$authService->isAuthenticated()) {
        $session->flash('login_error', 'Bitte zuerst einloggen.');
        return Response::redirect('/login');
    }

    if ($access === 'admin' && !$authService->isAdmin()) {
        $user = $authService->currentUser();
        return new Response(View::render('errors/403', [
            'title' => '403 Forbidden',
            'current_path' => $request->path(),
            'auth' => [
                'is_authenticated' => true,
                'is_admin' => false,
                'user_name' => (string) ($user['name'] ?? ''),
            ],
        ]), 403);
    }

    return null;
});

$router->setCsrfGuard((new CsrfGuard($csrfTokenManager))->handle(...));

$router->get('/', function (Request $request) use ($pdo, $authService, $session, $accessibleModulesForUser, $publicRegistrationEnabled, $healthCheck, $showPublicHealthCheck, $nativeModules): Response {
    $message = 'Modulon Grundsystem läuft';
    if ($pdo !== null) {
        $message .= ' (DB verbunden)';
    }

    $user = $authService?->currentUser();
    $isAdmin = $authService?->isAdmin() ?? false;
    $availableModules = $accessibleModulesForUser($user, $isAdmin, 'home', $request->path());
    $flash = $session->pullFlash('auth_info');
    $healthSummary = [];
    if ($showPublicHealthCheck) {
        $health = $healthCheck->run();
        $healthSummary = is_array($health['summary'] ?? null) ? $health['summary'] : [];
    }

    $homeData = [
        'title' => 'Start',
        'current_path' => $request->path(),
        'message' => $message,
        'flash' => $flash,
        'user' => $user,
        'available_modules' => $availableModules,
        'public_registration_enabled' => $publicRegistrationEnabled,
        'health_summary' => $healthSummary,
    ];

    try {
        $homepageModule = $nativeModules['homepage'] ?? null;
        if ($homepageModule instanceof \Modulon\Modules\Homepage\HomepageModule) {
            $homepage = $homepageModule->renderer()->build($user, $isAdmin, $availableModules);
            if ($homepage !== null) {
                return new Response(View::render('homepage/render', array_merge($homeData, [
                    'homepage_blocks' => $homepage['blocks'],
                    'homepage_audience' => $homepage['audience'],
                ])));
            }
        }
    } catch (\Throwable $throwable) {
        error_log('Homepage root fallback: ' . $throwable->getMessage());
    }

    return new Response(View::render('home', $homeData));
});
$router->get('/login', [$authController, 'showLoginForm']);
$router->post('/login', [$authController, 'login'], 'public');
$router->get('/login/2fa', [$authController, 'showTwoFactorForm']);
$router->post('/login/2fa/totp', [$authController, 'verifyTwoFactorTotp'], 'public');
$router->post('/login/2fa/recovery', [$authController, 'verifyTwoFactorRecovery'], 'public');
$router->post('/webauthn/login/options', [$authController, 'webAuthnLoginOptions'], 'public');
$router->post('/webauthn/login/verify', [$authController, 'webAuthnLoginVerify'], 'public');
$router->get('/internal/register', [$authController, 'showRegisterForm']);
$router->post('/internal/register', [$authController, 'register'], 'public');
$router->post('/logout', [$authController, 'logout'], 'public');
$router->get('/account/security', [$authController, 'showSecurity'], 'user');
$router->post('/account/security/totp/start', [$authController, 'startTotpSetup'], 'user');
$router->post('/account/security/totp/confirm', [$authController, 'confirmTotpSetup'], 'user');
$router->post('/account/security/totp/disable', [$authController, 'disableTotp'], 'user');
$router->post('/account/security/recovery/regenerate', [$authController, 'regenerateRecoveryCodes'], 'user');
$router->post('/account/security/webauthn/options', [$authController, 'webAuthnRegisterOptions'], 'user');
$router->post('/account/security/webauthn/verify', [$authController, 'webAuthnRegisterVerify'], 'user');
$router->post('/account/security/webauthn/delete', [$authController, 'deleteWebAuthnCredential'], 'user');
foreach ($nativeModules as $nativeModule) {
    $nativeModule->registerRoutes($router);
}
$router->get('/admin', [$adminController, 'dashboard'], 'admin');
$router->get('/admin/modules', [$adminController, 'modules'], 'admin');
$router->get('/admin/modules/*', [$adminController, 'moduleSubRoute'], 'admin');
$router->get('/admin/users', [$adminController, 'users'], 'admin');
$router->get('/admin/users/*', [$adminController, 'userSubRoute'], 'admin');
$router->post('/admin/modules/create', [$adminController, 'createModule'], 'admin');
$router->post('/admin/modules/update', [$adminController, 'updateModule'], 'admin');
$router->post('/admin/modules/toggle', [$adminController, 'toggleModuleFlags'], 'admin');
$router->post('/admin/modules/reorder', [$adminController, 'reorderModules'], 'admin');
$router->post('/admin/modules/delete', [$adminController, 'deleteModule'], 'admin');
$router->post('/admin/users/create', [$adminController, 'createUser'], 'admin');
$router->post('/admin/users/update', [$adminController, 'updateUser'], 'admin');
$router->post('/admin/users/toggle-block', [$adminController, 'toggleUserBlocked'], 'admin');
$router->post('/admin/users/delete', [$adminController, 'deleteUser'], 'admin');
$router->post('/admin/settings/registration', [$adminController, 'updateRegistrationSetting'], 'admin');
$router->post('/admin/settings/registration/toggle', [$adminController, 'toggleRegistrationSetting'], 'admin');
foreach ($nativeModules as $nativeModule) {
    $nativeModule->registerAdminRoutes($router);
}

$injectLegacyOverlay = static function (string $html, Request $request, string $moduleName, bool $enableOverlay) use ($authService, $moduleRepository, $accessibleModulesForUser, $csrfTokenManager): string {
    if (!$enableOverlay || $authService === null || $moduleRepository === null) {
        return $html;
    }

    $user = $authService->currentUser();
    if ($user === null) {
        return $html;
    }

    // Nur injizieren, wenn eine valide HTML-Struktur vorhanden ist.
    if (stripos($html, '<html') === false
        || stripos($html, '<body') === false
        || stripos($html, '</head>') === false
        || str_contains($html, 'modulon-overlay-root')
    ) {
        return $html;
    }

    $isAdmin = $authService->isAdmin();
    $currentPath = $request->path();
    $moduleLinks = $accessibleModulesForUser($user, $isAdmin, 'all');

    $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $overlayAssets = <<<HTML
    <link rel="stylesheet" href="/assets/css/modulon-overlay.css">
    <script defer src="/assets/js/modulon-overlay.js"></script>
HTML;

    $moduleLinksHtml = '';
    foreach ($moduleLinks as $moduleLink) {
        $moduleLinksHtml .= '<a class="modulon-overlay-link" href="' . $esc((string) ($moduleLink['url'] ?? '/')) . '">' . $esc((string) ($moduleLink['name'] ?? 'Modul')) . '</a>';
    }
    $appsBlockHtml = $moduleLinksHtml !== ''
        ? $moduleLinksHtml
        : '<div class="modulon-overlay-empty">Keine aktiven Apps.</div>';

    $adminLinkHtml = $isAdmin
        ? '<a class="modulon-overlay-link" href="/admin/modules">Admin</a>'
        : '';

    $overlayHtml = <<<HTML
<div id="modulon-overlay-root" class="modulon-overlay-root" data-modulon-overlay-root>
    <button type="button" class="modulon-overlay-tab" data-modulon-overlay-toggle aria-label="Modulon Menü öffnen">
        <img src="/assets/img/modulon-icon.svg" alt="" class="modulon-overlay-tab-icon">
        <span class="modulon-overlay-visually-hidden">Modulon Menü</span>
    </button>
    <aside class="modulon-overlay-panel" data-modulon-overlay-panel aria-hidden="true">
        <div class="modulon-overlay-title">{$esc($moduleName)}</div>
        <div class="modulon-overlay-subtitle">{$esc((string) ($user['name'] ?? ''))}</div>
        <div class="modulon-overlay-section modulon-overlay-section-modulon">
            <div class="modulon-overlay-section-title">Modulon</div>
            <nav class="modulon-overlay-nav">
                <a class="modulon-overlay-link" href="/">Modulon Start</a>
                <a class="modulon-overlay-link" href="/profil/security">Profil / Sicherheit</a>
                {$adminLinkHtml}
            </nav>
        </div>
        <div class="modulon-overlay-section-separator"></div>
        <div class="modulon-overlay-section modulon-overlay-section-apps">
            <div class="modulon-overlay-section-title">Apps</div>
            <nav class="modulon-overlay-nav">
                {$appsBlockHtml}
            </nav>
        </div>
        <form method="post" action="/logout" class="modulon-overlay-logout-form">
            <input type="hidden" name="_csrf" value="{$esc($csrfTokenManager->token())}">
            <button type="submit" class="modulon-overlay-logout">Logout</button>
        </form>
        <div class="modulon-overlay-meta">Aktive Seite: {$esc($currentPath)}</div>
    </aside>
</div>
HTML;

    if (!preg_match('~</head>~i', $html) || !preg_match('~<body\b[^>]*>~i', $html)) {
        return $html;
    }

    $updated = preg_replace('~</head>~i', $overlayAssets . "\n</head>", $html, 1);
    if (!is_string($updated)) {
        return $html;
    }

    $updated = preg_replace('~<body\b[^>]*>~i', '$0' . "\n" . $overlayHtml, $updated, 1);
    return is_string($updated) ? $updated : $html;
};

if ($moduleRepository !== null) {
    foreach ($moduleRepository->listActive() as $module) {
        $moduleId = (int) ($module['id'] ?? 0);
        $prefix = trim((string) ($module['route_prefix'] ?? ''), '/');
        $name = (string) ($module['name'] ?? 'Modul');
        $description = (string) ($module['description'] ?? '');
        $access = strtolower((string) ($module['access_level'] ?? 'public'));
        $handler = strtolower((string) ($module['handler'] ?? 'placeholder'));
        $legacyEntry = trim((string) ($module['legacy_entry'] ?? ''), '/');

        if ($prefix === '') {
            continue;
        }

        // Native Module werden über eigene Controller registriert und nicht als Platzhalter geroutet.
        if ($handler === 'native') {
            continue;
        }

        $basePathPrefix = '/' . $prefix;

        if ($handler === 'legacy' && $legacyEntry !== '') {
            $legacyDispatcher = function (Request $request) use ($name, $legacyEntry, $basePath, $basePathPrefix, $moduleRepository, $moduleId, $injectLegacyOverlay): Response {
                $legacyRoot = realpath($basePath . '/app/Legacy');
                $legacyFile = $legacyRoot !== false ? realpath($legacyRoot . '/' . $legacyEntry) : false;

                if (!is_string($legacyRoot) || !is_string($legacyFile) || !is_file($legacyFile)) {
                    return new Response(View::render('errors/500', [
                        'title' => 'Legacy Modul Fehler',
                        'current_path' => $request->path(),
                    ]), 500);
                }

                $legacyRoot = str_replace('\\', '/', $legacyRoot);
                $legacyFile = str_replace('\\', '/', $legacyFile);
                if (!str_starts_with($legacyFile, $legacyRoot . '/')) {
                    return new Response(View::render('errors/403', [
                        'title' => '403 Forbidden',
                        'current_path' => $request->path(),
                    ]), 403);
                }

                // Canonical URL mit Trailing Slash, damit relative Legacy-Assets korrekt aufgelöst werden.
                if ($request->method() === 'GET'
                    && rtrim($request->path(), '/') === rtrim($basePathPrefix, '/')
                    && !str_ends_with($request->path(), '/')
                ) {
                    return Response::redirect($basePathPrefix . '/');
                }

                $moduleRoot = str_replace('\\', '/', dirname($legacyFile));
                $currentModule = $moduleRepository->findById($moduleId);
                $overlayEnabled = (int) ($currentModule['enable_overlay'] ?? 0) === 1;

                $executeLegacyPhp = static function (string $phpFile, bool $overlayEnabled) use ($basePathPrefix, $injectLegacyOverlay, $request, $name): Response {
                    $originalCwd = getcwd();
                    $originalLegacyPrefix = $_SERVER['MODULON_LEGACY_PREFIX'] ?? null;
                    ob_start();

                    try {
                        chdir(dirname($phpFile));
                        $_SERVER['MODULON_LEGACY_PREFIX'] = $basePathPrefix;
                        // Legacy-PHP erwartet häufig echte globale Variablen (z. B. $CONFIG).
                        extract($GLOBALS, EXTR_REFS);
                        require $phpFile;
                        $output = ob_get_clean();
                    } finally {
                        if ($originalLegacyPrefix === null) {
                            unset($_SERVER['MODULON_LEGACY_PREFIX']);
                        } else {
                            $_SERVER['MODULON_LEGACY_PREFIX'] = $originalLegacyPrefix;
                        }
                        if (is_string($originalCwd)) {
                            chdir($originalCwd);
                        }
                    }

                    $content = is_string($output ?? null) ? $output : '';
                    $content = $injectLegacyOverlay($content, $request, $name, $overlayEnabled);
                    return new Response($content === '' ? '<p>Legacy-Modul ohne Ausgabe.</p>' : $content);
                };

                $relativePath = ltrim(substr($request->path(), strlen($basePathPrefix)), '/');
                $relativePath = rawurldecode($relativePath);
                $requestedFile = false;
                $requestedDirectory = false;

                if ($relativePath !== '') {
                    $candidate = realpath($moduleRoot . '/' . $relativePath);
                    if (is_string($candidate)) {
                        $candidateNormalized = str_replace('\\', '/', $candidate);
                        if (str_starts_with($candidateNormalized, $moduleRoot . '/')) {
                            if (is_file($candidate)) {
                                $requestedFile = $candidate;
                            } elseif (is_dir($candidate)) {
                                $requestedDirectory = $candidate;
                            }
                        }
                    }
                }

                if (is_string($requestedDirectory)) {
                    if ($request->method() === 'GET' && !str_ends_with($request->path(), '/')) {
                        return Response::redirect($request->path() . '/');
                    }

                    $directoryIndex = realpath($requestedDirectory . '/index.php');
                    if (is_string($directoryIndex) && is_file($directoryIndex)) {
                        $directoryIndexNormalized = str_replace('\\', '/', $directoryIndex);
                        if (str_starts_with($directoryIndexNormalized, $moduleRoot . '/')) {
                            $requestedFile = $directoryIndex;
                        }
                    }
                }

                if (is_string($requestedFile)) {
                    $extension = strtolower((string) pathinfo($requestedFile, PATHINFO_EXTENSION));

                    if ($extension === 'php') {
                        return $executeLegacyPhp($requestedFile, $overlayEnabled);
                    }

                    $allowedStaticExtensions = [
                        'css', 'js', 'map', 'json', 'txt',
                        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
                        'woff', 'woff2', 'ttf', 'eot',
                    ];

                    if (!in_array($extension, $allowedStaticExtensions, true)) {
                        return new Response(View::render('errors/403', [
                            'title' => '403 Forbidden',
                            'current_path' => $request->path(),
                        ]), 403);
                    }

                    $content = file_get_contents($requestedFile);
                    if (!is_string($content)) {
                        return new Response(View::render('errors/500', [
                            'title' => 'Legacy Modul Fehler',
                            'current_path' => $request->path(),
                        ]), 500);
                    }

                    $mimeByExtension = [
                        'css' => 'text/css; charset=UTF-8',
                        'js' => 'application/javascript; charset=UTF-8',
                        'map' => 'application/json; charset=UTF-8',
                        'json' => 'application/json; charset=UTF-8',
                        'txt' => 'text/plain; charset=UTF-8',
                        'png' => 'image/png',
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'gif' => 'image/gif',
                        'svg' => 'image/svg+xml',
                        'webp' => 'image/webp',
                        'ico' => 'image/x-icon',
                        'woff' => 'font/woff',
                        'woff2' => 'font/woff2',
                        'ttf' => 'font/ttf',
                        'eot' => 'application/vnd.ms-fontobject',
                    ];

                    $mime = $mimeByExtension[$extension] ?? mime_content_type($requestedFile);
                    if (!is_string($mime) || $mime === '') {
                        $mime = 'application/octet-stream';
                    }

                    return new Response($content, 200, ['Content-Type' => $mime]);
                }

                return $executeLegacyPhp($legacyFile, $overlayEnabled);
            };

            $router->get($basePathPrefix . '/*', $legacyDispatcher, $access);
            $router->post($basePathPrefix . '/*', $legacyDispatcher, $access);
            $router->put($basePathPrefix . '/*', $legacyDispatcher, $access);
            $router->patch($basePathPrefix . '/*', $legacyDispatcher, $access);
            $router->delete($basePathPrefix . '/*', $legacyDispatcher, $access);

            continue;
        }

        $router->get($basePathPrefix, function (Request $request) use ($name, $description, $prefix, $access, $authService): Response {
            $user = $authService?->currentUser();
            return new Response(View::render('modules/show', [
                'title' => (string) $name,
                'current_path' => $request->path(),
                'module_name' => $name,
                'module_description' => $description,
                'prefix' => $prefix,
                'access' => $access,
                'user' => $user,
            ]));
        }, $access);
    }
}

$requestBootstrap = function (Request $request) use ($authService): void {
    if ($authService === null) {
        return;
    }

    $authService->enforceSessionLifetime();
    if ($authService->currentUser() === null) {
        $authService->tryRememberLogin($request->cookie($authService->rememberCookieName()), [
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'forwarded_for' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'request_method' => $request->method(),
            'request_path' => $request->path(),
            'session_active' => session_status() === PHP_SESSION_ACTIVE,
            'remember_cookie_present' => $request->cookie($authService->rememberCookieName()) !== null,
        ]);
    }
};

return new Application($router, $pdo, $requestBootstrap);
