<?php

declare(strict_types=1);

namespace Modulon\Core\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    /**
     * @param callable(string,array<string,mixed>):void|null $logger
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $basePath,
        private readonly mixed $logger = null,
    ) {
    }

    /**
     * @param array<int,string>|null $moduleDirectories
     * @return array{executed:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
     */
    public function run(?array $moduleDirectories = null): array
    {
        $this->ensureMigrationTable();

        $result = [
            'executed' => [],
            'skipped' => [],
            'errors' => [],
        ];
        $schema = new SchemaHelper($this->pdo);

        foreach ($this->discover($moduleDirectories) as $migration) {
            $key = $migration->key();
            $checksum = $this->checksum($migration);
            $existing = $this->existingMigration($key);
            if ($existing !== null) {
                $storedChecksum = (string) ($existing['checksum'] ?? '');
                if ($storedChecksum !== '' && $storedChecksum !== $checksum) {
                    $message = 'Migration-Checksum stimmt nicht mehr: ' . $key;
                    $result['errors'][] = ['key' => $key, 'message' => $message];
                    throw new RuntimeException($message);
                }
                $result['skipped'][] = $this->migrationPayload($migration, $checksum);
                continue;
            }

            try {
                $migration->up($this->pdo, $schema);
                $this->recordMigration($migration, $checksum);
                $payload = $this->migrationPayload($migration, $checksum);
                $result['executed'][] = $payload;
                $this->log('Migration ausgeführt', $payload);
            } catch (Throwable $throwable) {
                $payload = $this->migrationPayload($migration, $checksum);
                $payload['message'] = $throwable->getMessage();
                $result['errors'][] = $payload;
                $this->log('Migration fehlgeschlagen', $payload);
                throw $throwable;
            }
        }

        return $result;
    }

    public function ensureMigrationTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration_key VARCHAR(190) NOT NULL,
                scope VARCHAR(40) NOT NULL,
                module_key VARCHAR(120) NULL,
                description VARCHAR(255) NULL,
                checksum CHAR(64) NULL,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_schema_migrations_key (migration_key),
                INDEX idx_schema_migrations_scope_module (scope, module_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function audit(?array $moduleDirectories = null): array
    {
        $this->ensureMigrationTable();
        $rows = [];
        foreach ($this->discover($moduleDirectories) as $migration) {
            $checksum = $this->checksum($migration);
            $existing = $this->existingMigration($migration->key());
            $rows[] = [
                'migration' => $migration, 'key' => $migration->key(), 'expected_checksum' => $checksum,
                'stored_checksum' => is_array($existing) ? (string) ($existing['checksum'] ?? '') : '',
                'status' => $existing === null ? 'pending' : ((string) ($existing['checksum'] ?? '') === $checksum ? 'ok' : 'checksum_mismatch'),
            ];
        }
        return $rows;
    }

    public function repairStoredChecksum(string $key, string $checksum): void
    {
        $statement = $this->pdo->prepare('UPDATE schema_migrations SET checksum = :checksum WHERE migration_key = :key');
        $statement->execute(['checksum' => $checksum, 'key' => $key]);
        if ($statement->rowCount() !== 1) { throw new RuntimeException('Migrationsmetadaten konnten nicht aktualisiert werden.'); }
    }

    /**
     * @param array<int,string>|null $moduleDirectories
     * @return array<int,Migration>
     */
    private function discover(?array $moduleDirectories): array
    {
        $paths = [];
        foreach (glob($this->basePath . '/app/Database/migrations/*.php') ?: [] as $path) {
            $paths[] = $path;
        }

        foreach ($this->moduleDirectories($moduleDirectories) as $moduleDirectory) {
            foreach (glob($this->basePath . '/app/Modules/' . $moduleDirectory . '/Database/Migrations/*.php') ?: [] as $path) {
                $paths[] = $path;
            }
        }

        sort($paths, SORT_STRING);

        $migrations = [];
        foreach ($paths as $path) {
            $migration = require $path;
            if (!$migration instanceof Migration) {
                throw new RuntimeException('Ungültige Migration: ' . $this->relativePath($path));
            }
            $migrations[] = $migration;
        }

        usort($migrations, static fn (Migration $a, Migration $b): int => strcmp($a->key(), $b->key()));

        return $migrations;
    }

    /**
     * @param array<int,string>|null $moduleDirectories
     * @return array<int,string>
     */
    private function moduleDirectories(?array $moduleDirectories): array
    {
        if ($moduleDirectories !== null) {
            return array_values(array_unique(array_filter(array_map('strval', $moduleDirectories))));
        }

        $directories = [];
        foreach (glob($this->basePath . '/app/Modules/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $directories[] = basename($directory);
        }
        sort($directories, SORT_STRING);

        return $directories;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function existingMigration(string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM schema_migrations WHERE migration_key = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function recordMigration(Migration $migration, string $checksum): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO schema_migrations (migration_key, scope, module_key, description, checksum)
             VALUES (:migration_key, :scope, :module_key, :description, :checksum)'
        );
        $statement->execute([
            'migration_key' => $migration->key(),
            'scope' => $migration->scope(),
            'module_key' => $migration->moduleKey(),
            'description' => $migration->description(),
            'checksum' => $checksum,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function migrationPayload(Migration $migration, string $checksum): array
    {
        return [
            'key' => $migration->key(),
            'scope' => $migration->scope(),
            'module_key' => $migration->moduleKey(),
            'description' => $migration->description(),
            'checksum' => $checksum,
        ];
    }

    private function checksum(Migration $migration): string
    {
        $class = new \ReflectionClass($migration);
        $file = $class->getFileName();
        if (!is_string($file) || !is_file($file)) {
            return hash('sha256', $migration->key());
        }

        return hash_file('sha256', $file) ?: hash('sha256', $migration->key());
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $message, array $context): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($message, $context);
        }
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->basePath, '/') . '/';
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }
}
