<?php

declare(strict_types=1);

namespace Modulon\Core;

/** Persistiert einen bewusst kleinen, nicht öffentlichen Recovery-Zustand. */
final class RecoveryManager
{
    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function requireRecovery(array $details): array
    {
        $directory = $this->basePath . '/storage/recovery';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Recovery-Speicher kann nicht erstellt werden.');
        }

        $state = [
            'recovery_id' => 'rec_' . bin2hex(random_bytes(12)),
            'created_at' => gmdate(DATE_ATOM),
            'source' => $this->choice($details['source'] ?? 'other', ['update', 'migration', 'bootstrap', 'other']),
            'phase' => $this->text($details['phase'] ?? 'unknown'),
            'error_code' => $this->text($details['error_code'] ?? 'recovery_required'),
            'migration_key' => $this->text($details['migration_key'] ?? ''),
            'backup_path' => $this->safePath($details['backup_path'] ?? ''),
            'files_mutated' => !empty($details['files_mutated']),
            'migrations_started' => !empty($details['migrations_started']),
            'last_successful_step' => $this->text($details['last_successful_step'] ?? ''),
            'operator_hint' => $this->text($details['operator_hint'] ?? 'Im geschützten Recovery-Bereich erneut prüfen.'),
            'log_path' => 'storage/logs/recovery-YYYY-MM-DD.log',
        ];
        $this->writeJson($this->statePath(), $state);
        $this->appendLog('recovery_required', $state);
        $this->enableMaintenance($state);

        return $state;
    }

    /** @return array<string,mixed>|null */
    public function current(): ?array
    {
        $state = $this->readJson($this->statePath());
        return is_array($state) ? $state : null;
    }

    /** @param array<string,mixed> $context */
    public function appendLog(string $event, array $context = []): void
    {
        $entry = ['at' => gmdate(DATE_ATOM), 'event' => $this->text($event), 'context' => $this->redact($context)];
        $this->migrateLegacyLog();
        (new RotatingFileLogger($this->basePath))->write('recovery', $entry);
    }

    /** Übernimmt das historische Recovery-Log einmalig in die zentrale Struktur. */
    public function migrateLegacyLogs(): void
    {
        $this->migrateLegacyLog();
    }

    public function resolve(): void
    {
        $state = $this->current();
        $this->appendLog('recovery_resolved', ['recovery_id' => $state['recovery_id'] ?? '']);
        @unlink($this->statePath());
        @unlink($this->basePath . '/storage/maintenance.flag');
    }

    public function logPath(): string { return (new RotatingFileLogger($this->basePath))->path('recovery'); }

    private function statePath(): string { return $this->basePath . '/storage/recovery/state.json'; }

    private function migrateLegacyLog(): void
    {
        $legacy = $this->basePath . '/storage/recovery/recovery.log';
        if (!is_file($legacy) || !is_readable($legacy)) { return; }
        $content = @file_get_contents($legacy);
        if (!is_string($content) || $content === '') { return; }
        $target = (new RotatingFileLogger($this->basePath))->path('recovery');
        if (@file_put_contents($target, $content, FILE_APPEND | LOCK_EX) !== false) { @unlink($legacy); }
    }

    /** @param array<string,mixed> $state */
    private function enableMaintenance(array $state): void
    {
        $this->writeJson($this->basePath . '/storage/maintenance.flag', [
            'enabled_at' => gmdate(DATE_ATOM), 'reason' => 'Recovery erforderlich', 'recovery_required' => true,
            'recovery_id' => $state['recovery_id'] ?? '',
        ]);
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        $value = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Recovery-Speicher nicht verfügbar.'); }
        if (file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new \RuntimeException('Recovery-Zustand konnte nicht gespeichert werden.');
        }
    }

    private function choice(mixed $value, array $allowed): string { $value = $this->text($value); return in_array($value, $allowed, true) ? $value : 'other'; }
    private function text(mixed $value): string { return substr(preg_replace('/[\r\n\0]+/', ' ', (string) $value) ?? '', 0, 300); }
    private function safePath(mixed $value): string { $value = $this->text($value); return str_starts_with($value, $this->basePath . '/') ? substr($value, strlen($this->basePath) + 1) : ''; }
    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $key = (string) $key;
            $out[$key] = preg_match('/pass|secret|token|cookie|session|authorization/i', $key) ? '[redacted]' : (is_scalar($value) || $value === null ? $this->text($value) : '[omitted]');
        }
        return $out;
    }
}
