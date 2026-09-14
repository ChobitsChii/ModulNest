<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\Modules\Catalog\CatalogCache;
use Modulon\Core\Modules\Catalog\CatalogLoader;
use Modulon\Core\Modules\Catalog\CatalogPackageInstaller;
use Modulon\Core\Modules\Catalog\CatalogService;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\Catalog\LocalCatalogSource;
use Modulon\Core\Modules\LegacyModuleAdoptionService;
use Modulon\Core\Modules\ModuleAdoptionOperationService;
use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\ModuleOperationLock;
use Modulon\Core\Modules\ModulePackageInspector;
use Modulon\Core\Modules\PdoLogicalBackupProvider;
use Modulon\Core\Request;
use Modulon\Core\Session;
use Modulon\Modules\Admin\ModuleCatalogController;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function target_adoption_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function target_adoption_env(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value, " \t\"'");
    }
    return $values;
}

function target_adoption_copy(string $source, string $target): void
{
    if (!is_file($source)) {
        $root = dirname(__DIR__, 2);
        if (str_starts_with($source, $root . '/')) {
            $relative = substr($source, strlen($root) + 1);
            $v1 = '/srv/http/modulon-v1/' . $relative;
            if (is_file($v1)) {
                $source = $v1;
            }
        }
    }
    if (!is_file($source)) return;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
    copy($source, $target);
}

$moduleId = trim((string) ($argv[1] ?? ''));
$profiles = [
    'modulnest.pages' => ['directory' => 'pages', 'migration_directory' => 'Pages', 'route' => 'pages'],
    'modulnest.homepage' => ['directory' => 'homepage', 'migration_directory' => 'Homepage', 'route' => 'homepage'],
    'modulnest.data-portability' => ['directory' => 'data-portability', 'migration_directory' => null, 'route' => 'data-portability'],
    'modulnest.dashboard' => ['directory' => 'dashboard', 'migration_directory' => 'Dashboard', 'route' => 'dashboard'],
    'modulnest.sneak-preview' => ['directory' => 'sneak-preview', 'migration_directory' => 'SneakPreview', 'route' => 'sneak-preview'],
    'modulnest.tools' => ['directory' => 'tools', 'migration_directory' => null, 'route' => 'tools'],
    'modulnest.banking' => ['directory' => 'banking', 'migration_directory' => 'Banking', 'route' => 'banking'],
    'modulnest.fantasy-cards' => ['directory' => 'fantasy-cards', 'migration_directory' => null, 'route' => 'fantasy-cards'],
    'modulnest.mail' => ['directory' => 'mail', 'migration_directory' => null, 'route' => 'mail'],
];
$profile = $profiles[$moduleId] ?? null;
if (!is_array($profile)) {
    fwrite(STDOUT, "Target module has no generic adoption fixture: {$moduleId}\n");
    exit(0);
}

