<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

use Modulon\Core\Database;
use Modulon\Core\Env;
use Modulon\Core\Modules\Catalog\CatalogCache;
use Modulon\Core\Modules\Catalog\CatalogLoader;
use Modulon\Core\Modules\Catalog\CatalogPackageInstaller;
use Modulon\Core\Modules\Catalog\CatalogService;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\Catalog\HttpCatalogSource;
use Modulon\Core\Modules\Catalog\LocalCatalogSource;
use Modulon\Core\Modules\LegacyModuleAdoptionService;
use Modulon\Core\Modules\ModuleAdoptionOperationService;
use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\ModuleOperationLock;
use Modulon\Core\Modules\PdoLogicalBackupProvider;

$operationId = (string) ($argv[1] ?? '');
$moduleId = (string) ($argv[2] ?? '');
$operationService = null;

try {
    set_time_limit(0);
    Env::load($basePath . '/.env');
    $pdo = Database::connect(require $basePath . '/app/Config/database.php');
    $operationService = new ModuleAdoptionOperationService($pdo, $basePath);
    $version = require $basePath . '/app/Config/version.php';
    $config = require $basePath . '/app/Config/module_catalog.php';
    if (empty($config['enabled'])) throw new RuntimeException('Der vertrauenswürdige Modulkatalog ist nicht verfügbar.');

    $loader = new CatalogLoader(
        new CatalogTrustStore(is_array($config['trusted_keys'] ?? null) ? $config['trusted_keys'] : []),
        new CatalogCache($basePath . '/storage/catalog'),
    );
    $source = (string) ($config['source_url'] ?? '') !== ''
        ? new HttpCatalogSource((string) $config['source_id'], (string) $config['source_url'])
        : new LocalCatalogSource((string) $config['source_id'], (string) $config['source_path']);
    $snapshot = $loader->refreshOrLastKnownGood($source);
    $catalog = new CatalogService($pdo, (string) ($version['version'] ?? '0.0.0'), $snapshot);
    $lifecycle = new ModuleLifecycleService(
        $pdo,
        $basePath,
        (string) ($version['version'] ?? '0.0.0'),
        new PdoLogicalBackupProvider($pdo, $basePath . '/storage/backups/modules'),
        new ModuleOperationLock($basePath . '/storage/locks/modules'),
    );
    $installer = new CatalogPackageInstaller($loader, $source, $snapshot, $catalog, $lifecycle);
    $adoption = new LegacyModuleAdoptionService($pdo, $basePath, $installer);
    $operationService = new ModuleAdoptionOperationService($pdo, $basePath, $adoption);
    $operationService->run($operationId, $moduleId);
    exit(0);
} catch (Throwable $error) {
    if ($operationService instanceof ModuleAdoptionOperationService) {
        $operationService->recordWorkerFailure($operationId, $error);
    }
    error_log('Module adoption worker failed: ' . get_class($error) . ': ' . $error->getMessage());
    exit(1);
}
