<?php
declare(strict_types=1);

use Modulon\Core\Database\{Migration, SchemaHelper};

return new class implements Migration {
    public function key(): string { return '20260903_020000_wiki_active_source'; }
    public function scope(): string { return 'module'; }
    public function moduleKey(): ?string { return 'wiki'; }
    public function description(): string { return 'Trennt konfigurierte Wiki-Quelle vom zuletzt synchronisierten Stand.'; }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("ALTER TABLE wiki_sources
            ADD COLUMN IF NOT EXISTS active_source_type VARCHAR(16) NULL AFTER source_type,
            ADD COLUMN IF NOT EXISTS active_repository_owner VARCHAR(100) NULL AFTER active_source_type,
            ADD COLUMN IF NOT EXISTS active_repository_name VARCHAR(100) NULL AFTER active_repository_owner,
            ADD COLUMN IF NOT EXISTS active_ref_name VARCHAR(160) NULL AFTER active_repository_name,
            ADD COLUMN IF NOT EXISTS active_docs_root VARCHAR(255) NULL AFTER active_ref_name");
        $pdo->exec("UPDATE wiki_sources
            SET active_source_type = source_type,
                active_repository_owner = repository_owner,
                active_repository_name = repository_name,
                active_ref_name = ref_name,
                active_docs_root = docs_root
            WHERE active_source_type IS NULL");
    }
};
