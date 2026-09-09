<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\ModuleOperationLock;
use Modulon\Core\Modules\ModulePackageBuilder;
use Modulon\Core\Modules\ModulePackageInspector;
use Modulon\Core\Modules\PdoLogicalBackupProvider;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function target_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function target_assert(bool $condition, string $message): void
{
    if (!$condition) target_fail($message);
}

function target_env(string $path): array
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

$moduleId = trim((string) ($argv[1] ?? ''));
if (preg_match('/^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/D', $moduleId) !== 1) target_fail('Ungültige Modul-ID.');
$root = dirname(__DIR__, 2);
$workspace = null;
foreach (glob($root . '/modules-src/*/1.0.0/module.json') ?: [] as $manifestPath) {
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (($manifest['id'] ?? null) === $moduleId) {
        $workspace = dirname(dirname($manifestPath));
        break;
    }
}
if ($workspace === null) target_fail('Modul-Workspace fehlt für ' . $moduleId);
$versions = [];
foreach (glob($workspace . '/*/module.json') ?: [] as $manifestPath) {
    $versions[] = basename(dirname($manifestPath));
}
usort($versions, 'version_compare');
$currentVersion = $versions[array_key_last($versions)] ?? '';
if (!in_array('1.0.0', $versions, true) || $currentVersion === '1.0.0') {
    target_fail('Baseline- und aktueller Release-Workspace fehlen für ' . $moduleId);
}

$environment = target_env($root . '/.env');
$server = new PDO(
    'mysql:host=' . ($environment['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($environment['DB_PORT'] ?? '3306') . ';charset=' . ($environment['DB_CHARSET'] ?? 'utf8mb4'),
    $environment['DB_USER'] ?? '',
    $environment['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = 'modulnest_target_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . '/modulnest-target-' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    mkdir($temporary . '/bin', 0775, true);
    copy($root . '/bin/module-health.php', $temporary . '/bin/module-health.php');
    symlink($root . '/vendor', $temporary . '/vendor');
    $server->exec('USE `' . $database . '`');
    (new MigrationRunner($server, $root))->run(['Admin', 'Auth', 'Modules', 'User']);

    $builder = new ModulePackageBuilder();
    $archives = [];
    $hashes = [];
    foreach (['1.0.0', $currentVersion] as $version) {
        $archives[$version] = $temporary . '/' . $version . '.zip';
        $hashes[$version] = $builder->build($workspace . '/' . $version, $archives[$version]);
        $second = $temporary . '/' . $version . '-repeat.zip';
        target_assert(hash_equals($hashes[$version], $builder->build($workspace . '/' . $version, $second)), 'Paketbuild ist nicht reproduzierbar: ' . $version);
        (new ModulePackageInspector())->inspect($archives[$version], $hashes[$version]);
    }

    $lifecycle = new ModuleLifecycleService(
        $server,
        $temporary,
        '2.0.0-alpha.1',
        new PdoLogicalBackupProvider($server, $temporary . '/storage/backups/modules'),
        new ModuleOperationLock($temporary . '/storage/locks/modules'),
    );
    $installed = $lifecycle->install($archives['1.0.0'], $hashes['1.0.0'], false);
    target_assert($installed['installed_version'] === '1.0.0' && (int) $installed['is_active'] === 0, 'Install muss zunächst inaktiv sein.');
    $lifecycle->activate($moduleId);
    target_assert((int) $lifecycle->inspect($moduleId)['is_active'] === 1, 'Aktivierung fehlgeschlagen.');
    $updated = $lifecycle->update($archives[$currentVersion], $hashes[$currentVersion]);
    target_assert($updated['installed_version'] === $currentVersion, 'Update auf aktuelle Modulversion fehlgeschlagen.');
    $resources = $updated['resources'];
    $lifecycle->deactivate($moduleId);
    target_assert((int) $lifecycle->inspect($moduleId)['is_active'] === 0, 'Deaktivierung fehlgeschlagen.');
    $lifecycle->uninstall($moduleId);
    target_assert((int) $lifecycle->inspect($moduleId)['retained_data'] === 1, 'Retain-Uninstall fehlt.');
    $lifecycle->install($archives[$currentVersion], $hashes[$currentVersion], false);
    target_assert($lifecycle->inspect($moduleId)['installed_version'] === $currentVersion, 'Reinstall aus Retained-State fehlgeschlagen.');
    $lifecycle->uninstall($moduleId);
    $lifecycle->uninstall($moduleId, true);
    foreach ($resources as $resource) {
        if (($resource['resource_type'] ?? '') === 'tables') {
            $statement = $server->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $statement->execute([$resource['resource_key']]);
            target_assert((int) $statement->fetchColumn() === 0, 'Purge ließ Tabelle zurück: ' . $resource['resource_key']);
        }
        if (($resource['resource_type'] ?? '') === 'settings') {
            $statement = $server->prepare('SELECT COUNT(*) FROM app_settings WHERE `key`=?');
            $statement->execute([$resource['resource_key']]);
            target_assert((int) $statement->fetchColumn() === 0, 'Purge ließ Setting zurück: ' . $resource['resource_key']);
        }
    }
    target_assert((int) $server->query("SELECT COUNT(*) FROM schema_migrations WHERE module_key=" . $server->quote($moduleId))->fetchColumn() === 0, 'Purge ließ Migrationshistorie zurück.');
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_link($temporary . '/vendor')) unlink($temporary . '/vendor');
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Target module lifecycle passed: {$moduleId}\n");
