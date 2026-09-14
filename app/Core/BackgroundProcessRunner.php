<?php

declare(strict_types=1);

namespace Modulon\Core;

use Throwable;

final class BackgroundProcessRunner
{
    /**
     * @param list<string> $arguments
     * @return array{pid:int,php_binary:string,working_directory:string}
     */
    public function launchPhp(string $script, array $arguments, string $logFile, string $workingDirectory): array
    {
        if (!function_exists('proc_open')) {
            throw $this->failure('process_start_not_allowed', 'Der Server erlaubt keinen Hintergrund-Prozessstart.');
        }
        $php = $this->phpCliBinary();
        if ($php === null) {
            throw $this->failure('php_cli_not_found', 'PHP CLI wurde auf dem Server nicht gefunden.');
        }
        if (!is_file($script) || !is_readable($script)) {
            throw $this->failure('worker_script_unavailable', 'Das Worker-Script ist nicht lesbar.');
        }
        if (!is_dir($workingDirectory) || !is_readable($workingDirectory)) {
            throw $this->failure('working_directory_unavailable', 'Das Arbeitsverzeichnis des Workers ist nicht verfügbar.');
        }
        $logDirectory = dirname($logFile);
        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0770, true) && !is_dir($logDirectory)) {
            throw $this->failure('log_not_writable', 'Das Worker-Logverzeichnis kann nicht erstellt werden.');
        }
        if (!is_writable($logDirectory)) {
            throw $this->failure('log_not_writable', 'Das Worker-Logverzeichnis ist nicht beschreibbar.');
        }
        foreach ($arguments as $argument) {
            if (str_contains($argument, "\0")) {
                throw $this->failure('invalid_argument', 'Ein Worker-Argument ist ungültig.');
            }
        }

        $descriptor = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        try {
            $process = proc_open([$php, $script, ...$arguments], $descriptor, $pipes, $workingDirectory);
        } catch (Throwable $error) {
            throw $this->failure('process_start_failed', 'Der Worker-Prozess konnte nicht gestartet werden.', $error);
        }
        if (!is_resource($process)) {
            throw $this->failure('process_start_failed', 'Der Worker-Prozess konnte nicht gestartet werden.');
        }
        $status = proc_get_status($process);
        $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;
        if ($pid < 2) {
            throw $this->failure('worker_pid_unavailable', 'Der gestartete Worker lieferte keine Prozess-ID.');
        }
        $startupDeadline = microtime(true) + 0.25;
        do {
            usleep(25000);
            $status = proc_get_status($process);
            if (!is_array($status) || empty($status['running'])) {
                $exitCode = is_array($status) ? (int) ($status['exitcode'] ?? -1) : -1;
                throw $this->failure('worker_exited_immediately', 'Der Worker wurde gestartet, hat sich aber unmittelbar beendet.', null, ['exit_code' => $exitCode]);
            }
        } while (microtime(true) < $startupDeadline);

        // Wie beim bewährten Speech-Worker nicht proc_close() aufrufen: Der
        // HTTP-Request darf nicht auf den langlebigen Kindprozess warten.
        return ['pid' => $pid, 'php_binary' => $php, 'working_directory' => $workingDirectory];
    }

    /**
     * Launches a fixed, absolute executable in the background.
     *
     * @param list<string> $arguments
     * @param array<string,string> $env
     * @return array{pid:int,status:string,executable:string,working_directory:string,exit_code?:int}
     */
    public function launchExecutable(string $executable, array $arguments, string $logFile, string $workingDirectory, array $env = []): array
    {
        if (!function_exists('proc_open')) {
            throw $this->failure('process_start_not_allowed', 'Der Server erlaubt keinen Hintergrund-Prozessstart.');
        }
        if (!is_file($executable) || !is_executable($executable)) {
            throw $this->failure('executable_unavailable', 'Das auszuführende Skript ist nicht ausführbar oder nicht vorhanden: ' . $executable);
        }
        if (!is_dir($workingDirectory) || !is_readable($workingDirectory)) {
            throw $this->failure('working_directory_unavailable', 'Das Arbeitsverzeichnis ist nicht verfügbar.');
        }
        $logDirectory = dirname($logFile);
        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0770, true) && !is_dir($logDirectory)) {
            throw $this->failure('log_not_writable', 'Das Logverzeichnis kann nicht erstellt werden.');
        }
        if (!is_writable($logDirectory)) {
            throw $this->failure('log_not_writable', 'Das Logverzeichnis ist nicht beschreibbar.');
        }
        foreach ($arguments as $argument) {
            if (str_contains($argument, "\0")) {
                throw $this->failure('invalid_argument', 'Ein Argument ist ungültig.');
            }
        }

        $descriptor = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        try {
            $process = proc_open([$executable, ...$arguments], $descriptor, $pipes, $workingDirectory, $env ?: null);
        } catch (Throwable $error) {
            throw $this->failure('process_start_failed', 'Der Prozess konnte nicht gestartet werden.', $error);
        }
        if (!is_resource($process)) {
            throw $this->failure('process_start_failed', 'Der Prozess konnte nicht gestartet werden.');
        }
        $status = proc_get_status($process);
        $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;
        if ($pid < 2) {
            throw $this->failure('worker_pid_unavailable', 'Der gestartete Prozess lieferte keine Prozess-ID.');
        }
        $startupDeadline = microtime(true) + 0.1;
        do {
            usleep(20000);
            $status = proc_get_status($process);
            if (!is_array($status) || empty($status['running'])) {
                $exitCode = is_array($status) ? (int) ($status['exitcode'] ?? -1) : -1;
                if ($exitCode === 0) {
                    return ['pid' => $pid, 'status' => 'completed', 'exit_code' => 0, 'executable' => $executable, 'working_directory' => $workingDirectory];
                }
                if ($exitCode === 75) {
                    return ['pid' => $pid, 'status' => 'already_running', 'exit_code' => 75, 'executable' => $executable, 'working_directory' => $workingDirectory];
                }
                throw $this->failure('executable_exited_with_error', 'Der Prozess wurde gestartet, hat sich aber mit Fehlercode ' . $exitCode . ' beendet.', null, ['exit_code' => $exitCode]);
            }
        } while (microtime(true) < $startupDeadline);

        return ['pid' => $pid, 'status' => 'running', 'executable' => $executable, 'working_directory' => $workingDirectory];
    }

    public function phpCliBinary(): ?string
    {
        $candidates = [PHP_BINARY, '/usr/bin/php', '/bin/php', '/usr/local/bin/php'];
        foreach (array_unique($candidates) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || !is_executable($candidate)) continue;
            if (preg_match('/^php(?:[0-9]+(?:\\.[0-9]+)*)?$/D', basename($candidate)) !== 1) continue;
            return $candidate;
        }
        return null;
    }

    /** @param array<string,int|string> $context */
    private function failure(string $code, string $message, ?Throwable $previous = null, array $context = []): BackgroundProcessLaunchException
    {
        $technical = ['error_code' => $code, 'sapi' => PHP_SAPI] + $context;
        if ($previous !== null) {
            $technical['exception'] = get_class($previous);
            $technical['detail'] = mb_substr($previous->getMessage(), 0, 500);
        }
        error_log('[background-process] ' . json_encode($technical, JSON_UNESCAPED_SLASHES));
        return new BackgroundProcessLaunchException($code, $message, $previous);
    }
}
