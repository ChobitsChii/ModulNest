<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260514_000105_homepage_schema';
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
        return 'Homepage-Blöcke und Veröffentlichungsstatus für die konfigurierbare Startseite vorbereiten.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $schema->runSqlFile(dirname(__DIR__) . '/schema.sql');
    }
};
