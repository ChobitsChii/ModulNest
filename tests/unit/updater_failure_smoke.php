<?php
declare(strict_types=1);

use Modulon\Modules\Updates\UpdatesService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function ua(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function ur(string $path): void {
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}
$base = sys_get_temp_dir() . '/modulon-updater-' . bin2hex(random_bytes(6));
mkdir($base . '/storage/updates', 0775, true);
try {
    file_put_contents($base . '/storage/updates/state.json', json_encode(['prepared' => ['status' => 'prepared', 'version' => '1.0.1', 'staging_path' => $base . '/missing']]));
    try { (new UpdatesService($base))->install(); } catch (RuntimeException) {}
    ua(!is_file($base . '/storage/maintenance.flag'), 'Vor Mutation darf Maintenance nicht verbleiben.');

    $stage = $base . '/storage/updates/staging/1.0.1'; mkdir($stage, 0775, true); file_put_contents($stage . '/README.md', 'new'); mkdir($base . '/README.md', 0775, true);
    file_put_contents($base . '/storage/updates/state.json', json_encode(['prepared' => ['status' => 'prepared', 'version' => '1.0.1', 'staging_path' => $stage, 'from_version' => '1.0.0', 'requires_migrations' => false]]));
    try { (new UpdatesService($base))->install(); throw new RuntimeException('Fehler nach Mutation fehlt.'); } catch (RuntimeException $e) { ua(str_contains($e->getMessage(), 'Wartungsmodus bleibt aktiv'), 'Recovery-Hinweis fehlt.'); }
    ua(is_file($base . '/storage/maintenance.flag'), 'Maintenance muss nach Mutation aktiv bleiben.');
    $state = json_decode((string) file_get_contents($base . '/storage/updates/state.json'), true);
    ua(is_array($state['recovery_required'] ?? null), 'Recovery-Zustand fehlt.');
    ua(!isset($state['last_install']), 'Fehlgeschlagenes Update darf nicht installiert sein.');
    ua((string) ($state['recovery_required']['backup_path'] ?? '') !== '', 'Backup-Pfad fehlt.');
    $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/app/bootstrap.php');
    ua(str_contains($bootstrap, 'Migration-Recovery erforderlich') && str_contains($bootstrap, 'http_response_code(503)'), 'Bootstrap muss Migrationsfehler fail-closed behandeln.');
} finally { ur($base); }
echo "Updater failure smoke test passed.\n";
