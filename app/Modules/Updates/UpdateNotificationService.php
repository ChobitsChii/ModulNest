<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

use Modulon\Core\Modules\Catalog\CatalogService;
use Throwable;

final class UpdateNotificationService
{
    private const CACHE_TTL = 43200; // 12 Stunden

    public function __construct(
        private readonly UpdatesService $updatesService,
        private readonly ?CatalogService $catalogService,
        private readonly string $installedCoreVersion,
        private readonly string $cacheDir,
        private readonly int $ttl = self::CACHE_TTL,
    ) {
    }

    /**
     * @return array{
     *     has_updates: bool,
     *     core_update_available: bool,
     *     core_current_version: string,
     *     core_latest_version: ?string,
     *     module_updates_count: int,
     *     module_updates: list<array{id: string, name: string, current_version: string, available_version: string}>,
     *     checked_at: string,
     *     checked_at_timestamp: int
     * }
     */
    public function check(bool $force = false): array
    {
        $cacheFile = rtrim($this->cacheDir, '/') . '/notification_cache.json';
        if (!$force && is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if (is_string($raw) && $raw !== '') {
                try {
                    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($data) && isset($data['checked_at_timestamp']) && (time() - (int) $data['checked_at_timestamp']) < $this->ttl) {
                        $cachedInstalled = (string) ($data['core_current_version'] ?? '');
                        $coreUpdateAvailable = !empty($data['core_update_available']);
                        $coreLatest = !empty($data['core_latest_version']) ? (string) $data['core_latest_version'] : null;

                        // Cache is valid only if:
                        // 1. Cached installed core version matches currently installed core version
                        // 2. If core update was marked available, core_latest must still be strictly newer than installed
                        $isStale = ($cachedInstalled !== $this->installedCoreVersion);
                        if (!$isStale && $coreUpdateAvailable && ($coreLatest === null || !version_compare($coreLatest, $this->installedCoreVersion, '>'))) {
                            $isStale = true;
                        }

                        if (!$isStale) {
                            return $data;
                        }
                    }
                } catch (Throwable) {
                    // Cache abgelaufen oder beschädigt, neu prüfen
                }
            }
        }

        // 1. Core Update Check
        $coreAvailable = false;
        $coreLatest = null;
        try {
            $status = $this->updatesService->status($this->installedCoreVersion, 'stable');
            $lastCheck = $status['state']['last_check'] ?? null;
            if (is_array($lastCheck) && !empty($lastCheck['latest'])) {
                $candidate = (string) $lastCheck['latest'];
                if (version_compare($candidate, $this->installedCoreVersion, '>')) {
                    $coreAvailable = true;
                    $coreLatest = $candidate;
                }
            }

            if (!$coreAvailable && empty($lastCheck)) {
                $checkResult = $this->updatesService->check($this->installedCoreVersion, 'stable');
                if (!empty($checkResult['available'])) {
                    $candidate = (string) ($checkResult['latest'] ?? '');
                    if (version_compare($candidate, $this->installedCoreVersion, '>')) {
                        $coreAvailable = true;
                        $coreLatest = $candidate;
                    }
                }
            }
        } catch (Throwable) {
            // Netzwerk- oder Offline-Fehler geräuschlos abfangen
        }

        // 2. Modul-Updates prüfen
        $moduleUpdates = [];
        if ($this->catalogService !== null) {
            try {
                $updates = $this->catalogService->updates();
                foreach ($updates as $mod) {
                    $moduleUpdates[] = [
                        'id' => (string) ($mod['id'] ?? ''),
                        'name' => (string) ($mod['name'] ?? $mod['id'] ?? ''),
                        'current_version' => (string) ($mod['installed_version'] ?? ''),
                        'available_version' => (string) ($mod['available_version'] ?? ''),
                    ];
                }
            } catch (Throwable) {
                // Katalog-Fehler geräuschlos abfangen
            }
        }

        $result = [
            'has_updates' => $coreAvailable || count($moduleUpdates) > 0,
            'core_update_available' => $coreAvailable,
            'core_current_version' => $this->installedCoreVersion,
            'core_latest_version' => $coreLatest,
            'module_updates_count' => count($moduleUpdates),
            'module_updates' => $moduleUpdates,
            'checked_at' => gmdate(DATE_ATOM),
            'checked_at_timestamp' => time(),
        ];

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $result;
    }

    public static function clearCache(string $cacheDir): void
    {
        $cacheFile = rtrim($cacheDir, '/') . '/notification_cache.json';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    public function clear(): void
    {
        self::clearCache($this->cacheDir);
    }
}
