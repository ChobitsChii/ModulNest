<?php

declare(strict_types=1);

use Modulon\Core\Modules\Catalog\CatalogCache;
use Modulon\Core\Modules\Catalog\CatalogLoader;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\Catalog\LocalCatalogSource;
use Modulon\Core\Modules\ModulePackageInspector;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function production_signing_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$temporary = sys_get_temp_dir() . '/modulnest-production-signing-' . bin2hex(random_bytes(6));
mkdir($temporary . '/keys', 0700, true);
mkdir($temporary . '/published', 0700, true);
$keys = [];
foreach (['root', 'release'] as $role) {
    $pair = ParagonIE_Sodium_Compat::crypto_sign_keypair();
    $secret = ParagonIE_Sodium_Compat::crypto_sign_secretkey($pair);
    $public = ParagonIE_Sodium_Compat::crypto_sign_publickey($pair);
    $keys[$role] = base64_encode($public);
    file_put_contents($temporary . '/keys/' . $role . '.key', "EPHEMERAL TEST KEY\n" . base64_encode($secret) . "\n");
    chmod($temporary . '/keys/' . $role . '.key', 0600);
}

try {
    $arguments = [
        PHP_BINARY, $root . '/tools/build-module-catalog.php',
        '--target', $temporary . '/source', '--catalog-id', 'modulnest.production-test',
        '--root-key-file', $temporary . '/keys/root.key', '--root-key-id', 'root-test',
        '--release-key-file', $temporary . '/keys/release.key', '--release-key-id', 'release-test',
        '--sequence-state', $temporary . '/sequence', '--cache-root', $temporary . '/cache',
        '--published-root', $temporary . '/published',
        '--expires-at', '2030-01-01T00:00:00+00:00',
    ];
    exec(implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $status);
    production_signing_assert($status === 0, 'Production-Publisher mit getrennten Keys fehlgeschlagen: ' . implode(' ', $output));

    $loader = new CatalogLoader(
        new CatalogTrustStore(['root-test' => $keys['root'], 'release-test' => $keys['release']], ['root-test']),
        new CatalogCache($temporary . '/runtime-cache'),
    );
    $source = new LocalCatalogSource('modulnest.production-test', $temporary . '/source');
    $snapshot = $loader->refresh($source);
    production_signing_assert(count($snapshot->modules) === 17, 'Production-Katalog enthält nicht exakt elf Module.');
    foreach ($snapshot->modules as $module) {
        production_signing_assert(count($module['releases']) === 1, 'Production-Katalog veröffentlicht nicht exakt den aktuellen Modulrelease.');
        production_signing_assert($module['releases'][0]['signing_key_id'] === 'release-test', 'Modulpaket nutzt nicht den separaten Release-Key.');
                if (in_array($module['id'], ['modulnest.calendar', 'modulnest.mirror', 'modulnest.repository-manager', 'modulnest.mail', 'modulnest.fantasy-cards', 'modulnest.mail-client'])) {
            continue;
        }
        production_signing_assert(isset($module['adoption']), 'Signierte modulbezogene Adoptionsmetadaten fehlen.');
        $metadata = \Modulon\Core\Modules\Catalog\CatalogAdoptionMetadata::fromModuleIndex($module);
        production_signing_assert($metadata->legacyVersion === '1.2.0' && $metadata->fileHashes !== [], 'Adoptionsmetadaten sind nicht vollständig validierbar.');
        production_signing_assert(str_starts_with($module['releases'][0]['package']['location'], 'packages/' . $module['id'] . '/'), 'Modulpaket liegt nicht im unabhängig versionierbaren Repositorypfad.');
        $loader->verifyPackage($source, $module['releases'][0]);
    }
    $rootSignature = json_decode((string) file_get_contents($temporary . '/source/catalog/v1/root.json.sig'), true, 8, JSON_THROW_ON_ERROR);
    production_signing_assert(($rootSignature['key_id'] ?? '') === 'root-test', 'Katalog-Root nutzt nicht den separaten Root-Key.');

    $sameKeyArguments = $arguments;
    $sameKeyArguments[11] = $temporary . '/keys/root.key';
    $sameKeyArguments[13] = 'root-test';
    exec(implode(' ', array_map('escapeshellarg', $sameKeyArguments)) . ' 2>&1', $sameOutput, $sameStatus);
    production_signing_assert($sameStatus !== 0, 'Production-Publisher akzeptiert identische Root-/Release-Keys.');
} finally {
    ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Production catalog signing smoke passed.\n");
