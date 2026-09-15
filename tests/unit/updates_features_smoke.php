<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;
use Modulon\Modules\Updates\UpdatesController;
use Modulon\Modules\Updates\UpdatesModule;
use Modulon\Modules\Updates\UpdatesService;

function upd_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('upd-smoke-' . bin2hex(random_bytes(4)));
$session = new Session();

$tmpDir = sys_get_temp_dir() . '/modulnest_upd_smoke_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE dummy (id INTEGER PRIMARY KEY, title TEXT)');
$pdo->exec('INSERT INTO dummy (title) VALUES ("Testdaten")');

try {
    $feedFetcher = static function (string $url): string {
        return json_encode([
            'latest' => '2.2.0',
            'channel' => 'stable',
            'packages' => [
                'bundled' => [
                    'url' => 'https://example.invalid/bundled.zip',
                    'sha256' => str_repeat('b', 64),
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    };

    $updatesService = new UpdatesService($tmpDir, $pdo, $feedFetcher);

    $controller = new UpdatesController(
        $updatesService,
        $session,
        '2.1.5',
        'stable',
        null,
        null,
        null
    );

    // 1. Test notificationStatus endpoint
    $reqStatus = new Request('GET', '/admin/api/updates/status', [], [], []);
    $resStatus = $controller->notificationStatus($reqStatus);
    ob_start();
    $resStatus->send();
    $statusContent = (string) ob_get_clean();
    $data = json_decode($statusContent, true);
    upd_test_assert(is_array($data), 'notificationStatus Rückgabe ist kein JSON.');
    upd_test_assert($data['has_updates'] === true, 'has_updates sollte true sein.');
    upd_test_assert($data['core_update_available'] === true, 'core_update_available sollte true sein.');
    upd_test_assert($data['core_latest_version'] === '2.2.0', 'core_latest_version sollte 2.2.0 sein.');

    // 2. Test downloadDatabaseBackup endpoint (live dump)
    $reqBackup = new Request('POST', '/admin/updates/backup-db', [], [], []);
    $resBackup = $controller->downloadDatabaseBackup($reqBackup);
    ob_start();
    $resBackup->send();
    $zipContent = (string) ob_get_clean();
    upd_test_assert(strlen($zipContent) > 50, 'ZIP-Content ist leer.');
    $tempZipFile = tempnam(sys_get_temp_dir(), 'zip_test_');
    file_put_contents($tempZipFile, $zipContent);
    $zip = new ZipArchive();
    upd_test_assert($zip->open($tempZipFile) === true, 'Heruntergeladenes ZIP lässt sich nicht öffnen.');
    $sql = $zip->getFromIndex(0);
    $zip->close();
    @unlink($tempZipFile);
    upd_test_assert(str_contains((string)$sql, 'Testdaten'), 'Dump im ZIP enthält keine Testdaten.');

    // 3. Test backupsOverview and historical backup discovery
    $fakeBackupDir = $tmpDir . '/storage/backups/updates/20260914_120000_2.1.3';
    mkdir($fakeBackupDir, 0775, true);
    file_put_contents($fakeBackupDir . '/old_file.php', '<?php echo "old";');

    // Create a fake database-backup.zip inside it
    $fakeDbZip = new ZipArchive();
    $fakeDbZip->open($fakeBackupDir . '/database-backup.zip', ZipArchive::CREATE);
    $fakeDbZip->addFromString('database.sql', '-- Historical SQL');
    $fakeDbZip->close();

    $overview = $updatesService->backupsOverview();
    upd_test_assert($overview['backups_count'] === 1, 'backups_count sollte 1 sein.');
    upd_test_assert($overview['total_size'] > 0, 'total_size sollte > 0 sein.');
    upd_test_assert(isset($overview['items'][0]), 'items[0] fehlt.');
    $item = $overview['items'][0];
    upd_test_assert($item['id'] === '20260914_120000_2.1.3', 'Backup ID stimmt nicht.');
    upd_test_assert($item['version'] === '2.1.3', 'Backup Version stimmt nicht.');
    upd_test_assert($item['has_database_backup'] === true, 'has_database_backup sollte true sein.');
    upd_test_assert($item['database_backup_size'] > 0, 'database_backup_size sollte > 0 sein.');

    // 4. Test downloadDatabaseBackup with historical id
    $reqHist = new Request('GET', '/admin/updates/backup-db', [], ['id' => '20260914_120000_2.1.3'], []);
    $resHist = $controller->downloadDatabaseBackup($reqHist);
    ob_start();
    $resHist->send();
    $histContent = (string) ob_get_clean();
    upd_test_assert(str_contains($histContent, 'Historical SQL'), 'Historischer SQL-Dump konnte nicht heruntergeladen werden.');

    // 5. Test deleteBackup
    $reqDelete = new Request('POST', '/admin/updates/backups/delete', ['id' => '20260914_120000_2.1.3'], [], []);
    $resDelete = $controller->deleteBackup($reqDelete);
    upd_test_assert(!is_dir($fakeBackupDir), 'Backup-Verzeichnis wurde nicht gelöscht.');
    $overviewAfter = $updatesService->backupsOverview();
    upd_test_assert($overviewAfter['backups_count'] === 0, 'backups_count nach Löschen sollte 0 sein.');

    // 6. Test route registration in UpdatesModule
    $router = new Router();
    $module = new UpdatesModule($controller, ['key' => 'updates', 'handler' => 'native']);
    $module->registerAdminRoutes($router);

    $dispatchGet = $router->dispatch(new Request('GET', '/admin/updates/backup-db', [], [], []));
    upd_test_assert($dispatchGet !== null, 'Route GET /admin/updates/backup-db konnte nicht aufgelöst werden.');
    $dispatchStatus = $router->dispatch(new Request('GET', '/admin/api/updates/status', [], [], []));
    upd_test_assert($dispatchStatus !== null, 'Route GET /admin/api/updates/status konnte nicht aufgelöst werden.');
    $dispatchDelete = $router->dispatch(new Request('POST', '/admin/updates/backups/delete', [], [], []));
    upd_test_assert($dispatchDelete !== null, 'Route POST /admin/updates/backups/delete konnte nicht aufgelöst werden.');

} finally {
    if (is_dir($tmpDir)) {
        @unlink($tmpDir . '/storage/updates/notification_cache.json');
        @rmdir($tmpDir . '/storage/updates');
        @rmdir($tmpDir . '/storage');
        @rmdir($tmpDir);
    }
}

fwrite(STDOUT, "updates_features_smoke passed.\n");
