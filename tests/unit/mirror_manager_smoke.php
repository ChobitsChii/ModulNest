<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// Autoload mirror module
spl_autoload_register(static function (string $class): void {
    $prefix = 'ModulNest\\Mirror\\';
    $baseDir = __DIR__ . '/../../modules/modulnest.mirror/releases/0.1.0-beta.1-f7a93c41b802/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Setup SQLite in-memory PDO for tests
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
CREATE TABLE `mirror_configs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `type` VARCHAR(32) NOT NULL,
    `source_type` VARCHAR(32) NOT NULL DEFAULT "github",
    `source_url` VARCHAR(500) NOT NULL,
    `source_branch` VARCHAR(100) NOT NULL DEFAULT "main",
    `target_path` VARCHAR(500) NOT NULL,
    `public_url` VARCHAR(500) NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_synced_at` DATETIME NULL,
    `last_trigger` VARCHAR(50) NULL,
    `last_status` VARCHAR(50) NOT NULL DEFAULT "idle",
    `last_log` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
');

// 1. Test MirrorPathResolver
$testHome = sys_get_temp_dir() . '/test-mirror-home-' . bin2hex(random_bytes(4));
mkdir($testHome . '/domains/repo.modulnest.de/public_html', 0775, true);
mkdir($testHome . '/domains/updates.modulnest.de/public_html', 0775, true);

$resolver = new \ModulNest\Mirror\Service\MirrorPathResolver($testHome);
assert($resolver->home() === realpath($testHome), 'Home directory matches');

$listing = $resolver->listDirectories('');
assert(in_array('domains', $listing['directories'], true), 'Domains dir found');

$subListing = $resolver->listDirectories('domains');
assert(in_array('repo.modulnest.de', $subListing['directories'], true), 'Subdomain dir found');

// Path resolution check
$resolved = $resolver->resolvePath($testHome . '/domains/repo.modulnest.de/public_html');
assert($resolved === realpath($testHome . '/domains/repo.modulnest.de/public_html'), 'Resolved valid home subpath');

$traversalFailed = false;
try {
    $resolver->resolvePath('/etc/passwd');
} catch (RuntimeException) {
    $traversalFailed = true;
}
assert($traversalFailed, 'Traversal outside home directory blocked');

// 2. Test MirrorConfigRepository
$repo = new \ModulNest\Mirror\Repository\MirrorConfigRepository($pdo);
$id = $repo->create([
    'name' => 'Offizielles Repo',
    'type' => 'repository',
    'source_type' => 'github',
    'source_url' => 'https://github.com/ChobitsChii/modulon',
    'source_branch' => 'main',
    'target_path' => $resolved,
    'public_url' => 'https://repo.modulnest.de',
    'enabled' => 1,
]);
assert($id > 0, 'Created mirror record');

$all = $repo->findAll();
assert(count($all) === 1, '1 mirror record exists');
assert($all[0]->name === 'Offizielles Repo', 'Name matches');
assert($all[0]->type === 'repository', 'Type matches');
assert($all[0]->targetPath === $resolved, 'Target path matches');

// Update status
$repo->updateStatus($id, 'success', 'Sync log output OK');
$m = $repo->findById($id);
assert($m->lastStatus === 'success', 'Status updated to success');
assert($m->lastLog === 'Sync log output OK', 'Log updated');

// Toggle enabled
$repo->toggleEnabled($id);
$m2 = $repo->findById($id);
assert(!$m2->enabled, 'Mirror toggled to disabled');

// 3. Test Data Portability Provider
$portability = new \ModulNest\Mirror\Portability\MirrorDataPortabilityProvider($pdo);
assert($portability->key() === 'mirror', 'Key is mirror');
assert($portability->schemaVersion() === 1, 'Schema version is 1');

$collector = new \Modulon\Core\Modules\DataPortability\DataPortabilityFileCollector("mirror");
$export = $portability->export(1, $collector);
assert($export['counts']['mirrors'] === 1, 'Exported 1 mirror');

// Clean test dirs
system('rm -rf ' . escapeshellarg($testHome));

echo "Mirror manager smoke passed.\n";
