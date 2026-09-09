<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

use Modulon\Core\Database;
use Modulon\Core\Env;
use Modulon\Core\Modules\Catalog\{CatalogCache,CatalogLoader,CatalogPackageInstaller,CatalogService,CatalogTrustStore,HttpCatalogSource,LocalCatalogSource};
use Modulon\Core\Modules\{ModuleBatchUpdateService,ModuleLifecycleService,ModuleOperationLock,PdoLogicalBackupProvider};

$service = null;

try {
    set_time_limit(0);
    $operationId = (string) ($argv[1] ?? '');
    Env::load($basePath . '/.env');
    $pdo = Database::connect(require $basePath . '/app/Config/database.php');
    $version = require $basePath . '/app/Config/version.php';
    $config = require $basePath . '/app/Config/module_catalog.php';
    if (empty($config['enabled'])) throw new RuntimeException('Der vertrauenswürdige Modulkatalog ist nicht verfügbar.');
    $loader = new CatalogLoader(new CatalogTrustStore((array) ($config['trusted_keys'] ?? [])), new CatalogCache($basePath . '/storage/catalog'));
    $source = (string) ($config['source_url'] ?? '') !== ''
        ? new HttpCatalogSource((string) $config['source_id'], (string) $config['source_url'])
        : new LocalCatalogSource((string) $config['source_id'], (string) $config['source_path']);
    $snapshot = $loader->refreshOrLastKnownGood($source);
    $catalog = new CatalogService($pdo, (string) ($version['version'] ?? '0.0.0'), $snapshot);
    $lifecycle = new ModuleLifecycleService($pdo, $basePath, (string) ($version['version'] ?? '0.0.0'), new PdoLogicalBackupProvider($pdo, $basePath . '/storage/backups/modules'), new ModuleOperationLock($basePath . '/storage/locks/modules'));
    $installer = new CatalogPackageInstaller($loader, $source, $snapshot, $catalog, $lifecycle);
    $service = new ModuleBatchUpdateService($pdo, $basePath, $catalog, $installer);
    $service->run($operationId);
    exit(0);
} catch (Throwable $error) {
    if ($service instanceof ModuleBatchUpdateService) $service->recordFailure((string) ($operationId ?? ''), 'worker_failed', $error->getMessage());
    error_log('Module batch update worker failed: ' . get_class($error) . ': ' . $error->getMessage());
    exit(1);
}
