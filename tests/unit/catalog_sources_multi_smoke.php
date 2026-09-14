<?php

declare(strict_types=1);

use Modulon\Core\Database\{MigrationRunner, SchemaHelper};
use Modulon\Core\ModuleContext;
use Modulon\Core\Modules\Catalog\{CatalogAggregateLoader, CatalogCache, CatalogPackageInstaller, CatalogService, CatalogSourceRegistry, CatalogSourceResolver};
use Modulon\Core\Modules\{ModuleLifecycleService, ModuleOperationLock, ModulePackageInspector, PdoLogicalBackupProvider};
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function catalog_sources_assert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

function catalog_sources_throws(callable $callable, string $message, string $contains = ''): void
{
    try { $callable(); } catch (Throwable $error) {
        catalog_sources_assert($contains === '' || str_contains($error->getMessage(), $contains), $message . ' Falscher Fehler: ' . $error->getMessage());
        return;
    }
    catalog_sources_assert(false, $message);
}

/** @return array<string,string> */
function catalog_sources_env(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value, " \t\"'");
    }
    return $values;
}

function catalog_sources_copy(string $source, string $target): void
{
    if (!is_dir($target)) mkdir($target, 0775, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $destination = $target . '/' . substr($item->getPathname(), strlen($source) + 1);
        $item->isDir() ? mkdir($destination, 0775, true) : copy($item->getPathname(), $destination);
    }
}

