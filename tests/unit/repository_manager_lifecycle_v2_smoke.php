<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\DatabaseBackupProviderInterface;
use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\ModuleOperationLock;
use Modulon\Core\Modules\ModulePackageBuilder;
use Modulon\Core\Modules\ModulePackageInspector;
use Modulon\Core\Modules\ModuleReleaseLocator;
use Modulon\Core\Modules\ModuleRuntimeSnapshot;
use Modulon\Core\Modules\PdoLogicalBackupProvider;
use Modulon\Modules\Modules\ModuleRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function repo_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @return array<string,string> */
function repo_env(string $path): array
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

$moduleId = 'modulnest.repository-manager';
$version = '0.1.0-beta.4';
$root = dirname(__DIR__, 2);
$workspace = $root . '/modules-src/repository-manager/' . $version;
repo_assert(is_file($workspace . '/module.json'), 'Modul-Workspace fehlt.');

$env = repo_env($root . '/.env');
$server = new PDO(
    'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';charset=' . ($env['DB_CHARSET'] ?? 'utf8mb4'),
    $env['DB_USER'] ?? '',
    $env['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = 'modulnest_repository_lifecycle_' . bin2hex(random_bytes(4));
$temporary = sys_get_temp_dir() . '/modulnest-repository-lifecycle-' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $server->exec('USE `' . $database . '`');
    (new MigrationRunner($server, $root))->run([]);

    mkdir($temporary . '/bin', 0775, true);
    copy($root . '/bin/module-health.php', $temporary . '/bin/module-health.php');
    symlink($root . '/vendor', $temporary . '/vendor');

    $zipPath = $temporary . '/repo.zip';
    if (!is_dir($temporary)) {
        mkdir($temporary, 0775, true);
    }
    (new ModulePackageBuilder())->build($workspace, $zipPath);

    $lifecycle = new ModuleLifecycleService(
        $server,
        $temporary,
        '2.1.0',
        new PdoLogicalBackupProvider($server, $temporary . '/storage/backups/modules'),
        new ModuleOperationLock($temporary . '/storage/locks/modules'),
    );

    $inspection = $lifecycle->install($zipPath, null, true, 'catalog-managed', 'modulnest.dev', 1);
    repo_assert(($inspection['installed_version'] ?? '') === $version, 'Installierte Version weicht ab.');

    $moduleRow = $server->query("SELECT * FROM modules WHERE module_key='{$moduleId}'")->fetch(PDO::FETCH_ASSOC);
    repo_assert(is_array($moduleRow), 'Module-Zeile wurde nicht angelegt.');
    repo_assert((int) $moduleRow['show_in_header'] === 0, 'show_in_header muss für Repository Manager 0 sein.');
    repo_assert((int) $moduleRow['show_on_home'] === 0, 'show_on_home muss für Repository Manager 0 sein.');

    $snapshot = ModuleRuntimeSnapshot::capture($server, new ModuleReleaseLocator($temporary));
    $active = $snapshot->get($moduleId);
    repo_assert($active !== null, 'RuntimeSnapshot enthält installierten Repository Manager nicht.');
    repo_assert($active->manifest->version->value === $version, 'Snapshot-Version fehlerhaft.');

    fwrite(STDOUT, "Repository Manager beta.4 lifecycle smoke passed.\n");
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_link($temporary . '/vendor')) {
        unlink($temporary . '/vendor');
    }
    if (is_dir($temporary)) {
        ModulePackageInspector::removeTree($temporary);
    }
}
