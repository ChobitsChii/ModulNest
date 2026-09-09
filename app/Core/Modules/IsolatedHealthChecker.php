<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use RuntimeException;

final readonly class IsolatedHealthChecker
{
    private string $phpCli;

    public function __construct(
        private string $basePath,
        private int $timeoutSeconds = 15,
        ?string $phpCli = null,
    ) {
        $this->phpCli = $this->resolvePhpCli($phpCli);
    }

    /** @return array{ok:bool,message:string} */
    public function check(string $releaseRoot): array
    {
        $command = [$this->phpCli, $this->basePath . '/bin/module-health.php', $releaseRoot];
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->basePath,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Health-Prozess kann nicht gestartet werden.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $output = '';
        $errorOutput = '';
        while (true) {
            $status = proc_get_status($process);
            $output .= stream_get_contents($pipes[1]);
            $errorOutput .= stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $start > $this->timeoutSeconds) {
                proc_terminate($process, 9);
                throw new RuntimeException('Health-Check Timeout.');
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $data = json_decode($output, true);
        if ($code !== 0 || !is_array($data) || ($data['ok'] ?? false) !== true) {
            throw new RuntimeException('Health-Check fehlgeschlagen: ' . trim((string) ($data['message'] ?? $errorOutput)));
        }

        return ['ok' => true, 'message' => (string) ($data['message'] ?? 'ok')];
    }

    private function resolvePhpCli(?string $configured): string
    {
        $executable = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $environment = getenv('MODULON_PHP_CLI_BINARY');
        $candidates = [
            $configured,
            is_string($environment) ? $environment : null,
            PHP_BINDIR . DIRECTORY_SEPARATOR . $executable,
            PHP_BINARY,
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('PHP-CLI für den isolierten Modul-Health-Check ist nicht verfügbar.');
    }
}
