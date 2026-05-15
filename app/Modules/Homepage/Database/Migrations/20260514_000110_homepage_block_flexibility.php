<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260514_000110_homepage_block_flexibility';
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
        return 'Homepage-Blöcke um flexible Breiten, mehrere Buttons und Feature-Listen erweitern.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec(
            "ALTER TABLE homepage_blocks
                MODIFY COLUMN type ENUM('custom_content', 'module_list', 'feature_list') NOT NULL DEFAULT 'custom_content',
                MODIFY COLUMN column_span ENUM('full', 'half', 'two_thirds', 'one_third') NOT NULL DEFAULT 'full'"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS homepage_block_buttons (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                block_id BIGINT UNSIGNED NOT NULL,
                label VARCHAR(120) NOT NULL,
                url VARCHAR(255) NOT NULL,
                variant ENUM('primary', 'secondary') NOT NULL DEFAULT 'primary',
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_homepage_block_buttons_block
                    FOREIGN KEY (block_id) REFERENCES homepage_blocks(id)
                    ON DELETE CASCADE,
                INDEX idx_homepage_block_buttons_block_sort (block_id, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS homepage_block_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                block_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(190) NOT NULL,
                content_markdown MEDIUMTEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_homepage_block_items_block
                    FOREIGN KEY (block_id) REFERENCES homepage_blocks(id)
                    ON DELETE CASCADE,
                INDEX idx_homepage_block_items_block_sort (block_id, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "INSERT INTO homepage_block_buttons (block_id, label, url, variant, sort_order)
             SELECT id, button_label, button_url, 'primary', 10
             FROM homepage_blocks
             WHERE button_label IS NOT NULL
               AND button_label <> ''
               AND button_url IS NOT NULL
               AND button_url <> ''
               AND NOT EXISTS (
                   SELECT 1
                   FROM homepage_block_buttons
                   WHERE homepage_block_buttons.block_id = homepage_blocks.id
               )"
        );
    }
};
