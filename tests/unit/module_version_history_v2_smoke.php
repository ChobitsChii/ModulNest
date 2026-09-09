<?php

declare(strict_types=1);

use Modulon\Core\Modules\Catalog\CatalogSchemaValidator;
use Modulon\Core\Modules\ModulePackageInspector;
use Modulon\Core\Modules\VersionConstraint;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function version_history_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$expected = [
    'banking' => ['modulnest.banking', '1.2.0'],
    'dashboard' => ['modulnest.dashboard', '1.3.0'],
    'data-portability' => ['modulnest.data-portability', '1.3.0'],
    'homepage' => ['modulnest.homepage', '1.1.0'],
    'logs' => ['modulnest.logs', '1.2.0'],
    'news' => ['modulnest.news', '1.2.0'],
    'pages' => ['modulnest.pages', '1.1.0'],
    'sneak-preview' => ['modulnest.sneak-preview', '1.2.0'],
    'systeminfo' => ['modulnest.systeminfo', '1.1.0'],
    'tools' => ['modulnest.tools', '1.1.0'],
    'wiki' => ['modulnest.wiki', '1.3.0'],
];
$temporary = sys_get_temp_dir() . '/modulnest-version-history-' . bin2hex(random_bytes(6));

try {
    foreach ($expected as $directory => [$moduleId, $currentVersion]) {
        $releasePath = $root . '/modules-src/' . $directory . '/' . $currentVersion;
        $baselinePath = $root . '/modules-src/' . $directory . '/1.0.0';
        $manifest = json_decode((string) file_get_contents($releasePath . '/module.json'), true, 64, JSON_THROW_ON_ERROR);
        version_history_assert(($manifest['id'] ?? '') === $moduleId && ($manifest['version'] ?? '') === $currentVersion, 'Aktuelle Manifestversion stimmt nicht: ' . $moduleId);
        version_history_assert(($manifest['requires']['core'] ?? '') === '>=2.0.0-alpha.1 <3.0.0', 'Core-Range ist nicht alpha-kompatibel: ' . $moduleId);
        version_history_assert(VersionConstraint::parse((string) $manifest['requires']['core'])->matches('2.0.0-alpha.1'), 'Aktueller Alpha-Core wird abgelehnt: ' . $moduleId);
        $changelog = (string) file_get_contents($releasePath . '/CHANGELOG.md');
        version_history_assert($changelog !== '' && $changelog === (string) file_get_contents($baselinePath . '/CHANGELOG.md'), 'Paket-Releases enthalten nicht dieselbe vollständige Modulhistorie: ' . $moduleId);
        version_history_assert(str_contains($changelog, '## ' . $currentVersion . ' - '), 'Aktuelle Version fehlt im CHANGELOG: ' . $moduleId);
    }

    $command = implode(' ', array_map('escapeshellarg', [
        PHP_BINARY,
        $root . '/tools/build-module-catalog.php',
        '--development',
        '--target', $temporary . '/source',
        '--sequence-state', $temporary . '/sequence',
        '--cache-root', $temporary . '/cache',
    ]));
    exec($command, $output, $status);
    version_history_assert($status === 0, 'Historischer Entwicklungskatalog konnte nicht gebaut werden.');

    $validator = new CatalogSchemaValidator();
    foreach ($expected as [$moduleId, $currentVersion]) {
        $indexPath = $temporary . '/source/catalog/v1/modules/' . $moduleId . '.json';
        $index = $validator->module((string) file_get_contents($indexPath));
        version_history_assert($index['schema_version'] === 2 && ($index['history'][0]['version'] ?? '') === $currentVersion, 'Strukturierte Historie oder aktuelle Version fehlt: ' . $moduleId);
        $releases = array_column($index['releases'], null, 'version');
        version_history_assert(is_array($releases[$currentVersion]['release_notes'] ?? null), 'Release Notes sind nicht strukturiert: ' . $moduleId);
        version_history_assert(($releases[$currentVersion]['release_notes']['changes'] ?? []) === $index['history'][0]['changes'], 'Release Notes weichen von der Historie ab: ' . $moduleId);

        $archive = $temporary . '/source/packages/' . $moduleId . '/' . $currentVersion . '/' . $moduleId . '-' . $currentVersion . '.zip';
        $zip = new ZipArchive();
        version_history_assert($zip->open($archive) === true && $zip->locateName('CHANGELOG.md') !== false, 'CHANGELOG ist nicht im Paket enthalten: ' . $moduleId);
        $zip->close();
    }
    $tampered = json_decode((string) file_get_contents($temporary . '/source/catalog/v1/modules/modulnest.wiki.json'), true, 64, JSON_THROW_ON_ERROR);
    $tampered['releases'][0]['release_notes']['changes'][0] = 'Nicht in der Historie belegt';
    try {
        $validator->module(json_encode($tampered, JSON_THROW_ON_ERROR));
        version_history_assert(false, 'Katalog akzeptierte Release Notes, die von der Modulhistorie abweichen.');
    } catch (InvalidArgumentException) {
    }
} finally {
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Module version histories and packaged changelogs passed (11 modules).\n");
