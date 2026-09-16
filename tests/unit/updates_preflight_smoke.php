<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Core\Request;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Updates\UpdatesController;
use Modulon\Modules\Updates\UpdatesService;

function preflight_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// 1. Setup SQLite in-memory DB
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("
    CREATE TABLE modules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        route_prefix TEXT,
        module_key TEXT,
        handler TEXT,
        is_active INTEGER DEFAULT 1
    );
");

// Insert standard active modules:
// - Core modules (module_key is null)
$pdo->exec("INSERT INTO modules (name, route_prefix, module_key, handler, is_active) VALUES ('Profil', 'profil', NULL, 'native', 1)");
$pdo->exec("INSERT INTO modules (name, route_prefix, module_key, handler, is_active) VALUES ('Updates', 'updates', NULL, 'native', 1)");
// - Migrated v2 modules
$pdo->exec("INSERT INTO modules (name, route_prefix, module_key, handler, is_active) VALUES ('News', 'news', 'modulnest.news', 'native', 1)");
$pdo->exec("INSERT INTO modules (name, route_prefix, module_key, handler, is_active) VALUES ('Dashboard', 'dashboard', 'modulnest.dashboard', 'native', 1)");

$tmpDir = sys_get_temp_dir() . '/modulnest_preflight_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir . '/storage/updates', 0775, true);

