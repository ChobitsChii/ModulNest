<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\Modules\Catalog\CatalogCache;
use Modulon\Core\Modules\Catalog\CatalogLoader;
use Modulon\Core\Modules\Catalog\CatalogPackageInstaller;
use Modulon\Core\Modules\Catalog\CatalogService;
use Modulon\Core\Modules\Catalog\CatalogSnapshot;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\Catalog\HttpCatalogSource;
use Modulon\Core\Modules\LegacyModuleAdoptionService;
use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\ModuleOperationLock;
use Modulon\Core\Modules\PdoLogicalBackupProvider;
use Modulon\Core\Modules\WikiAdoptionService;
use Modulon\Core\Request;
use Modulon\Core\Session;
use Modulon\Modules\Admin\ModuleCatalogController;
use Modulon\Modules\Updates\UpdateChannel;
use Modulon\Modules\Updates\UpdatesService;

function blackboxAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = realpath(dirname(__DIR__, 2));
blackboxAssert(is_string($root), 'Artefaktwurzel fehlt.');
$expected = (string) (getenv('BLACKBOX_EXPECTED_VERSION') ?: '2.0.0-rc.3');
$catalogUrl = (string) (getenv('BLACKBOX_CATALOG_URL') ?: 'https://raw.githubusercontent.com/ChobitsChii/ModulNest-Modules/main');
$openBasedir = (string) ini_get('open_basedir');
blackboxAssert($openBasedir !== '' && str_contains($openBasedir, $root), 'Blackbox-Test läuft nicht in einer Dateisystem-Sandbox.');
blackboxAssert(!str_contains($openBasedir, '/srv/http/modulon'), 'Privater Source-Checkout ist im Blackbox-Test erreichbar.');
blackboxAssert(!file_exists($root . '/modules-src'), 'Public-Artefakt enthält modules-src.');

require $root . '/vendor/autoload.php';

