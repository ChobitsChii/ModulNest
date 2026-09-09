<?php

declare(strict_types=1);

use Modulon\Core\Modules\Catalog\CatalogAdoptionMetadata;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function adoption_metadata_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$valid = [
    'adoption' => [
        'legacy_version' => '1.2.0',
        'file_hashes' => ['app/Modules/News/NewsModule.php' => str_repeat('a', 64)],
        'baseline_migrations' => [['key' => 'modulnest.news_001_schema', 'checksum' => str_repeat('b', 64)]],
    ],
];
$metadata = CatalogAdoptionMetadata::fromModuleIndex($valid);
adoption_metadata_assert($metadata->legacyVersion === '1.2.0', 'Legacy-Version fehlt.');
adoption_metadata_assert($metadata->fileHashes['app/Modules/News/NewsModule.php'] === [str_repeat('a', 64)], 'Dateihash wurde nicht normalisiert.');

foreach ([
    [],
    ['adoption' => ['legacy_version' => '1.2.0', 'file_hashes' => [], 'baseline_migrations' => []]],
    ['adoption' => ['legacy_version' => '1.2.0', 'file_hashes' => ['../private.php' => str_repeat('a', 64)], 'baseline_migrations' => []]],
] as $invalid) {
    try {
        CatalogAdoptionMetadata::fromModuleIndex($invalid);
        adoption_metadata_assert(false, 'Fehlende oder ungültige Adoptionsmetadaten wurden akzeptiert.');
    } catch (InvalidArgumentException) {
    }
}

fwrite(STDOUT, "Signed adoption metadata smoke passed.\n");
