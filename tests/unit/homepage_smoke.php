<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\MarkdownRenderer;
use Modulon\Core\NativeModuleMigrationService;
use Modulon\Core\Request;
use Modulon\Core\Session;
use Modulon\Modules\Admin\AdminController;
use Modulon\Modules\Admin\AppSettingRepository;
use Modulon\Modules\Homepage\HomepageController;
use Modulon\Modules\Homepage\HomepageRenderer;
use Modulon\Modules\Homepage\HomepageRepository;
use Modulon\Modules\Modules\ModuleRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function homepage_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * @return array<string,string>
 */
function homepage_smoke_env(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $values[trim($key)] = $value;
    }

    return $values;
}

$basePath = dirname(__DIR__, 2);
$env = homepage_smoke_env($basePath . '/.env');
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';

$server = new PDO("mysql:host={$host};port={$port};charset={$charset}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);

$dbName = 'modulnest_homepage_smoke_' . bin2hex(random_bytes(4));
$quotedDbName = '`' . str_replace('`', '``', $dbName) . '`';
$server->exec("CREATE DATABASE {$quotedDbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $server->exec("USE {$quotedDbName}");

    $runner = new MigrationRunner($server, $basePath);
    $result = $runner->run(['Admin', 'Auth', 'Modules', 'User']);
    homepage_smoke_assert(count($result['errors']) === 0, 'Homepage-Migration enthält Fehler.');
    homepage_smoke_assert($server->query("SHOW TABLES LIKE 'homepage_blocks'")->fetchColumn() === false, 'Homepage-Tabelle darf vor Modulaktivierung noch nicht existieren.');

    $moduleRepository = new ModuleRepository($server);
    $moduleRepository->discoverNativeModules($basePath);
    $homepageModule = null;
    foreach ($moduleRepository->listAll() as $module) {
        if ((string) ($module['route_prefix'] ?? '') === 'homepage') {
            $homepageModule = $module;
            break;
        }
    }
    homepage_smoke_assert(is_array($homepageModule), 'Homepage-Modul wurde nicht entdeckt.');
    homepage_smoke_assert((int) ($homepageModule['is_active'] ?? 1) === 0, 'Homepage-Modul muss vor dem Aktivierungstest inaktiv sein.');

    $activationSession = new Session();
    $activationSession->start();
    $activationController = new AdminController(
        $moduleRepository,
        null,
        null,
        $activationSession,
        null,
        $basePath,
        null,
        [],
        new NativeModuleMigrationService($server, $basePath),
    );
    $activationController->toggleModuleFlags(new Request('POST', '/admin/modules/toggle-flags', [
        'module_id' => (string) ($homepageModule['id'] ?? 0),
        'field' => 'is_active',
        'enabled' => '1',
    ], [], []));

    $tableExists = $server->query("SHOW TABLES LIKE 'homepage_blocks'")->fetchColumn();
    homepage_smoke_assert(is_string($tableExists) && $tableExists === 'homepage_blocks', 'homepage_blocks wurde nicht angelegt.');
    homepage_smoke_assert($server->query("SHOW TABLES LIKE 'homepage_block_buttons'")->fetchColumn() === 'homepage_block_buttons', 'homepage_block_buttons wurde nicht angelegt.');
    homepage_smoke_assert($server->query("SHOW TABLES LIKE 'homepage_block_items'")->fetchColumn() === 'homepage_block_items', 'homepage_block_items wurde nicht angelegt.');
    homepage_smoke_assert((int) $server->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'homepage_blocks' AND COLUMN_NAME = 'button_layout'")->fetchColumn() === 1, 'button_layout wurde nicht angelegt.');
    homepage_smoke_assert((int) $server->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'homepage_blocks' AND COLUMN_NAME = 'show_title'")->fetchColumn() === 1, 'show_title wurde nicht angelegt.');
    $homepageAfterActivation = $moduleRepository->findById((int) ($homepageModule['id'] ?? 0));
    homepage_smoke_assert(is_array($homepageAfterActivation) && (int) ($homepageAfterActivation['is_active'] ?? 0) === 1, 'Homepage-Modul wurde nicht aktiviert.');
    $rerun = (new NativeModuleMigrationService($server, $basePath))->runForRoutePrefix('homepage');
    homepage_smoke_assert(count($rerun['executed']) === 0, 'Bereits ausgeführte Homepage-Migration darf nicht erneut laufen.');
    $moduleRepository->updateFlags((int) ($homepageModule['id'] ?? 0), false, false);
    $tableAfterDeactivation = $server->query("SHOW TABLES LIKE 'homepage_blocks'")->fetchColumn();
    homepage_smoke_assert(is_string($tableAfterDeactivation) && $tableAfterDeactivation === 'homepage_blocks', 'Deaktivieren darf Homepage-Datenbankstruktur nicht löschen.');

    $settings = new AppSettingRepository($server);
    $repository = new HomepageRepository($server, $settings);
    $renderer = new HomepageRenderer($repository, new MarkdownRenderer());

    homepage_smoke_assert(!$repository->isPublished(), 'Homepage muss standardmäßig unveröffentlicht sein.');
    homepage_smoke_assert($renderer->build(null, false, []) === null, 'Unveröffentlichte Homepage muss Fallback auslösen.');

    $guestId = $repository->createBlock([
        'type' => 'custom_content',
        'title' => 'Gastblock',
        'show_title' => true,
        'content_markdown' => '**Hallo** `ModulNest`',
        'button_label' => 'Start',
        'button_url' => '/dashboard',
        'visibility_guest' => true,
        'visibility_user' => false,
        'visibility_admin' => false,
        'column_span' => 'full',
        'button_layout' => 'inline_right',
        'is_enabled' => true,
        'buttons' => [
            ['label' => 'Login', 'url' => '/login', 'variant' => 'primary'],
            ['label' => 'GitHub', 'url' => 'https://github.com/ChobitsChii/ModulNest', 'variant' => 'secondary'],
        ],
        'items' => [],
    ]);
    $adminId = $repository->createBlock([
        'type' => 'custom_content',
        'title' => 'Adminblock',
        'show_title' => true,
        'content_markdown' => 'Nur Admins',
        'button_label' => null,
        'button_url' => null,
        'visibility_guest' => false,
        'visibility_user' => false,
        'visibility_admin' => true,
        'column_span' => 'half',
        'is_enabled' => true,
        'buttons' => [],
        'items' => [],
    ]);
    $featureId = $repository->createBlock([
        'type' => 'feature_list',
        'title' => 'Warum ModulNest',
        'show_title' => true,
        'content_markdown' => 'Kurz erklärt.',
        'button_label' => null,
        'button_url' => null,
        'visibility_guest' => true,
        'visibility_user' => true,
        'visibility_admin' => true,
        'column_span' => 'one_third',
        'is_enabled' => true,
        'buttons' => [],
        'items' => [
            ['title' => 'Ein Login', 'content_markdown' => 'Mehrere Bereiche unter einem Konto.'],
            ['title' => 'Module', 'content_markdown' => 'Aktivierbare Bausteine.'],
            ['title' => 'Self-hosted', 'content_markdown' => 'Läuft auf eigener Infrastruktur.'],
        ],
    ]);
    $moduleListId = $repository->createBlock([
        'type' => 'module_list',
        'title' => 'Module',
        'show_title' => true,
        'content_markdown' => 'Diese Bereiche sind verfügbar.',
        'button_label' => null,
        'button_url' => null,
        'visibility_guest' => true,
        'visibility_user' => true,
        'visibility_admin' => true,
        'column_span' => 'full',
        'is_enabled' => true,
        'buttons' => [],
        'items' => [],
    ]);
    $repository->setPublished(true);

    $guest = $renderer->build(null, false, [[
        'name' => 'News',
        'description' => 'Updates',
        'url' => '/news',
    ]]);
    homepage_smoke_assert(is_array($guest), 'Veröffentlichte Gast-Homepage sollte gerendert werden.');
    homepage_smoke_assert(count($guest['blocks']) === 3, 'Gast sollte Gastblock, Feature-Liste und Modulliste sehen.');
    homepage_smoke_assert((string) ($guest['audience'] ?? '') === 'guest', 'Gast-Audience falsch.');
    $guestFirst = $guest['blocks'][0] ?? [];
    homepage_smoke_assert(is_array($guestFirst) && (string) ($guestFirst['column_span'] ?? '') === 'full', 'Full-Breite wurde nicht erhalten.');
    homepage_smoke_assert(is_array($guestFirst) && (string) ($guestFirst['button_layout'] ?? '') === 'inline_right', 'Button-Layout wurde nicht gerendert.');
    homepage_smoke_assert(is_array($guestFirst) && (bool) ($guestFirst['show_title'] ?? false), 'Titel-Sichtbarkeit wurde nicht gerendert.');
    homepage_smoke_assert(is_array($guestFirst) && count($guestFirst['buttons'] ?? []) === 2, 'Mehrere Buttons wurden nicht gerendert.');
    $featureBlock = null;
    foreach ($guest['blocks'] as $block) {
        if (is_array($block) && (string) ($block['type'] ?? '') === 'feature_list') {
            $featureBlock = $block;
            break;
        }
    }
    homepage_smoke_assert(is_array($featureBlock), 'Feature-Listen-Block wurde nicht gerendert.');
    homepage_smoke_assert((string) ($featureBlock['column_span'] ?? '') === 'one_third', 'Ein-Drittel-Breite wurde nicht gerendert.');
    homepage_smoke_assert(count($featureBlock['items'] ?? []) === 3, 'Feature-Items wurden nicht gerendert.');

    $admin = $renderer->build(['id' => 1], true, []);
    homepage_smoke_assert(is_array($admin), 'Veröffentlichte Admin-Homepage sollte gerendert werden.');
    homepage_smoke_assert(count($admin['blocks']) === 3, 'Admin sollte Adminblock, Feature-Liste und Modulliste sehen.');
    homepage_smoke_assert((string) ($admin['audience'] ?? '') === 'admin', 'Admin-Audience falsch.');

    $repository->updateBlock($guestId, [
        'type' => 'custom_content',
        'title' => 'Gastblock bearbeitet',
        'show_title' => false,
        'content_markdown' => 'Aktualisiert',
        'button_label' => 'Unsicher',
        'button_url' => null,
        'visibility_guest' => true,
        'visibility_user' => true,
        'visibility_admin' => false,
        'column_span' => 'half',
        'button_layout' => 'below_text',
        'is_enabled' => true,
        'buttons' => [],
        'items' => [],
    ]);
    $updated = $repository->findBlock($guestId);
    homepage_smoke_assert(is_array($updated) && (string) ($updated['title'] ?? '') === 'Gastblock bearbeitet', 'Block bearbeiten fehlgeschlagen.');
    homepage_smoke_assert((string) ($updated['column_span'] ?? '') === 'half', 'Block-Breite wurde nicht gespeichert.');
    homepage_smoke_assert((string) ($updated['button_layout'] ?? '') === 'below_text', 'Button-Layout wurde nicht gespeichert.');
    homepage_smoke_assert((int) ($updated['show_title'] ?? 1) === 0, 'Titel-Sichtbarkeit wurde nicht gespeichert.');

    $repository->updateBlock($featureId, [
        'type' => 'feature_list',
        'title' => 'Warum ModulNest aktualisiert',
        'show_title' => true,
        'content_markdown' => null,
        'button_label' => null,
        'button_url' => null,
        'visibility_guest' => true,
        'visibility_user' => true,
        'visibility_admin' => true,
        'column_span' => 'two_thirds',
        'is_enabled' => true,
        'buttons' => [],
        'items' => [
            ['title' => 'Zwei Drittel', 'content_markdown' => 'Layout-Test.'],
        ],
    ]);
    $featureUpdated = $repository->findBlock($featureId);
    homepage_smoke_assert(is_array($featureUpdated) && (string) ($featureUpdated['column_span'] ?? '') === 'two_thirds', 'Zwei-Drittel-Breite wurde nicht gespeichert.');
    homepage_smoke_assert(is_array($featureUpdated) && count($featureUpdated['items'] ?? []) === 1, 'Feature-Items wurden beim Bearbeiten nicht ersetzt.');

    $repository->setBlockEnabled($guestId, false);
    $guestAfterDisable = $renderer->build(null, false, []);
    homepage_smoke_assert(is_array($guestAfterDisable) && count($guestAfterDisable['blocks']) === 2, 'Deaktivierter Block darf nicht gerendert werden.');

    $repository->setBlockEnabled($guestId, true);
    $beforeMove = array_map(static fn (array $block): int => (int) $block['id'], $repository->listBlocks());
    $repository->moveBlock($moduleListId, 'up');
    $afterMove = array_map(static fn (array $block): int => (int) $block['id'], $repository->listBlocks());
    homepage_smoke_assert($beforeMove !== $afterMove, 'Sortierung wurde nicht geändert.');

    $repository->deleteBlock($adminId);
    homepage_smoke_assert($repository->findBlock($adminId) === null, 'Block löschen fehlgeschlagen.');

    $server->exec(
        "INSERT INTO homepage_blocks
            (type, title, content_markdown, button_label, button_url, visibility_guest, visibility_user, visibility_admin, sort_order, column_span, is_enabled)
         VALUES
            ('custom_content', 'Unsafe', 'Text', 'Gefährlich', 'javascript:alert(1)', 1, 1, 1, 100, 'full', 1)"
    );
    $unsafe = $renderer->build(null, false, []);
    homepage_smoke_assert(is_array($unsafe), 'Renderer sollte trotz unsafe URL rendern.');
    foreach ($unsafe['blocks'] as $block) {
        homepage_smoke_assert((string) ($block['button_url'] ?? '') !== 'javascript:alert(1)', 'Unsafe Button-URL darf nicht gerendert werden.');
    }

    $beforeUnsafeControllerCreate = count($repository->listBlocks());
    $session = new Session();
    $session->start();
    $controller = new HomepageController($repository, $session, $renderer);
    $controller->createBlock(new Request('POST', '/admin/homepage/blocks/create', [
        'type' => 'custom_content',
        'title' => 'Unsafe via Controller',
        'content_markdown' => 'Text',
        'button_label' => 'Unsafe',
        'button_url' => 'javascript:alert(1)',
        'visibility_guest' => '1',
        'visibility_user' => '1',
        'visibility_admin' => '1',
        'column_span' => 'full',
        'is_enabled' => '1',
        'buttons' => [
            ['label' => 'Unsafe', 'url' => 'javascript:alert(1)', 'variant' => 'primary'],
        ],
    ], [], []));
    homepage_smoke_assert(count($repository->listBlocks()) === $beforeUnsafeControllerCreate, 'Controller darf unsafe Button-URL nicht speichern.');
} finally {
    $server->exec("DROP DATABASE IF EXISTS {$quotedDbName}");
}

fwrite(STDOUT, "Homepage smoke test passed.\n");
