<?php

declare(strict_types=1);

use Modulon\Core\Modules\ModuleOperationLock;
use Modulon\Core\Modules\ModulePackageInspector;
use ModulNest\Tools\ToolsSpeechService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/modules-src/tools/1.1.0/src/ToolsSpeechService.php';

function tools_v2_worker_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$base = sys_get_temp_dir() . '/modulnest-tools-worker-' . bin2hex(random_bytes(5));
$moduleRoot = $base . '/modules/modulnest.tools/releases/1.0.1-fixture';
$storage = $base . '/storage/modules/modulnest.tools/speech';

try {
    mkdir($moduleRoot, 0775, true);
    mkdir($storage . '/jobs', 0775, true);
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE modules(id INTEGER PRIMARY KEY,is_active INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE module_installations(module_id TEXT,module_row_id INTEGER,installed_version TEXT,active_release_id TEXT)');
    $pdo->exec('CREATE TABLE module_releases(module_id TEXT,release_id TEXT,release_path TEXT)');
    $pdo->exec('INSERT INTO modules VALUES(1,0)');
    $pdo->exec("INSERT INTO module_installations VALUES('modulnest.tools',1,'1.0.1','release-fixture')");
    $statement = $pdo->prepare("INSERT INTO module_releases VALUES('modulnest.tools','release-fixture',?)");
    $statement->execute([$moduleRoot]);

    $jobId = '20260908_130000_abcdef123456';
    $jobPath = $storage . '/jobs/' . $jobId . '.json';
    file_put_contents($jobPath, json_encode(['id' => $jobId, 'status' => 'queued'], JSON_THROW_ON_ERROR));
    $service = new ToolsSpeechService($base, $moduleRoot, $pdo);
    tools_v2_worker_assert($service->processJob($jobId) === 0, 'Deaktiviertes Modul startet einen wartenden Speech-Job.');
    $job = json_decode((string) file_get_contents($jobPath), true, 16, JSON_THROW_ON_ERROR);
    tools_v2_worker_assert(($job['status'] ?? null) === 'queued', 'Disable verändert oder verliert einen wartenden Job.');

    $lockDirectory = $base . '/storage/locks/modules';
    if (!is_dir($lockDirectory)) mkdir($lockDirectory, 0775, true);
    $workerLock = fopen($lockDirectory . '/modulnest.tools.lock', 'c');
    tools_v2_worker_assert(is_resource($workerLock) && flock($workerLock, LOCK_SH | LOCK_NB), 'Worker-Lifecycle-Lock kann nicht gesetzt werden.');
    $operationLock = new ModuleOperationLock($lockDirectory);
    try {
        $operationLock->acquire('modulnest.tools');
        tools_v2_worker_assert(false, 'Update/Uninstall/Purge kann einen laufenden Worker übergehen.');
    } catch (RuntimeException $error) {
        tools_v2_worker_assert(str_contains($error->getMessage(), 'Operation'), 'Lock-Konflikt meldet keinen Lifecycle-Hinweis.');
    }
    flock($workerLock, LOCK_UN);
    fclose($workerLock);

    tools_v2_worker_assert(is_dir($storage . '/uploads') && is_dir($storage . '/wav'), 'Jobgebundene Runtime-Verzeichnisse fehlen im Modulstorage.');
    tools_v2_worker_assert(is_dir($base . '/storage/cache/modules/modulnest.tools/speech'), 'Temporärer Worker-Lockbereich ist nicht vom persistenten Storage getrennt.');

    $workerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/modules-src/tools/1.1.0/bin/tools-speech-worker.php');
    tools_v2_worker_assert(str_contains($workerSource, "require \$moduleRoot . '/src/ToolsSpeechService.php'"), 'Worker lädt nicht die aktive paketierte Speech-Implementierung.');
    tools_v2_worker_assert(!str_contains($workerSource, 'Modulon\\Modules\\Tools'), 'Worker ist noch an die v1-Codebasis gekoppelt.');
    foreach ([
        dirname(__DIR__, 2) . '/app/Modules/Tools/ToolsSpeechService.php',
        dirname(__DIR__, 2) . '/modules-src/tools/1.1.0/src/ToolsSpeechService.php',
    ] as $speechServicePath) {
        $launchSource = (string) file_get_contents($speechServicePath);
        tools_v2_worker_assert(str_contains($launchSource, "'/usr/bin/php'") && str_contains($launchSource, 'proc_open([$php, $worker,'), 'Speech-Worker verliert PHP-CLI-Fallback oder Argument-Array-Start.');
        tools_v2_worker_assert(str_contains($launchSource, '$pipes, $this->basePath)'), 'Speech-Worker verliert sein definiertes Working Directory.');
        tools_v2_worker_assert(str_contains($launchSource, 'Do not call proc_close()'), 'Speech-Worker verliert sein nicht blockierendes Detach-Verhalten.');
    }
} finally {
    if (is_dir($base)) ModulePackageInspector::removeTree($base);
}

fwrite(STDOUT, "Tools v2 worker lifecycle smoke passed.\n");
