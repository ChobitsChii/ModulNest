<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;
return new class implements Migration {
    public function key(): string
    {
        return '20260510_000104_sneak_preview_070_schema';
    }

    public function scope(): string
    {
        return 'module';
    }

    public function moduleKey(): ?string
    {
        return 'sneak-preview';
    }

    public function description(): string
    {
        return 'Sneak-Preview-Einträge und Einstellungen für ModulNest 0.7.0 absichern.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $schema->runSqlFile(dirname(__DIR__) . '/schema.sql');
    }
};
