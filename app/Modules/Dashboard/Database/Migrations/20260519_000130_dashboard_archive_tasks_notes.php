<?php

declare(strict_types=1);

use Modulon\Core\Database\Migration;
use Modulon\Core\Database\SchemaHelper;

return new class implements Migration {
    public function key(): string
    {
        return '20260519_000130_dashboard_archive_tasks_notes';
    }

    public function scope(): string
    {
        return 'module';
    }

    public function moduleKey(): ?string
    {
        return 'dashboard';
    }

    public function description(): string
    {
        return 'Archiv-Zeitstempel fuer Dashboard-Aufgaben und -Notizen ergaenzen.';
    }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        if ($schema->tableExists('dashboard_tasks') && !$schema->columnExists('dashboard_tasks', 'archived_at')) {
            $pdo->exec('ALTER TABLE dashboard_tasks ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER done_at');
        }

        if ($schema->tableExists('dashboard_tasks') && !$schema->indexExists('dashboard_tasks', 'idx_dashboard_tasks_widget_archive_sort')) {
            $pdo->exec('CREATE INDEX idx_dashboard_tasks_widget_archive_sort ON dashboard_tasks (widget_id, archived_at, is_done, sort_order)');
        }

        if ($schema->tableExists('dashboard_notes') && !$schema->columnExists('dashboard_notes', 'archived_at')) {
            $pdo->exec('ALTER TABLE dashboard_notes ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER is_archived');
        }

        if ($schema->tableExists('dashboard_notes') && $schema->columnExists('dashboard_notes', 'is_archived')) {
            $pdo->exec('UPDATE dashboard_notes SET archived_at = COALESCE(updated_at, CURRENT_TIMESTAMP) WHERE is_archived = 1 AND archived_at IS NULL');
        }

        if ($schema->tableExists('dashboard_notes') && !$schema->indexExists('dashboard_notes', 'idx_dashboard_notes_widget_archive_sort')) {
            $pdo->exec('CREATE INDEX idx_dashboard_notes_widget_archive_sort ON dashboard_notes (widget_id, archived_at, is_pinned, sort_order)');
        }
    }
};
