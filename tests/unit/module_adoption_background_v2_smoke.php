<?php

declare(strict_types=1);

use Modulon\Core\Modules\ModuleAdoptionOperationService;
use Modulon\Core\Modules\ModulePackageInspector;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function adoption_background_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$temporary = sys_get_temp_dir() . '/modulnest-adoption-background-' . bin2hex(random_bytes(6));
mkdir($temporary . '/bin', 0775, true);
file_put_contents($temporary . '/bin/module-adoption-worker.php', "<?php sleep(2);\n");
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE module_operations (operation_id TEXT PRIMARY KEY,module_id TEXT,operation_type TEXT,phase TEXT,status TEXT,error_message TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE modules (id INTEGER PRIMARY KEY,module_key TEXT,route_prefix TEXT,is_active INTEGER)');
$pdo->exec("INSERT INTO modules VALUES(1,NULL,'tools',1)");
$pdo->exec('CREATE TABLE module_installations (module_id TEXT,module_row_id INTEGER,installed_version TEXT)');
$pdo->exec('CREATE TABLE module_data_resources (module_id TEXT)');
$pdo->exec('CREATE TABLE module_releases (module_id TEXT)');

try {
    $service = new ModuleAdoptionOperationService($pdo, $temporary);
    $startedAt = microtime(true);
    $operation = $service->start('modulnest.tools');
    adoption_background_assert(microtime(true) - $startedAt < 1.5, 'HTTP-Start wartet auf den Hintergrundjob.');
    adoption_background_assert(($operation['status'] ?? '') === 'running', 'Gestartete Adoption fehlt im Journal.');
    adoption_background_assert((int) ($operation['pid'] ?? 0) > 1, 'Worker-PID fehlt im persistenten Status.');
    adoption_background_assert(is_file($temporary . '/storage/module-operations/adoptions/' . $operation['operation_id'] . '.json'), 'Reload-fester Status wurde nicht geschrieben.');
    try {
        $service->start('modulnest.tools');
        adoption_background_assert(false, 'Parallele Tools-Adoption wurde trotz aktivem Worker gestartet.');
    } catch (RuntimeException $error) {
        adoption_background_assert(str_contains($error->getMessage(), 'läuft bereits'), 'Parallele Adoption liefert keinen verständlichen Lockstatus.');
    }
    $log = (string) file_get_contents($temporary . '/storage/logs/module-lifecycle-' . gmdate('Y-m-d') . '.log');
    adoption_background_assert(str_contains($log, '"module_id":"modulnest.tools"') && str_contains($log, '"operation":"adoption"') && str_contains($log, '"started_at":'), 'Strukturiertes Lifecycle-Logging fehlt.');
    sleep(3);
    $recovered = $service->latest('modulnest.tools');
    adoption_background_assert(is_array($recovered) && $recovered['status'] === 'failed' && ($recovered['error_code'] ?? '') === 'worker_interrupted', 'Abgebrochener Worker wurde nicht sicher als wiederholbar recovered.');
} finally {
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Background module adoption smoke passed.\n");
