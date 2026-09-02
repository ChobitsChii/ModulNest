<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Core\RecoveryManager;

$base = sys_get_temp_dir() . '/modulon-recovery-' . bin2hex(random_bytes(4));
mkdir($base . '/storage', 0775, true);
$recovery = new RecoveryManager($base);
$state = $recovery->requireRecovery([
    'source' => 'migration', 'phase' => 'migration_verification', 'error_code' => 'migration_checksum_mismatch',
    'migration_key' => '20260521_000100_pages_schema', 'migrations_started' => true,
    'operator_hint' => 'Geschützte Prüfung erforderlich.', 'password' => 'must-not-appear',
]);
$recovery->appendLog('test', ['password' => 'must-not-appear']);
if (!str_starts_with((string) $state['recovery_id'], 'rec_') || !is_file($base . '/storage/logs/recovery-' . gmdate('Y-m-d') . '.log')) { throw new RuntimeException('Recovery-State oder -Log fehlt.'); }
$log = (string) file_get_contents($base . '/storage/logs/recovery-' . gmdate('Y-m-d') . '.log');
if (str_contains($log, 'must-not-appear') || !str_contains($log, '[redacted]')) { throw new RuntimeException('Recovery-Log redigiert sensible Werte nicht.'); }
$index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php'); $entry = (string) file_get_contents(dirname(__DIR__, 2) . '/recovery.php'); $service = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/RecoveryMigrationService.php');
foreach (['$requestPath === \'/recovery\'', 'require $basePath . \'/recovery.php\''] as $needle) { if (!str_contains($index, $needle)) { throw new RuntimeException('Recovery-Einstieg ist nicht vor Bootstrap verfügbar.'); } }
foreach (['AuthRateLimiter', 'CsrfTokenManager', 'SAFE_AUTOMATIC_REPAIR', 'METADATA_ONLY_REPAIR', 'MANUAL_RECOVERY_REQUIRED', 'backupDatabase'] as $needle) { if (!str_contains($entry . $service, $needle)) { throw new RuntimeException('Recovery-Sicherheitsvertrag fehlt: ' . $needle); } }
$recovery->resolve();
if (is_file($base . '/storage/maintenance.flag') || $recovery->current() !== null) { throw new RuntimeException('Recovery wurde nicht sauber aufgelöst.'); }
@unlink($base . '/storage/logs/recovery-' . gmdate('Y-m-d') . '.log'); @unlink($base . '/storage/runtime/log-rotation.lock'); @rmdir($base . '/storage/runtime'); @rmdir($base . '/storage/recovery'); @rmdir($base . '/storage/logs'); @rmdir($base . '/storage'); @rmdir($base);
echo "Recovery smoke tests passed\n";