$root = dirname(__DIR__, 2);
$env = catalog_sources_env($root . '/.env');
$server = new PDO(
    'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';charset=' . ($env['DB_CHARSET'] ?? 'utf8mb4'),
    $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = 'modulnest_catalog_sources_' . bin2hex(random_bytes(4));
$temporary = sys_get_temp_dir() . '/modulnest-catalog-sources-' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $server->exec('USE `' . $database . '`');
    (new MigrationRunner($server, $root))->run([]);
    $server->exec("DELETE FROM catalog_sources WHERE id='modulnest.official'");
    $server->exec("INSERT INTO modules(module_key,name,description,route_prefix,access_level,handler,is_active,show_in_header,show_on_home) VALUES('example.bound','Bound','Existing','bound','admin','native',1,0,0)");
    $moduleRow = (int) $server->lastInsertId();
    $server->prepare("INSERT INTO module_installations(module_id,module_row_id,origin,catalog_source_id,catalog_sequence,installed_version,active_release_id) VALUES('example.bound',?,'catalog-managed','modulnest.official',7,'1.0.0','1.0.0-existing')")->execute([$moduleRow]);
    $migration = require $root . '/app/Database/migrations/20260910_000100_catalog_sources.php';
    $migration->up($server, new SchemaHelper($server));
    catalog_sources_assert((string) $server->query("SELECT location FROM catalog_sources WHERE id='modulnest.official'")->fetchColumn() === 'https://repo.modulnest.de', 'Migration legt die offizielle Quelle nicht an.');
    catalog_sources_assert((string) $server->query("SELECT catalog_source_id FROM module_installations WHERE module_id='example.bound'")->fetchColumn() === 'modulnest.official', 'Migration verändert eine bestehende Quellenbindung.');

    mkdir($temporary . '/bin', 0775, true);
    copy($root . '/bin/module-health.php', $temporary . '/bin/module-health.php');
    symlink($root . '/vendor', $temporary . '/vendor');
    catalog_sources_copy($root . '/tests/Fixtures/catalog-v1/source-sequence-1', $temporary . '/source-a');
    catalog_sources_copy($root . '/tests/Fixtures/catalog-v1/source-sequence-2', $temporary . '/source-b');
    catalog_sources_copy($root . '/tests/Fixtures/catalog-v1/source-sequence-1', $temporary . '/source-untrusted');

    $keyLines = file($root . '/tests/Fixtures/catalog-v1/keys/TEST_ONLY_ed25519_public.key', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $keys = ['modulnest-test-2026' => (string) $keyLines[1]];
    $roots = ['modulnest-test-2026'];
    $registry = new CatalogSourceRegistry($server);
    $registry->add('test.source-a', 'Test Source A', 'local', $temporary . '/source-a', true, 100, $keys, $roots, 'smoke-test');
    $registry->disable('modulnest.official', 'smoke-test');
    $registry->add('test.source-b', 'Test Source B', 'local', $temporary . '/source-b', true, 10, $keys, $roots, 'smoke-test');
    catalog_sources_assert((new ModuleContext($temporary, $server, new Session(), ['catalogSourceRegistry'=>$registry]))->catalogSources() === $registry, 'Öffentliche ModuleContext-API stellt die Registry nicht bereit.');
    catalog_sources_throws(fn () => $registry->add('bad', 'Bad HTTP', 'https', 'http://example.test', true, 0, $keys, $roots), 'Unsichere HTTP-Quelle wurde akzeptiert.');
    catalog_sources_throws(fn () => $registry->add('test.unbound', 'Unbound', 'local', $temporary . '/source-untrusted', true, 0, $keys, ['missing']), 'Ungebundener Root-Key wurde akzeptiert.');
    catalog_sources_throws(fn () => $registry->update('test.source-a', ['id'=>'changed']), 'Immutable Quellen-ID konnte aktualisiert werden.');

    $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-06T13:00:00+00:00');
    $load = static fn () => (new CatalogAggregateLoader($registry, new CatalogCache($temporary . '/cache'), clock: $clock))->refreshAll();
    $aggregate = $load();
    catalog_sources_assert(count($aggregate->snapshots) === 2, 'Zwei aktivierte Quellen werden nicht parallel geladen.');
    $resolver = new CatalogSourceResolver($server, $aggregate);
    $snapshot = $resolver->snapshot();
    catalog_sources_assert(($snapshot->modules['example.example-notes']['_catalog_source_id'] ?? '') === 'test.source-a', 'Priority-Konflikt wird nicht deterministisch nach Source-Priority aufgelöst.');
    catalog_sources_assert(($snapshot->modules['example.example-notes']['releases'][0]['version'] ?? '') === '0.1.0', 'Resolver hat still die höchste Version aus der niedriger priorisierten Quelle gewählt.');

    $registry->disable('test.source-b', 'smoke-test');
    catalog_sources_assert(array_keys($load()->snapshots) === ['test.source-a'], 'Deaktivierte Quelle wird weiterhin geladen.');
    $registry->enable('test.source-b', 'smoke-test');
    $registry->update('test.source-b', ['priority'=>100], 'smoke-test');
    $tieResolver = new CatalogSourceResolver($server, $load());
    catalog_sources_assert(!isset($tieResolver->snapshot()->modules['example.example-notes']) && isset($tieResolver->conflicts()['example.example-notes']), 'Gleich priorisierte doppelte Modul-ID wird nicht als sichtbarer Konflikt blockiert.');
    $registry->update('test.source-b', ['priority'=>10], 'smoke-test');

    $aggregate = $load();
    $resolver = new CatalogSourceResolver($server, $aggregate);
    $snapshot = $resolver->snapshot();
    $catalog = new CatalogService($server, '1.2.0', $snapshot);
    $lifecycle = new ModuleLifecycleService($server, $temporary, '1.2.0', new PdoLogicalBackupProvider($server, $temporary . '/storage/backups/modules'), new ModuleOperationLock($temporary . '/storage/locks/modules'));
    $installer = CatalogPackageInstaller::fromAggregate($aggregate, $resolver, $catalog, $lifecycle);
    $installer->install('example.example-notes', false);
    catalog_sources_assert((string) $server->query("SELECT catalog_source_id FROM module_installations WHERE module_id='example.example-notes'")->fetchColumn() === 'test.source-a', 'Installation wird nicht an die aufgelöste Quelle gebunden.');
    $registry->disable('test.source-a', 'smoke-test');
    $disabledBindingCatalog = new CatalogService($server, '1.2.0', (new CatalogSourceResolver($server, $load()))->snapshot());
    catalog_sources_assert($disabledBindingCatalog->module('example.example-notes')['catalog'] === null, 'Deaktivierte gebundene Quelle lässt Updates still aus einer anderen Quelle zu.');
    $registry->enable('test.source-a', 'smoke-test');

    $registry->update('test.source-b', ['priority'=>200], 'smoke-test');
    $aggregate = $load();
    $resolver = new CatalogSourceResolver($server, $aggregate);
    $boundSnapshot = $resolver->snapshot();
    catalog_sources_assert(($boundSnapshot->modules['example.example-notes']['_catalog_source_id'] ?? '') === 'test.source-a', 'Installierte Source-Bindung wird von höherer Priority überschrieben.');
    $sourceBCatalog = new CatalogService($server, '1.2.0', $aggregate->snapshots['test.source-b']);
    $sourceBInstaller = new CatalogPackageInstaller($aggregate->loaders['test.source-b'], $aggregate->sources['test.source-b'], $aggregate->snapshots['test.source-b'], $sourceBCatalog, $lifecycle);
    catalog_sources_throws(fn () => $sourceBInstaller->update('example.example-notes'), 'Update aus fremder Quelle wurde ohne expliziten Wechsel akzeptiert.', 'expliziten Quellenwechsel');

    $catalog = new CatalogService($server, '1.2.0', $boundSnapshot);
    $installer = CatalogPackageInstaller::fromAggregate($aggregate, $resolver, $catalog, $lifecycle);
    $installer->switchSource('example.example-notes', 'test.source-b');
    $binding = $server->query("SELECT catalog_source_id,installed_version FROM module_installations WHERE module_id='example.example-notes'")->fetch(PDO::FETCH_ASSOC);
    catalog_sources_assert(($binding['catalog_source_id'] ?? '') === 'test.source-b' && ($binding['installed_version'] ?? '') === '0.2.0', 'Expliziter verifizierter Quellenwechsel wurde nicht persistiert.');

    $registry->add('test.untrusted', 'Untrusted Source', 'local', $temporary . '/source-untrusted', true, 0, ['wrong-key'=>base64_encode(random_bytes(32))], ['wrong-key'], 'smoke-test');
    $withUntrusted = $load();
    catalog_sources_assert(!isset($withUntrusted->snapshots['test.untrusted']) && isset($withUntrusted->warnings['test.untrusted']) && isset($withUntrusted->snapshots['test.source-a']), 'Unvertraute Zusatzquelle beeinträchtigt vertrauenswürdige Snapshots.');

    file_put_contents($temporary . '/source-b/catalog/v1/root.json.sig', '{"broken":true}');
    $withBrokenSource = $load();
    catalog_sources_assert(!$withBrokenSource->snapshots['test.source-a']->fromCache, 'Fehler einer Zusatzquelle zerstört oder ersetzt den LKG-Pfad einer anderen Quelle.');
    catalog_sources_assert($withBrokenSource->snapshots['test.source-b']->fromCache && isset($withBrokenSource->warnings['test.source-b']), 'Source-spezifisches LKG wird bei einem Refresh-Fehler nicht verwendet.');
    catalog_sources_assert($registry->get('test.source-b')['last_error_at'] !== null, 'Refresh-Fehler wird nicht auditierbar an der Quelle erfasst.');
    $registry->update('test.source-b', ['trusted_keys'=>['replacement-key'=>base64_encode(random_bytes(32))], 'root_key_ids'=>['replacement-key']], 'smoke-test');
    $afterTrustChange = $load();
    catalog_sources_assert(!isset($afterTrustChange->snapshots['test.source-b']), 'LKG aus einer alten Source-/Trust-Bindung wurde nach Trust-Wechsel weiterverwendet.');
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_link($temporary . '/vendor')) unlink($temporary . '/vendor');
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Catalog sources multi-repository smoke passed.\n");
