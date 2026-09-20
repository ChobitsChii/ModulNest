<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Core\Modules\Catalog\CatalogCache;
use Modulon\Core\Modules\Catalog\CatalogLoader;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\Catalog\LocalCatalogSource;
use Modulon\Core\Modules\ModulePackageInspector;

function dev_catalog_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/modulnest-dev-catalog-' . bin2hex(random_bytes(5));
$target = $tmp . '/source';
$state = $tmp . '/sequence';
$cache = $tmp . '/cache';

try {
    mkdir($tmp, 0775, true);
    $command = static function () use ($root, $target, $state, $cache): void {
        $arguments = [
            PHP_BINARY,
            $root . '/tools/build-module-catalog.php',
            '--development',
            '--target', $target,
            '--sequence-state', $state,
            '--cache-root', $cache,
        ];
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        exec($command, $output, $status);
        dev_catalog_assert($status === 0, 'Dev-Katalog-Build ist fehlgeschlagen.');
    };

    $command();
    $first = json_decode((string) file_get_contents($target . '/catalog/v1/root.json'), true, 32, JSON_THROW_ON_ERROR);
    $command();
    $second = json_decode((string) file_get_contents($target . '/catalog/v1/root.json'), true, 32, JSON_THROW_ON_ERROR);
    dev_catalog_assert($first['sequence'] === 1 && $second['sequence'] === 2, 'Dev-Sequenzen steigen nicht monoton.');

    $ids = array_column($second['modules'], 'id');
    sort($ids);
    $expected = [
        'modulnest.banking',
        'modulnest.calendar',
        'modulnest.dashboard',
        'modulnest.data-portability',
        'modulnest.fantasy-cards',
        'modulnest.homepage',
        'modulnest.logs',
        'modulnest.mail',
        'modulnest.mail-client',
        'modulnest.mirror',
        'modulnest.news',
        'modulnest.pages',
        'modulnest.repository-manager',
        'modulnest.sneak-preview',
        'modulnest.systeminfo',
        'modulnest.tools',
        'modulnest.wiki'
    ];
    sort($expected);
    dev_catalog_assert($ids === $expected, 'Dev-Katalog enthält nicht exakt die Produktmodule.');
    dev_catalog_assert(!in_array('example.example-notes', $ids, true), 'Referenzmodul erscheint als Produktmodul.');

    $publicKey = file($root . '/tests/Fixtures/catalog-v1/keys/TEST_ONLY_ed25519_public.key', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $loader = new CatalogLoader(
        new CatalogTrustStore(['modulnest-test-2026' => (string) $publicKey[1]]),
        new CatalogCache($cache),
    );
    $source = new LocalCatalogSource('modulnest.dev', $target);
    $snapshot = $loader->refresh($source);
    dev_catalog_assert(count($snapshot->modules) === count($expected) && !$snapshot->fromCache, 'Signierter Dev-Katalog ist nicht direkt ladbar.');
    foreach ($snapshot->modules as $module) {
        foreach ($module['releases'] as $release) {
            dev_catalog_assert(hash('sha256', $loader->verifyPackage($source, $release)) === $release['package']['sha256'], 'Dev-Paketprüfung ist fehlgeschlagen.');
        }
    }
} finally {
    if (is_dir($tmp)) {
        ModulePackageInspector::removeTree($tmp);
    }
}

fwrite(STDOUT, "Development catalog builder smoke passed.\n");
