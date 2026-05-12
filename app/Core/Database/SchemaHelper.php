<?php

declare(strict_types=1);

namespace Modulon\Core\Database;

use PDO;
use RuntimeException;

final class SchemaHelper
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function runSqlFile(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('SQL-Datei nicht gefunden: ' . $path);
        }

        $sql = trim((string) file_get_contents($path));
        if ($sql === '') {
            return;
        }

        $this->pdo->exec($sql);
    }

    public function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function indexExists(string $table, string $index): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index_name'
        );
        $statement->execute(['table' => $table, 'index_name' => $index]);

        return (int) $statement->fetchColumn() > 0;
    }
}
