<?php

declare(strict_types=1);

use Modulon\Modules\Dashboard\DashboardRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function dashboard_archive_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: SQLite PDO driver is not available.\n");
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE dashboard_widgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    widget_type TEXT NOT NULL,
    title TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    layout_width INTEGER NOT NULL DEFAULT 6,
    is_active INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE dashboard_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    widget_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    details TEXT NULL,
    link_url TEXT NULL,
    priority TEXT NOT NULL DEFAULT "normal",
    due_at TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_done INTEGER NOT NULL DEFAULT 0,
    done_at TEXT NULL,
    archived_at TEXT NULL,
    repeat_type TEXT NULL,
    repeat_time TEXT NULL,
    repeat_weekday INTEGER NULL,
    repeat_month_mode TEXT NULL,
    repeat_month_day INTEGER NULL,
    repeat_month_ordinal INTEGER NULL,
    repeat_month_weekday INTEGER NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE dashboard_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    widget_id INTEGER NOT NULL,
    title TEXT NULL,
    content TEXT NOT NULL,
    textarea_height INTEGER NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_pinned INTEGER NOT NULL DEFAULT 0,
    is_archived INTEGER NOT NULL DEFAULT 0,
    archived_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec("INSERT INTO dashboard_widgets (id, user_id, widget_type, title, sort_order, layout_width, is_active)
    VALUES (1, 1, 'tasks', 'Tasks', 1, 6, 1), (2, 1, 'notes', 'Notes', 2, 6, 1), (3, 2, 'tasks', 'Other', 1, 6, 1)");
$pdo->exec("INSERT INTO dashboard_tasks (id, widget_id, title, details, sort_order, repeat_type)
    VALUES (10, 1, 'Archive me', '', 1, 'none'), (30, 3, 'Foreign task', '', 1, 'none')");
$pdo->exec("INSERT INTO dashboard_notes (id, widget_id, title, content, sort_order)
    VALUES (20, 2, 'Note', 'Archive me too', 1)");

$repo = new DashboardRepository($pdo);

dashboard_archive_assert(count($repo->listTasksByWidgetIds([1])[1] ?? []) === 1, 'Aktive Aufgabe fehlt vor Archivierung.');
dashboard_archive_assert(($repo->listArchivedTasksByWidgetIds([1])[1] ?? []) === [], 'Archivierte Aufgabenliste ist vor Archivierung nicht leer.');
dashboard_archive_assert($repo->findTaskForUser(10, 2) === null, 'Fremder User kann Aufgabe sehen.');

$repo->setTaskArchived(10, true);
dashboard_archive_assert(($repo->listTasksByWidgetIds([1])[1] ?? []) === [], 'Archivierte Aufgabe bleibt in aktiver Liste.');
dashboard_archive_assert(count($repo->listArchivedTasksByWidgetIds([1])[1] ?? []) === 1, 'Archivierte Aufgabe fehlt in Archivliste.');

$repo->setTaskArchived(10, false);
dashboard_archive_assert(count($repo->listTasksByWidgetIds([1])[1] ?? []) === 1, 'Wiederhergestellte Aufgabe fehlt in aktiver Liste.');
dashboard_archive_assert(($repo->listArchivedTasksByWidgetIds([1])[1] ?? []) === [], 'Wiederhergestellte Aufgabe bleibt in Archivliste.');

dashboard_archive_assert(count($repo->listNotesByWidgetIds([2])[2] ?? []) === 1, 'Aktive Notiz fehlt vor Archivierung.');
dashboard_archive_assert($repo->findNoteForUser(20, 2) === null, 'Fremder User kann Notiz sehen.');

$repo->setNoteArchived(20, true);
dashboard_archive_assert(($repo->listNotesByWidgetIds([2])[2] ?? []) === [], 'Archivierte Notiz bleibt in aktiver Liste.');
dashboard_archive_assert(count($repo->listArchivedNotesByWidgetIds([2])[2] ?? []) === 1, 'Archivierte Notiz fehlt in Archivliste.');

$repo->setNoteArchived(20, false);
dashboard_archive_assert(count($repo->listNotesByWidgetIds([2])[2] ?? []) === 1, 'Wiederhergestellte Notiz fehlt in aktiver Liste.');
dashboard_archive_assert(($repo->listArchivedNotesByWidgetIds([2])[2] ?? []) === [], 'Wiederhergestellte Notiz bleibt in Archivliste.');

fwrite(STDOUT, "Dashboard archive smoke test passed.\n");
