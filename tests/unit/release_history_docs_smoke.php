<?php

declare(strict_types=1);

function release_docs_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__, 2);
$versions = [
    '0.5.0' => '08.05.2026', '0.5.1' => '08.05.2026',
    '0.6.0' => '08.05.2026', '0.6.1' => '08.05.2026',
    '0.7.0' => '12.05.2026', '0.7.1' => '12.05.2026',
    '0.7.2' => '12.05.2026', '0.7.3' => '12.05.2026',
    '0.7.4' => '13.05.2026', '0.7.5' => '13.05.2026',
    '0.8.0' => '15.05.2026', '0.8.1' => '19.05.2026',
    '0.8.2' => '20.05.2026', '0.8.3' => '20.05.2026',
    '0.9.0' => '21.05.2026', '1.0.0' => '02.09.2026', '1.0.1' => '02.09.2026',
    '1.1.0' => '03.09.2026', '1.1.1' => '03.09.2026', '1.2.0' => '04.09.2026',
];
$overview = (string) file_get_contents($root . '/docs/releases/README.md');
release_docs_assert(str_contains($overview, '# ModulNest Releases'), 'Release overview title must be stable.');
foreach ($versions as $version => $date) {
    $path = $root . '/docs/releases/' . $version . '.md';
    release_docs_assert(is_file($path), "Missing release history page {$version}.");
    $page = (string) file_get_contents($path);
    release_docs_assert(str_contains($page, $date) && str_contains($page, '/tag/v' . $version), "Release page {$version} must contain its verified date and GitHub tag link.");
    release_docs_assert(str_contains($overview, '](' . $version . '.md)'), "Release overview must link {$version}.");
}
release_docs_assert(!is_file($root . '/docs/release-notes-1.0.0.md') && !is_file($root . '/docs/release-notes-1.0.1.md'), 'Release notes must no longer be loose files in docs root.');
fwrite(STDOUT, "Release history documentation smoke passed.\n");
