<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260904_000100_user_theme_preferences';
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
        return 'Speichert Theme-Modus und Sichtbarkeit des Theme-Umschalters pro Benutzer.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS theme_mode VARCHAR(16) NOT NULL DEFAULT 'system' AFTER timezone,
            ADD COLUMN IF NOT EXISTS theme_switcher_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER theme_mode");
    }
};
