<?php

declare(strict_types=1);

use Modulon\Modules\Updates\UpdatesService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function runtimeRefreshAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Updates/UpdatesService.php');
runtimeRefreshAssert(str_contains($source, 'opcache_invalidate'), 'Der Updater muss ausgetauschte PHP-Dateien im OPcache invalidieren.');
runtimeRefreshAssert(str_contains($source, 'refreshPhpRuntime'), 'Der Updater muss den PHP-Runtime-Refresh zentral ausführen.');

$base = sys_get_temp_dir() . '/modulon-runtime-refresh-' . bin2hex(random_bytes(6));
if (!mkdir($base, 0775, true) && !is_dir($base)) {
    throw new RuntimeException('Temporäres Testverzeichnis konnte nicht erstellt werden.');
}

try {
    $fixture = $base . '/updated.php';
    $child = $base . '/opcache-check.php';
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    file_put_contents($child, <<<'PHP'
<?php
declare(strict_types=1);

require $argv[1];

use Modulon\Modules\Updates\UpdatesService;

if (!function_exists('opcache_invalidate') || !function_exists('opcache_compile_file')) {
    fwrite(STDERR, "OPcache APIs are unavailable.\n");
    exit(2);
}

$fixture = $argv[2];
file_put_contents($fixture, '<?php return "old";');
if (!@opcache_compile_file($fixture)) {
    fwrite(STDERR, "Could not prime OPcache.\n");
    exit(3);
}
if (include $fixture !== 'old') {
    fwrite(STDERR, "Old fixture was not loaded.\n");
    exit(4);
}

file_put_contents($fixture, '<?php return "new";');
clearstatcache(true, $fixture);

$service = new UpdatesService(dirname($fixture));
$method = new ReflectionMethod($service, 'refreshPhpRuntime');
$result = $method->invoke($service, [$fixture]);
if (!is_array($result) || ($result['available'] ?? false) !== true || (int) ($result['invalidated'] ?? 0) !== 1) {
    fwrite(STDERR, "Updated PHP file was not invalidated.\n");
    exit(5);
}
if (include $fixture !== 'new') {
    fwrite(STDERR, "Stale OPcache bytecode remained after invalidation.\n");
    exit(6);
}
PHP
    );

    $command = escapeshellarg(PHP_BINARY)
        . ' -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d opcache.revalidate_freq=60 '
        . escapeshellarg($child) . ' ' . escapeshellarg($autoload) . ' ' . escapeshellarg($fixture) . ' 2>&1';
    exec($command, $output, $exitCode);
    runtimeRefreshAssert($exitCode === 0, 'OPcache-Regression fehlgeschlagen: ' . implode("\n", $output));
} finally {
    foreach (glob($base . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($base);
}

echo "Updater runtime refresh smoke test passed.\n";
