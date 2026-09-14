<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$tmpDir = sys_get_temp_dir() . '/modulon-updates-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir . '/storage/updates', 0775, true);

$service = new \Modulon\Modules\Updates\UpdatesService($tmpDir);

// 1. Default sources
$sources = $service->getUpdateSources();
assert(count($sources) === 1, 'Should have 1 default official source');
assert($sources[0]['id'] === 'official', 'Default source id should be official');
assert($sources[0]['is_active'] === true, 'Default source should be active');

// 2. Add custom source
$added = $service->addUpdateSource('Mein Test Mirror', 'https://mirror.example.org/core');
assert($added['name'] === 'Mein Test Mirror', 'Name matches');
assert($added['base_url'] === 'https://mirror.example.org/core', 'Base URL matches');

$sources2 = $service->getUpdateSources();
assert(count($sources2) === 2, 'Should now have 2 sources');

// 3. Switch active source
$service->setActiveUpdateSource($added['id']);
$active = $service->getActiveUpdateSource();
assert($active['id'] === $added['id'], 'Active source changed to custom');
assert($active['base_url'] === 'https://mirror.example.org/core', 'Active base URL matches');

// 4. Test fetchMetadata using custom source
$customFetcher = static function (string $url): string {
    if (str_ends_with($url, 'stable.json')) {
        return json_encode([
            'latest' => '3.0.0',
            'channel' => 'stable',
            'packages' => [
                'bundled' => [
                    'url' => 'https://mirror.example.org/core/releases/3.0.0/modulnest-bundled-3.0.0.zip',
                    'sha256' => str_repeat('a', 64),
                ],
            ],
        ]);
    }
    throw new RuntimeException('404 Not Found: ' . $url);
};

$serviceWithFetcher = new \Modulon\Modules\Updates\UpdatesService($tmpDir, null, $customFetcher);
$meta = $serviceWithFetcher->fetchMetadata('stable');
assert($meta['latest'] === '3.0.0', 'Latest version should be 3.0.0 from custom mirror');
assert($meta['_feed_url'] === 'https://mirror.example.org/core/stable.json', 'Feed URL matches active source');

// 5. Delete custom source
$service->deleteUpdateSource($added['id']);
$sources3 = $service->getUpdateSources();
assert(count($sources3) === 1, 'Deleted custom source, back to 1');
assert($service->getActiveUpdateSource()['id'] === 'official', 'Active source reverted to official');

// Cleanup
@unlink($tmpDir . '/storage/updates/sources.json');
@rmdir($tmpDir . '/storage/updates');
@rmdir($tmpDir . '/storage');
@rmdir($tmpDir);

echo "Update sources smoke passed.\n";
