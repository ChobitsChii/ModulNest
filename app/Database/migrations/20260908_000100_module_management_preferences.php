<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string { return '20260908_000100_module_management_preferences'; }
    public function scope(): string { return 'core'; }
    public function moduleKey(): ?string { return null; }
    public function description(): string { return 'Speichert die sichtbaren Spalten der Modulverwaltung pro Benutzer.'; }
    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS module_management_columns TEXT NULL AFTER theme_switcher_visible');
    }
};
