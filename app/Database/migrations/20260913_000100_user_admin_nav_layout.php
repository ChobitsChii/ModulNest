<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260913_000100_user_admin_nav_layout';
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
        return 'Speichert das bevorzugte Admin-Navigations-Layout (Tabs vs. Sidebar) pro Benutzer.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS admin_nav_layout VARCHAR(16) NOT NULL DEFAULT 'tabs' AFTER theme_switcher_visible");
    }
};
