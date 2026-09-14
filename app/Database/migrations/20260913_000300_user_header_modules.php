<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260913_000300_user_header_modules';
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
        return 'Ergänzt individuelle Header-Navigation-Pins für Benutzer.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS header_modules TEXT NULL AFTER favorite_modules");
    }
};
