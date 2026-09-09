<?php

declare(strict_types=1);

use Modulon\Core\BackgroundProcessLaunchException;
use Modulon\Core\BackgroundProcessRunner;
use Modulon\Core\Modules\ModulePackageInspector;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function background_runner_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$temporary = sys_get_temp_dir() . '/modulnest-background-runner-' . bin2hex(random_bytes(5));
mkdir($temporary . '/logs', 0775, true);
$worker = $temporary . '/worker.php';
$marker = $temporary . '/status.json';
file_put_contents($worker, <<<'PHP'
<?php
file_put_contents($argv[1], json_encode(['pid' => getmypid(), 'cwd' => getcwd(), 'environment' => getenv('MODULON_BACKGROUND_SMOKE')]));
fwrite(STDOUT, "probe-running\n");
usleep(400000);
PHP);
putenv('MODULON_BACKGROUND_SMOKE=inherited');

try {
    $runner = new BackgroundProcessRunner();
    background_runner_assert($runner->phpCliBinary() === '/usr/bin/php', 'PHP-CLI-Fallback wurde nicht korrekt aufgelöst.');
    $started = microtime(true);
    $launch = $runner->launchPhp($worker, [$marker], $temporary . '/logs/worker.log', $temporary);
    background_runner_assert(microtime(true) - $started < 1.0, 'Launcher wartet auf den Hintergrundworker.');
    for ($attempt = 0; $attempt < 20 && !is_file($marker); $attempt++) usleep(25000);
    $status = json_decode((string) file_get_contents($marker), true, 8, JSON_THROW_ON_ERROR);
    background_runner_assert((int) $status['pid'] === $launch['pid'], 'Launcher-PID und Worker-PID stimmen nicht überein.');
    background_runner_assert($status['cwd'] === $temporary, 'Working Directory wurde nicht übernommen.');
    background_runner_assert($status['environment'] === 'inherited', 'Prozessumgebung wurde nicht geerbt.');
    background_runner_assert(str_contains((string) file_get_contents($temporary . '/logs/worker.log'), 'probe-running'), 'Worker-Logging wurde nicht umgeleitet.');

    $immediate = $temporary . '/immediate.php';
    file_put_contents($immediate, '<?php exit(7);');
    try {
        $runner->launchPhp($immediate, [], $temporary . '/logs/immediate.log', $temporary);
        background_runner_assert(false, 'Sofort beendeter Worker wurde als erfolgreich gestartet gemeldet.');
    } catch (BackgroundProcessLaunchException $error) {
        background_runner_assert($error->reasonCode === 'worker_exited_immediately', 'Sofortiger Worker-Abbruch hat keinen konkreten Fehlercode.');
    }
} finally {
    putenv('MODULON_BACKGROUND_SMOKE');
    usleep(500000);
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}

fwrite(STDOUT, "Background process runner smoke passed.\n");
