<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use Modulon\Core\BackgroundProcessRunner;
use Modulon\Core\Modules\Catalog\CatalogPackageInstaller;
use Modulon\Core\Modules\Catalog\CatalogService;
use PDO;
use RuntimeException;
use Throwable;

final class ModuleBatchUpdateService
{
    private const JOURNAL_ID = 'modulnest.batch-update';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $basePath,
        private readonly CatalogService $catalog,
        private readonly CatalogPackageInstaller $installer,
        private readonly BackgroundProcessRunner $runner = new BackgroundProcessRunner(),
    ) {}

    /** @param list<string>|null $moduleIds @return list<array<string,mixed>> */
    public function plan(?array $moduleIds = null): array
    {
        $updates = array_column($this->catalog->updates(), null, 'id');
        $selected = $moduleIds === null ? array_keys($updates) : array_values(array_unique(array_map('strval', $moduleIds)));
        $plan = [];
        foreach ($selected as $id) {
            $module = $updates[$id] ?? null;
            if (!is_array($module) || empty($module['compatible']) || !is_array($module['release'] ?? null)) {
                throw new RuntimeException('Modulupdate ist nicht kompatibel oder nicht verfügbar: ' . $id);
            }
            $release = $module['release'];
            $plan[] = [
                'module_id' => $id,
                'name' => (string) $module['name'],
                'from' => (string) $module['installed_version'],
                'to' => (string) $module['available_version'],
                'migration_count' => (int) ($release['migration_count'] ?? 0),
                'dependencies' => is_array($release['dependencies'] ?? null) ? $release['dependencies'] : [],
                'backup' => true,
                'status' => 'waiting',
            ];
        }
        return $plan;
    }

    /** @param list<string> $moduleIds @return array<string,mixed> */
    public function start(array $moduleIds): array
    {
        $latest = $this->latest();
        if (is_array($latest) && ($latest['status'] ?? '') === 'running') throw new RuntimeException('Eine Modul-Aktualisierung läuft bereits.');
        $state = $this->queue($moduleIds);
        $id = (string) $state['operation_id'];
        try {
            $launch = $this->runner->launchPhp($this->basePath . '/bin/module-update-worker.php', [$id], $this->basePath . '/storage/logs/module-update-worker.log', $this->basePath);
        } catch (Throwable $error) {
            $completed = $this->status($id);
            if (is_array($completed) && ($completed['status'] ?? '') === 'succeeded') return $completed;
            if (is_array($completed) && ($completed['status'] ?? '') === 'failed') {
                throw new RuntimeException('Das Batch-Update wurde unmittelbar mit einem Fehler beendet. Details stehen im Modul-Lifecycle-Status.', 0, $error);
            }
            $this->recordFailure($id, 'worker_start_failed', $error->getMessage());
            throw $error;
        }
        $state = $this->status($id) ?? [];
        $state['pid'] = $launch['pid'];
        $this->write($id, $state);
        return $state;
    }

    /**
     * Creates a durable operation which a CLI/background process can execute.
     * Exposed separately so recovery tooling does not need to start a web request.
     *
     * @param list<string> $moduleIds
     * @return array<string,mixed>
     */
    public function queue(array $moduleIds): array
    {
        $plan = $this->plan($moduleIds);
        if ($plan === []) throw new RuntimeException('Es wurden keine kompatiblen Modulupdates ausgewählt.');
        $id = $this->uuid();
        $now = gmdate(DATE_ATOM);
        $this->pdo->prepare("INSERT INTO module_operations(operation_id,module_id,operation_type,phase,status) VALUES(?,?,'batch-update','queued','running')")
            ->execute([$id, self::JOURNAL_ID]);
        $state = [
            'operation_id' => $id,
            'operation' => 'batch-update',
            'status' => 'running',
            'phase' => 'queued',
            'current' => 0,
            'total' => count($plan),
            'modules' => $plan,
            'started_at' => $now,
            'updated_at' => $now,
        ];
        $this->write($id, $state);
        $this->writeLatestId($id);
        return $state;
    }

    /** @return array<string,mixed> */
    public function run(string $operationId): array
    {
        $state = $this->status($operationId);
        if (!is_array($state) || ($state['status'] ?? '') !== 'running' || !is_array($state['modules'] ?? null)) throw new RuntimeException('Ungültiger Batch-Update-Auftrag.');
        $failedCount = 0;
        $firstErrorMessage = null;
        foreach ($state['modules'] as $index => &$module) {
            $module['status'] = 'running';
            $state['phase'] = 'updating';
            $state['current'] = $index + 1;
            $state['updated_at'] = gmdate(DATE_ATOM);
            $this->write($operationId, $state);
            try {
                $this->installer->update((string) $module['module_id']);
                $module['status'] = 'succeeded';
                $module['ended_at'] = gmdate(DATE_ATOM);
            } catch (Throwable $error) {
                $failedCount++;
                $module['status'] = 'failed';
                $module['error'] = mb_substr($error->getMessage(), 0, 500);
                $module['ended_at'] = gmdate(DATE_ATOM);
                if ($firstErrorMessage === null) {
                    $firstErrorMessage = (string) $module['module_id'] . ': ' . $module['error'];
                }
            }
            $state['updated_at'] = gmdate(DATE_ATOM);
            $this->write($operationId, $state);
        }
        unset($module);

        $state['ended_at'] = gmdate(DATE_ATOM);
        $state['updated_at'] = gmdate(DATE_ATOM);

        if ($failedCount > 0) {
            $state['status'] = 'failed';
            $state['phase'] = ($failedCount === count($state['modules'])) ? 'failed' : 'partial_failure';
            $state['error_code'] = 'module_update_failed';
            $errorMsg = ($failedCount === count($state['modules']))
                ? ($firstErrorMessage ?? 'Alle Modulupdates fehlgeschlagen.')
                : ($failedCount . ' von ' . count($state['modules']) . ' Modulupdates fehlgeschlagen. Erstes Problem: ' . $firstErrorMessage);
            $this->pdo->prepare("UPDATE module_operations SET phase=?,status='failed',error_message=? WHERE operation_id=?")
                ->execute([$state['phase'], $errorMsg, $operationId]);
        } else {
            $state['status'] = 'succeeded';
            $state['phase'] = 'complete';
            $this->pdo->prepare("UPDATE module_operations SET phase='complete',status='succeeded',error_message=NULL WHERE operation_id=?")
                ->execute([$operationId]);
        }
        $this->write($operationId, $state);
        $this->log($state);
        return $state;
    }

    public function latest(): ?array
    {
        $pointer = $this->directory() . '/latest';
        $id = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
        if (preg_match('/^[a-f0-9-]{36}$/D', $id) !== 1) {
            $statement = $this->pdo->prepare("SELECT operation_id FROM module_operations WHERE module_id=? AND operation_type='batch-update' ORDER BY created_at DESC LIMIT 1");
            $statement->execute([self::JOURNAL_ID]);
            $id = (string) $statement->fetchColumn();
        }
        $state = is_string($id) ? $this->status($id) : null;
        if (is_array($state) && ($state['status'] ?? '') === 'running' && !$this->workerIsAlive($state)) {
            $this->recordFailure((string) $state['operation_id'], 'worker_interrupted', 'Der Hintergrundprozess wurde unterbrochen. Das aktuell bearbeitete Modul wurde durch seinen Lifecycle zurückgerollt; ausstehende Module wurden nicht gestartet.');
            return $this->status((string) $state['operation_id']);
        }
        return $state;
    }

    public function dismiss(?string $operationId = null): void
    {
        $latest = $this->latest();
        if ($latest === null) return;
        if ($operationId !== null && ($latest['operation_id'] ?? '') !== $operationId) return;
        if (($latest['status'] ?? '') === 'running') return;
        $pointer = $this->directory() . '/latest';
        if (is_file($pointer)) @unlink($pointer);
    }

    public function status(string $operationId): ?array
    {
        $file = $this->file($operationId);
        if (!is_file($file)) return null;
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function write(string $operationId, array $state): void
    {
        $dir = $this->directory();
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        file_put_contents($this->file($operationId), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function writeLatestId(string $operationId): void
    {
        $dir = $this->directory();
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        file_put_contents($dir . '/latest', $operationId, LOCK_EX);
    }

    private function recordFailure(string $operationId, string $errorCode, string $message): void
    {
        $state = $this->status($operationId) ?? [
            'operation_id' => $operationId,
            'operation' => 'batch-update',
            'modules' => [],
        ];
        $state['status'] = 'failed';
        $state['phase'] = 'failed';
        $state['error_code'] = $errorCode;
        $state['error'] = $message;
        $state['ended_at'] = gmdate(DATE_ATOM);
        $state['updated_at'] = gmdate(DATE_ATOM);
        $this->pdo->prepare("UPDATE module_operations SET phase='failed',status='failed',error_message=? WHERE operation_id=?")
            ->execute([$message, $operationId]);
        $this->write($operationId, $state);
        $this->log($state);
    }

    private function log(array $state): void
    {
        $dir = $this->basePath . '/storage/logs';
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        file_put_contents(
            $dir . '/module-lifecycle-' . gmdate('Y-m-d') . '.log',
            json_encode([
                'timestamp' => gmdate(DATE_ATOM),
                'module_id' => self::JOURNAL_ID,
                'operation' => 'batch-update',
                'phase' => $state['phase'],
                'status' => $state['status'],
                'started_at' => $state['started_at'] ?? null,
                'ended_at' => $state['ended_at'] ?? null,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function workerIsAlive(array $state): bool
    {
        $pid = (int) ($state['pid'] ?? 0);
        if ($pid <= 0) return false;
        if (DIRECTORY_SEPARATOR === '\\') return true;
        return posix_kill($pid, 0);
    }

    private function file(string $operationId): string
    {
        return $this->directory() . '/' . $operationId . '.json';
    }

    private function directory(): string
    {
        return $this->basePath . '/storage/modules/operations/batch-update';
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
