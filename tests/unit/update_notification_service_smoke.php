<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Modules\Updates\UpdateNotificationService;
use Modulon\Modules\Updates\UpdatesService;
use Modulon\Core\Modules\Catalog\CatalogService;

function notify_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$tmpDir = sys_get_temp_dir() . '/modulnest_notify_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);

try {
    // Mock UpdatesService using closure feed fetcher
    $feedFetcher = static function (string $url): string {
        return json_encode([
            'latest' => '2.5.0',
            'channel' => 'stable',
            'packages' => [
                'bundled' => [
                    'url' => 'https://example.invalid/bundled.zip',
                    'sha256' => str_repeat('a', 64),
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    };

    $updatesService = new UpdatesService($tmpDir, null, $feedFetcher);

    // Create UpdateNotificationService with 1.0.0 installed version
    $service = new UpdateNotificationService(
        $updatesService,
        null,
        '1.0.0',
        $tmpDir . '/cache',
        3600
    );

    $result = $service->check(true);

    notify_test_assert($result['has_updates'] === true, 'has_updates sollte true sein.');
    notify_test_assert($result['core_update_available'] === true, 'core_update_available sollte true sein.');
    notify_test_assert($result['core_latest_version'] === '2.5.0', 'core_latest_version sollte 2.5.0 sein.');
    notify_test_assert(is_file($tmpDir . '/cache/notification_cache.json'), 'Cache-Datei wurde nicht geschrieben.');

    // Test cache hit: now change installed version on service instance, but check without force
    $service2 = new UpdateNotificationService(
        $updatesService,
        null,
        '2.5.0',
        $tmpDir . '/cache',
        3600
    );
    $cachedResult = $service2->check(false);
    notify_test_assert($cachedResult['core_current_version'] === '1.0.0', 'Cache wurde nicht verwendet.');

} finally {
    if (is_dir($tmpDir)) {
        @unlink($tmpDir . '/cache/notification_cache.json');
        @rmdir($tmpDir . '/cache');
        @rmdir($tmpDir . '/storage/updates');
        @rmdir($tmpDir . '/storage');
        @rmdir($tmpDir);
    }
}

fwrite(STDOUT, "UpdateNotificationService smoke test passed.\n");
