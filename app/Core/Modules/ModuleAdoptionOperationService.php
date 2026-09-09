<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use Modulon\Core\BackgroundProcessLaunchException;
use Modulon\Core\BackgroundProcessRunner;
use PDO;
use RuntimeException;
use Throwable;

final class ModuleAdoptionOperationService
{
    private const MODULE_ID = 'modulnest.tools';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $basePath,
        private readonly ?LegacyModuleAdoptionService $adoption = null,
        private readonly BackgroundProcessRunner $processRunner = new BackgroundProcessRunner(),
    ) {
    }

    /** @return array<string,mixed> */
    public function start(string $moduleId): array
    {
        if ($moduleId !== self::MODULE_ID) {
            throw new RuntimeException('Diese Moduladoption wird nicht im Hintergrund ausgeführt.');
        }
        $running = $this->latest($moduleId);
        if (is_array($running) && ($running['status'] ?? '') === 'running') {
            if ($this->workerIsAlive($running)) {
                throw new RuntimeException('Für das Modul läuft bereits eine Adoption.');
            }
            $this->fail((string) $running['operation_id'], 'worker_interrupted', 'Der Hintergrundprozess wurde unterbrochen. Der Modul-v1-Stand blieb aktiv.');
        }

        $this->removeIncompleteStages($moduleId);
        $this->removePreparedRelease($moduleId);
        $operationId = $this->uuid();
        $this->pdo->prepare(
            "INSERT INTO module_operations(operation_id,module_id,operation_type,phase,status) VALUES(?,?,'adoption','queued','running')"
        )->execute([$operationId, $moduleId]);
        $this->writeState($operationId, [
            'operation_id' => $operationId,
            'module_id' => $moduleId,
            'operation' => 'adoption',
            'status' => 'running',
            'phase' => 'queued',
            'message' => 'Die Adoption wurde zur Verarbeitung eingeplant.',
            'started_at' => gmdate(DATE_ATOM),
            'updated_at' => gmdate(DATE_ATOM),
        ]);
        $this->log($moduleId, $operationId, 'queued', 'running');

        $worker = $this->basePath . '/bin/module-adoption-worker.php';
        $log = $this->logDirectory() . '/module-adoption-worker.log';
        try {
            $launch = $this->processRunner->launchPhp($worker, [$operationId, $moduleId], $log, $this->basePath);
        } catch (BackgroundProcessLaunchException $error) {
            $this->fail($operationId, $error->reasonCode, $error->getMessage());
            throw $error;
        }
        $this->progress($operationId, ['pid' => $launch['pid']]);
        return $this->status($operationId) ?? [];
    }

    public function run(string $operationId, string $moduleId): void
    {
        if ($moduleId !== self::MODULE_ID || preg_match('/^[a-f0-9-]{36}$/D', $operationId) !== 1 || $this->adoption === null) {
            throw new RuntimeException('Ungültiger Adoptionsauftrag.');
        }
        $row = $this->byId($operationId);
        if (!is_array($row) || ($row['module_id'] ?? '') !== $moduleId || ($row['status'] ?? '') !== 'running') {
            throw new RuntimeException('Der Adoptionsauftrag ist nicht mehr ausführbar.');
        }

        $lock = new ModuleOperationLock($this->basePath . '/storage/locks/module-adoptions');
        try {
            $lock->acquire($moduleId);
            $this->progress($operationId, ['pid' => getmypid(), 'phase' => 'preflight', 'message' => 'Sicherheitsprüfung läuft.']);
            $this->adoption->adopt($moduleId, function (array $progress) use ($operationId): void {
                $this->progress($operationId, $progress);
            });
            $this->pdo->prepare("UPDATE module_operations SET phase='complete',status='succeeded',error_message=NULL WHERE operation_id=?")
                ->execute([$operationId]);
            $this->progress($operationId, [
                'phase' => 'complete', 'status' => 'succeeded',
                'message' => 'Die Modul-v2-Adoption wurde erfolgreich abgeschlossen.',
                'ended_at' => gmdate(DATE_ATOM),
            ]);
        } catch (Throwable $error) {
            $this->fail($operationId, $this->errorCode($error), $error->getMessage());
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public function recordWorkerFailure(string $operationId, Throwable $error): void
    {
        $operation = $this->byId($operationId);
        if (is_array($operation) && ($operation['status'] ?? '') === 'running') {
            $this->fail($operationId, $this->errorCode($error), $error->getMessage());
        }
    }

    /** @return array<string,mixed>|null */
    public function latest(string $moduleId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM module_operations WHERE module_id=? AND operation_type='adoption' ORDER BY created_at DESC LIMIT 1"
        );
        $statement->execute([$moduleId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $operation = array_replace($row, $this->readState((string) $row['operation_id']));
        if (($operation['status'] ?? '') === 'running' && !$this->workerIsAlive($operation)) {
            $this->recoverInterrupted($moduleId, (string) $operation['operation_id']);
            $row = $this->byId((string) $operation['operation_id']);
            return is_array($row) ? array_replace($row, $this->readState((string) $operation['operation_id'])) : null;
        }
        return $operation;
    }

    /** @return array<string,mixed>|null */
    public function status(string $operationId): ?array
    {
        $row = $this->byId($operationId);
        if (!is_array($row)) return null;
        return array_replace($row, $this->readState($operationId));
    }

    /** @param array<string,mixed> $values */
    private function progress(string $operationId, array $values): void
    {
        $previous = $this->readState($operationId);
        $state = array_replace($previous, $values, ['updated_at' => gmdate(DATE_ATOM)]);
        $phase = (string) ($state['phase'] ?? 'running');
        $status = (string) ($state['status'] ?? 'running');
        $this->pdo->prepare('UPDATE module_operations SET phase=?,status=? WHERE operation_id=?')->execute([$phase, $status, $operationId]);
        $this->writeState($operationId, $state);
        if (($previous['phase'] ?? null) !== $phase || ($previous['status'] ?? null) !== $status) {
            $this->log((string) ($state['module_id'] ?? self::MODULE_ID), $operationId, $phase, $status);
        }
    }

    private function fail(string $operationId, string $errorCode, string $message): void
    {
        $safeMessage = mb_substr(trim($message), 0, 1000);
        $this->pdo->prepare("UPDATE module_operations SET status='failed',phase='failed',error_message=? WHERE operation_id=?")
            ->execute([$errorCode . ': ' . $safeMessage, $operationId]);
        $state = array_replace($this->readState($operationId), [
            'status' => 'failed', 'phase' => 'failed', 'error_code' => $errorCode,
            'message' => $safeMessage, 'ended_at' => gmdate(DATE_ATOM), 'updated_at' => gmdate(DATE_ATOM),
        ]);
        $this->writeState($operationId, $state);
        $this->log((string) ($state['module_id'] ?? self::MODULE_ID), $operationId, 'failed', 'failed', $errorCode);
    }

    /** @return array<string,mixed>|null */
    private function byId(string $operationId): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM module_operations WHERE operation_id=? AND operation_type='adoption'");
        $statement->execute([$operationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function workerIsAlive(array $row): bool
    {
        $state = $this->readState((string) $row['operation_id']);
        $pid = (int) ($state['pid'] ?? 0);
        $updatedAt = strtotime((string) ($state['updated_at'] ?? ''));
        $recentlyQueued = $updatedAt !== false && time() - $updatedAt < 2;
        if ($pid < 2) return $recentlyQueued;
        if (!is_dir('/proc/' . $pid)) return false;
        $commandLine = @file_get_contents('/proc/' . $pid . '/cmdline');
        if (is_string($commandLine)
            && str_contains($commandLine, 'module-adoption-worker.php')
            && str_contains($commandLine, (string) $row['operation_id'])) return true;
        return $recentlyQueued;
    }

    private function removeIncompleteStages(string $moduleId): void
    {
        $patterns = [
            $this->basePath . '/storage/adoption-backups/' . str_replace('.', '-', $moduleId) . '-*.stage',
            $this->basePath . '/storage/modules/' . $moduleId . '.adoption-*',
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (is_dir($path)) ModulePackageInspector::removeTree($path);
            }
        }
    }

    private function removePreparedRelease(string $moduleId): void
    {
        $legacy = $this->pdo->prepare("SELECT COUNT(*) FROM modules WHERE route_prefix='tools' AND module_key IS NULL AND is_active=1");
        $legacy->execute();
        $installation = $this->pdo->prepare('SELECT COUNT(*) FROM module_installations WHERE module_id=?');
        $installation->execute([$moduleId]);
        if ((int) $legacy->fetchColumn() !== 1 || (int) $installation->fetchColumn() !== 0) return;
        $this->pdo->prepare('DELETE FROM module_data_resources WHERE module_id=?')->execute([$moduleId]);
        $this->pdo->prepare('DELETE FROM module_releases WHERE module_id=?')->execute([$moduleId]);
        foreach ([$this->basePath . '/modules/' . $moduleId, $this->basePath . '/public/assets/modules/' . $moduleId] as $path) {
            if (is_dir($path)) ModulePackageInspector::removeTree($path);
        }
    }

    private function recoverInterrupted(string $moduleId, string $operationId): void
    {
        $installed = $this->pdo->prepare(
            'SELECT COUNT(*) FROM module_installations i JOIN modules m ON m.id=i.module_row_id WHERE i.module_id=? AND i.installed_version IS NOT NULL AND m.module_key=?'
        );
        $installed->execute([$moduleId, $moduleId]);
        if ((int) $installed->fetchColumn() === 1) {
            $legacyStorage = $this->basePath . '/storage/tools/speech';
            if (is_dir($legacyStorage)) ModulePackageInspector::removeTree($legacyStorage);
            $this->pdo->prepare("UPDATE module_operations SET phase='complete',status='succeeded',error_message=NULL WHERE operation_id=?")
                ->execute([$operationId]);
            $this->progress($operationId, [
                'phase' => 'complete', 'status' => 'succeeded', 'error_code' => null,
                'message' => 'Die bereits atomar umgeschaltete Adoption wurde nach einem Worker-Abbruch sicher abgeschlossen.',
                'ended_at' => gmdate(DATE_ATOM),
            ]);
            return;
        }

        $legacy = $this->pdo->prepare("SELECT COUNT(*) FROM modules WHERE route_prefix='tools' AND module_key IS NULL AND is_active=1");
        $legacy->execute();
        if ((int) $legacy->fetchColumn() === 1) {
            $newStorage = $this->basePath . '/storage/modules/' . $moduleId;
            if (is_dir($newStorage)) ModulePackageInspector::removeTree($newStorage);
            $this->removeIncompleteStages($moduleId);
            $this->removePreparedRelease($moduleId);
            $this->fail($operationId, 'worker_interrupted', 'Der Hintergrundprozess wurde unterbrochen. Unvollständige v2-Artefakte wurden entfernt; Modul v1 blieb aktiv.');
            return;
        }
        $this->fail($operationId, 'manual_recovery_required', 'Der Hintergrundprozess wurde unterbrochen; der Registryzustand muss vor einem neuen Versuch geprüft werden.');
    }

    /** @return array<string,mixed> */
    private function readState(string $operationId): array
    {
        $path = $this->stateDirectory() . '/' . $operationId . '.json';
        if (!is_file($path)) return [];
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $state */
    private function writeState(string $operationId, array $state): void
    {
        $directory = $this->stateDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Adoptionsstatus konnte nicht gespeichert werden.');
        }
        $path = $directory . '/' . $operationId . '.json';
        $temporary = $path . '.tmp-' . getmypid();
        file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        if (!rename($temporary, $path)) throw new RuntimeException('Adoptionsstatus konnte nicht atomar aktualisiert werden.');
    }

    private function stateDirectory(): string
    {
        return $this->basePath . '/storage/module-operations/adoptions';
    }

    private function logDirectory(): string
    {
        $directory = $this->basePath . '/storage/logs';
        if (!is_dir($directory)) mkdir($directory, 0770, true);
        return $directory;
    }

    private function log(string $moduleId, string $operationId, string $phase, string $status, ?string $errorCode = null): void
    {
        $entry = [
            'timestamp' => gmdate(DATE_ATOM), 'module_id' => $moduleId, 'operation' => 'adoption',
            'operation_id' => $operationId, 'phase' => $phase, 'status' => $status,
        ];
        if ($phase === 'queued') $entry['started_at'] = $entry['timestamp'];
        if (in_array($status, ['succeeded', 'failed'], true)) $entry['ended_at'] = $entry['timestamp'];
        if ($errorCode !== null) $entry['error_code'] = $errorCode;
        file_put_contents(
            $this->logDirectory() . '/module-lifecycle-' . gmdate('Y-m-d') . '.log',
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    private function errorCode(Throwable $error): string
    {
        return match (true) {
            str_contains($error->getMessage(), 'Lock'), str_contains($error->getMessage(), 'Operation') => 'operation_locked',
            str_contains($error->getMessage(), 'verifiziert') => 'verification_failed',
            str_contains($error->getMessage(), 'Storage'), str_contains($error->getMessage(), 'Datei') => 'storage_failed',
            default => 'adoption_failed',
        };
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-' . dechex(random_int(8, 11)) . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }
}
