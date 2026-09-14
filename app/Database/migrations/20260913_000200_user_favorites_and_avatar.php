<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260913_000200_user_favorites_and_avatar';
    }

    public function scope(): string
    {
        return 'core';
    }

    public function moduleKey(): ?string
    {
        return null;
    }

    public function description(): string
    {
        return 'Ergänzt Modul-Favoriten und Profil-Avatar für Benutzer.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS favorite_modules TEXT NULL AFTER admin_nav_layout,
            ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255) NULL AFTER favorite_modules");
    }
};
