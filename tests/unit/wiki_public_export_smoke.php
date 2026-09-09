<?php

declare(strict_types=1);

function wiki_export_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__, 2);
$target = sys_get_temp_dir() . '/modulnest-wiki-export-' . bin2hex(random_bytes(5));
mkdir($target . '/build/update', 0775, true);
file_put_contents($target . '/build/update/stable.json', '{"latest":"1.3.0"}');
file_put_contents($target . '/build/update/prerelease.json', '{"latest":"2.0.0"}');
$command = 'cd ' . escapeshellarg($root) . ' && bash tools/release/export-modulnest.sh --target ' . escapeshellarg($target) . ' --no-ui --yes --requires-migrations true 2>&1';
exec($command, $output, $status);
try {
    wiki_export_assert($status === 0, 'The public export must succeed for the Wiki documentation resources.');
    wiki_export_assert(is_file($target . '/build/update/stable.json') && is_file($target . '/build/update/prerelease.json'), 'Public export must preserve both release feeds.');
    foreach ([
        'docs/README.md',
        '.gitattributes',
        'docs/development/README.md',
        'docs/development/example-module.md',
        'docs/releases/README.md',
        'docs/releases/1.0.0.md',
        'docs/releases/1.0.1.md',
        'docs/releases/1.1.0.md',
        'docs/releases/1.1.1.md',
        'docs/releases/1.2.0.md',
        'docs/releases/1.3.0.md',
        'docs/releases/2.0.0.md',
        'docs/third-party.md',
        'assets/markdown-highlight.js',
        'package.json',
        'package-lock.json',
        'public/assets/js/markdown-highlight.js',
        'examples/modules/ExampleNotes/README.md',
        'tools/create-module.php',
        'bin/module-update-worker.php',
    ] as $path) {
        wiki_export_assert(is_file($target . '/' . $path), "Public export must contain {$path}.");
    }
    foreach ([
        'app/Modules/Banking', 'app/Modules/Dashboard', 'app/Modules/DataPortability',
        'app/Modules/Homepage', 'app/Modules/Logs', 'app/Modules/News', 'app/Modules/Pages',
        'app/Modules/SneakPreview', 'app/Modules/Systeminfo', 'app/Modules/Tools', 'app/Modules/Wiki',
        'app/Views/banking', 'app/Views/dashboard', 'app/Views/data-portability', 'app/Views/homepage',
        'app/Views/logs', 'app/Views/news', 'app/Views/pages', 'app/Views/sneak-preview',
        'app/Views/systeminfo', 'app/Views/tools', 'app/Views/wiki',
        'modules-src', 'tests/Fixtures/legacy-modules-1.2.0', 'docs/ai-benchmark',
    ] as $path) {
        wiki_export_assert(!file_exists($target . '/' . $path), "Public export must not contain legacy/package workspace path {$path}.");
    }
    foreach (['Admin', 'Auth', 'Modules', 'User', 'Updates'] as $coreModule) {
        wiki_export_assert(is_dir($target . '/app/Modules/' . $coreModule), "Public export must retain core module {$coreModule}.");
    }
    wiki_export_assert(!is_dir($target . '/app/Modules/ExampleNotes'), 'ExampleNotes must remain reference code outside productive module discovery.');
    $metadata = json_decode((string) file_get_contents($target . '/modulnest-package.json'), true);
    wiki_export_assert(is_array($metadata)
        && ($metadata['version'] ?? '') === '2.0.0'
        && ($metadata['channel'] ?? '') === 'stable'
        && ($metadata['requires_migrations'] ?? false) === true,
        'The stable package metadata must carry its version, channel, and migration flag.');
    wiki_export_assert(($metadata['required_modules'] ?? []) === ['Admin', 'Auth', 'Modules', 'User', 'Updates'], 'Public stable core boundary is not exact.');
    wiki_export_assert(($metadata['optional_modules'] ?? null) === [], 'Catalog product modules must not be bundled as optional core modules.');
} finally {
    if (is_dir($target)) {
        system('rm -rf ' . escapeshellarg($target));
    }
}
fwrite(STDOUT, "Wiki public export smoke passed.\n");
