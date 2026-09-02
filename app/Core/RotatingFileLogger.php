<?php

declare(strict_types=1);

namespace Modulon\Core;

/** Small JSONL file logger with bounded, lock-protected daily retention. */
final class RotatingFileLogger
{
    /** @var \Closure():\DateTimeImmutable */
    private \Closure $clock;

    public function __construct(private readonly string $basePath, ?callable $clock = null)
    {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function write(string $type, array $record): bool
    {
        $type = $this->type($type);
        if ($type === null) { return false; }
        $this->rotateIfDue();
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return false; }
        $record = $this->redact($record);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($line) && @file_put_contents($this->path($type), $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    public function path(string $type, ?\DateTimeImmutable $date = null): string
    {
        $type = $this->type($type) ?? 'modulon';
        $date ??= ($this->clock)();
        return $this->directory() . '/' . $type . '-' . $date->format('Y-m-d') . '.log';
    }

    public function rotateIfDue(): void
    {
        $runtime = $this->basePath . '/storage/runtime';
        if (!is_dir($runtime) && !@mkdir($runtime, 0775, true) && !is_dir($runtime)) { return; }
        $handle = @fopen($runtime . '/log-rotation.lock', 'c+');
        if (!is_resource($handle)) { return; }
        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) { return; }
            $today = ($this->clock)()->format('Y-m-d'); rewind($handle); $done = trim((string) stream_get_contents($handle));
            if ($done !== $today) {
                $this->rotate($today); ftruncate($handle, 0); rewind($handle); fwrite($handle, $today); fflush($handle);
            }
        } catch (\Throwable) {
            // Logging must never make application requests fail.
        } finally { @flock($handle, LOCK_UN); fclose($handle); }
    }

    private function rotate(string $today): void
    {
        $dir = $this->directory(); if (!is_dir($dir)) { return; }
        $compressAfter = $this->setting('LOG_COMPRESS_AFTER_DAYS', 3, 1, 365);
        $retention = $this->setting('LOG_RETENTION_DAYS', 30, $compressAfter + 1, 3650);
        $todayTime = (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->getTimestamp();
        foreach (glob($dir . '/*.log') ?: [] as $path) {
            if (!preg_match('/^[a-z0-9_-]+-(\d{4}-\d{2}-\d{2})\.log$/', basename($path), $matches)) { continue; }
            $age = (int) floor(($todayTime - (new \DateTimeImmutable($matches[1], new \DateTimeZone('UTC')))->getTimestamp()) / 86400);
            if ($age > $compressAfter && is_file($path)) { $this->gzip($path); }
        }
        foreach (glob($dir . '/*.log.gz') ?: [] as $path) {
            if (!preg_match('/^[a-z0-9_-]+-(\d{4}-\d{2}-\d{2})\.log\.gz$/', basename($path), $matches)) { continue; }
            $age = (int) floor(($todayTime - (new \DateTimeImmutable($matches[1], new \DateTimeZone('UTC')))->getTimestamp()) / 86400);
            if ($age > $retention) { @unlink($path); }
        }
    }

    private function gzip(string $path): void
    {
        $target = $path . '.gz'; if (is_file($target)) { return; }
        $in = @fopen($path, 'rb'); $out = @gzopen($target . '.tmp', 'wb9'); if (!is_resource($in) || $out === false) { if (is_resource($in)) fclose($in); return; }
        while (!feof($in)) { $chunk = fread($in, 8192); if ($chunk === false || gzwrite($out, $chunk) === false) { fclose($in); gzclose($out); @unlink($target . '.tmp'); return; } }
        fclose($in); gzclose($out); if (@rename($target . '.tmp', $target)) { @unlink($path); }
    }

    private function directory(): string { return $this->basePath . '/storage/logs'; }
    private function type(string $type): ?string { $type = strtolower(trim($type)); return preg_match('/^[a-z0-9_-]{1,60}$/', $type) ? $type : null; }
    private function setting(string $key, int $default, int $min, int $max): int { $value = filter_var(Env::get($key), FILTER_VALIDATE_INT); return is_int($value) && $value >= $min && $value <= $max ? $value : $default; }
    private function redact(array $value): array { foreach ($value as $key => $item) { if (preg_match('/pass|secret|token|cookie|session|authorization|recovery.?code/i', (string) $key)) { $value[$key] = '[redacted]'; } elseif (is_array($item)) { $value[$key] = $this->redact($item); } } return $value; }
}
