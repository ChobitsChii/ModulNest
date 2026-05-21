<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260521_000200_pages_header_footer_columns';
    }

    public function scope(): string
    {
        return 'module';
    }

    public function moduleKey(): ?string
    {
        return 'pages';
    }

    public function description(): string
    {
        return 'Ergänzt show_in_header/show_in_footer für pages_entries.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        if (!$schema->tableExists('pages_entries')) {
            return;
        }

        if (!$schema->columnExists('pages_entries', 'show_in_header')) {
            $pdo->exec('ALTER TABLE pages_entries ADD COLUMN show_in_header TINYINT(1) NOT NULL DEFAULT 0 AFTER menu_group');
        }

        if (!$schema->columnExists('pages_entries', 'show_in_footer')) {
            $pdo->exec('ALTER TABLE pages_entries ADD COLUMN show_in_footer TINYINT(1) NOT NULL DEFAULT 0 AFTER show_in_header');
        }

        if (!$schema->indexExists('pages_entries', 'idx_pages_entries_header')) {
            $pdo->exec('CREATE INDEX idx_pages_entries_header ON pages_entries (show_in_header, visibility, is_active)');
        }

        if (!$schema->indexExists('pages_entries', 'idx_pages_entries_footer')) {
            $pdo->exec('CREATE INDEX idx_pages_entries_footer ON pages_entries (show_in_footer, visibility, is_active)');
        }
    }
};

