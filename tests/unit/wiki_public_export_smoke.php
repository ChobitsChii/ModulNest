<?php

declare(strict_types=1);

function wiki_export_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__, 2);
$target = sys_get_temp_dir() . '/modulnest-wiki-export-' . bin2hex(random_bytes(5));
$command = 'cd ' . escapeshellarg($root) . ' && bash tools/release/export-modulnest.sh --target ' . escapeshellarg($target) . ' --no-ui --yes --requires-migrations true 2>&1';
exec($command, $output, $status);
try {
    wiki_export_assert($status === 0, 'The public export must succeed for the Wiki documentation resources.');
    foreach ([
        'docs/README.md',
        'docs/development/README.md',
        'docs/development/example-module.md',
        'docs/releases/README.md',
        'docs/releases/1.0.0.md',
        'docs/releases/1.0.1.md',
        'docs/releases/1.1.0.md',
        'examples/modules/ExampleNotes/README.md',
        'tools/create-module.php',
        'app/Modules/Wiki/WikiNavigationBuilder.php',
        'app/Views/wiki/index.php',
    ] as $path) {
        wiki_export_assert(is_file($target . '/' . $path), "Public export must contain {$path}.");
    }
    wiki_export_assert(!is_dir($target . '/app/Modules/ExampleNotes'), 'ExampleNotes must remain reference code outside productive module discovery.');
    $metadata = json_decode((string) file_get_contents($target . '/modulnest-package.json'), true);
    wiki_export_assert(is_array($metadata) && ($metadata['version'] ?? '') === '1.1.0' && ($metadata['requires_migrations'] ?? false) === true, 'The 1.1.0 public package must require its Wiki migrations.');
} finally {
    if (is_dir($target)) {
        system('rm -rf ' . escapeshellarg($target));
    }
}
fwrite(STDOUT, "Wiki public export smoke passed.\n");
