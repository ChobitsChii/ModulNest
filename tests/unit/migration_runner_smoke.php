<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function migration_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * @return array<string,string>
 */
function migration_smoke_env(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $values[trim($key)] = $value;
    }

    return $values;
}

function migration_smoke_exec_sql_file(PDO $pdo, string $path): void
{
    $sql = trim((string) file_get_contents($path));
    if ($sql !== '') {
        $pdo->exec($sql);
    }
}

/**
 * @return array<int,string>
 */
function migration_smoke_tables(PDO $pdo): array
{
    return array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
}

$basePath = dirname(__DIR__, 2);
$env = migration_smoke_env($basePath . '/.env');
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';

$server = new PDO("mysql:host={$host};port={$port};charset={$charset}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);

$publicModules = ['Admin', 'Auth', 'Modules', 'User', 'Banking', 'Dashboard', 'DataPortability', 'Homepage', 'Logs', 'News', 'SneakPreview', 'Systeminfo', 'Tools', 'Updates', 'Wiki'];

$dbName = 'modulnest_migration_smoke_' . bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE `' . str_replace('`', '``', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $server->exec('USE `' . str_replace('`', '``', $dbName) . '`');
    $runner = new MigrationRunner($server, $basePath);
    $first = $runner->run($publicModules);
    migration_smoke_assert(count($first['errors']) === 0, 'Erster Migrationslauf enthält Fehler.');
    migration_smoke_assert(count($first['executed']) === 12, 'Erster Migrationslauf sollte 12 Migrationen ausführen.');

    $second = $runner->run($publicModules);
    migration_smoke_assert(count($second['executed']) === 0, 'Zweiter Migrationslauf darf nichts erneut ausführen.');
    migration_smoke_assert(count($second['skipped']) === 12, 'Zweiter Migrationslauf sollte 12 Migrationen überspringen.');

    $tables = migration_smoke_tables($server);
    foreach (['schema_migrations', 'users', 'modules', 'news_entries', 'homepage_blocks', 'homepage_block_buttons', 'homepage_block_items', 'dashboard_widgets', 'dashboard_tasks', 'dashboard_notes', 'banking_accounts', 'banking_transactions', 'banking_recurring_rules', 'sneak_preview_entries', 'sneak_preview_settings', 'wiki_sources', 'wiki_pages', 'wiki_assets', 'wiki_sync_runs'] as $table) {
        migration_smoke_assert(in_array($table, $tables, true), "Tabelle fehlt nach Migration: {$table}");
    }
    migration_smoke_assert((int) $server->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_tasks' AND COLUMN_NAME = 'archived_at'")->fetchColumn() === 1, 'dashboard_tasks.archived_at fehlt.');
    migration_smoke_assert((int) $server->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_notes' AND COLUMN_NAME = 'archived_at'")->fetchColumn() === 1, 'dashboard_notes.archived_at fehlt.');
    foreach (['mail_accounts', 'mail_message_index', 'card_sets', 'cards', 'user_cards', 'fantasy_card_user_state'] as $table) {
        migration_smoke_assert(!in_array($table, $tables, true), "Nicht ausgewähltes Modul wurde migriert: {$table}");
    }

    $executedCount = (int) $server->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    migration_smoke_assert($executedCount === 12, 'schema_migrations sollte 12 Einträge enthalten.');
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbName) . '`');
}

$oldDbName = 'modulnest_migration_old_smoke_' . bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE `' . str_replace('`', '``', $oldDbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $server->exec('USE `' . str_replace('`', '``', $oldDbName) . '`');
    migration_smoke_exec_sql_file($server, $basePath . '/app/Database/schema.sql');
    $server->exec("INSERT IGNORE INTO app_settings (`key`, `value`) VALUES ('migration_smoke_marker', 'keep')");
    $server->exec('DROP TABLE IF EXISTS schema_migrations');

    $runner = new MigrationRunner($server, $basePath);
    $result = $runner->run($publicModules);
    migration_smoke_assert(count($result['errors']) === 0, 'Migration über bestehendes Gesamtschema enthält Fehler.');
    migration_smoke_assert((string) $server->query("SELECT `value` FROM app_settings WHERE `key` = 'migration_smoke_marker'")->fetchColumn() === 'keep', 'Bestehende Daten wurden verändert.');
    migration_smoke_assert((int) $server->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 12, 'Migrationen wurden in alter DB nicht markiert.');
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $oldDbName) . '`');
}

fwrite(STDOUT, "Migration runner smoke test passed.\n");
