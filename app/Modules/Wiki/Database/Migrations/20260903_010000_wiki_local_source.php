<?php
declare(strict_types=1);
use Modulon\Core\Database\{Migration,SchemaHelper};
return new class implements Migration { public function key(): string{return '20260903_010000_wiki_local_source';} public function scope(): string{return 'module';} public function moduleKey(): ?string{return 'wiki';} public function description(): string{return 'Ergänzt lokale, relative Wiki-Quellen.';} public function up(\PDO $pdo, SchemaHelper $schema): void { $pdo->exec("ALTER TABLE wiki_sources ADD COLUMN IF NOT EXISTS source_type VARCHAR(16) NOT NULL DEFAULT 'github' AFTER id"); } };