try {
    $service = new UpdatesService($tmpDir, $pdo);

    // TEST 1: Initial state (all v2 or core) -> no legacy modules
    $legacy = $service->detectLegacyModules();
    preflight_assert($legacy === [], 'Legacy-Module sollten leer sein, wenn alles auf v2 ist.');

    $check = $service->preflightCheck();
    preflight_assert($check['passed'] === true, 'Preflight sollte bestanden sein.');
    preflight_assert($check['has_legacy_modules'] === false, 'has_legacy_modules sollte false sein.');
    preflight_assert($check['warnings'] === [], 'Warnings sollten leer sein.');

    // TEST 2: Add legacy v1 module (pages, inactive banking)
    $pdo->exec("INSERT INTO modules (name, route_prefix, module_key, handler, is_active) VALUES ('Pages', 'pages', NULL, 'native', 1)");
    $pdo->exec("INSERT INTO modules (name, route_prefix, module_key, handler, is_active) VALUES ('Inaktives Banking', 'banking', NULL, 'native', 0)");

    $legacy = $service->detectLegacyModules();
    preflight_assert(count($legacy) === 1, 'Genau 1 aktives Legacy-Modul erwartet.');
    preflight_assert($legacy[0]['name'] === 'Pages', 'Name des Legacy-Moduls sollte Pages sein.');
    preflight_assert($legacy[0]['route_prefix'] === 'pages', 'Route prefix sollte pages sein.');
    preflight_assert($legacy[0]['catalog_id'] === 'modulnest.pages', 'Catalog ID sollte modulnest.pages sein.');

    $check = $service->preflightCheck();
    preflight_assert($check['passed'] === false, 'Preflight sollte bei Legacy-Modulen fehlschlagen.');
    preflight_assert($check['has_legacy_modules'] === true, 'has_legacy_modules sollte true sein.');
    preflight_assert(count($check['warnings']) === 1, 'Warnung sollte vorhanden sein.');

    // TEST 3: Controller blocks installation if unconfirmed, allows if confirmed
    $session = new Session();
    $controller = new UpdatesController($service, $session, '2.1.5', 'stable');

    $unconfirmedRequest = new Request('POST', '/admin/updates/install', [], [], []);
    $response = $controller->install($unconfirmedRequest);
    $errorFlash = $session->pullFlash('updates_error');
    preflight_assert(str_contains((string) $errorFlash, 'Installation gestoppt'), 'Installation sollte ohne Bestätigung blockiert werden.');

    $confirmedRequest = new Request('POST', '/admin/updates/install', ['confirm_unmigrated' => '1'], [], []);
    $response = $controller->install($confirmedRequest);
    $errorFlash = $session->pullFlash('updates_error');
    preflight_assert(!str_contains((string) $errorFlash, 'Installation gestoppt'), 'Installation sollte mit Bestätigung nicht wegen v1 blockiert werden.');
    preflight_assert(str_contains((string) $errorFlash, 'Kein vorbereitetes Update'), 'Sollte an nachfolgender Install-Logik ankommen.');

    // TEST 4: View rendering with legacy warning
    $viewHtml = View::render('updates/admin', [
        'title' => 'Updates',
        'current_path' => '/admin/updates',
        'admin_section' => 'updates',
        'active_tab' => 'updates',
        'csrf_token' => 'test-token',
        'message' => '',
        'error' => '',
        'status' => [
            'installed_version' => '2.1.5',
            'installed_release_label' => 'Stable',
            'feed_url' => 'https://updates.modulnest.de/core/stable.json',
            'prerelease_feed_url' => '',
            'update_channel' => 'stable',
            'update_channel_label' => 'Stable',
            'state' => [
                'prepared' => [
                    'version' => '2.1.6',
                    'status' => 'prepared',
                    'staging_path' => '/tmp/staging',
                ],
            ],
        ],
        'sources' => [],
        'active_source' => ['id' => 'default', 'name' => 'Offiziell', 'base_url' => 'https://updates.modulnest.de/core'],
        'mirror_status' => [],
        'backups_overview' => ['total_size' => 0, 'total_size_formatted' => '0 B', 'backups_count' => 0, 'items' => []],
        'preflight' => $check,
    ]);

    preflight_assert(str_contains($viewHtml, 'Pre-Flight-Warnung: Nicht umgestellte v1-Module'), 'HTML sollte Pre-Flight-Warnung enthalten.');
    preflight_assert(str_contains($viewHtml, 'Pages'), 'HTML sollte Pages als Legacy-Modul auflisten.');
    preflight_assert(str_contains($viewHtml, 'name="confirm_unmigrated"'), 'HTML sollte Checkbox confirm_unmigrated enthalten.');
    preflight_assert(str_contains($viewHtml, 'Trotzdem installieren'), 'Button sollte Trotzdem installieren heißen.');

    // TEST 5: View rendering clean without legacy modules
    $cleanCheck = [
        'passed' => true,
        'has_legacy_modules' => false,
        'legacy_modules' => [],
        'pending_module_updates' => [],
        'warnings' => [],
    ];
    $cleanViewHtml = View::render('updates/admin', [
        'title' => 'Updates',
        'current_path' => '/admin/updates',
        'admin_section' => 'updates',
        'active_tab' => 'updates',
        'csrf_token' => 'test-token',
        'message' => '',
        'error' => '',
        'status' => [
            'installed_version' => '2.1.5',
            'installed_release_label' => 'Stable',
            'feed_url' => 'https://updates.modulnest.de/core/stable.json',
            'prerelease_feed_url' => '',
            'update_channel' => 'stable',
            'update_channel_label' => 'Stable',
            'state' => [
                'prepared' => [
                    'version' => '2.1.6',
                    'status' => 'prepared',
                    'staging_path' => '/tmp/staging',
                ],
            ],
        ],
        'sources' => [],
        'active_source' => ['id' => 'default', 'name' => 'Offiziell', 'base_url' => 'https://updates.modulnest.de/core'],
        'mirror_status' => [],
        'backups_overview' => ['total_size' => 0, 'total_size_formatted' => '0 B', 'backups_count' => 0, 'items' => []],
        'preflight' => $cleanCheck,
    ]);

    preflight_assert(!str_contains($cleanViewHtml, 'Pre-Flight-Warnung: Nicht umgestellte v1-Module'), 'HTML sollte keine Warnung enthalten, wenn clean.');
    preflight_assert(!str_contains($cleanViewHtml, 'name="confirm_unmigrated"'), 'HTML sollte keine confirm_unmigrated Checkbox enthalten, wenn clean.');
    preflight_assert(str_contains($cleanViewHtml, 'Vorbereitetes Update installieren'), 'Button sollte Vorbereitetes Update installieren heißen.');

    echo "PASS: Updates Pre-Flight smoke test passed.\n";
} finally {
    // Cleanup tmp dir
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($tmpDir);
}
