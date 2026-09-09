<?php

declare(strict_types=1);

use Modulon\Modules\Updates\UpdatesService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function distribution_source_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function distribution_source_env(string $key, ?string $value): void
{
    unset($_ENV[$key]);
    if ($value === null) {
        putenv($key);
        return;
    }
    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
}

$keys = ['APP_ENV', 'MODULE_CATALOG_SOURCE_URL', 'MODULE_CATALOG_SOURCE_PATH'];
$saved = [];
foreach ($keys as $key) {
    $value = getenv($key);
    $saved[$key] = $value === false ? null : $value;
}

try {
    distribution_source_env('APP_ENV', 'production');
    distribution_source_env('MODULE_CATALOG_SOURCE_PATH', null);
    distribution_source_env('MODULE_CATALOG_SOURCE_URL', null);
    $default = require dirname(__DIR__, 2) . '/app/Config/module_catalog.php';
    distribution_source_assert($default['source_url'] === 'https://repo.modulnest.de', 'Neue offizielle Katalogquelle ist nicht der Production-Default.');

    distribution_source_env('MODULE_CATALOG_SOURCE_URL', 'https://raw.githubusercontent.com/ChobitsChii/ModulNest-Modules/main/');
    $legacy = require dirname(__DIR__, 2) . '/app/Config/module_catalog.php';
    distribution_source_assert($legacy['source_url'] === 'https://repo.modulnest.de', 'Bisherige offizielle GitHub-Quelle wird nicht migriert.');

    distribution_source_env('MODULE_CATALOG_SOURCE_URL', 'https://modules.example.test/custom');
    $custom = require dirname(__DIR__, 2) . '/app/Config/module_catalog.php';
    distribution_source_assert($custom['source_url'] === 'https://modules.example.test/custom', 'Benutzerdefinierte Katalogquelle wurde überschrieben.');

    distribution_source_assert(UpdatesService::UPDATE_FEED_URL === 'https://updates.modulnest.de/core/stable.json', 'Stable-Feed nutzt nicht den offiziellen Endpoint.');
    distribution_source_assert(UpdatesService::PRERELEASE_FEED_URL === 'https://updates.modulnest.de/core/prerelease.json', 'Preview-Feed nutzt nicht den offiziellen Endpoint.');
} finally {
    foreach ($saved as $key => $value) {
        distribution_source_env($key, $value);
    }
}

fwrite(STDOUT, "Distribution sources smoke passed.\n");
