<?php

declare(strict_types=1);

use Modulon\Core\Modules\Catalog\CatalogCache;
use Modulon\Core\Modules\Catalog\CatalogLoader;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\Catalog\LocalCatalogSource;
use Modulon\Core\Modules\ModulePackageInspector;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function generic_publisher_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$temporary = sys_get_temp_dir() . '/modulnest-generic-publisher-' . bin2hex(random_bytes(6));
$workspace = $root . '/tests/Fixtures/module-publisher-v2/publisher-test/1.0.0';
mkdir($temporary . '/keys', 0700, true);
mkdir($temporary . '/published', 0700, true);
$publicKeys = [];
foreach (['root', 'release'] as $role) {
    $pair = ParagonIE_Sodium_Compat::crypto_sign_keypair();
    $secret = ParagonIE_Sodium_Compat::crypto_sign_secretkey($pair);
    $public = ParagonIE_Sodium_Compat::crypto_sign_publickey($pair);
    $publicKeys[$role] = base64_encode($public);
    file_put_contents($temporary . '/keys/' . $role . '.key', "EPHEMERAL TEST KEY\n" . base64_encode($secret) . "\n");
    chmod($temporary . '/keys/' . $role . '.key', 0600);
}

$run = static function (string $source, string $published, string $moduleWorkspace, ?string $publisher = 'modulnest', bool $discoverRoot = false) use ($root, $temporary): array {
    $arguments = [
        PHP_BINARY, $root . '/tools/build-module-catalog.php',
        '--target', $source, '--catalog-id', 'modulnest.generic-publisher-test',
        '--root-key-file', $temporary . '/keys/root.key', '--root-key-id', 'root-test',
        '--release-key-file', $temporary . '/keys/release.key', '--release-key-id', 'release-test',
        '--sequence-state', $temporary . '/sequence', '--cache-root', $temporary . '/cache',
        '--published-root', $published, $discoverRoot ? '--workspace-root' : '--workspace', $moduleWorkspace,
        '--module', 'modulnest.publisher-test', '--expires-at', '2030-01-01T00:00:00+00:00',
    ];
    if ($publisher !== null) {
        $arguments[] = '--publisher';
        $arguments[] = $publisher;
    }
    exec(implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $status);
    return [$status, $output];
};

try {
    [$status, $output] = $run($temporary . '/source', $temporary . '/published', dirname($workspace, 2), 'modulnest', true);
    generic_publisher_assert($status === 0, 'Neues Modul konnte nicht gebaut werden: ' . implode(' ', $output));

    $loader = new CatalogLoader(
        new CatalogTrustStore(['root-test' => $publicKeys['root'], 'release-test' => $publicKeys['release']], ['root-test']),
        new CatalogCache($temporary . '/runtime-cache'),
    );
    $source = new LocalCatalogSource('modulnest.generic-publisher-test', $temporary . '/source');
    $snapshot = $loader->refresh($source);
    generic_publisher_assert(count($snapshot->modules) === 1, 'Fixture-Katalog enthält unerwartete Module.');
    $module = array_values($snapshot->modules)[0];
    generic_publisher_assert($module['id'] === 'modulnest.publisher-test', 'Neue namespaced Modul-ID fehlt.');
    generic_publisher_assert(!isset($module['adoption']), 'Neues Modul enthält erfundene Adoption-Metadaten.');
    $release = $module['releases'][0];
    $packageBytes = $loader->verifyPackage($source, $release);
    generic_publisher_assert(hash('sha256', $packageBytes) === $release['package']['sha256'], 'Paket-Hash/Signatur ist ungültig.');

    $packagePath = $temporary . '/source/' . $release['package']['location'];
    $originalHash = hash_file('sha256', $packagePath);
    [$repeatStatus] = $run($temporary . '/source', $temporary . '/source', $workspace);
    generic_publisher_assert($repeatStatus === 0 && hash_file('sha256', $packagePath) === $originalHash, 'Identische Wiederholung verändert Paketbytes.');

    $changedWorkspace = $temporary . '/changed-workspace';
    mkdir($changedWorkspace, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $destination = $changedWorkspace . '/' . substr($item->getPathname(), strlen($workspace) + 1);
        $item->isDir() ? mkdir($destination, 0700, true) : copy($item->getPathname(), $destination);
    }
    file_put_contents($changedWorkspace . '/src/PublisherTestModule.php', "\n// changed bytes\n", FILE_APPEND);
    [$changedStatus] = $run($temporary . '/source', $temporary . '/source', $changedWorkspace);
    generic_publisher_assert($changedStatus !== 0, 'Veröffentlichte Version wurde mit anderen Bytes überschrieben.');
    generic_publisher_assert(hash_file('sha256', $packagePath) === $originalHash, 'Abgelehnter Build hat das veröffentlichte Paket verändert.');

    mkdir($temporary . '/unauthorized-published', 0700, true);
    [$unauthorizedStatus] = $run($temporary . '/unauthorized-source', $temporary . '/unauthorized-published', $workspace, null);
    generic_publisher_assert($unauthorizedStatus !== 0, 'Workspace ohne explizit autorisierten Publisher wurde akzeptiert.');
} finally {
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Generic module catalog publisher smoke passed.\n");
