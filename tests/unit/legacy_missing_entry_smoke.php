<?php

declare(strict_types=1);

use Modulon\Core\Env;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\RotatingFileLogger;
use Modulon\Core\Router;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Modules\ModuleRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function legacy_missing_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$basePath = dirname(__DIR__, 2);
Env::load($basePath . '/.env');

// 1. Berechtigungs-Test in isoliertem Verzeichnis (CLI -> Webserver-kompatible 0666 Rechte)
$tempDir = sys_get_temp_dir() . '/modulon-logger-perm-test-' . bin2hex(random_bytes(4));
mkdir($tempDir . '/storage/logs', 0777, true);
try {
    $testLogger = new RotatingFileLogger($tempDir);
    $testLogger->write('perm-test', ['event' => 'perm_check']);
    $testLogFile = $testLogger->path('perm-test');
    legacy_missing_assert(is_file($testLogFile), 'Test-Logdatei wurde nicht erstellt.');
    $perms = fileperms($testLogFile) & 0777;
    legacy_missing_assert($perms === 0666, 'Neu erstellte Logdatei muss 0666 Rechte haben (hat ' . decoct($perms) . ').');

    $lockFile = $tempDir . '/storage/runtime/log-rotation.lock';
    if (is_file($lockFile)) {
        $lockPerms = fileperms($lockFile) & 0777;
        legacy_missing_assert($lockPerms === 0666, 'Lockdatei muss 0666 Rechte haben (hat ' . decoct($lockPerms) . ').');
    }
} finally {
    foreach (glob($tempDir . '/storage/logs/*') ?: [] as $f) { @unlink($f); }
    foreach (glob($tempDir . '/storage/runtime/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tempDir . '/storage/logs');
    @rmdir($tempDir . '/storage/runtime');
    @rmdir($tempDir . '/storage');
    @rmdir($tempDir);
}

// 2. Integrationstest für Legacy-Dispatcher mit fehlendem Einstiegspunkt
$logger = new RotatingFileLogger($basePath);
$logPath = $logger->path('modulon');
$initialLogContent = is_file($logPath) ? (string) file_get_contents($logPath) : '';
$initialLogLines = $initialLogContent !== '' ? explode("\n", trim($initialLogContent)) : [];

$dummyPrefix = 'dummy-missing-legacy-' . bin2hex(random_bytes(3));
$dummyName = 'Dummy Missing Module';
$dummyEntry = 'missing-app/index.php';
$dummyModuleId = 99999;
$basePathPrefix = '/' . $dummyPrefix;

// Legacy-Dispatcher-Logik exakt wie in app/bootstrap.php
$legacyDispatcher = function (Request $request) use ($dummyName, $dummyPrefix, $dummyEntry, $basePath, $basePathPrefix, $dummyModuleId): Response {
    $legacyRoot = realpath($basePath . '/app/Legacy');
    $legacyFile = $legacyRoot !== false ? realpath($legacyRoot . '/' . $dummyEntry) : false;

    if (!is_string($legacyRoot) || !is_string($legacyFile) || !is_file($legacyFile)) {
        (new RotatingFileLogger($basePath))->write('modulon', [
            'timestamp' => date('c'),
            'env' => (string) Env::get('APP_ENV', 'production'),
            'debug' => Env::getBool('APP_DEBUG', false),
            'type' => 'legacy_module_missing_entry',
            'message' => "Legacy-Modul '{$dummyName}' ({$dummyPrefix}) ist nicht verfuegbar: Einstiegspunkt '{$dummyEntry}' wurde nicht gefunden.",
            'file' => $legacyFile !== false ? $legacyFile : ($basePath . '/app/Legacy/' . $dummyEntry),
            'line' => 0,
            'method' => $request->method(),
            'uri' => $request->path(),
            'module_id' => $dummyModuleId,
            'module_name' => $dummyName,
            'route_prefix' => $dummyPrefix,
            'legacy_entry' => $dummyEntry,
        ]);

        return new Response(View::render('errors/500', [
            'title' => 'Legacy Modul Fehler',
            'current_path' => $request->path(),
        ]), 500);
    }

    return new Response('ok');
};

$router = new Router();
$router->get($basePathPrefix . '/*', $legacyDispatcher, 'public');

$request = new Request('GET', '/' . $dummyPrefix . '/', [], [], []);
$response = $router->dispatch($request);

ob_start();
$response->send();
$body = (string) ob_get_clean();
$status = http_response_code();

legacy_missing_assert($status === 500, 'Legacy-Dispatcher muss HTTP 500 liefern, wenn Entry-Point fehlt (erhalten: ' . $status . ').');
legacy_missing_assert(str_contains($body, '500') || str_contains($body, 'Legacy Modul Fehler'), '500-Fehlerseite wurde nicht gerendert.');

// Logeintrag verifizieren
$afterLogContent = is_file($logPath) ? (string) file_get_contents($logPath) : '';
$afterLogLines = $afterLogContent !== '' ? explode("\n", trim($afterLogContent)) : [];
legacy_missing_assert(count($afterLogLines) > count($initialLogLines), 'Es wurde kein neuer Logeintrag erzeugt.');

$foundLogEntry = false;
foreach ($afterLogLines as $line) {
    $data = json_decode($line, true);
    if (is_array($data) && ($data['type'] ?? '') === 'legacy_module_missing_entry' && ($data['route_prefix'] ?? '') === $dummyPrefix) {
        $foundLogEntry = true;
        legacy_missing_assert(str_contains((string) ($data['message'] ?? ''), $dummyEntry), 'Logeintrag enthaelt nicht den erwarteten Dateipfad.');
        break;
    }
}
legacy_missing_assert($foundLogEntry, 'Kein passender legacy_module_missing_entry Logeintrag gefunden.');

// Bootstrap Quellcode auf Logging-Aufruf prüfen
$bootstrapCode = (string) file_get_contents($basePath . '/app/bootstrap.php');
legacy_missing_assert(
    str_contains($bootstrapCode, "'legacy_module_missing_entry'"),
    'app/bootstrap.php enthaelt den legacy_module_missing_entry Logaufruf nicht.'
);
legacy_missing_assert(
    str_contains($bootstrapCode, 'new RotatingFileLogger($basePath)'),
    'app/bootstrap.php instanziiert den zentralen RotatingFileLogger nicht.'
);

fwrite(STDOUT, "Legacy missing entry smoke test passed.\n");
