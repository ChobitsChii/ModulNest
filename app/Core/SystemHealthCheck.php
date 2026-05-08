<?php

declare(strict_types=1);

namespace Modulon\Core;

use DateTimeZone;
use PDO;

final class SystemHealthCheck
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?PDO $pdo = null,
        private readonly ?HealthCheckRegistry $healthCheckRegistry = null,
    ) {
    }

    /**
     * @return array{
     *   summary: array{total:int,ok:int,warning:int,error:int,status:string,text:string},
     *   checks: array<int, array{key:string,label:string,status:string,severity:string,value:string,details:string}>
     * }
     */
    public function run(?string $effectiveTimezone = null): array
    {
        $checks = [];

        // PHP Extensions (kritisch)
        $checks[] = $this->extensionCheck('ext_gd', 'PHP Extension gd', 'gd', 'error');
        $checks[] = $this->extensionCheck('ext_curl', 'PHP Extension curl', 'curl', 'error');
        $checks[] = $this->extensionCheck('ext_mbstring', 'PHP Extension mbstring', 'mbstring', 'error');
        $checks[] = $this->extensionCheck('ext_fileinfo', 'PHP Extension fileinfo', 'fileinfo', 'error');
        $checks[] = $this->extensionCheck('ext_openssl', 'PHP Extension openssl', 'openssl', 'error');
        $checks[] = $this->extensionCheck('ext_session', 'PHP Extension session', 'session', 'error');
        $checks[] = $this->extensionCheck('ext_pdo', 'PHP Extension pdo', 'pdo', 'error');
        $checks[] = $this->extensionCheck('ext_pdo_mysql', 'PHP Extension pdo_mysql', 'pdo_mysql', 'error');
        $checks[] = $this->extensionCheck('ext_iconv', 'PHP Extension iconv (Mail/IMAP)', 'iconv', 'warning');

        // Filesystem (kritisch)
        $checks[] = $this->writableDirectoryCheck('dir_storage', 'Verzeichnis storage/', $this->basePath . '/storage', 'error');
        $checks[] = $this->writableDirectoryCheck('dir_storage_logs', 'Verzeichnis storage/logs/', $this->basePath . '/storage/logs', 'error');
        array_push($checks, ...$this->registeredChecks());

        // App/Umgebung
        $checks[] = $this->databaseCheck();
        $checks[] = $this->envCheck('env_loaded', '.env geladen', 'APP_ENV');
        $checks[] = $this->timezoneCheck($effectiveTimezone);
        $checks[] = $this->envValueCheck('app_env', 'APP_ENV', 'APP_ENV', 'info');
        $checks[] = $this->debugCheck();

        $ok = 0;
        $warning = 0;
        $error = 0;
        foreach ($checks as $check) {
            $status = strtolower((string) ($check['status'] ?? ''));
            if ($status === 'ok') {
                $ok++;
            } elseif ($status === 'warning') {
                $warning++;
            } else {
                $error++;
            }
        }

        $total = count($checks);
        $summaryStatus = $error > 0 ? 'error' : ($warning > 0 ? 'warning' : 'ok');

        return [
            'summary' => [
                'total' => $total,
                'ok' => $ok,
                'warning' => $warning,
                'error' => $error,
                'status' => $summaryStatus,
                'text' => sprintf('%d/%d Voraussetzungen erfüllt', $ok, $total),
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key:string,label:string,status:string,severity:string,value:string,details:string}
     */
    private function extensionCheck(string $key, string $label, string $extension, string $severity): array
    {
        $loaded = extension_loaded($extension);

        return [
            'key' => $key,
            'label' => $label,
            'status' => $loaded ? 'ok' : $severity,
            'severity' => $severity,
            'value' => $loaded ? 'Aktiv' : 'Fehlt',
            'details' => $loaded ? 'Extension geladen' : 'Extension nicht verfügbar',
        ];
    }

    /**
     * @return array{key:string,label:string,status:string,severity:string,value:string,details:string}
     */
    private function writableDirectoryCheck(string $key, string $label, string $path, string $severity): array
    {
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);

        $value = 'Fehlt';
        $details = $path;
        $status = $severity;
        if ($exists && $writable) {
            $value = 'Schreibbar';
            $status = 'ok';
        } elseif ($exists) {
            $value = 'Nicht schreibbar';
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'severity' => $severity,
            'value' => $value,
            'details' => $details,
        ];
    }

    /**
     * @return array{key:string,label:string,status:string,severity:string,value:string,details:string}
     */
    private function databaseCheck(): array
    {
        if (!$this->pdo instanceof PDO) {
            return [
                'key' => 'db_connection',
                'label' => 'Datenbankverbindung',
                'status' => 'error',
                'severity' => 'error',
                'value' => 'Nicht verbunden',
                'details' => 'PDO-Verbindung fehlt',
            ];
        }

        try {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            return [
                'key' => 'db_connection',
                'label' => 'Datenbankverbindung',
                'status' => 'ok',
                'severity' => 'error',
                'value' => 'Verbunden',
                'details' => $driver !== '' ? 'Treiber: ' . $driver : 'PDO aktiv',
            ];
        } catch (\Throwable) {
            return [
                'key' => 'db_connection',
                'label' => 'Datenbankverbindung',
                'status' => 'error',
                'severity' => 'error',
                'value' => 'Fehler',
                'details' => 'PDO-Attribute nicht lesbar',
            ];
        }
    }

    /**
     * @return array{key:string,label:string,status:string,severity:string,value:string,details:string}
     */
    private function envCheck(string $key, string $label, string $requiredEnvKey): array
    {
        $value = Env::get($requiredEnvKey);
        $ok = is_string($value) && trim($value) !== '';

        return [
            'key' => $key,
            'label' => $label,
            'status' => $ok ? 'ok' : 'error',
            'severity' => 'error',
            'value' => $ok ? 'Ja' : 'Nein',
            'details' => $ok ? 'Schlüssel gefunden: ' . $requiredEnvKey : 'Schlüssel fehlt: ' . $requiredEnvKey,
        ];
    }

    /**
     * @return array{key:string,label:string,status:string,severity:string,value:string,details:string}
     */
    private function envValueCheck(string $key, string $label, string $envKey, string $severity): array
    {
        $value = trim((string) Env::get($envKey, ''));
        $ok = $value !== '';

        return [
            'key' => $key,
            'label' => $label,
            'status' => $ok ? 'ok' : $severity,
            'severity' => $severity,
            'value' => $ok ? $value : 'Nicht gesetzt',
            'details' => $envKey,
        ];
    }

    /**
     * @return array{key:string,label:string,status:string,severity:string,value:string,details:string}
     */
    private function timezoneCheck(?string $effectiveTimezone = null): array
    {
        $serverTimezone = (string) date_default_timezone_get();
        $timezoneName = trim((string) ($effectiveTimezone ?? ''));
        if ($timezoneName === '') {
            $timezoneName = $serverTimezone;
        }
        $valid = $timezoneName !== '' && in_array($timezoneName, DateTimeZone::listIdentifiers(), true);
        $isUserBased = trim((string) ($effectiveTimezone ?? '')) !== '';

        return [
            'key' => 'timezone',
            'label' => 'Zeitzone (effektiv)',
            'status' => $valid ? 'ok' : 'warning',
            'severity' => 'warning',
            'value' => $timezoneName !== '' ? $timezoneName : 'Nicht gesetzt',
            'details' => $valid
                ? (($isUserBased ? 'Quelle: Benutzer' : 'Quelle: Server/PHP') . ' | Server: ' . $serverTimezone)
                : 'Ungültig oder nicht gesetzt',
        ];
    }

    /**
     * @return array{key:string,label:string,status:string,severity:string,value:string,details:string}
     */
    private function debugCheck(): array
    {
        $env = strtolower(trim((string) Env::get('APP_ENV', 'production')));
        $debug = Env::getBool('APP_DEBUG', false);

        if ($debug && in_array($env, ['production', 'prod'], true)) {
            return [
                'key' => 'app_debug',
                'label' => 'APP_DEBUG',
                'status' => 'warning',
                'severity' => 'warning',
                'value' => 'Aktiv',
                'details' => 'Debug ist in Production aktiv',
            ];
        }

        return [
            'key' => 'app_debug',
            'label' => 'APP_DEBUG',
            'status' => 'ok',
            'severity' => 'info',
            'value' => $debug ? 'Aktiv' : 'Inaktiv',
            'details' => 'Umgebung: ' . ($env !== '' ? $env : 'unbekannt'),
        ];
    }

    /**
     * @return array<int, array{key:string,label:string,status:string,severity:string,value:string,details:string}>
     */
    private function registeredChecks(): array
    {
        if (!$this->healthCheckRegistry instanceof HealthCheckRegistry) {
            return [];
        }

        $checks = [];
        foreach ($this->healthCheckRegistry->checks() as $entry) {
            if (($entry['type'] ?? '') === 'writable_directory') {
                $checks[] = $this->writableDirectoryCheck(
                    (string) $entry['key'],
                    (string) $entry['label'],
                    (string) ($entry['path'] ?? ''),
                    (string) $entry['severity'],
                );
            }
        }

        return $checks;
    }
}
