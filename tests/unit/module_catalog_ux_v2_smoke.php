<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Core\Modules\Catalog\CatalogService;
use Modulon\Core\Modules\Catalog\CatalogSnapshot;

function catalog_ux_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function catalog_ux_module(string $id, string $name): array
{
    return [
        'schema_version' => 1,
        'id' => $id,
        'name' => $name,
        'description' => $name . ' als unabhängiges Paket.',
        'homepage' => 'https://example.invalid',
        'repository' => 'https://example.invalid/repository',
        'license' => 'MIT',
        'authors' => ['Test'],
        'releases' => [
            ['version' => '1.0.0', 'core' => '>=1.2.0 <3.0.0', 'php' => '>=8.3.0', 'dependencies' => []],
            ['version' => '1.0.1', 'core' => '>=1.2.0 <3.0.0', 'php' => '>=8.3.0', 'dependencies' => []],
        ],
    ];
}

function catalog_ux_render(array $modules, string $tab = 'installiert'): string
{
    $counts = ['entdecken' => 0, 'installiert' => count($modules), 'updates' => 0];
    $csrf_token = 'test';
    $catalog_warning = null;
    $test_key_active = true;
    ob_start();
    require dirname(__DIR__, 2) . '/app/Views/admin/module-catalog/index.php';
    return (string) ob_get_clean();
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE modules (id INTEGER PRIMARY KEY AUTOINCREMENT, module_key TEXT NULL, name TEXT, description TEXT, route_prefix TEXT, access_level TEXT DEFAULT "user", handler TEXT, is_active INTEGER, sort_order INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE module_installations (module_id TEXT PRIMARY KEY, module_row_id INTEGER, origin TEXT, catalog_source_id TEXT, catalog_sequence INTEGER, installed_version TEXT, active_release_id TEXT, data_schema_version INTEGER DEFAULT 0, retained_data INTEGER DEFAULT 0, health_status TEXT, package_sha256 TEXT)');
$pdo->exec('CREATE TABLE module_releases (module_id TEXT, release_id TEXT, manifest_json TEXT)');
$pdo->exec('CREATE TABLE module_data_resources (module_id TEXT, resource_type TEXT, resource_key TEXT)');
$pdo->exec('CREATE TABLE module_operations (module_id TEXT, operation_type TEXT, status TEXT, phase TEXT, error_message TEXT, created_at TEXT)');

$insert = $pdo->prepare('INSERT INTO modules (name,description,route_prefix,handler,is_active,sort_order) VALUES (?,?,?,?,?,?)');
$rows = [
    ['Profil', 'Core-Profil', 'profil', 'native', 1, 1],
    ['Updates', 'Core-Updates', 'updates', 'native', 1, 2],
    ['Wiki', 'Gebündeltes Wiki', 'wiki', 'native', 1, 3],
    ['Logs', 'Gebündelte Logs', 'logs', 'native', 1, 4],
    ['Systeminfo', 'Gebündelte Systeminfo', 'systeminfo', 'native', 0, 5],
    ['News', 'Gebündelte News', 'news', 'native', 1, 6],
    ['Dashboard', 'Gebündeltes Dashboard', 'dashboard', 'native', 1, 7],
    ['Banking (old)', 'Tatsächliche Altanwendung', 'banking-old', 'legacy', 1, 8],
    ['Lokaler Link', 'Manueller lokaler Eintrag', 'local-link', 'placeholder', 1, 9],
];
foreach ($rows as $row) $insert->execute($row);

$catalogModules = [];
foreach (['wiki' => 'Wiki', 'logs' => 'Logs', 'systeminfo' => 'Systeminfo', 'news' => 'News'] as $key => $name) {
    $catalogModules['modulnest.' . $key] = catalog_ux_module('modulnest.' . $key, $name);
}
$snapshot = new CatalogSnapshot('modulnest.dev', ['sequence' => 1], $catalogModules);
$service = new CatalogService($pdo, '1.2.0', $snapshot);

catalog_ux_assert($service->discover() === [], 'Vorhandene v1-Module erscheinen fälschlich unter Entdecken.');
catalog_ux_assert($service->updates() === [], 'v1→v2-Adoption erscheint fälschlich unter Updates.');
$installed = $service->installed();
foreach ($catalogModules as $id => $module) {
    $item = $service->module($id);
    catalog_ux_assert($item !== null && $item['classification'] === 'v1' && $item['adoptable'] && $item['available_version'] === '1.0.1', $id . ' ist nicht als adoptierbares Modul v1 klassifiziert.');
    catalog_ux_assert(count(array_filter($installed, static fn (array $row): bool => $row['name'] === $module['name'])) === 1, $module['name'] . ' erscheint doppelt.');
}
catalog_ux_assert(count(array_filter($installed, static fn (array $row): bool => $row['classification'] === 'core')) === 5, 'Die fünf Core-Komponenten sind nicht sichtbar.');
catalog_ux_assert(count(array_filter($installed, static fn (array $row): bool => $row['classification'] === 'legacy')) === 1, 'Echte Legacy-Anwendung ist falsch klassifiziert.');
catalog_ux_assert(count(array_filter($installed, static fn (array $row): bool => $row['classification'] === 'local')) === 1, 'Lokaler manueller Eintrag ist falsch klassifiziert.');

$html = catalog_ux_render($installed);
catalog_ux_assert(str_contains($html, 'Modul v1') && str_contains($html, 'Modul-v2-Version 1.0.1 verfügbar'), 'v1-Migrationsstatus fehlt in der Oberfläche.');
catalog_ux_assert(str_contains($html, 'Auf Modul v2 umstellen'), 'Explizite Umstellungsaktion fehlt.');
catalog_ux_assert(!str_contains($html, 'Technische Ansicht'), 'Redundanter Technikbutton ist noch vorhanden.');
catalog_ux_assert(!str_contains($html, 'Erweiterte Modulverwaltung'), 'Redundanter Kopfbutton ist noch vorhanden.');

$blockedWiki = $service->module('modulnest.wiki');
catalog_ux_assert($blockedWiki !== null, 'Wiki fehlt für den UI-Preflight-Test.');
$blockedWiki['adoptable'] = false;
$blockedWiki['adoption_candidate'] = true;
$blockedWiki['adoption_preflight'] = [
    'eligible' => false,
    'status' => 'changed',
    'message' => 'Modul v1 wurde lokal verändert',
    'technical_detail' => 'Erste Abweichung: app/Modules/Wiki/WikiModule.php (Prüfsumme stimmt nicht überein).',
    'first_difference' => 'app/Modules/Wiki/WikiModule.php',
];
$blockedWiki['reinstallable'] = true;
$blockedWiki['reinstall_preflight'] = [
    'eligible' => true,
    'status' => 'reinstallable',
    'message' => 'Paket und Daten sind kompatibel.',
    'technical_detail' => null,
    'first_difference' => null,
];
$blockedHtml = catalog_ux_render([$blockedWiki]);
catalog_ux_assert(str_contains($blockedHtml, 'Modul v1 wurde lokal verändert'), 'Blockierter Preflight wird nicht verständlich angezeigt.');
catalog_ux_assert(str_contains($blockedHtml, 'Automatische Umstellung nicht möglich.'), 'Blockierter Preflight enthält keine Handlungsaussage.');
catalog_ux_assert(!str_contains($blockedHtml, 'Auf Modul v2 umstellen'), 'Blockierter Preflight bietet weiterhin die Mutationsaktion an.');
catalog_ux_assert(str_contains($blockedHtml, 'Modul v2 neu installieren'), 'Sicherer Neuinstallationspfad fehlt bei kompatiblen Daten.');
catalog_ux_assert(!str_contains($blockedHtml, '/srv/http/'), 'Blockierter Preflight veröffentlicht einen absoluten lokalen Pfad.');
$detailSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/module-catalog/detail.php');
catalog_ux_assert(str_contains($detailSource, 'Vorhandene kompatible Daten bleiben standardmäßig erhalten.'), 'Neuinstallationsdetail erklärt den Datenerhalt nicht.');
catalog_ux_assert(str_contains($detailSource, 'confirm_reinstall_module_id'), 'Bewusste Bestätigung der Neuinstallation fehlt.');
catalog_ux_assert(str_contains($detailSource, 'data-adoption-operation') && str_contains($detailSource, 'module-catalog-operation/status'), 'Reload-fester Adoptionsfortschritt fehlt in der Katalogdetailseite.');
$controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Admin/ModuleCatalogController.php');
catalog_ux_assert(str_contains($controllerSource, '$id === \'modulnest.tools\'') && str_contains($controllerSource, 'adoptionOperations->start($id)'), 'Tools-Adoption wird nicht als kurzer Hintergrundauftrag gestartet.');

$wikiId = (int) $pdo->query("SELECT id FROM modules WHERE route_prefix = 'wiki'")->fetchColumn();
$pdo->exec("DELETE FROM modules WHERE id = {$wikiId}");
$pdo->exec("INSERT INTO modules (module_key,name,description,route_prefix,handler,is_active,sort_order) VALUES ('modulnest.wiki','Wiki','Wiki v2','wiki','native',1,3)");
$managedId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO module_installations (module_id,module_row_id,origin,catalog_source_id,catalog_sequence,installed_version,active_release_id,data_schema_version,retained_data) VALUES ('modulnest.wiki',{$managedId},'catalog-managed','modulnest.dev',1,'1.0.0','release-1',1,0)");
$service = new CatalogService($pdo, '1.2.0', $snapshot);
$wiki = $service->module('modulnest.wiki');
catalog_ux_assert($wiki !== null && $wiki['classification'] === 'v2' && $wiki['v2_installed'] && !$wiki['adoptable'], 'Adoptiertes Wiki wird nicht eindeutig als Modul v2 angezeigt.');
catalog_ux_assert(count($service->updates()) === 1 && $service->updates()[0]['id'] === 'modulnest.wiki', 'Normales v2-Update erscheint nicht korrekt unter Updates.');

fwrite(STDOUT, "Module catalog classification and UX smoke passed.\n");
