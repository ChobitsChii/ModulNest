<?php

declare(strict_types=1);

namespace Modulon\Core;

use Modulon\Core\Database\MigrationRunner;
use PDO;

/** Conservative, allowlisted recovery for known additive migration drift. */
final class RecoveryMigrationService
{
    public const SAFE_AUTOMATIC_REPAIR = 'safe_automatic_repair';
    public const METADATA_ONLY_REPAIR = 'metadata_only_repair';
    public const MANUAL_RECOVERY_REQUIRED = 'manual_recovery_required';

    public function __construct(private readonly PDO $pdo, private readonly string $basePath, private readonly RecoveryManager $recovery) {}

    /** @return array<int,array<string,mixed>> */
    public function diagnose(): array
    {
        $runner = new MigrationRunner($this->pdo, $this->basePath); $results = [];
        foreach ($runner->audit() as $row) {
            if ($row['status'] !== 'checksum_mismatch') { continue; }
            $assessment = $this->assess((string) $row['key']);
            $results[] = array_merge($assessment, [
                'key' => $row['key'], 'status' => $row['status'], 'expected_checksum' => $row['expected_checksum'],
                'stored_checksum' => $row['stored_checksum'],
            ]);
        }
        $this->recovery->appendLog('migration_diagnosis', ['mismatches' => count($results)]);
        return $results;
    }

    /** @return array{backup_path:string,recovery_id:string} */
    public function repair(string $key, string $displayedStoredChecksum): array
    {
        $match = null; foreach ($this->diagnose() as $row) { if ($row['key'] === $key) { $match = $row; break; } }
        if (!is_array($match) || !hash_equals((string) $match['stored_checksum'], $displayedStoredChecksum)) {
            throw new \RuntimeException('Der Recovery-Zustand hat sich geändert. Bitte erneut prüfen.');
        }
        if (!in_array($match['classification'], [self::SAFE_AUTOMATIC_REPAIR, self::METADATA_ONLY_REPAIR], true)) {
            throw new \RuntimeException('Diese Schemaabweichung ist nicht sicher automatisch reparierbar.');
        }
        $this->assertOnlyVerifiedCompanionMigrationsPending();
        $backup = $this->backupDatabase();
        $this->recovery->appendLog('recovery_backup_verified', ['migration_key' => $key, 'backup_path' => $backup]);
        if ($match['classification'] === self::SAFE_AUTOMATIC_REPAIR) {
            foreach ($match['safe_repairs'] as $repair) {
                $this->pdo->exec((string) $repair['sql']);
            }
            $this->recovery->appendLog('schema_safe_repair_applied', ['migration_key' => $key, 'repair_count' => count($match['safe_repairs'])]);
        }
        $after = $this->assess($key);
        if (($after['classification'] ?? '') !== self::METADATA_ONLY_REPAIR) {
            throw new \RuntimeException('Die Schema-Prüfung nach der Reparatur ist nicht eindeutig erfolgreich.');
        }
        $runner = new MigrationRunner($this->pdo, $this->basePath);
        $runner->repairStoredChecksum($key, (string) $match['expected_checksum']);
        // The historical Pages companion merely creates the same two columns
        // and indexes idempotently. It is allowed only after the exact schema
        // was verified above; arbitrary pending migrations are never run here.
        if ($this->pendingMigrationKeys() !== []) { $runner->run(); }
        if (!$this->isConsistent()) { throw new \RuntimeException('Die vollständige Migrationsprüfung ist nach der Reparatur nicht konsistent.'); }
        $state = $this->recovery->current() ?? [];
        $this->recovery->appendLog('migration_recovery_completed', ['migration_key' => $key, 'backup_path' => $backup, 'recovery_id' => $state['recovery_id'] ?? '']);
        return ['backup_path' => $backup, 'recovery_id' => (string) ($state['recovery_id'] ?? '')];
    }

    public function isConsistent(): bool
    {
        foreach ((new MigrationRunner($this->pdo, $this->basePath))->audit() as $row) {
            if (($row['status'] ?? '') !== 'ok') { return false; }
        }
        return true;
    }

    /** @return array{backup_path:string}|null */
    public function completeVerifiedPendingMigrations(): ?array
    {
        $pending = $this->pendingMigrationKeys();
        if ($pending === []) { return null; }
        $this->assertOnlyVerifiedCompanionMigrationsPending();
        // The only permitted companion is proven idempotent against the
        // verified Pages schema, but metadata is still mutated: back it up.
        $backup = $this->backupDatabase();
        (new MigrationRunner($this->pdo, $this->basePath))->run();
        if (!$this->isConsistent()) { throw new \RuntimeException('Begleitmigration konnte Recovery nicht vollständig abschließen.'); }
        $this->recovery->appendLog('verified_companion_migration_completed', ['backup_path' => $backup, 'migration_count' => count($pending)]);
        return ['backup_path' => $backup];
    }

    private function assertOnlyVerifiedCompanionMigrationsPending(): void
    {
        $pending = $this->pendingMigrationKeys();
        foreach ($pending as $key) {
            if ($key !== '20260521_000200_pages_header_footer_columns') {
                throw new \RuntimeException('Es sind weitere, nicht für Recovery freigegebene Migrationen ausstehend.');
            }
        }
    }

    /** @return array<int,string> */
    private function pendingMigrationKeys(): array
    {
        $pending = [];
        foreach ((new MigrationRunner($this->pdo, $this->basePath))->audit() as $row) {
            if (($row['status'] ?? '') === 'pending') { $pending[] = (string) $row['key']; }
        }
        return $pending;
    }

