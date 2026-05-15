<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260514_000115_homepage_button_layout';
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
        return 'Button-Layout fuer Homepage-Inhaltsbloecke ergaenzen.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        if (!$schema->columnExists('homepage_blocks', 'button_layout')) {
            $pdo->exec(
                "ALTER TABLE homepage_blocks
                    ADD COLUMN button_layout ENUM('below_text', 'inline_right') NOT NULL DEFAULT 'below_text'
                    AFTER button_url"
            );
        }

        $pdo->exec(
            "ALTER TABLE homepage_blocks
                MODIFY COLUMN button_layout ENUM('below_text', 'inline_right') NOT NULL DEFAULT 'below_text'"
        );
    }
};
