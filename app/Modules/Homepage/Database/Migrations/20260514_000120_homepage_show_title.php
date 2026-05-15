<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260514_000120_homepage_show_title';
    }

    public function scope(): string
    {
        return 'module';
    }

    public function moduleKey(): ?string
    {
        return 'homepage';
    }

    public function description(): string
    {
        return 'Sichtbarkeit des Homepage-Blocktitels separat steuerbar machen.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        if (!$schema->columnExists('homepage_blocks', 'show_title')) {
            $pdo->exec(
                "ALTER TABLE homepage_blocks
                    ADD COLUMN show_title TINYINT(1) NOT NULL DEFAULT 1
                    AFTER title"
            );
        }

        $pdo->exec("UPDATE homepage_blocks SET show_title = 1 WHERE show_title IS NULL");
    }
};
