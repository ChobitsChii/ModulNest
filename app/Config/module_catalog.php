<?php

declare(strict_types=1);

use Modulon\Core\Env;

$environment = strtolower((string) Env::get('APP_ENV', 'production'));
$isDevelopment = in_array($environment, ['development', 'dev', 'testing', 'test', 'local'], true);
$officialTrust = require __DIR__ . '/module_catalog_trust.php';
$officialKeys = [];
foreach ($officialTrust as $metadata) {
    $publicKey = base64_decode((string) ($metadata['public_key'] ?? ''), true);
    if (!is_string($publicKey)
        || strlen($publicKey) !== 32
        || !hash_equals((string) ($metadata['fingerprint'] ?? ''), hash('sha256', $publicKey))
    ) {
        throw new RuntimeException('Ungültige eingebettete Katalog-Trust-Metadaten.');
    }
    $officialKeys[(string) $metadata['key_id']] = (string) $metadata['public_key'];
}

$trustedJson = trim((string) Env::get('MODULE_CATALOG_TRUSTED_KEYS_JSON', ''));
$trusted = $trustedJson !== '' ? json_decode($trustedJson, true) : ($isDevelopment ? [] : $officialKeys);
if (!is_array($trusted)) {
    $trusted = [];
}
$sourcePath = (string) Env::get('MODULE_CATALOG_SOURCE_PATH', '');
$sourceUrl = (string) Env::get(
    'MODULE_CATALOG_SOURCE_URL',
    $isDevelopment ? '' : 'https://raw.githubusercontent.com/ChobitsChii/ModulNest-Modules/main'
);
if ($isDevelopment && $trusted === []) {
    $lines = file(dirname(__DIR__, 2) . '/tests/Fixtures/catalog-v1/keys/TEST_ONLY_ed25519_public.key', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (isset($lines[1])) {
        $trusted = ['modulnest-test-2026' => $lines[1]];
    }
}
if ($isDevelopment && $sourcePath === '') {
    $sourcePath = dirname(__DIR__, 2) . '/storage/catalog-dev/source';
}
$rootKeyIdsJson = trim((string) Env::get('MODULE_CATALOG_ROOT_KEY_IDS_JSON', ''));
$rootKeyIds = $rootKeyIdsJson !== '' ? json_decode($rootKeyIdsJson, true) : null;
if (!is_array($rootKeyIds) || !array_is_list($rootKeyIds)) {
    $rootKeyIds = $isDevelopment
        ? array_keys($trusted)
        : [(string) $officialTrust['root']['key_id']];
}

return [
    'enabled' => $trusted !== [] && ($sourcePath !== '' || $sourceUrl !== ''),
    'source_id' => (string) Env::get('MODULE_CATALOG_SOURCE_ID', $isDevelopment ? 'modulnest.dev' : 'modulnest.official'),
    'source_path' => $sourcePath,
    'source_url' => $sourceUrl,
    'trusted_keys' => $trusted,
    'root_key_ids' => $rootKeyIds,
    'test_key_active' => $isDevelopment && isset($trusted['modulnest-test-2026']),
];
