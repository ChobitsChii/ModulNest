<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string { return '20260902_000100_example_notes'; }
    public function scope(): string { return 'module'; }
    public function moduleKey(): ?string { return 'example-notes'; }
    public function description(): string { return 'Legt die kleine Example-Notes-Tabelle an.'; }
    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        if ($schema->tableExists('example_notes')) { return; }
        $pdo->exec('CREATE TABLE example_notes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, title VARCHAR(160) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_example_notes_user (user_id, is_active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
