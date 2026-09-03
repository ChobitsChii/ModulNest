<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\RecoveryManager;
use Modulon\Core\RotatingFileLogger;
use Modulon\Modules\Modules\ModuleRepository;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

final class UpdatesService
{
    public const UPDATE_FEED_URL = 'https://raw.githubusercontent.com/ChobitsChii/ModulNest/main/build/update/stable.json';

    private string $storagePath;
    private string $downloadsPath;
    private string $stagingPath;
    private string $statePath;
    private string $maintenanceFlag;

    public function __construct(private readonly string $basePath, private readonly ?PDO $pdo = null)
    {
        $this->storagePath = $this->basePath . '/storage/updates';
        $this->downloadsPath = $this->storagePath . '/downloads';
        $this->stagingPath = $this->storagePath . '/staging';
        $this->statePath = $this->storagePath . '/state.json';
        $this->maintenanceFlag = $this->basePath . '/storage/maintenance.flag';
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $installedVersion, string $channel): array
    {
        $state = $this->readState();
        $state = $this->normalizeStateForInstalledVersion($state, $installedVersion);

        return [
            'installed_version' => $this->displayInstalledVersion($installedVersion, $state),
            'channel' => $channel,
            'feed_url' => self::UPDATE_FEED_URL,
            'state' => $state,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $installedVersion): array
    {
        $metadata = $this->fetchMetadata();
        $latest = (string) ($metadata['latest'] ?? '');
        if ($latest === '') {
            throw new RuntimeException('Update-Metadaten enthalten keine latest-Version.');
        }

        $package = $this->bundledPackage($metadata);
        $available = version_compare($latest, $installedVersion, '>');

        $result = [
            'checked_at' => gmdate(DATE_ATOM),
            'installed_version' => $installedVersion,
            'latest' => $latest,
            'available' => $available,
            'metadata' => $this->publicMetadata($metadata),
            'package' => $package,
            'message' => $available ? 'Update verfügbar.' : 'Kein Update erforderlich.',
        ];
        $this->writeState(array_merge($this->readState(), [
            'last_check' => $result,
        ]));
        $this->log('Update-Prüfung abgeschlossen', ['installed' => $installedVersion, 'latest' => $latest, 'available' => $available]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(string $installedVersion): array
    {
        $metadata = $this->fetchMetadata();
        $latest = (string) ($metadata['latest'] ?? '');
        if ($latest === '' || !version_compare($latest, $installedVersion, '>')) {
            throw new RuntimeException('Es ist kein neueres Update verfügbar.');
        }

        $package = $this->bundledPackage($metadata);
        $url = (string) ($package['url'] ?? '');
        $sha256 = strtolower((string) ($package['sha256'] ?? ''));
        if ($url === '' || $sha256 === '' || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new RuntimeException('Bundled-Paket oder SHA256 fehlt in den Update-Metadaten.');
        }

        $this->ensureDirectories();
        $downloadPath = $this->downloadsPath . '/modulnest-bundled-' . $latest . '.zip';
        $stagingRoot = $this->stagingPath . '/' . $latest;
        $this->removeDirectory($stagingRoot);
        $this->downloadFile($url, $downloadPath);

        $actualHash = hash_file('sha256', $downloadPath);
        if (!is_string($actualHash) || strtolower($actualHash) !== $sha256) {
            @unlink($downloadPath);
            throw new RuntimeException('SHA256-Prüfung fehlgeschlagen.');
        }

        $this->extractZipSafely($downloadPath, $stagingRoot);
        $packageMetadata = $this->readPackageMetadata($stagingRoot);
        $packageVersion = (string) ($packageMetadata['version'] ?? '');
        if ($packageVersion !== $latest) {
            throw new RuntimeException('Paketversion passt nicht zu stable.json.');
        }

        $prepared = [
            'prepared_at' => gmdate(DATE_ATOM),
            'status' => 'prepared',
            'from_version' => $installedVersion,
            'version' => $latest,
            'package_type' => 'bundled',
            'download_url' => $url,
            'sha256' => $sha256,
            'download_path' => $downloadPath,
            'staging_path' => $stagingRoot,
            'metadata' => $this->publicMetadata($metadata),
            'package_metadata' => [
                'version' => $packageVersion,
                'channel' => (string) ($packageMetadata['channel'] ?? ''),
                'modules' => array_map(static fn (array $module): string => (string) ($module['directory'] ?? ''), is_array($packageMetadata['modules'] ?? null) ? $packageMetadata['modules'] : []),
            ],
            'requires_migrations' => (bool) ($metadata['requires_migrations'] ?? false),
        ];

        $this->writeState(array_merge($this->readState(), [
            'prepared' => $prepared,
        ]));
        $this->log('Update vorbereitet', ['version' => $latest, 'package' => 'bundled']);

        return $prepared;
    }

    /**
     * @return array<string, mixed>
     */
    public function install(): array
    {
        $state = $this->readState();
        $prepared = is_array($state['prepared'] ?? null) ? $state['prepared'] : null;
        if ($prepared === null || (string) ($prepared['status'] ?? '') !== 'prepared') {
            throw new RuntimeException('Kein vorbereitetes Update gefunden.');
        }

        $stagingPath = (string) ($prepared['staging_path'] ?? '');
        $version = (string) ($prepared['version'] ?? '');
        if ($version === '' || !is_dir($stagingPath)) {
            $this->log('Update-Installation vor Dateikopie fehlgeschlagen', [
                'version' => $version,
                'reason' => 'prepared_staging_missing',
            ]);
            throw new RuntimeException('Vorbereitetes Staging-Verzeichnis fehlt.');
        }

        $backupPath = $this->basePath . '/storage/backups/updates/' . date('Ymd_His') . '_' . $version;
        $copied = 0;
        $backedUp = 0;
        $skipped = [];
        $this->ensureDirectories();
        $mutationStarted = false;
        $keepMaintenance = false;
        $this->enableMaintenance('Update auf ' . $version);

        try {
            [$copied, $backedUp, $skipped, $copiedPhpFiles] = $this->copyPreparedFiles($stagingPath, $backupPath, static function () use (&$mutationStarted): void {
                $mutationStarted = true;
            });
            $migrationResult = $this->runMigrations($prepared);
            $activatedModules = $this->syncPackageModules();
            $runtimeRefresh = $this->refreshPhpRuntime($copiedPhpFiles);
            $installed = [
                'installed_at' => gmdate(DATE_ATOM),
                'status' => 'installed',
                'from_version' => (string) ($prepared['from_version'] ?? ''),
                'version' => $version,
                'backup_path' => $backupPath,
                'copied_files' => $copied,
                'backed_up_files' => $backedUp,
                'skipped_entries' => $skipped,
                'requires_migrations' => (bool) ($prepared['requires_migrations'] ?? false),
                'migrations' => $migrationResult,
                'activated_default_modules' => $activatedModules,
                'runtime_refresh' => $runtimeRefresh,
                'migration_note' => $migrationResult === null
                    ? 'Keine Datenbankverbindung verfügbar; Migrationen wurden nicht ausgeführt.'
                    : 'Datenbankmigrationen geprüft: ' . count($migrationResult['executed']) . ' ausgeführt, ' . count($migrationResult['skipped']) . ' übersprungen.',
            ];
            $nextState = array_merge($state, [
                'prepared' => null,
                'last_install' => $installed,
            ]);
            $nextState = $this->normalizeStateForInstalledVersion($nextState, $version);
            $this->writeState($nextState);
            $this->log('Update installiert', [
                'version' => $version,
                'copied' => $copied,
                'backup' => $backupPath,
                'opcache_invalidated' => $runtimeRefresh['invalidated'],
                'opcache_available' => $runtimeRefresh['available'],
                'opcache_reset' => $runtimeRefresh['reset'],
            ]);

            return $installed;
        } catch (Throwable $exception) {
            if ($mutationStarted) {
                $keepMaintenance = true;
                $recovery = [
                    'failed_at' => gmdate(DATE_ATOM),
                    'status' => 'recovery_required',
                    'version' => $version,
                    'backup_path' => $backupPath,
                    'message' => 'Update nach Beginn der Dateikopie fehlgeschlagen. Wiederherstellung prüfen.',
                ];
                $this->writeState(array_merge($state, [
                    'prepared' => $prepared,
                    'recovery_required' => $recovery,
                ]));
                (new RecoveryManager($this->basePath))->requireRecovery([
                    'source' => 'update', 'phase' => 'file_copy', 'error_code' => 'update_install_failed',
                    'backup_path' => $backupPath, 'files_mutated' => true,
                    'last_successful_step' => 'Update vorbereitet',
                    'operator_hint' => 'Dateibackup im geschützten Recovery-Bereich prüfen.',
                ]);
                $this->log('Update-Installation fehlgeschlagen; Recovery erforderlich', ['version' => $version, 'backup' => $backupPath]);
                throw new RuntimeException('Update nach Beginn der Installation fehlgeschlagen. Wartungsmodus bleibt aktiv. Backup und Update-Log prüfen: ' . $backupPath, 0, $exception);
            }

            $this->log('Update-Installation vor Dateikopie fehlgeschlagen', ['version' => $version]);
            throw $exception;
        } finally {
            if (!$keepMaintenance) {
                $this->disableMaintenance();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchMetadata(): array
    {
        $payload = $this->httpGet(self::UPDATE_FEED_URL);
        $metadata = json_decode($payload, true);
        if (!is_array($metadata)) {
            throw new RuntimeException('Update-Metadaten konnten nicht gelesen werden.');
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function readState(): array
    {
        if (!is_file($this->statePath)) {
            return [];
        }

        $json = file_get_contents($this->statePath);
        $state = is_string($json) ? json_decode($json, true) : null;

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function normalizeStateForInstalledVersion(array $state, string $installedVersion): array
    {
        if ($installedVersion === '' || !isset($state['last_check']) || !is_array($state['last_check'])) {
            return $state;
        }

        $latest = (string) ($state['last_check']['latest'] ?? '');
        if ($latest === '') {
            return $state;
        }

        $state['last_check']['installed_version'] = $installedVersion;
        if (!version_compare($latest, $installedVersion, '>')) {
            $state['last_check']['available'] = false;
            $state['last_check']['message'] = 'Kein Update erforderlich.';
        }

        return $state;
    }

    /**
     * A successful update writes last_install only after every mutating step,
     * migrations and the runtime refresh have completed. During the first
     * redirect after an update, the already loaded controller can still carry
     * the previous version from its request context. In that narrow case the
     * verified installation record is the authoritative display value.
     *
     * @param array<string,mixed> $state
     */
    private function displayInstalledVersion(string $installedVersion, array $state): string
    {
        $lastInstall = is_array($state['last_install'] ?? null) ? $state['last_install'] : [];
        $fromVersion = trim((string) ($lastInstall['from_version'] ?? ''));
        $installedVersionFromState = trim((string) ($lastInstall['version'] ?? ''));

        if (
            (string) ($lastInstall['status'] ?? '') === 'installed'
            && $fromVersion !== ''
            && $fromVersion === $installedVersion
            && $installedVersionFromState !== ''
            && version_compare($installedVersionFromState, $installedVersion, '>')
        ) {
            return $installedVersionFromState;
        }

        return $installedVersion;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $this->ensureDirectories();
        file_put_contents($this->statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->storagePath, $this->downloadsPath, $this->stagingPath, $this->basePath . '/storage/backups/updates'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Verzeichnis konnte nicht erstellt werden: ' . $dir);
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function bundledPackage(array $metadata): array
    {
        $packages = is_array($metadata['packages'] ?? null) ? $metadata['packages'] : [];
        $bundled = is_array($packages['bundled'] ?? null) ? $packages['bundled'] : [];
        if ($bundled === []) {
            throw new RuntimeException('stable.json enthält kein bundled Paket.');
        }

        return [
            'type' => 'bundled',
            'url' => (string) ($bundled['url'] ?? ''),
            'sha256' => strtolower((string) ($bundled['sha256'] ?? '')),
            'needs_composer' => (bool) ($bundled['needs_composer'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function publicMetadata(array $metadata): array
    {
        return [
            'latest' => (string) ($metadata['latest'] ?? ''),
            'channel' => (string) ($metadata['channel'] ?? ''),
            'changelog_url' => (string) ($metadata['changelog_url'] ?? ''),
            'requires_migrations' => (bool) ($metadata['requires_migrations'] ?? false),
        ];
    }

    private function httpGet(string $url): string
    {
        if (!str_starts_with($url, 'https://raw.githubusercontent.com/ChobitsChii/ModulNest/')) {
            throw new RuntimeException('Nicht erlaubte Update-Quelle.');
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'user_agent' => 'ModulNest-Updater/1.0',
                'ignore_errors' => true,
            ],
        ]);
        $payload = @file_get_contents($url, false, $context);
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Update-Quelle konnte nicht geladen werden.');
        }

        return $payload;
    }

    private function downloadFile(string $url, string $target): void
    {
        if (!str_starts_with($url, 'https://github.com/ChobitsChii/ModulNest/releases/download/')) {
            throw new RuntimeException('Nicht erlaubte Paket-URL.');
        }

        $payload = $this->httpDownload($url);
        if (strlen($payload) < 1024) {
            throw new RuntimeException('Heruntergeladenes Paket ist unerwartet klein.');
        }

        file_put_contents($target, $payload);
    }

    private function httpDownload(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'ModulNest-Updater/1.0',
                'ignore_errors' => true,
            ],
        ]);
        $payload = @file_get_contents($url, false, $context);
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Paket konnte nicht heruntergeladen werden.');
        }

        return $payload;
    }

    private function extractZipSafely(string $zipPath, string $targetDir): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP konnte nicht geöffnet werden.');
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $zip->close();
            throw new RuntimeException('Staging-Verzeichnis konnte nicht erstellt werden.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '' || $this->isUnsafeZipPath($name)) {
                $zip->close();
                throw new RuntimeException('Unsicherer ZIP-Pfad erkannt.');
            }
        }

        if (!$zip->extractTo($targetDir)) {
            $zip->close();
            throw new RuntimeException('ZIP konnte nicht entpackt werden.');
        }
        $zip->close();
    }

    private function isUnsafeZipPath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return true;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPackageMetadata(string $stagingRoot): array
    {
        $path = $stagingRoot . '/modulnest-package.json';
        if (!is_file($path)) {
            throw new RuntimeException('modulnest-package.json fehlt im Paket.');
        }
        $json = file_get_contents($path);
        $metadata = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($metadata)) {
            throw new RuntimeException('modulnest-package.json ist ungültig.');
        }

        return $metadata;
    }

    /**
     * @return array{0:int,1:int,2:array<int,string>,3:array<int,string>}
     */
    private function copyPreparedFiles(string $sourceRoot, string $backupRoot, ?callable $onMutation = null): array
    {
        $copied = 0;
        $backedUp = 0;
        $skipped = [];
        $copiedPhpFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $source = $item->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($source, strlen($sourceRoot))), '/');
            if ($relative === '' || $this->isProtectedUpdatePath($relative)) {
                $skipped[] = $relative;
                continue;
            }

            $target = $this->basePath . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    $onMutation?->__invoke();
                    if (!mkdir($target, 0775, true) && !is_dir($target)) {
                        throw new RuntimeException('Zielverzeichnis konnte nicht erstellt werden: ' . $relative);
                    }
                }
                continue;
            }

            $onMutation?->__invoke();
            if (is_file($target)) {
                $backup = $backupRoot . '/' . $relative;
                $backupDir = dirname($backup);
                if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                    throw new RuntimeException('Backup-Verzeichnis konnte nicht erstellt werden.');
                }
                if (!copy($target, $backup)) {
                    throw new RuntimeException('Backup konnte nicht geschrieben werden: ' . $relative);
                }
                $backedUp++;
            }

            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Zielverzeichnis konnte nicht erstellt werden: ' . $targetDir);
            }
            if (!@copy($source, $target)) {
                throw new RuntimeException('Datei konnte nicht kopiert werden: ' . $relative);
            }
            $copied++;
            if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'php') {
                $copiedPhpFiles[] = $target;
            }
        }

        return [$copied, $backedUp, array_values(array_filter($skipped)), $copiedPhpFiles];
    }

    /**
     * Invalidates updated PHP bytecode before the post-install redirect starts a
     * fresh request. This is deliberately best effort: unavailable or restricted
     * OPcache APIs must never turn a completed file update into a failed update.
     *
     * @param array<int,string> $phpFiles
     * @return array{available:bool,invalidated:int,failed:int,reset:bool}
     */
    private function refreshPhpRuntime(array $phpFiles): array
    {
        if (!function_exists('opcache_invalidate')) {
            return ['available' => false, 'invalidated' => 0, 'failed' => 0, 'reset' => false];
        }

        $invalidated = 0;
        $failed = 0;
        foreach (array_values(array_unique($phpFiles)) as $phpFile) {
            clearstatcache(true, $phpFile);
            if (@opcache_invalidate($phpFile, true)) {
                $invalidated++;
            } else {
                $failed++;
            }
        }

        // A failed per-file invalidation can happen on locked-down hosts. Reset
        // the local OPcache as a final best-effort fallback, while keeping the
        // successful update intact even when the host denies that operation.
        $reset = false;
        if ($failed > 0 && function_exists('opcache_reset')) {
            $reset = @opcache_reset();
        }

        return ['available' => true, 'invalidated' => $invalidated, 'failed' => $failed, 'reset' => $reset];
    }

    private function isProtectedUpdatePath(string $relative): bool
    {
        $path = trim(str_replace('\\', '/', $relative), '/');
        if ($path === '') {
            return true;
        }

        $exact = ['.env', '.git', '.local', 'install.php', 'public/.user.ini'];
        if (in_array($path, $exact, true)) {
            return true;
        }

        foreach (['storage', '.git/', '.local/', 'storage/', 'var/cache/', 'var/log/'] as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $prepared
     * @return array{executed:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}|null
     */
    private function runMigrations(array $prepared): ?array
    {
        if ($this->pdo === null) {
            $this->log('Migrationen übersprungen', ['reason' => 'Keine Datenbankverbindung verfügbar']);
            return null;
        }

        $modules = [];
        $packageMetadata = is_array($prepared['package_metadata'] ?? null) ? $prepared['package_metadata'] : [];
        foreach (is_array($packageMetadata['modules'] ?? null) ? $packageMetadata['modules'] : [] as $module) {
            $module = (string) $module;
            if ($module !== '') {
                $modules[] = $module;
            }
        }

        $runner = new MigrationRunner(
            $this->pdo,
            $this->basePath,
            function (string $message, array $context): void {
                $this->log($message, $context);
            }
        );

        $result = $runner->run(array_values(array_unique($modules)));
        $this->log('Migrationen abgeschlossen', [
            'executed' => count($result['executed']),
            'skipped' => count($result['skipped']),
            'errors' => count($result['errors']),
        ]);

        return $result;
    }

    /**
     * @return array<int,string>
     */
    private function syncPackageModules(): array
    {
        if ($this->pdo === null) {
            $this->log('Paketmodule nicht synchronisiert', ['reason' => 'Keine Datenbankverbindung verfügbar']);
            return [];
        }

        $repository = new ModuleRepository($this->pdo);
        $activated = $repository->syncPackageDefaultModules($this->basePath);
        if ($activated !== []) {
            $this->log('Neue Paketmodule aktiviert', ['modules' => $activated]);
        }

        return $activated;
    }

    private function enableMaintenance(string $reason): void
    {
        $dir = dirname($this->maintenanceFlag);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Storage-Verzeichnis konnte nicht erstellt werden.');
        }

        file_put_contents($this->maintenanceFlag, json_encode([
            'enabled_at' => gmdate(DATE_ATOM),
            'reason' => $reason,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function disableMaintenance(): void
    {
        if (is_file($this->maintenanceFlag)) {
            @unlink($this->maintenanceFlag);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context = []): void
    {
        $this->ensureDirectories();
        $safeContext = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            $safeContext[$key] = str_contains($lower, 'pass') || str_contains($lower, 'token') || str_contains($lower, 'secret') || str_contains($lower, 'key')
                ? '***'
                : $value;
        }

        (new RotatingFileLogger($this->basePath))->write('update', [
            'timestamp' => gmdate(DATE_ATOM),
            'message' => $message,
            'context' => $safeContext,
        ]);
    }
}
