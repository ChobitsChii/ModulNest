<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Core\Modules\Catalog\CatalogSchemaValidator;
use Modulon\Core\Modules\ModuleManifest;

function dep_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function dep_throws(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (\Throwable) {
        return;
    }
    dep_assert(false, $message);
}

// 1. ModuleManifest tests
$manifestData = [
    'manifest_version' => 2,
    'id' => 'example.test-deprecated',
    'name' => 'Test Modul',
    'description' => 'Test Modul Beschreibung',
    'version' => '1.0.0',
    'license' => 'MIT',
    'authors' => [['name' => 'Tester']],
    'requires' => [
        'core' => '>=2.1.0 <3.0.0',
        'php' => '>=8.3.0',
    ],
    'entrypoint' => [
        'class' => 'Example\\Test\\TestModule',
        'file' => 'src/TestModule.php',
    ],
    'autoload' => [
        'psr4' => [
            'Example\\Test\\' => 'src/',
        ],
    ],
    'route_prefix' => 'test-deprecated',
    'access_level' => 'admin',
    'data' => [
        'schema_version' => 0,
        'ownership' => [
            'tables' => [],
            'settings' => [],
            'storage' => [],
            'uploads' => [],
            'jobs' => [],
        ],
    ],
    'deprecated' => true,
    'deprecation_reason' => 'Dieses Modul ist veraltet.',
];

$manifest = ModuleManifest::fromArray($manifestData);
dep_assert($manifest->deprecated === true, 'ModuleManifest: deprecated sollte true sein.');
dep_assert($manifest->deprecationReason === 'Dieses Modul ist veraltet.', 'ModuleManifest: deprecationReason stimmt nicht.');

$normalData = $manifestData;
unset($normalData['deprecated'], $normalData['deprecation_reason']);
$normalManifest = ModuleManifest::fromArray($normalData);
dep_assert($normalManifest->deprecated === false, 'ModuleManifest: normal sollte deprecated false sein.');
dep_assert($normalManifest->deprecationReason === null, 'ModuleManifest: normal sollte deprecationReason null sein.');

// 2. CatalogSchemaValidator tests
$validator = new CatalogSchemaValidator();
$catalogModuleJson = json_encode([
    'schema_version' => 2,
    'id' => 'example.test-deprecated',
    'name' => 'Test Modul',
    'description' => 'Test Modul',
    'homepage' => '',
    'repository' => '',
    'license' => 'MIT',
    'authors' => ['Tester'],
    'history' => [
        [
            'version' => '1.0.0',
            'date' => '2026-09-14',
            'changes' => ['Initial release'],
        ],
    ],
    'releases' => [
        [
            'version' => '1.0.0',
            'channel' => 'stable',
            'release_notes' => [
                'summary' => 'Initial release',
                'changes' => ['Initial release'],
            ],
            'core' => '>=2.1.0 <3.0.0',
            'php' => '>=8.3.0',
            'dependencies' => (object) [],
            'migration_count' => 0,
            'package' => [
                'location' => 'packages/example.test-deprecated/1.0.0/pkg.zip',
                'size' => 1234,
                'sha256' => str_repeat('a', 64),
            ],
            'signing_key_id' => 'test-key',
            'signing_fingerprint' => str_repeat('b', 64),
            'signature' => 'sig',
            'published_at' => (new DateTimeImmutable('2026-09-14T12:00:00+00:00'))->format(DATE_ATOM),
        ],
    ],
    'deprecated' => true,
    'deprecation_reason' => 'Dieses Modul ist veraltet.',
], JSON_THROW_ON_ERROR);

$parsed = $validator->module($catalogModuleJson);
dep_assert(($parsed['deprecated'] ?? false) === true, 'CatalogSchemaValidator: deprecated field missing');
dep_assert(($parsed['deprecation_reason'] ?? '') === 'Dieses Modul ist veraltet.', 'CatalogSchemaValidator: deprecation_reason missing');

// Invalid deprecated type
$invalidDeprecated = json_decode($catalogModuleJson, true);
$invalidDeprecated['deprecated'] = 'not-a-bool';
dep_throws(static fn () => $validator->module(json_encode($invalidDeprecated, JSON_THROW_ON_ERROR)), 'CatalogSchemaValidator should reject non-bool deprecated.');

// Invalid deprecation_reason type
$invalidReason = json_decode($catalogModuleJson, true);
$invalidReason['deprecation_reason'] = '';
dep_throws(static fn () => $validator->module(json_encode($invalidReason, JSON_THROW_ON_ERROR)), 'CatalogSchemaValidator should reject empty deprecation_reason.');

fwrite(STDOUT, "Module deprecated smoke tests passed.\n");
