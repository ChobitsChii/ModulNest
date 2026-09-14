#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

\Modulon\Core\Env::load(dirname(__DIR__, 2) . '/.env');
$dbConfig = require dirname(__DIR__, 2) . '/app/Config/database.php';
$pdo = \Modulon\Core\Database::connect($dbConfig);

$options = getopt('', ['id:', 'trigger:']);
$id = (int) ($options['id'] ?? 0);
$trigger = (string) ($options['trigger'] ?? 'manual');

if ($id <= 0) {
    fwrite(STDERR, "Fehler: --id=<id> ist erforderlich.\n");
    exit(1);
}

// Autoloader for mirror module
spl_autoload_register(static function (string $class): void {
    $prefix = 'ModulNest\\Mirror\\';
    $baseDir = dirname(__DIR__, 2) . '/modules/modulnest.mirror/releases/0.1.0-beta.1-f7a93c41b802/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

$repo = new \ModulNest\Mirror\Repository\MirrorConfigRepository($pdo);
$syncService = new \ModulNest\Mirror\Service\MirrorSyncService(dirname(__DIR__, 2), $repo);

$result = $syncService->executeSync($id, $trigger);

if (!empty($result['success'])) {
    fwrite(STDOUT, "Mirror-Sync #" . $id . " erfolgreich beendet.\n");
    exit(0);
} else {
    fwrite(STDERR, "Mirror-Sync #" . $id . " fehlgeschlagen: " . ($result['error'] ?? 'Unbekannter Fehler') . "\n");
    exit(1);
}