    /** @return array{classification:string,summary:string,deviations:array<int,string>,safe_repairs:array<int,array{label:string,sql:string}>} */
    private function assess(string $key): array
    {
        if ($key !== '20260521_000100_pages_schema') {
            return ['classification' => self::MANUAL_RECOVERY_REQUIRED, 'summary' => 'Für diese Migration existiert keine sichere automatische Reparatur.', 'deviations' => [], 'safe_repairs' => []];
        }
        $columns = $this->columns('pages_entries'); $indexes = $this->indexes('pages_entries'); $deviations = [];
        $expectedColumns = ['id'=>'bigint(20) unsigned','title'=>'varchar(180)','slug'=>'varchar(180)','content_markdown'=>'mediumtext','visibility'=>'varchar(20)','menu_group'=>'varchar(120)','show_in_header'=>'tinyint(1)','show_in_footer'=>'tinyint(1)','is_active'=>'tinyint(1)','sort_order'=>'int(11)','created_at'=>'timestamp','updated_at'=>'timestamp'];
        foreach ($expectedColumns as $column => $type) {
            if (!isset($columns[$column])) { $deviations[] = 'Spalte ' . $column . ' fehlt'; }
            elseif (strtolower((string) ($columns[$column]['Type'] ?? '')) !== $type) { $deviations[] = 'Spalte ' . $column . ' hat einen abweichenden Typ'; }
        }
        $expectedIndexes = ['uq_pages_entries_slug','idx_pages_entries_visibility_active','idx_pages_entries_menu_group','idx_pages_entries_header','idx_pages_entries_footer','idx_pages_entries_sort_order'];
        foreach ($expectedIndexes as $index) { if (!isset($indexes[$index])) { $deviations[] = 'Index ' . $index . ' fehlt'; } }
        $missingSafe = array_values(array_filter(['idx_pages_entries_header','idx_pages_entries_footer'], static fn (string $index): bool => !isset($indexes[$index])));
        $unsafe = array_values(array_filter($deviations, static fn (string $item): bool => !in_array($item, array_map(static fn (string $index): string => 'Index ' . $index . ' fehlt', ['idx_pages_entries_header','idx_pages_entries_footer']), true)));
        if ($deviations === []) { return ['classification' => self::METADATA_ONLY_REPAIR, 'summary' => 'Schema entspricht vollständig dem Soll; nur Migrationsmetadaten sind abweichend.', 'deviations' => [], 'safe_repairs' => []]; }
        if ($unsafe === [] && $missingSafe !== []) {
            $repairs = [];
            if (in_array('idx_pages_entries_header', $missingSafe, true)) { $repairs[] = ['label' => 'Index idx_pages_entries_header erstellen', 'sql' => 'CREATE INDEX idx_pages_entries_header ON pages_entries (show_in_header, visibility, is_active)']; }
            if (in_array('idx_pages_entries_footer', $missingSafe, true)) { $repairs[] = ['label' => 'Index idx_pages_entries_footer erstellen', 'sql' => 'CREATE INDEX idx_pages_entries_footer ON pages_entries (show_in_footer, visibility, is_active)']; }
            return ['classification' => self::SAFE_AUTOMATIC_REPAIR, 'summary' => 'Ausschließlich fehlende, additive Indizes wurden erkannt.', 'deviations' => $deviations, 'safe_repairs' => $repairs];
        }
        return ['classification' => self::MANUAL_RECOVERY_REQUIRED, 'summary' => 'Die Schemaabweichung ist nicht eindeutig additiv und erfordert manuelle Recovery.', 'deviations' => $deviations, 'safe_repairs' => []];
    }

    /** @return array<string,array<string,mixed>> */
    private function columns(string $table): array { $statement = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`'); $items = []; foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) { $items[(string) $row['Field']] = $row; } return $items; }
    /** @return array<string,bool> */
    private function indexes(string $table): array { $statement = $this->pdo->query('SHOW INDEX FROM `' . str_replace('`', '', $table) . '`'); $items = []; foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) { $items[(string) $row['Key_name']] = true; } return $items; }

    private function backupDatabase(): string
    {
        $config = require $this->basePath . '/app/Config/database.php'; if (($config['driver'] ?? 'mysql') !== 'mysql') { throw new \RuntimeException('Für diesen Datenbanktreiber ist keine sichere automatische Backup-Erstellung verfügbar.'); }
        $dir = $this->basePath . '/storage/backups/recovery'; if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Recovery-Backup-Verzeichnis nicht verfügbar.'); }
        $path = $dir . '/database-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql'; $binary = trim((string) shell_exec('command -v mariadb-dump || command -v mysqldump'));
        if ($binary === '') { throw new \RuntimeException('Kein unterstütztes Datenbank-Backup-Werkzeug verfügbar.'); }
        // Tabellen, Daten und Trigger des Anwendungs-Schemas werden gesichert.
        // Routinen/Events sind kein ModulNest-Installationsbestandteil; ihre
        // explizite Abfrage kann auf nicht aktualisierten MariaDB-Systemtabellen
        // fehlschlagen und würde sonst eine sichere Tabellen-Backup-Recovery
        // unnötig blockieren.
        $command = escapeshellarg($binary) . ' --host=' . escapeshellarg((string) $config['host']) . ' --port=' . escapeshellarg((string) $config['port']) . ' --user=' . escapeshellarg((string) $config['user']) . ' --single-transaction ' . escapeshellarg((string) $config['name']) . ' > ' . escapeshellarg($path);
        $env = $_ENV; $env['MYSQL_PWD'] = (string) $config['pass']; $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) { throw new \RuntimeException('Datenbank-Backup konnte nicht gestartet werden.'); }
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { stream_get_contents($pipe); fclose($pipe); } }
        if (proc_close($process) !== 0 || !is_file($path) || filesize($path) < 32) { @unlink($path); throw new \RuntimeException('Datenbank-Backup konnte nicht verifiziert werden.'); }
        return $path;
    }
}
