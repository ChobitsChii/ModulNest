<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\Modules\Catalog\{CatalogCache, CatalogLoader, CatalogPackageInstaller, CatalogService, CatalogTrustStore, LocalCatalogSource};
use Modulon\Core\Modules\{ModuleBatchUpdateService, ModuleLifecycleService, ModuleOperationLock, ModulePackageInspector, PdoLogicalBackupProvider};

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function batch_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function batch_environment(string $path): array
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

$root = dirname(__DIR__, 2);
$environment = batch_environment($root . '/.env');
$server = new PDO(
    'mysql:host=' . ($environment['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($environment['DB_PORT'] ?? '3306') . ';charset=utf8mb4',
    $environment['DB_USER'] ?? '',
    $environment['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = 'modulnest_batch_update_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . '/modulnest-batch-update-' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    mkdir($temporary . '/bin', 0775, true);
    mkdir($temporary . '/storage/logs', 0775, true);
    copy($root . '/bin/module-health.php', $temporary . '/bin/module-health.php');
    symlink($root . '/vendor', $temporary . '/vendor');
    $server->exec('USE `' . $database . '`');
    (new MigrationRunner($server, $root))->run(['Admin', 'Auth', 'Modules', 'User']);

    $keyLines = file($root . '/tests/Fixtures/catalog-v1/keys/TEST_ONLY_ed25519_public.key', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $loader = new CatalogLoader(
        new CatalogTrustStore(['modulnest-test-2026' => (string) $keyLines[1]]),
        new CatalogCache($temporary . '/storage/catalog'),
        static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-06T20:30:00+00:00'),
    );
    $lifecycle = new ModuleLifecycleService($server, $temporary, '1.2.0', new PdoLogicalBackupProvider($server, $temporary . '/storage/backups/modules'), new ModuleOperationLock($temporary . '/storage/locks/modules'));

    $source1 = new LocalCatalogSource('modulnest.test', $root . '/tests/Fixtures/catalog-v1/source-multi-sequence-1');
    $snapshot1 = $loader->refresh($source1);
    $catalog1 = new CatalogService($server, '1.2.0', $snapshot1);
    $installer1 = new CatalogPackageInstaller($loader, $source1, $snapshot1, $catalog1, $lifecycle);
    foreach (['modulnest.logs', 'modulnest.systeminfo', 'modulnest.news'] as $moduleId) {
        $installer1->install($moduleId, true);
    }

    $source2 = new LocalCatalogSource('modulnest.test', $root . '/tests/Fixtures/catalog-v1/source-multi-sequence-2');
    $snapshot2 = $loader->refresh($source2);
    $catalog2 = new CatalogService($server, '1.2.0', $snapshot2);
    $installer2 = new CatalogPackageInstaller($loader, $source2, $snapshot2, $catalog2, $lifecycle);
    $successfulBatch = new ModuleBatchUpdateService($server, $temporary, $catalog2, $installer2);
    $success = $successfulBatch->queue(['modulnest.logs', 'modulnest.systeminfo']);
    $success = $successfulBatch->run((string) $success['operation_id']);
    batch_assert($success['status'] === 'succeeded' && $success['current'] === 2, 'Sequenzieller Zwei-Modul-Updateplan wurde nicht abgeschlossen.');
    batch_assert(array_column($success['modules'], 'status') === ['succeeded', 'succeeded'], 'Erfolgszusammenfassung ist unvollständig.');

    $sourceFail = new LocalCatalogSource('modulnest.test', $root . '/tests/Fixtures/catalog-v1/source-multi-health-fail');
    $snapshotFail = $loader->refresh($sourceFail);
    $catalogFail = new CatalogService($server, '1.2.0', $snapshotFail);
    $installerFail = new CatalogPackageInstaller($loader, $sourceFail, $snapshotFail, $catalogFail, $lifecycle);
    $failingBatch = new ModuleBatchUpdateService($server, $temporary, $catalogFail, $installerFail);
    $failure = $failingBatch->queue(['modulnest.news', 'modulnest.logs']);
    $failure = $failingBatch->run((string) $failure['operation_id']);
    batch_assert($failure['status'] === 'failed' && $failure['error_code'] === 'module_update_failed', 'Absichtlich fehlschlagendes Batchupdate meldet keinen verständlichen Abschluss.');
    batch_assert(array_column($failure['modules'], 'status') === ['succeeded', 'failed'], 'Batch-Fortschritt unterscheidet erfolgreiche und fehlgeschlagene Module nicht.');
    batch_assert($lifecycle->inspect('modulnest.news')['installed_version'] === '1.0.1', 'Bereits erfolgreiches Update wurde nach späterem Fehler zurückgenommen.');
    batch_assert($lifecycle->inspect('modulnest.logs')['installed_version'] === '1.0.1', 'Fehlgeschlagenes Modul wurde nicht auf seinen funktionierenden Stand zurückgerollt.');
    batch_assert($lifecycle->inspect('modulnest.systeminfo')['installed_version'] === '1.0.1', 'Nicht beteiligtes Modul wurde verändert.');
    batch_assert(($failingBatch->latest()['operation_id'] ?? '') === $failure['operation_id'], 'Abschlussstatus ist nach Reload nicht mehr abrufbar.');
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_link($temporary . '/vendor')) unlink($temporary . '/vendor');
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Sequential module batch update and failure rollback smoke passed.\n");
