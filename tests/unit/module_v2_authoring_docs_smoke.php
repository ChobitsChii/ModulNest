<?php

declare(strict_types=1);

use Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface;
use Modulon\Core\Modules\ModuleId;
use Modulon\Core\Modules\ModuleManifestReader;
use Modulon\Core\Modules\ModulePackageBuilder;
use Modulon\Core\Modules\ModulePackageInspector;
use Modulon\Core\Modules\PageLinkProviderInterface;
use Modulon\Core\Modules\RootPageProviderInterface;
use Modulon\Core\Modules\VersionConstraint;

$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';

function authoring_docs_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$guide = (string) file_get_contents($base . '/docs/development/module-v2-authoring.md');
$checklist = (string) file_get_contents($base . '/docs/development/module-v2-ai-checklist.md');
$publishing = (string) file_get_contents($base . '/docs/development/module-v2-publishing.md');
authoring_docs_assert($guide !== '' && $checklist !== '' && $publishing !== '', 'Authoring-Dokumente fehlen.');
authoring_docs_assert(
    preg_match('/<!-- module-v2-manifest-example:start -->\s*```json\s*(\{.*?\})\s*```\s*<!-- module-v2-manifest-example:end -->/s', $guide, $match) === 1,
    'Das kanonische Manifestbeispiel ist nicht eindeutig markiert.',
);

$manifest = json_decode($match[1], true, 64, JSON_THROW_ON_ERROR);
$temp = sys_get_temp_dir() . '/modulnest-authoring-docs-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700, true) && !is_dir($temp)) {
    throw new RuntimeException('Temporäres Doku-Fixture kann nicht erstellt werden.');
}
try {
    file_put_contents($temp . '/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $parsed = (new ModuleManifestReader())->read($temp . '/module.json');
    authoring_docs_assert($parsed->id === 'acme.example-notes', 'Manifestbeispiel wird nicht korrekt validiert.');
} finally {
    @unlink($temp . '/module.json');
    @rmdir($temp);
}

foreach (['acme.example-notes', 'modulnest.wiki'] as $validId) {
    authoring_docs_assert(ModuleId::isValid($validId), 'Dokumentierte gültige ID wird abgelehnt: ' . $validId);
}
foreach (['wiki', 'Acme.notes', 'acme.example_notes', 'acme.-notes', 'a.b.c'] as $invalidId) {
    authoring_docs_assert(!ModuleId::isValid($invalidId), 'Dokumentierte ungültige ID wird akzeptiert: ' . $invalidId);
}

$range = VersionConstraint::parse((string) $manifest['requires']['core']);
authoring_docs_assert($range->matches('2.0.1'), 'Dokumentierte Core-Range akzeptiert 2.0.1 nicht.');
authoring_docs_assert(!$range->matches('2.1.0-beta.1'), 'Stable-only-Dokumentationsrange akzeptiert Prerelease.');
authoring_docs_assert(!$range->matches('3.0.0'), 'Dokumentierte Core-Range akzeptiert 3.0.0.');

$capabilities = [
    'root_page' => RootPageProviderInterface::class,
    'page_links' => PageLinkProviderInterface::class,
    'data_portability' => DataPortabilityProviderInterface::class,
];
foreach ($capabilities as $key => $interface) {
    authoring_docs_assert(str_contains($guide, '`' . $key . '`'), 'Capability fehlt in der Doku: ' . $key);
    authoring_docs_assert(interface_exists($interface), 'Dokumentiertes Capability-Interface fehlt: ' . $interface);
}

foreach (['tools/test-v2-module.sh', 'tools/test-v2-fast.sh', 'tools/build-module-catalog.php', 'bin/modules.php'] as $command) {
    authoring_docs_assert(is_file($base . '/' . $command), 'Dokumentierter Befehl fehlt: ' . $command);
}
foreach (['--workspace-root', '--publisher', '--published-root'] as $publisherOption) {
    authoring_docs_assert(str_contains($publishing, $publisherOption), 'Publisheroption fehlt in der Doku: ' . $publisherOption);
}
authoring_docs_assert(method_exists(ModulePackageBuilder::class, 'build'), 'Dokumentierter Package-Builder fehlt.');
authoring_docs_assert(method_exists(ModulePackageInspector::class, 'inspect'), 'Dokumentierter Package-Inspector fehlt.');

fwrite(STDOUT, "Module v2 authoring documentation smoke test passed.\n");