$server = new PDO(
    'mysql:host=' . (getenv('BLACKBOX_DB_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('BLACKBOX_DB_PORT') ?: '3306') . ';charset=utf8mb4',
    (string) getenv('BLACKBOX_DB_USER'),
    (string) getenv('BLACKBOX_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = 'modulnest_public_blackbox_' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$profiles = [
    'modulnest.banking' => ['legacy' => 'Banking', 'route' => 'banking'],
    'modulnest.dashboard' => ['legacy' => 'Dashboard', 'route' => 'dashboard'],
    'modulnest.data-portability' => ['legacy' => 'DataPortability', 'route' => 'data-portability'],
    'modulnest.homepage' => ['legacy' => 'Homepage', 'route' => 'homepage'],
    'modulnest.logs' => ['legacy' => 'Logs', 'route' => 'logs'],
    'modulnest.news' => ['legacy' => 'News', 'route' => 'news'],
    'modulnest.pages' => ['legacy' => 'Pages', 'route' => 'pages'],
    'modulnest.sneak-preview' => ['legacy' => 'SneakPreview', 'route' => 'sneak-preview'],
    'modulnest.systeminfo' => ['legacy' => 'Systeminfo', 'route' => 'systeminfo'],
    'modulnest.tools' => ['legacy' => 'Tools', 'route' => 'tools'],
    'modulnest.wiki' => ['legacy' => 'Wiki', 'route' => 'wiki'],
];

try {
    $server->exec('USE `' . $database . '`');
    (new MigrationRunner($server, $root))->run(array_merge(['Admin', 'Auth', 'Modules', 'User', 'Updates'], array_column($profiles, 'legacy')));
    $server->exec((string) file_get_contents($root . '/app/Database/seeds/core.sql'));
    $insert = $server->prepare("INSERT INTO modules(name,description,route_prefix,access_level,handler,is_active,show_in_header,show_on_home) VALUES(?,?,?,'admin','native',1,1,0)");
    foreach ($profiles as $profile) $insert->execute([$profile['legacy'], 'Public 1.3.0 Blackbox', $profile['route']]);
    $server->exec("INSERT INTO app_settings (`key`,`value`) VALUES ('update_channel','preview') ON DUPLICATE KEY UPDATE `value`='preview'");
    $server->exec("INSERT INTO news_entries(title,slug,excerpt,content,type,status,published_at) VALUES('Blackbox News','blackbox-news','Bleibt','Bleibt','news','published',CURRENT_TIMESTAMP)");
    $server->exec("INSERT INTO pages_entries(title,slug,content_markdown,visibility,is_active,sort_order) VALUES('Blackbox Page','blackbox-page','Bleibt','public',1,1)");
    $server->exec("INSERT INTO homepage_blocks(type,title,content_markdown,visibility_guest,visibility_user,visibility_admin,sort_order,column_span,is_enabled) VALUES('custom_content','Blackbox Home','Bleibt',1,1,1,1,'full',1)");
    $server->exec("INSERT INTO users(id,name,username,email,password_hash) VALUES(9901,'Blackbox','blackbox','blackbox@example.test','x')");
    $server->exec("INSERT INTO dashboard_widgets(id,user_id,widget_type,title,sort_order,layout_width,is_active) VALUES(9902,9901,'notes','Blackbox Widget',1,6,1)");
    $server->exec("INSERT INTO dashboard_notes(widget_id,title,content,sort_order,is_pinned,is_archived) VALUES(9902,'Blackbox Note','Bleibt',1,0,0)");
    $server->exec("INSERT INTO sneak_preview_entries(sneak_date,title,location) VALUES('2026-09-09','Blackbox Sneak','Kino')");
    $server->exec("INSERT INTO banking_migration_runs(id,target_user_id,source_snapshot_label,status) VALUES(9910,9901,'Blackbox','completed')");
    $server->exec("INSERT INTO banking_accounts(id,user_id,migration_run_id,account_identifier,display_name,currency) VALUES(9911,9901,9910,'blackbox','Blackbox','EUR')");
    $server->exec("INSERT INTO wiki_sources(id,source_type,repository_owner,repository_name,ref_name,docs_root,enabled,last_sync_status) VALUES(9920,'github','Owner','Repo','main','docs',1,'success')");
    $server->exec("INSERT INTO wiki_pages(id,source_id,relative_path,route_path,title,content_hash) VALUES(9921,9920,'README.md','readme','Blackbox Wiki',REPEAT('c',64))");
    $historic = (int) $server->query("SELECT COUNT(*) FROM schema_migrations WHERE scope='module'")->fetchColumn();

    if (getenv('BLACKBOX_PREINSTALLED') !== '1') {
        $updates = new UpdatesService($root, $server);
        $stable = $updates->check('1.3.0', UpdateChannel::STABLE);
        blackboxAssert(($stable['available'] ?? true) === false, 'Stable-Kanal bietet einen RC an.');
        $preview = $updates->check('1.3.0', UpdateChannel::PREVIEW);
        blackboxAssert(($preview['latest'] ?? '') === $expected && ($preview['available'] ?? false), 'Preview-Kanal erkennt den erwarteten RC nicht.');
        $updates->prepare('1.3.0', UpdateChannel::PREVIEW);
        $coreResult = $updates->install();
        blackboxAssert(($coreResult['version'] ?? '') === $expected, 'Public Core Upgrade wurde nicht installiert.');
    } else {
        $version = require $root . '/app/Config/version.php';
        blackboxAssert(($version['version'] ?? '') === $expected, 'Vorinstalliertes Blackbox-Paket hat nicht die erwartete Version.');
    }
    blackboxAssert((string) $server->query("SELECT `value` FROM app_settings WHERE `key`='update_channel'")->fetchColumn() === 'preview', 'Updatekanal ging verloren.');
    blackboxAssert(!file_exists($root . '/modules-src'), 'Core Upgrade hat modules-src eingeführt.');
    blackboxAssert(is_file($root . '/app/Views/admin/module-catalog/index.php'), 'Public Core-Paket enthält die Modul-Katalog-View nicht.');

    // Ein echter Folgerequest lädt den gerade installierten Composer-Autoloader
    // neu. Der Test bleibt in einem Prozess und lädt deshalb nur die mit v2 neu
    // hinzugekommene Signaturbibliothek explizit nach.
    if (!class_exists(ParagonIE_Sodium_Compat::class, false)) {
        $sodiumAutoload = $root . '/vendor/paragonie/sodium_compat/autoload.php';
        blackboxAssert(is_file($sodiumAutoload), 'Sodium-Compat fehlt im aktualisierten Public-Artefakt.');
        require $sodiumAutoload;
    }

    $trust = require $root . '/app/Config/module_catalog_trust.php';
    $keys = [];
    foreach (['root', 'release'] as $role) $keys[$trust[$role]['key_id']] = $trust[$role]['public_key'];
    $loader = new CatalogLoader(new CatalogTrustStore($keys, [$trust['root']['key_id']]), new CatalogCache($root . '/storage/catalog-blackbox'));
    $source = new HttpCatalogSource('modulnest.official', $catalogUrl, 60);
    $snapshot = $loader->refresh($source);
    blackboxAssert(count($snapshot->modules) === 11, 'Remote-Katalog enthält nicht elf Module.');
    $catalog = new CatalogService($server, $expected, $snapshot);
    $lifecycle = new ModuleLifecycleService($server, $root, $expected, new PdoLogicalBackupProvider($server, $root . '/storage/backups/modules'), new ModuleOperationLock($root . '/storage/locks/modules'));
    $installer = new CatalogPackageInstaller($loader, $source, $snapshot, $catalog, $lifecycle);
    $legacy = new LegacyModuleAdoptionService($server, $root, $installer);
    $wiki = new WikiAdoptionService($server, $root, $installer);

    foreach (array_keys($profiles) as $moduleId) {
        $preflight = $moduleId === 'modulnest.wiki' ? $wiki->preflight() : $legacy->preflight($moduleId);
        blackboxAssert($preflight['eligible'] === true, $moduleId . ' Public-Preflight ist nicht adoptierbar: ' . ($preflight['message'] ?? ''));
    }
    $controller = new ModuleCatalogController($catalog, $lifecycle, $installer, new Session(), wikiAdoption: $wiki, legacyAdoption: $legacy);
    ob_start();
    $controller->index(new Request('GET', '/admin/module-catalog', [], ['bereich' => 'installiert'], []))->send();
    $catalogHtml = (string) ob_get_clean();
    blackboxAssert(http_response_code() === 200 && str_contains($catalogHtml, 'Modul-Katalog'), '/admin/module-catalog ist nicht HTTP 200.');

    $logsPath = $root . '/app/Modules/Logs/LogsModule.php';
    $logsOriginal = (string) file_get_contents($logsPath);
    file_put_contents($logsPath, $logsOriginal . "\n// blackbox unknown modification");
    $blocked = $legacy->preflight('modulnest.logs');
    blackboxAssert(!$blocked['eligible'] && $blocked['status'] === 'changed', 'Manipulierter v1-Code wurde nicht blockiert.');
    file_put_contents($logsPath, $logsOriginal);

    foreach (array_keys($profiles) as $moduleId) {
        $result = $moduleId === 'modulnest.wiki' ? $wiki->adopt() : $legacy->adopt($moduleId);
        blackboxAssert(($result['adopted'] ?? false) === true, $moduleId . ' wurde nicht adoptiert.');
    }
    blackboxAssert((int) $server->query('SELECT COUNT(*) FROM module_installations')->fetchColumn() === 11, 'Nicht alle Module sind katalogverwaltet.');
    blackboxAssert($server->query('SELECT route_prefix,COUNT(*) amount FROM modules GROUP BY route_prefix HAVING amount>1')->fetchAll(PDO::FETCH_ASSOC) === [], 'Doppelte Module oder Routen entstanden.');
    blackboxAssert((int) $server->query("SELECT COUNT(*) FROM news_entries WHERE slug='blackbox-news'")->fetchColumn() === 1, 'News-Daten gingen verloren.');
    blackboxAssert((int) $server->query("SELECT COUNT(*) FROM pages_entries WHERE slug='blackbox-page'")->fetchColumn() === 1, 'Pages-Daten gingen verloren.');
    blackboxAssert((int) $server->query("SELECT COUNT(*) FROM homepage_blocks WHERE title='Blackbox Home'")->fetchColumn() === 1, 'Homepage-Daten gingen verloren.');
    blackboxAssert((int) $server->query("SELECT COUNT(*) FROM dashboard_notes WHERE title='Blackbox Note'")->fetchColumn() === 1, 'Dashboard-Daten gingen verloren.');
    blackboxAssert((int) $server->query("SELECT COUNT(*) FROM banking_accounts WHERE account_identifier='blackbox'")->fetchColumn() === 1, 'Banking-Daten gingen verloren.');
    blackboxAssert((int) $server->query("SELECT COUNT(*) FROM wiki_pages WHERE route_path='readme'")->fetchColumn() === 1, 'Wiki-Daten gingen verloren.');
    blackboxAssert((int) $server->query("SELECT COUNT(*) FROM schema_migrations WHERE scope='module'")->fetchColumn() >= $historic, 'Migration-History ging verloren.');
    blackboxAssert(!file_exists($root . '/modules-src'), 'Blackbox-Test griff auf privaten Modulsource zurück.');
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

fwrite(STDOUT, "PUBLIC BLACKBOX PASS: 1.3.0 -> {$expected}, catalog HTTP 200, 11 adoptions, no modules-src.\n");