$root = dirname(__DIR__, 2);
$releaseManifests = glob($root . '/modules-src/' . $profile['directory'] . '/*/module.json') ?: [];
$releaseVersions = array_map(static fn (string $path): string => basename(dirname($path)), $releaseManifests);
usort($releaseVersions, 'version_compare');
$currentVersion = $releaseVersions[array_key_last($releaseVersions)] ?? '';
target_adoption_assert($currentVersion !== '', 'Aktuelle Modulversion konnte nicht bestimmt werden.');
$environment = target_adoption_env($root . '/.env');
$server = new PDO(
    'mysql:host=' . ($environment['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($environment['DB_PORT'] ?? '3306') . ';charset=' . ($environment['DB_CHARSET'] ?? 'utf8mb4'),
    $environment['DB_USER'] ?? '',
    $environment['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = 'modulnest_adopt_target_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . '/modulnest-adopt-target-' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    mkdir($temporary . '/bin', 0775, true);
    mkdir($temporary . '/storage/modules', 0775, true);
    mkdir($temporary . '/public/assets/modules', 0775, true);
    copy($root . '/bin/module-health.php', $temporary . '/bin/module-health.php');
    symlink($root . '/vendor', $temporary . '/vendor');
    symlink($root . '/modules-src', $temporary . '/modules-src');

    $inventoryPath = $root . '/modules-src/' . $profile['directory'] . '/adoption/v1-file-hashes.json';
    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 32, JSON_THROW_ON_ERROR);
    foreach ($inventory as $relative => $hash) {
        target_adoption_copy($root . '/' . $relative, $temporary . '/' . $relative);
    }

    $server->exec('USE `' . $database . '`');
    (new MigrationRunner($server, $root))->run(['Admin', 'Auth', 'Modules', 'User']);
    if (is_string($profile['migration_directory'])) {
        (new MigrationRunner($server, $temporary))->run([$profile['migration_directory']]);
    }
    $statement = $server->prepare("INSERT INTO modules(name,description,route_prefix,access_level,handler,is_active,show_in_header,show_on_home) VALUES(?,?,?,?, 'native',1,0,0)");
    $statement->execute([$moduleId, 'Adoption fixture', $profile['route'], $moduleId === 'modulnest.pages' ? 'public' : 'admin']);

    if ($moduleId === 'modulnest.pages') {
        $server->exec("INSERT INTO pages_entries(title,slug,content_markdown,visibility,menu_group,show_in_header,show_in_footer,is_active,sort_order) VALUES('Bleibt','adoption-bleibt','**Markdown bleibt**','public','Test',1,1,1,77)");
    } elseif ($moduleId === 'modulnest.homepage') {
        $server->exec("INSERT INTO homepage_blocks(type,title,content_markdown,visibility_guest,visibility_user,visibility_admin,sort_order,column_span,is_enabled) VALUES('custom_content','Bleibt','**Homepage bleibt**',1,1,1,77,'full',1)");
        $server->exec("UPDATE app_settings SET value='1' WHERE `key`='homepage.is_published'");
    } else {
        if ($moduleId === 'modulnest.dashboard') {
            $server->exec("INSERT INTO users(id,name,email,password_hash) VALUES(7001,'Dashboard Adoption','dashboard-adoption@example.test','x')");
            $server->exec("INSERT INTO dashboard_widgets(id,user_id,widget_type,title,sort_order,layout_width,is_active) VALUES(7101,7001,'notes','Bleibt',3,6,1)");
            $server->exec("INSERT INTO dashboard_notes(widget_id,title,content,sort_order,is_pinned,is_archived) VALUES(7101,'Notiz bleibt','Inhalt bleibt',2,1,0)");
            mkdir($temporary . '/storage/favicons', 0775, true);
            mkdir($temporary . '/public/assets/favicons', 0775, true);
            file_put_contents($temporary . '/storage/favicons/fav-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png', 'storage-favicon');
            file_put_contents($temporary . '/public/assets/favicons/fav-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png', 'public-favicon');
        } elseif ($moduleId === 'modulnest.sneak-preview') {
            $server->exec("INSERT INTO sneak_preview_entries(sneak_date,title,location,poster_path,tmdb_id) VALUES('2026-09-08','Sneak bleibt','Kino','/assets/sneak-preview/posters/tmdb_123.jpg',123)");
            $server->exec("INSERT INTO sneak_preview_settings(`key`,`value`) VALUES('tmdb_api_key','fixture-value'),('save_posters_locally','1')");
            mkdir($temporary . '/public/assets/sneak-preview/posters', 0775, true);
            file_put_contents($temporary . '/public/assets/sneak-preview/posters/tmdb_123.jpg', 'poster-data');
        } elseif ($moduleId === 'modulnest.tools') {
            foreach (['jobs', 'models', 'results', 'logs', 'uploads', 'wav'] as $directory) {
                mkdir($temporary . '/storage/tools/speech/' . $directory, 0775, true);
            }
            $jobId = '20260908_120000_abcdef123456';
            file_put_contents($temporary . '/storage/tools/speech/worker.lock', '');
            file_put_contents($temporary . '/storage/tools/speech/models/fixture.bin', 'model-data');
            file_put_contents($temporary . '/storage/tools/speech/results/' . $jobId . '.txt', 'transcript-data');
            file_put_contents($temporary . '/storage/tools/speech/logs/' . $jobId . '.log', 'log-data');
            file_put_contents($temporary . '/storage/tools/speech/uploads/' . $jobId . '_audio.wav', 'upload-data');
            file_put_contents($temporary . '/storage/tools/speech/wav/' . $jobId . '.wav', 'wav-data');
            file_put_contents($temporary . '/storage/tools/speech/jobs/' . $jobId . '.json', json_encode([
                'id' => $jobId,
                'status' => 'done',
                'source_file' => $temporary . '/storage/tools/speech/uploads/' . $jobId . '_audio.wav',
                'wav_file' => $temporary . '/storage/tools/speech/wav/' . $jobId . '.wav',
                'model_path' => $temporary . '/storage/tools/speech/models/fixture.bin',
                'result_base' => $temporary . '/storage/tools/speech/results/' . $jobId,
                'results' => ['txt' => $temporary . '/storage/tools/speech/results/' . $jobId . '.txt'],
                'log_file' => $temporary . '/storage/tools/speech/logs/' . $jobId . '.log',
            ], JSON_THROW_ON_ERROR));
        } elseif ($moduleId === 'modulnest.fantasy-cards') {
            $server->exec((string) file_get_contents($temporary . '/app/Modules/FantasyCards/Database/schema.sql'));
            $server->exec((string) file_get_contents($temporary . '/app/Modules/FantasyCards/Database/seeds.sql'));
            $server->exec("INSERT INTO users(id,name,email,password_hash) VALUES(7301,'Fantasy Adoption','fantasy-adoption@example.test','x')");
            $server->exec("INSERT INTO booster_types(id, uuid, name, description, cards_per_pack, is_active) VALUES(1, '20000000-0000-4000-8000-000000000001', 'Standard Daily', 'Tägliches Booster', 3, 1)");
            $server->exec("INSERT INTO user_booster_inventory(user_id,booster_type_id,quantity) VALUES(7301,1,5)");
            $server->exec("INSERT INTO user_cards(user_id,card_id,quantity) VALUES(7301,1,3)");
            $server->exec("INSERT INTO fantasy_card_user_state(user_id,free_claims,last_free_claim_at) VALUES(7301,2,'2026-09-08 12:00:00')");
            $server->exec("INSERT INTO fantasy_card_booster_openings(id,uuid,user_id,booster_type_id,opened_at) VALUES(7310,'30000000-0000-4000-8000-000000000001',7301,1,'2026-09-08 12:05:00')");
            $server->exec("INSERT INTO fantasy_card_booster_opening_cards(opening_id,card_id,reveal_order) VALUES(7310,1,1)");
            $server->exec("INSERT INTO fantasy_card_profile_settings(user_id,favorite_card_id,showcase_mode,is_collection_public) VALUES(7301,1,'manual',1)");
            $server->exec("INSERT INTO fantasy_card_profile_showcase_cards(user_id,card_id,slot) VALUES(7301,1,1)");
        } elseif ($moduleId === 'modulnest.mail') {
            $server->exec((string) file_get_contents($temporary . '/app/Modules/Mail/Database/schema.sql'));
            $server->exec("INSERT INTO users(id,name,email,password_hash) VALUES(7401,'Mail Adoption','mail-adoption@example.test','x')");
            $server->exec("INSERT INTO mail_accounts(id,user_id,display_name,email_address,imap_host,imap_username,smtp_host,smtp_username,encrypted_password,is_active) VALUES(1,7401,'Test Account','user@example.test','imap.example.test','user','smtp.example.test','user','enc_secret',1)");
            $server->exec("INSERT INTO mail_favorite_folders(user_id,mail_account_id,folder_name,sort_order) VALUES(7401,1,'INBOX',0)");
            $server->exec("INSERT INTO mail_sender_whitelist(user_id,scope_type,scope_value) VALUES(7401,'sender','trusted@example.test')");
            $server->exec("INSERT INTO mail_sender_exclusions(user_id,mail_account_id,folder_name,sender_key) VALUES(7401,1,'INBOX','spam@example.test')");
            $server->exec("INSERT INTO mail_list_preferences(user_id,mail_account_id,folder_name,sort_field) VALUES(7401,1,'INBOX','date')");
            $server->exec("INSERT INTO mail_message_index(user_id,mail_account_id,folder_name,uid,sender_key,sender_label,subject,message_timestamp) VALUES(7401,1,'INBOX',101,'sender@example.test','Sender','Test Betreff',1700000000)");
        } elseif (in_array($moduleId, ['modulnest.banking', 'modulnest.fantasy-cards', 'modulnest.mail'], true)) {
            $server->exec("INSERT INTO users(id,name,email,password_hash) VALUES(7201,'Banking Adoption','banking-adoption@example.test','x')");
            $server->exec("INSERT INTO banking_migration_runs(id,target_user_id,source_snapshot_label,status) VALUES(7210,7201,'Bleibt','completed')");
            $server->exec("INSERT INTO banking_accounts(id,user_id,migration_run_id,account_identifier,display_name,currency) VALUES(7220,7201,7210,'konto-bleibt','Konto bleibt','EUR')");
            $server->exec("INSERT INTO banking_categories(id,user_id,migration_run_id,name,normalized_name) VALUES(7230,7201,7210,'Kategorie bleibt','kategorie bleibt')");
            $server->exec("INSERT INTO banking_import_batches(id,user_id,account_id,migration_run_id,source_type,original_filename,status) VALUES(7240,7201,7220,7210,'csv','bleibt.csv','completed')");
            $server->exec("INSERT INTO banking_transactions(id,user_id,account_id,category_id,import_batch_id,migration_run_id,booking_date,booking_text,amount,transaction_hash) VALUES(7250,7201,7220,7230,7240,7210,'2026-09-08','Buchung bleibt',12.34,REPEAT('a',64))");
            $server->exec("INSERT INTO banking_recurring_rules(id,user_id,account_id,category_id,migration_run_id,name,interval_type,is_active) VALUES(7260,7201,7220,7230,7210,'Regel bleibt','monthly',1)");
            $server->exec("INSERT INTO banking_recurring_rule_conditions(id,user_id,recurring_rule_id,migration_run_id,field,operator,value) VALUES(7270,7201,7260,7210,'verwendungszweck','contains','bleibt')");
            $server->exec("INSERT INTO banking_dashboard_cache(user_id,cache_scope,period_key,data_hash,payload_json) VALUES(7201,'overview','2026-09',REPEAT('b',64),'{\"bleibt\":true}')");
        } else {
        mkdir($temporary . '/storage/data-portability/exports', 0775, true);
        file_put_contents($temporary . '/storage/data-portability/exports/adoption-marker.txt', 'bleibt');
        }
    }

    $catalogTarget = $temporary . '/catalog-source';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-module-catalog.php')
        . ' --development --workspace-root=' . escapeshellarg($root . '/modules-src') . ' --publisher=modulnest'
        . ' --module=' . escapeshellarg($moduleId)
        . ' --target=' . escapeshellarg($catalogTarget)
        . ' --sequence-state=' . escapeshellarg($temporary . '/catalog-sequence')
        . ' --cache-root=' . escapeshellarg($temporary . '/catalog-cache');
    exec($command, $buildOutput, $buildCode);
    target_adoption_assert($buildCode === 0, 'Temporärer Einzelmodulkatalog konnte nicht gebaut werden.');

    $keyLines = file($root . '/tests/Fixtures/catalog-v1/keys/TEST_ONLY_ed25519_public.key', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $loader = new CatalogLoader(new CatalogTrustStore(['modulnest-test-2026' => $keyLines[1]]), new CatalogCache($temporary . '/runtime-catalog'));
    $source = new LocalCatalogSource('modulnest.dev', $catalogTarget);
    $snapshot = $loader->refresh($source);
    $catalog = new CatalogService($server, '2.1.0', $snapshot);
    $lifecycle = new ModuleLifecycleService($server, $temporary, '2.1.0', new PdoLogicalBackupProvider($server, $temporary . '/storage/backups/modules'), new ModuleOperationLock($temporary . '/storage/locks/modules'));
    $installer = new CatalogPackageInstaller($loader, $source, $snapshot, $catalog, $lifecycle);
    $adoption = new LegacyModuleAdoptionService($server, $temporary, $installer);

    if ($moduleId === 'modulnest.banking') {
        $missingMetadataModules = $snapshot->modules;
        unset($missingMetadataModules[$moduleId]['adoption']);
        $missingSnapshot = new \Modulon\Core\Modules\Catalog\CatalogSnapshot($snapshot->sourceId, $snapshot->root, $missingMetadataModules);
        $missingCatalog = new CatalogService($server, '2.1.0', $missingSnapshot);
        $missingInstaller = new CatalogPackageInstaller($loader, $source, $missingSnapshot, $missingCatalog, $lifecycle);
        $missingAdoption = new LegacyModuleAdoptionService($server, $temporary, $missingInstaller);
        $metadataBlocked = $missingAdoption->preflight($moduleId);
        target_adoption_assert(!$metadataBlocked['eligible'] && $metadataBlocked['status'] === 'metadata-unavailable', 'Fehlende signierte Adoptionsmetadaten blockieren nicht fail-closed.');
        $controller = new ModuleCatalogController($missingCatalog, $lifecycle, $missingInstaller, new Session(), legacyAdoption: $missingAdoption);
        ob_start();
        $controller->index(new Request('GET', '/admin/module-catalog', [], ['bereich' => 'installiert'], []))->send();
        $catalogBody = (string) ob_get_clean();
        target_adoption_assert(http_response_code() === 200 && str_contains($catalogBody, 'Automatische Umstellung nicht möglich'), 'Katalogseite bleibt bei fehlenden Adoptionsmetadaten nicht HTTP 200.');
    }

    target_adoption_assert($catalog->module($moduleId)['adoption_candidate'] === true, 'Katalog erkennt v1 nicht als Adoptionkandidat.');
    target_adoption_assert($adoption->preflight($moduleId)['eligible'] === true, 'Exact-Code-Adoption-Preflight ist nicht grün.');
    $firstFile = (string) array_key_first($inventory);
    $original = (string) file_get_contents($temporary . '/' . $firstFile);
    file_put_contents($temporary . '/' . $firstFile, $original . "\n// local change");
    $blocked = $adoption->preflight($moduleId);
    target_adoption_assert(!$blocked['eligible'] && $blocked['status'] === 'changed' && $blocked['first_difference'] === $firstFile, 'Lokale Änderung wird nicht exakt blockiert.');
    file_put_contents($temporary . '/' . $firstFile, $original);

    if (in_array($moduleId, ['modulnest.dashboard', 'modulnest.sneak-preview', 'modulnest.tools', 'modulnest.banking', 'modulnest.fantasy-cards'], true)) {
        $packages = glob($catalogTarget . '/packages/*/' . $currentVersion . '/*-' . $currentVersion . '.zip') ?: [];
        target_adoption_assert(count($packages) === 1, 'Temporäres aktuelles Modulpaket fehlt für den Rollback-Test.');
        $disabledPackage = $packages[0] . '.disabled';
        rename($packages[0], $disabledPackage);
        try {
            $adoption->adopt($moduleId);
            target_adoption_assert(false, 'Adoption ohne verfügbares v2-Paket hätte fehlschlagen müssen.');
        } catch (RuntimeException) {
        } finally {
            rename($disabledPackage, $packages[0]);
        }
        target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM modules WHERE route_prefix='" . $profile['route'] . "' AND module_key IS NULL AND is_active=1")->fetchColumn() === 1, 'v1-Modul war nach simuliertem Paketfehler nicht mehr aktiv.');
        if ($moduleId === 'modulnest.banking') {
            target_adoption_assert((string) $server->query("SELECT access_level FROM modules WHERE route_prefix='banking' AND module_key IS NULL")->fetchColumn() === 'admin', 'Banking-Zugriffslevel wurde beim Rollback nicht wiederhergestellt.');
        }
        target_adoption_assert(!is_dir($temporary . '/storage/modules/' . $moduleId), 'v2-Storage blieb nach simuliertem Paketfehler zurück.');
        if ($moduleId === 'modulnest.dashboard') {
            target_adoption_assert(is_file($temporary . '/storage/favicons/fav-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'), 'Dashboard-Favicon ging beim Rollback verloren.');
            target_adoption_assert(is_file($temporary . '/public/assets/favicons/fav-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png'), 'Öffentliches Dashboard-Favicon ging beim Rollback verloren.');
        } elseif ($moduleId === 'modulnest.sneak-preview') {
            target_adoption_assert(is_file($temporary . '/public/assets/sneak-preview/posters/tmdb_123.jpg'), 'Sneak-Poster ging beim Rollback verloren.');
        } elseif ($moduleId === 'modulnest.tools') {
            target_adoption_assert(is_file($temporary . '/storage/tools/speech/models/fixture.bin'), 'Tools-Modell ging beim Rollback verloren.');
            target_adoption_assert(is_file($temporary . '/storage/tools/speech/jobs/20260908_120000_abcdef123456.json'), 'Tools-Job ging beim Rollback verloren.');
        } elseif ($moduleId === 'modulnest.fantasy-cards') {
            target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM user_cards WHERE user_id=7301 AND card_id=1 AND quantity=3")->fetchColumn() === 1, 'FantasyCards-Daten gingen beim Rollback verloren.');
            target_adoption_assert((string) $server->query("SELECT access_level FROM modules WHERE route_prefix='fantasy-cards' AND module_key IS NULL")->fetchColumn() === 'admin', 'FantasyCards-Zugriffslevel wurde beim Rollback nicht wiederhergestellt.');
        } else {
            target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM banking_transactions WHERE id=7250 AND booking_text='Buchung bleibt'")->fetchColumn() === 1, 'Banking-Daten gingen beim Rollback verloren.');
        }
    }

    $progressPhases = [];
    if ($moduleId === 'modulnest.tools') {
        $operationId = '11111111-1111-4111-8111-111111111111';
        $server->prepare("INSERT INTO module_operations(operation_id,module_id,operation_type,phase,status) VALUES(?,?,'adoption','queued','running')")
            ->execute([$operationId, $moduleId]);
        $operations = new ModuleAdoptionOperationService($server, $temporary, $adoption);
        $operations->run($operationId, $moduleId);
        $operation = $operations->status($operationId);
        target_adoption_assert(is_array($operation) && $operation['status'] === 'succeeded' && $operation['phase'] === 'complete', 'Tools-Hintergrundadoption wurde nicht erfolgreich journalisiert.');
        target_adoption_assert(is_file($temporary . '/storage/module-operations/adoptions/' . $operationId . '.json'), 'Persistenter Tools-Adoptionsstatus fehlt.');
        $lifecycleLog = (string) file_get_contents($temporary . '/storage/logs/module-lifecycle-' . gmdate('Y-m-d') . '.log');
        foreach (['backup_copy', 'backup_verify', 'storage_copy', 'storage_verify', 'registry_switch', 'package_install', 'legacy_cleanup', 'complete'] as $phase) {
            target_adoption_assert(str_contains($lifecycleLog, '"phase":"' . $phase . '"'), 'Tools-Adoptionslog enthält die Phase nicht: ' . $phase);
        }
        target_adoption_assert(str_contains($lifecycleLog, '"ended_at":'), 'Tools-Adoptionslog enthält keinen Abschlusszeitpunkt.');
        $statement = $server->prepare('SELECT i.installed_version,i.active_release_id,m.is_active FROM module_installations i JOIN modules m ON m.id=i.module_row_id WHERE i.module_id=?');
        $statement->execute([$moduleId]);
        $adopted = $statement->fetch(PDO::FETCH_ASSOC);
        target_adoption_assert(is_array($adopted), 'Tools-Hintergrundadoption hinterließ keine Installation.');
    } else {
        $adopted = $adoption->adopt($moduleId, static function (array $progress) use (&$progressPhases): void {
            $progressPhases[] = (string) ($progress['phase'] ?? '');
        });
    }
    target_adoption_assert($adopted['installed_version'] === $currentVersion && (int) $adopted['is_active'] === 1, 'Adoption erhielt Version oder Aktivstatus nicht.');
    if ($moduleId === 'modulnest.pages') {
        target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM pages_entries WHERE slug='adoption-bleibt' AND show_in_header=1 AND show_in_footer=1")->fetchColumn() === 1, 'Pages-Daten gingen verloren.');
    } elseif ($moduleId === 'modulnest.homepage') {
        target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM homepage_blocks WHERE title='Bleibt' AND content_markdown='**Homepage bleibt**'")->fetchColumn() === 1, 'Homepage-Blöcke gingen verloren.');
        target_adoption_assert((string) $server->query("SELECT value FROM app_settings WHERE `key`='homepage.is_published'")->fetchColumn() === '1', 'Homepage-Published-State ging verloren.');
    } elseif ($moduleId === 'modulnest.dashboard') {
        target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM dashboard_notes WHERE title='Notiz bleibt' AND content='Inhalt bleibt'")->fetchColumn() === 1, 'Dashboard-Daten gingen verloren.');
        target_adoption_assert(is_file($temporary . '/storage/modules/modulnest.dashboard/favicons/fav-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'), 'Dashboard-Storage-Favicon ging verloren.');
        target_adoption_assert(is_file($temporary . '/storage/modules/modulnest.dashboard/favicons/fav-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png'), 'Historisches öffentliches Dashboard-Favicon ging verloren.');
        target_adoption_assert(!is_file($temporary . '/storage/favicons/fav-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'), 'Altes Dashboard-Storage-Favicon wurde nach erfolgreicher Adoption nicht bereinigt.');
        target_adoption_assert(!is_file($temporary . '/public/assets/favicons/fav-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png'), 'Altes öffentliches Dashboard-Favicon wurde nach erfolgreicher Adoption nicht bereinigt.');
    } elseif ($moduleId === 'modulnest.sneak-preview') {
        target_adoption_assert((string) $server->query("SELECT poster_path FROM sneak_preview_entries WHERE tmdb_id=123")->fetchColumn() === '/sneak-preview/posters/tmdb_123.jpg', 'Sneak-Posterpfad wurde nicht sicher migriert.');
        target_adoption_assert((string) $server->query("SELECT `value` FROM sneak_preview_settings WHERE `key`='tmdb_api_key'")->fetchColumn() === 'fixture-value', 'Sneak-TMDB-Konfiguration ging verloren.');
        target_adoption_assert(is_file($temporary . '/storage/modules/modulnest.sneak-preview/posters/tmdb_123.jpg'), 'Sneak-Posterdatei ging verloren.');
        target_adoption_assert(!is_file($temporary . '/public/assets/sneak-preview/posters/tmdb_123.jpg'), 'Alte Sneak-Posterdatei wurde nach erfolgreicher Adoption nicht bereinigt.');
    } elseif ($moduleId === 'modulnest.tools') {
        $toolsStorage = $temporary . '/storage/modules/modulnest.tools/speech';
        target_adoption_assert(is_file($toolsStorage . '/models/fixture.bin'), 'Tools-Modell ging bei Adoption verloren.');
        target_adoption_assert(is_file($toolsStorage . '/results/20260908_120000_abcdef123456.txt'), 'Tools-Ergebnis ging bei Adoption verloren.');
        target_adoption_assert(is_file($toolsStorage . '/jobs/20260908_120000_abcdef123456.json'), 'Tools-Job ging bei Adoption verloren.');
        target_adoption_assert(!is_file($toolsStorage . '/worker.lock'), 'Temporärer v1-Worker-Lock wurde in persistenten Tools-v2-Storage übernommen.');
        target_adoption_assert(!is_file($temporary . '/storage/tools/speech/jobs/20260908_120000_abcdef123456.json'), 'Alter Tools-Runtimejob wurde nicht bereinigt.');
        $releaseRoot = $temporary . '/modules/modulnest.tools/releases/' . $adopted['active_release_id'];
        require $releaseRoot . '/src/ToolsSpeechService.php';
        $service = new \ModulNest\Tools\ToolsSpeechService($temporary, $releaseRoot, $server);
        $jobs = $service->listJobs();
        target_adoption_assert(count($jobs) === 1 && ($jobs[0]['transcript'] ?? '') === 'transcript-data', 'Adoptierter Tools-Job oder sein Ergebnis ist nicht mehr lesbar.');
    } elseif ($moduleId === 'modulnest.banking') {
        foreach (['banking_migration_runs', 'banking_accounts', 'banking_categories', 'banking_import_batches', 'banking_transactions', 'banking_recurring_rules', 'banking_recurring_rule_conditions', 'banking_dashboard_cache'] as $table) {
            target_adoption_assert((int) $server->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === 1, 'Banking-Daten gingen verloren: ' . $table);
        }
        target_adoption_assert((string) $server->query("SELECT access_level FROM modules WHERE module_key='modulnest.banking'")->fetchColumn() === 'admin', 'Bestehender Banking-Zugriffslevel wurde bei Adoption erweitert.');
        target_adoption_assert((string) $server->query("SELECT module_key FROM schema_migrations WHERE migration_key='20260510_000103_banking_070_schema'")->fetchColumn() === 'modulnest.banking', 'Historische Banking-Migration wurde nicht dem v2-Modul zugeordnet.');
        target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM schema_migrations WHERE migration_key='modulnest.banking_001_baseline' AND module_key='modulnest.banking'")->fetchColumn() === 1, 'Adoptierte Banking-Baseline wurde nicht markiert.');
    } elseif ($moduleId === 'modulnest.fantasy-cards') {
        $tables = [
            'card_sets', 'cards', 'booster_types', 'user_booster_inventory', 'user_cards',
            'fantasy_card_user_state', 'fantasy_card_booster_openings', 'fantasy_card_booster_opening_cards',
            'fantasy_card_profile_settings', 'fantasy_card_profile_showcase_cards',
        ];
        foreach ($tables as $table) {
            target_adoption_assert((int) $server->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() > 0, 'FantasyCards-Tabelle leer nach Adoption: ' . $table);
        }
        target_adoption_assert((int) $server->query("SELECT quantity FROM user_cards WHERE user_id=7301 AND card_id=1")->fetchColumn() === 3, 'FantasyCards-User-Card-Daten gingen verloren.');
        target_adoption_assert((int) $server->query("SELECT quantity FROM user_booster_inventory WHERE user_id=7301 AND booster_type_id=1")->fetchColumn() === 5, 'FantasyCards-Booster-Inventory ging verloren.');
        target_adoption_assert((string) $server->query("SELECT showcase_mode FROM fantasy_card_profile_settings WHERE user_id=7301")->fetchColumn() === 'manual', 'FantasyCards-Profil-Settings gingen verloren.');
        target_adoption_assert((string) $server->query("SELECT access_level FROM modules WHERE module_key='modulnest.fantasy-cards'")->fetchColumn() === 'admin', 'FantasyCards-Zugriffslevel wurde bei Adoption modifiziert.');
        target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM schema_migrations WHERE migration_key='modulnest.fantasy-cards_001_schema' AND module_key='modulnest.fantasy-cards'")->fetchColumn() === 1, 'Adoptierte FantasyCards-Schema-Baseline nicht markiert.');
        target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM schema_migrations WHERE migration_key='modulnest.fantasy-cards_002_seeds' AND module_key='modulnest.fantasy-cards'")->fetchColumn() === 1, 'Adoptierte FantasyCards-Seeds-Baseline nicht markiert.');
    } elseif ($moduleId === 'modulnest.mail') {
        $tables = [
            'mail_accounts', 'mail_favorite_folders', 'mail_sender_whitelist',
            'mail_sender_exclusions', 'mail_list_preferences', 'mail_message_index',
        ];
        foreach ($tables as $table) {
            target_adoption_assert((int) $server->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() > 0, 'Mail-Tabelle leer nach Adoption: ' . $table);
        }
        target_adoption_assert((string) $server->query("SELECT email_address FROM mail_accounts WHERE user_id=7401 AND id=1")->fetchColumn() === 'user@example.test', 'Mail-Account-Daten gingen verloren.');
        target_adoption_assert((string) $server->query("SELECT folder_name FROM mail_favorite_folders WHERE user_id=7401 AND mail_account_id=1")->fetchColumn() === 'INBOX', 'Mail-Favoriten gingen verloren.');
        target_adoption_assert((string) $server->query("SELECT scope_value FROM mail_sender_whitelist WHERE user_id=7401")->fetchColumn() === 'trusted@example.test', 'Mail-Whitelist ging verloren.');
        target_adoption_assert((string) $server->query("SELECT access_level FROM modules WHERE module_key='modulnest.mail'")->fetchColumn() === 'admin', 'Mail-Zugriffslevel wurde bei Adoption modifiziert.');
        target_adoption_assert((int) $server->query("SELECT COUNT(*) FROM schema_migrations WHERE migration_key='modulnest.mail_001_schema' AND module_key='modulnest.mail'")->fetchColumn() === 1, 'Adoptierte Mail-Schema-Baseline nicht markiert.');
    } else {
        target_adoption_assert(is_file($temporary . '/storage/modules/modulnest.data-portability/exports/adoption-marker.txt'), 'DataPortability-Storage ging verloren.');
    }
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    foreach (['vendor', 'modules-src'] as $link) if (is_link($temporary . '/' . $link)) unlink($temporary . '/' . $link);
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Target module adoption passed: {$moduleId}\n");
