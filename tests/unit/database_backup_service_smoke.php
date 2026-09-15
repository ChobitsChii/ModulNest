<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Core\Database\DatabaseBackupService;

function backup_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
$pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');

$pdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)')->execute(['Alice', 'alice@example.invalid']);
$pdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)')->execute(['Bob', 'bob@example.invalid']);
$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['site_name', 'ModulNest Test']);

$tmpDir = sys_get_temp_dir() . '/modulnest_backup_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);

try {
    $service = new DatabaseBackupService($pdo, $tmpDir);

    // 1. Test dump to stream
    $streamHandle = fopen('php://temp', 'wb+');
    $service->dumpToStream($streamHandle);
    rewind($streamHandle);
    $sqlContent = stream_get_contents($streamHandle);
    fclose($streamHandle);

    backup_test_assert(str_contains($sqlContent, 'CREATE TABLE users'), 'SQL enthält users CREATE nicht.');
    backup_test_assert(str_contains($sqlContent, 'Alice'), 'SQL enthält Testdaten Alice nicht.');
    backup_test_assert(str_contains($sqlContent, 'ModulNest Test'), 'SQL enthält Settings-Daten nicht.');

    // 2. Test dumpToZipFile
    $zipPath = $tmpDir . '/backup.zip';
    $resPath = $service->dumpToZipFile($zipPath, 'custom_dump.sql');
    backup_test_assert(is_file($zipPath) && $resPath === $zipPath, 'ZIP-Datei wurde nicht erzeugt.');
    backup_test_assert(filesize($zipPath) > 50, 'ZIP-Datei ist leer.');

    $zip = new ZipArchive();
    backup_test_assert($zip->open($zipPath) === true, 'ZIP-Datei lässt sich nicht öffnen.');
    backup_test_assert($zip->locateName('custom_dump.sql') !== false, 'custom_dump.sql fehlt im ZIP.');
    $zippedSql = $zip->getFromName('custom_dump.sql');
    $zip->close();

    backup_test_assert(is_string($zippedSql) && str_contains($zippedSql, 'Bob'), 'Entpackter SQL-Dump im ZIP enthält Testdaten nicht.');

    // 3. Test dumpToTempZip
    $tempZip = $service->dumpToTempZip();
    backup_test_assert(is_file($tempZip), 'dumpToTempZip erzeugte keine Datei.');
    $zip2 = new ZipArchive();
    backup_test_assert($zip2->open($tempZip) === true, 'Temp-ZIP lässt sich nicht öffnen.');
    $zip2->close();
    @unlink($tempZip);

} finally {
    if (is_file($tmpDir . '/backup.zip')) {
        @unlink($tmpDir . '/backup.zip');
    }
    @rmdir($tmpDir);
}

fwrite(STDOUT, "DatabaseBackupService smoke test passed.\n");
