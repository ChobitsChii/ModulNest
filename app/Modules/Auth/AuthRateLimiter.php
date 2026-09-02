<?php

declare(strict_types=1);

namespace Modulon\Modules\Auth;

use RuntimeException;

/**
 * Kleiner persistenter Rate-Limiter für Authentifizierungsversuche.
 * Der Zustand enthält ausschließlich gehashte Bucket-Schlüssel und Zeitstempel.
 */
final class AuthRateLimiter
{
    /** @var \Closure():int */
    private \Closure $clock;

    public function __construct(
        private readonly string $storagePath,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /**
     * Reserviert einen Versuch. Nach maxAttempts fehlgeschlagenen bzw. noch
     * nicht erfolgreich abgeschlossenen Versuchen folgt bis Window-Ende false.
     */
    public function consume(string $scope, string $ip, string $subject): bool
    {
        return $this->withState(function (array &$state) use ($scope, $ip, $subject): bool {
            $now = ($this->clock)();
            $bucket = $this->bucketKey($scope, $ip, $subject);
            $timestamps = array_values(array_filter(
                is_array($state[$bucket] ?? null) ? $state[$bucket] : [],
                fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > ($now - $this->windowSeconds),
            ));

            if (count($timestamps) >= $this->maxAttempts) {
                $state[$bucket] = $timestamps;
                return false;
            }

            $timestamps[] = $now;
            $state[$bucket] = $timestamps;
            return true;
        });
    }

    /** Löscht den Bucket nach einem erfolgreichen Authentifizierungsschritt. */
    public function reset(string $scope, string $ip, string $subject): void
    {
        $this->withState(function (array &$state) use ($scope, $ip, $subject): bool {
            unset($state[$this->bucketKey($scope, $ip, $subject)]);
            return true;
        });
    }

    private function bucketKey(string $scope, string $ip, string $subject): string
    {
        return hash('sha256', strtolower(trim($scope)) . "\0" . trim($ip) . "\0" . trim($subject));
    }

    /**
     * @param callable(array<string,array<int,int>>&):bool $operation
     */
    private function withState(callable $operation): bool
    {
        $directory = dirname($this->storagePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Auth-Rate-Limit-Speicher kann nicht erstellt werden.');
        }

        $handle = fopen($this->storagePath, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('Auth-Rate-Limit-Speicher ist nicht verfügbar.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Auth-Rate-Limit-Speicher kann nicht gesperrt werden.');
            }

            rewind($handle);
            $json = stream_get_contents($handle);
            $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];
            $state = is_array($decoded) ? $decoded : [];
            $result = $operation($state);

            ftruncate($handle, 0);
            rewind($handle);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            if (fwrite($handle, $encoded) === false || !fflush($handle)) {
                throw new RuntimeException('Auth-Rate-Limit-Speicher kann nicht geschrieben werden.');
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
