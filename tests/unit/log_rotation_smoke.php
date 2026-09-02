<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Modulon\Core\RotatingFileLogger;
use Modulon\Modules\Logs\LogsController;

$base = sys_get_temp_dir() . '/modulon-logs-' . bin2hex(random_bytes(4));
mkdir($base . '/storage/logs', 0775, true);
$now = new DateTimeImmutable('2026-09-02 12:00:00', new DateTimeZone('UTC'));
$logger = new RotatingFileLogger($base, static fn (): DateTimeImmutable => $now);
if (!$logger->write('auth-login', ['event' => 'login', 'password' => 'hidden'])) { throw new RuntimeException('Tageslog konnte nicht geschrieben werden.'); }
$today = $base . '/storage/logs/auth-login-2026-09-02.log';
if (!is_file($today) || str_contains((string) file_get_contents($today), 'hidden')) { throw new RuntimeException('Tagesname oder Redaction fehlerhaft.'); }
foreach (['2026-08-29','2026-08-28','2026-07-01'] as $date) { file_put_contents($base . '/storage/logs/test-' . $date . '.log', "entry\n"); }
putenv('LOG_COMPRESS_AFTER_DAYS=3'); putenv('LOG_RETENTION_DAYS=30');
@unlink($base . '/storage/runtime/log-rotation.lock');
$logger->rotateIfDue();
if (!is_file($base . '/storage/logs/test-2026-08-29.log.gz') || is_file($base . '/storage/logs/test-2026-08-29.log')) { throw new RuntimeException('Altes Log wurde nicht einzeln gzip-komprimiert.'); }
if (gzdecode((string) file_get_contents($base . '/storage/logs/test-2026-08-29.log.gz')) !== "entry\n") { throw new RuntimeException('Gzip-Inhalt fehlerhaft.'); }
if (is_file($base . '/storage/logs/test-2026-07-01.log') || is_file($base . '/storage/logs/test-2026-07-01.log.gz')) { throw new RuntimeException('Retention hat alte Datei nicht entfernt.'); }
if (!is_file($today)) { throw new RuntimeException('Aktives Tageslog wurde verändert.'); }
putenv('LOG_COMPRESS_AFTER_DAYS=invalid'); putenv('LOG_RETENTION_DAYS=invalid'); $logger->rotateIfDue();
$logsController = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Logs/LogsController.php');
if (!str_contains($logsController, "'/*.log.gz'") || !str_contains($logsController, 'gzopen')) { throw new RuntimeException('Admin-Leser unterstützt gzip nicht.'); }
$controller = new LogsController($base);
$method = new ReflectionMethod($controller, 'formatLine');
$recovery = $method->invoke($controller, json_encode(['at' => '2026-09-01T22:46:33+00:00', 'event' => 'recovery_resolved']), new DateTimeZone('Europe/Berlin'));
$regular = $method->invoke($controller, json_encode(['timestamp' => '2026-09-01T22:46:33+00:00', 'event' => 'auth_login']), new DateTimeZone('Europe/Berlin'));
if (($recovery['timestamp_local'] ?? '') !== ($regular['timestamp_local'] ?? '') || ($recovery['timestamp_local'] ?? '') === '') { throw new RuntimeException('Recovery-Log verwendet nicht dieselbe Zeitzonenformatierung.'); }
foreach (glob($base . '/storage/logs/*') ?: [] as $file) { @unlink($file); } @unlink($base . '/storage/runtime/log-rotation.lock'); @rmdir($base . '/storage/runtime'); @rmdir($base . '/storage/logs'); @rmdir($base . '/storage'); @rmdir($base);
echo "Log rotation smoke tests passed\n";
