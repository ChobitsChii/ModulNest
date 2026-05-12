#!/usr/bin/env php
<?php

declare(strict_types=1);

use Modulon\Core\Database;
use Modulon\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

final class BankingMigrationCli
{
    private const LEGACY_TABLES = [
        'transactions',
        'recurring_rules',
        'recurring_rule_conditions',
    ];

    private const TARGET_TABLES = [
        'banking_migration_runs',
        'banking_accounts',
        'banking_categories',
        'banking_import_batches',
        'banking_transactions',
        'banking_recurring_rules',
        'banking_recurring_rule_conditions',
    ];

    private const TRANSACTION_COLUMNS = [
        'account_id',
        'category_id',
        'booking_date',
        'value_date',
        'booking_text',
        'purpose',
        'counterparty_name',
        'counterparty_iban',
        'counterparty_bic',
        'amount',
        'currency',
        'raw_info',
        'legacy_category_name',
        'transaction_hash',
        'booking_status',
        'legacy_created_at',
    ];

    private const RULE_COLUMNS = [
        'account_id',
        'category_id',
        'name',
        'interval_type',
        'notes',
        'rule_type',
        'group_label',
        'active_from',
        'active_to',
        'period_mode',
        'due_day',
        'is_active',
        'legacy_created_at',
        'legacy_updated_at',
    ];

    private const CONDITION_COLUMNS = [
        'recurring_rule_id',
        'field',
        'operator',
        'value',
        'legacy_created_at',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const MAPPING = [
        'transactions' => [
            'transactions.id -> banking_transactions.legacy_id',
            'transactions.auftragskonto -> banking_accounts.account_identifier -> banking_transactions.account_id',
            'transactions.kategorie -> banking_categories.name -> banking_transactions.category_id + legacy_category_name',
            'transactions.hash -> banking_transactions.transaction_hash (UNIQUE pro user_id)',
            'transactions.betrag -> banking_transactions.amount DECIMAL(12,2)',
        ],
        'recurring_rules' => [
            'recurring_rules.id -> banking_recurring_rules.legacy_id',
            'recurring_rules.* -> banking_recurring_rules.* mit user_id und migration_run_id',
        ],
        'recurring_rule_conditions' => [
            'recurring_rule_conditions.id -> banking_recurring_rule_conditions.legacy_id',
            'recurring_rule_conditions.rule_id -> banking_recurring_rules.id per legacy_id Lookup',
        ],
    ];

    private string $basePath;

    public function __construct(
        private readonly array $argv,
    ) {
        $this->basePath = dirname(__DIR__);
    }

    public function run(): int
    {
        if (PHP_SAPI !== 'cli') {
            $this->error('Dieses Skript darf nur per CLI ausgeführt werden.');
            return 1;
        }

        $options = $this->parseOptions($this->argv);
        if ($options['help'] === true) {
            $this->printHelp();
            return 0;
        }

        $mode = $this->resolveMode($options);
        if ($mode === null) {
            return 1;
        }

        if (($mode === 'verify' || $mode === 'rollback') && $options['run_id'] <= 0) {
            $this->error(sprintf('--%s benötigt --run-id=<id>.', $mode));
            return 1;
        }

        if ($mode === 'apply' && $options['user_id_was_explicit'] === false) {
            $this->error('--apply benötigt eine explizite --user-id=<id>.');
            return 1;
        }

        if ($options['user_id'] <= 0) {
            $this->error('--user-id muss eine positive Zahl sein.');
            return 1;
        }

        $this->line('Modulon Banking Migration');
        $this->line('=========================');
        $this->line('Modus: ' . $mode);
        $this->line('Ziel-User-ID: ' . $options['user_id'] . ($options['user_id_was_explicit'] ? '' : ' (Default)'));
        $this->line('');

        try {
            $modulonPdo = $this->connectModulonDatabase();
            $this->ok('Modulon-DB Verbindung erfolgreich.');
        } catch (Throwable $exception) {
            $this->error('Modulon-DB Verbindung fehlgeschlagen: ' . $exception->getMessage());
            return 1;
        }

        if ($mode === 'rollback') {
            $this->line('');
            $targetTablesOk = $this->printTargetTableStatus($modulonPdo);
            if (!$targetTablesOk) {
                $this->error('--rollback abgebrochen: Banking-Zieltabellen fehlen.');
                return 1;
            }

            return $this->rollbackMigrationRun($modulonPdo, $options['run_id'], $options['confirm']);
        }

        try {
            $legacyPdo = $this->connectLegacyDatabase();
            $this->ok('Legacy-DB Verbindung erfolgreich.');
        } catch (Throwable $exception) {
            $this->error('Legacy-DB Verbindung fehlgeschlagen: ' . $exception->getMessage());
            return 1;
        }

        $this->line('');
        $legacyCounts = $this->printLegacyCounts($legacyPdo);

        $this->line('');
        $targetTablesOk = $this->printTargetTableStatus($modulonPdo);

        if ($mode === 'verify') {
            if (!$targetTablesOk) {
                $this->error('--verify abgebrochen: Banking-Zieltabellen fehlen.');
                return 1;
            }

            return $this->verifyMigrationRun($legacyPdo, $modulonPdo, $options['run_id'], $legacyCounts);
        }

        $this->line('');
        $userExists = $this->printTargetUserStatus($modulonPdo, $options['user_id']);

        $this->line('');
        $this->printPlannedMapping();

        $this->line('');
        if ($mode === 'dry-run') {
            $this->ok('Dry-Run abgeschlossen. Es wurden keine Schreiboperationen ausgeführt.');
            if (!$targetTablesOk || !$userExists) {
                $this->warning('Preflight enthält Warnungen. Vor einem späteren --apply müssen diese Punkte geklärt sein.');
            }
            return 0;
        }

        if (!$targetTablesOk) {
            $this->line('');
            $this->headline('Schema-Vorbereitung');
            $this->warning('Banking-Zieltabellen fehlen. Im --apply-Modus werden nur die Banking-CREATE-TABLE-Blöcke aus app/Database/schema.sql ausgeführt.');
            try {
                $this->ensureBankingTargetTables($modulonPdo);
            } catch (Throwable $exception) {
                $this->error('Banking-Zieltabellen konnten nicht angelegt werden: ' . $exception->getMessage());
                return 1;
            }

            $this->line('');
            $targetTablesOk = $this->printTargetTableStatus($modulonPdo);
            if (!$targetTablesOk) {
                $this->error('--apply abgebrochen: Banking-Zieltabellen fehlen weiterhin.');
                return 1;
            }
        }
        if (!$userExists) {
            $this->error('--apply abgebrochen: Zielbenutzer ist nicht vorhanden.');
            return 1;
        }

        return $this->applyImport($legacyPdo, $modulonPdo, $options['user_id'], $legacyCounts);
    }

    /**
     * @param array<int, string> $argv
     * @return array{help:bool,dry_run:bool,apply:bool,verify:bool,rollback:bool,confirm:bool,user_id:int,user_id_was_explicit:bool,run_id:int}
     */
    private function parseOptions(array $argv): array
    {
        $options = [
            'help' => false,
            'dry_run' => false,
            'apply' => false,
            'verify' => false,
            'rollback' => false,
            'confirm' => false,
            'user_id' => 1,
            'user_id_was_explicit' => false,
            'run_id' => 0,
        ];

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;
                continue;
            }
            if ($argument === '--dry-run') {
                $options['dry_run'] = true;
                continue;
            }
            if ($argument === '--apply') {
                $options['apply'] = true;
                continue;
            }
            if ($argument === '--verify') {
                $options['verify'] = true;
                continue;
            }
            if ($argument === '--rollback') {
                $options['rollback'] = true;
                continue;
            }
            if ($argument === '--confirm') {
                $options['confirm'] = true;
                continue;
            }
            if (str_starts_with($argument, '--user-id=')) {
                $options['user_id'] = (int) substr($argument, strlen('--user-id='));
                $options['user_id_was_explicit'] = true;
                continue;
            }
            if (str_starts_with($argument, '--run-id=')) {
                $options['run_id'] = (int) substr($argument, strlen('--run-id='));
                continue;
            }

            $this->warning('Unbekannte Option wird ignoriert: ' . $argument);
        }

        return $options;
    }

    /**
     * @param array{dry_run:bool,apply:bool,verify:bool,rollback:bool} $options
     */
    private function resolveMode(array $options): ?string
    {
        $enabled = array_filter([
            'dry-run' => $options['dry_run'],
            'apply' => $options['apply'],
            'verify' => $options['verify'],
            'rollback' => $options['rollback'],
        ]);

        if (count($enabled) > 1) {
            $this->error('Bitte nur einen Modus angeben: --dry-run, --apply, --verify oder --rollback.');
            return null;
        }

        if ($enabled === []) {
            return 'dry-run';
        }

        return (string) array_key_first($enabled);
    }

    private function connectLegacyDatabase(): PDO
    {
        $configPath = $this->basePath . '/app/Legacy/banking.old/config.php';
        if (!is_file($configPath)) {
            throw new RuntimeException('Legacy-Konfiguration nicht gefunden.');
        }

        require_once $configPath;

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $constant) {
            if (!defined($constant)) {
                throw new RuntimeException('Legacy-Konfiguration ist unvollständig: ' . $constant);
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            (string) constant('DB_HOST'),
            (string) constant('DB_NAME')
        );

        return new PDO(
            $dsn,
            (string) constant('DB_USER'),
            (string) constant('DB_PASS'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private function connectModulonDatabase(): PDO
    {
        Env::load($this->basePath . '/.env');
        $databaseConfig = require $this->basePath . '/app/Config/database.php';

        return Database::connect($databaseConfig);
    }

    /**
     * @return array<string, int>
     */
    private function printLegacyCounts(PDO $pdo): array
    {
        $this->headline('Legacy-Counts');
        $counts = [];
        foreach (self::LEGACY_TABLES as $table) {
            if (!$this->tableExists($pdo, $table)) {
                $this->error('Legacy-Tabelle fehlt: ' . $table);
                $counts[$table] = 0;
                continue;
            }

            $count = $this->countRows($pdo, $table);
            $counts[$table] = $count;
            $this->line(sprintf('  %-34s %d', $table, $count));
        }

        return $counts;
    }

    private function printTargetTableStatus(PDO $pdo): bool
    {
        $this->headline('Zieltabellen');
        $allPresent = true;
        foreach (self::TARGET_TABLES as $table) {
            $exists = $this->tableExists($pdo, $table);
            $allPresent = $allPresent && $exists;
            $this->line(sprintf('  %-34s %s', $table, $exists ? 'vorhanden' : 'FEHLT'));
        }

        return $allPresent;
    }

    private function printTargetUserStatus(PDO $pdo, int $userId): bool
    {
        $this->headline('Zielbenutzer');
        if (!$this->tableExists($pdo, 'users')) {
            $this->error('Tabelle users fehlt.');
            return false;
        }

        $statement = $pdo->prepare('SELECT id, name, email, is_blocked FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        if (!is_array($user)) {
            $this->error('Zielbenutzer existiert nicht: users.id=' . $userId);
            return false;
        }

        $blocked = (int) ($user['is_blocked'] ?? 0) === 1;
        $this->line('  users.id: ' . (int) ($user['id'] ?? 0));
        $this->line('  Name: ' . (string) ($user['name'] ?? ''));
        $this->line('  E-Mail: ' . (string) ($user['email'] ?? ''));
        $this->line('  Status: ' . ($blocked ? 'gesperrt' : 'aktiv'));

        if ($blocked) {
            $this->warning('Zielbenutzer ist gesperrt. Für die initiale Migration sollte das bewusst geprüft werden.');
        }

        return true;
    }

    private function printPlannedMapping(): void
    {
        $this->headline('Geplantes Mapping');
        foreach (self::MAPPING as $source => $rules) {
            $this->line('  ' . $source);
            foreach ($rules as $rule) {
                $this->line('    - ' . $rule);
            }
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function countRows(PDO $pdo, string $table): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    private function ensureBankingTargetTables(PDO $pdo): void
    {
        $schemaPath = $this->basePath . '/app/Database/schema.sql';
        $schema = file_get_contents($schemaPath);
        if (!is_string($schema) || $schema === '') {
            throw new RuntimeException('Schema-Datei konnte nicht gelesen werden.');
        }

        $startMarker = '-- Banking-Modul Zielstruktur';
        $endMarker = '-- Dashboard-Grundlage';
        $start = strpos($schema, $startMarker);
        $end = strpos($schema, $endMarker);
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('Banking-Schema-Block wurde nicht gefunden.');
        }

        $bankingBlock = substr($schema, $start, $end - $start);
        $statements = array_filter(array_map('trim', explode(';', $bankingBlock)));
        foreach ($statements as $statement) {
            if ($statement === '' || !str_contains($statement, 'CREATE TABLE IF NOT EXISTS banking_')) {
                continue;
            }
            $pdo->exec($statement);
        }

        $this->ok('Banking-Zieltabellen vorbereitet.');
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function insertTransaction(PDO $pdo, int $userId, int $migrationRunId, int $legacyId, array $payload): void
    {
        $columns = array_merge(['user_id', 'migration_run_id', 'legacy_id'], array_keys($payload));
        $sql = 'INSERT INTO banking_transactions (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')';
        $statement = $pdo->prepare($sql);
        $statement->execute(array_merge($payload, [
            'user_id' => $userId,
            'migration_run_id' => $migrationRunId,
            'legacy_id' => $legacyId,
        ]));
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function updateTransaction(PDO $pdo, int $id, int $migrationRunId, int $legacyId, array $payload): void
    {
        $payload['migration_run_id'] = $migrationRunId;
        $payload['legacy_id'] = $legacyId;
        $payload['updated_at'] = date('Y-m-d H:i:s');
        $assignments = [];
        foreach (array_keys($payload) as $column) {
            $assignments[] = '`' . $column . '` = :' . $column;
        }
        $payload['id'] = $id;

        $statement = $pdo->prepare('UPDATE banking_transactions SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        $statement->execute($payload);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function insertRecurringRule(PDO $pdo, int $userId, int $migrationRunId, int $legacyId, array $payload): int
    {
        $columns = array_merge(['user_id', 'migration_run_id', 'legacy_id'], array_keys($payload));
        $sql = 'INSERT INTO banking_recurring_rules (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')';
        $statement = $pdo->prepare($sql);
        $statement->execute(array_merge($payload, [
            'user_id' => $userId,
            'migration_run_id' => $migrationRunId,
            'legacy_id' => $legacyId,
        ]));

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function updateRecurringRule(PDO $pdo, int $id, int $migrationRunId, array $payload): void
    {
        $payload['migration_run_id'] = $migrationRunId;
        $payload['updated_at'] = date('Y-m-d H:i:s');
        $assignments = [];
        foreach (array_keys($payload) as $column) {
            $assignments[] = '`' . $column . '` = :' . $column;
        }
        $payload['id'] = $id;

        $statement = $pdo->prepare('UPDATE banking_recurring_rules SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        $statement->execute($payload);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function insertCondition(PDO $pdo, int $userId, int $migrationRunId, int $legacyId, array $payload): void
    {
        $columns = array_merge(['user_id', 'migration_run_id', 'legacy_id'], array_keys($payload));
        $sql = 'INSERT INTO banking_recurring_rule_conditions (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')';
        $statement = $pdo->prepare($sql);
        $statement->execute(array_merge($payload, [
            'user_id' => $userId,
            'migration_run_id' => $migrationRunId,
            'legacy_id' => $legacyId,
        ]));
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function updateCondition(PDO $pdo, int $id, int $migrationRunId, array $payload): void
    {
        $payload['migration_run_id'] = $migrationRunId;
        $assignments = [];
        foreach (array_keys($payload) as $column) {
            $assignments[] = '`' . $column . '` = :' . $column;
        }
        $payload['id'] = $id;

        $statement = $pdo->prepare('UPDATE banking_recurring_rule_conditions SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        $statement->execute($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExistingTransaction(PDO $pdo, int $userId, int $legacyId, ?string $hash): ?array
    {
        $row = $this->findExistingByLegacy($pdo, 'banking_transactions', $userId, $legacyId);
        if ($row !== null) {
            return $row;
        }

        if ($hash === null || $hash === '') {
            return null;
        }

        return $this->fetchOne($pdo, 'SELECT * FROM banking_transactions WHERE user_id = :user_id AND transaction_hash = :hash LIMIT 1', [
            'user_id' => $userId,
            'hash' => $hash,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExistingByLegacy(PDO $pdo, string $table, int $userId, int $legacyId): ?array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }

        return $this->fetchOne($pdo, 'SELECT * FROM `' . $table . '` WHERE user_id = :user_id AND legacy_id = :legacy_id LIMIT 1', [
            'user_id' => $userId,
            'legacy_id' => $legacyId,
        ]);
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(PDO $pdo, string $sql, array $parameters): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, scalar|null> $payload
     * @param array<int, string> $columns
     */
    private function rowsDiffer(array $existing, array $payload, array $columns): bool
    {
        foreach ($columns as $column) {
            $left = $existing[$column] ?? null;
            $right = $payload[$column] ?? null;
            if ($this->normalizeComparable($left) !== $this->normalizeComparable($right)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparable(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))) {
            return (string) (float) $value;
        }

        return trim((string) $value);
    }

    /**
     * @param array{inserted:int,skipped:int,updated:int} $transactionStats
     */
    private function finishImportBatch(PDO $pdo, int $importBatchId, array $transactionStats): void
    {
        $statement = $pdo->prepare(
            "UPDATE banking_import_batches
             SET status = 'completed',
                 imported_count = :inserted,
                 updated_count = :updated,
                 skipped_count = :skipped,
                 finished_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $importBatchId,
            'inserted' => $transactionStats['inserted'],
            'updated' => $transactionStats['updated'],
            'skipped' => $transactionStats['skipped'],
        ]);
    }

    /**
     * @param array<string, array{inserted:int,skipped:int,updated:int}> $stats
     */
    private function finishMigrationRun(PDO $pdo, int $migrationRunId, array $stats): void
    {
        $statement = $pdo->prepare(
            "UPDATE banking_migration_runs
             SET status = 'completed',
                 imported_transactions_count = :transactions,
                 imported_rules_count = :rules,
                 imported_conditions_count = :conditions,
                 notes = :notes,
                 finished_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $migrationRunId,
            'transactions' => $stats['transactions']['inserted'],
            'rules' => $stats['recurring_rules']['inserted'],
            'conditions' => $stats['recurring_rule_conditions']['inserted'],
            'notes' => json_encode(['stats' => $stats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifySummary(PDO $pdo, int $userId): array
    {
        return [
            'transactions' => $this->countRowsForUser($pdo, 'banking_transactions', $userId),
            'recurring_rules' => $this->countRowsForUser($pdo, 'banking_recurring_rules', $userId),
            'recurring_rule_conditions' => $this->countRowsForUser($pdo, 'banking_recurring_rule_conditions', $userId),
            'status_counts' => $this->groupCounts($pdo, 'banking_transactions', 'booking_status', $userId),
            'currency_counts' => $this->groupCounts($pdo, 'banking_transactions', 'currency', $userId),
            'date_range' => $this->fetchOne($pdo, 'SELECT MIN(booking_date) AS min_date, MAX(booking_date) AS max_date FROM banking_transactions WHERE user_id = :user_id', ['user_id' => $userId]) ?? [],
            'orphan_conditions' => $this->countOrphanConditions($pdo),
        ];
    }

    private function countRowsForUser(PDO $pdo, string $table, int $userId): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function groupCounts(PDO $pdo, string $table, string $column, int $userId): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Ungültiger Tabellen- oder Spaltenname.');
        }
        $statement = $pdo->prepare('SELECT `' . $column . '` AS value, COUNT(*) AS count_rows FROM `' . $table . '` WHERE user_id = :user_id GROUP BY `' . $column . '` ORDER BY `' . $column . '` ASC');
        $statement->execute(['user_id' => $userId]);
        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string) ($row['value'] ?? '')] = (int) ($row['count_rows'] ?? 0);
        }

        return $counts;
    }

    private function countOrphanConditions(PDO $pdo): int
    {
        $statement = $pdo->query(
            'SELECT COUNT(*)
             FROM banking_recurring_rule_conditions c
             LEFT JOIN banking_recurring_rules r ON r.id = c.recurring_rule_id
             WHERE r.id IS NULL'
        );

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, int> $legacyCounts
     */
    private function verifyMigrationRun(PDO $legacyPdo, PDO $modulonPdo, int $runId, array $legacyCounts): int
    {
        unset($legacyPdo);

        $this->line('');
        $this->headline('Verify Migration Run #' . $runId);

        $run = $this->fetchOne($modulonPdo, 'SELECT * FROM banking_migration_runs WHERE id = :id LIMIT 1', ['id' => $runId]);
        if ($run === null) {
            $this->error('Migration Run nicht gefunden: ' . $runId);
            return 1;
        }

        $userId = (int) ($run['target_user_id'] ?? 0);
        $this->line('  Status: ' . (string) ($run['status'] ?? ''));
        $this->line('  Ziel-User-ID: ' . $userId);
        $this->line('');

        $errors = 0;
        $warnings = 0;

        $transactionCount = $this->countRunRows($modulonPdo, 'banking_transactions', $runId, $userId);
        $ruleCount = $this->countRunRows($modulonPdo, 'banking_recurring_rules', $runId, $userId);
        $conditionCount = $this->countRunRows($modulonPdo, 'banking_recurring_rule_conditions', $runId, $userId);

        $this->verifyEquals('Legacy transactions vs. banking_transactions', (int) ($legacyCounts['transactions'] ?? 0), $transactionCount, $errors);
        $this->verifyEquals('Legacy recurring_rules vs. banking_recurring_rules', (int) ($legacyCounts['recurring_rules'] ?? 0), $ruleCount, $errors);
        $this->verifyEquals('Legacy recurring_rule_conditions vs. banking_recurring_rule_conditions', (int) ($legacyCounts['recurring_rule_conditions'] ?? 0), $conditionCount, $errors);

        $this->verifyEquals('Migration-Run protokollierte Transaktionen', (int) ($run['imported_transactions_count'] ?? 0), $transactionCount, $errors);
        $this->verifyEquals('Migration-Run protokollierte Regeln', (int) ($run['imported_rules_count'] ?? 0), $ruleCount, $errors);
        $this->verifyEquals('Migration-Run protokollierte Bedingungen', (int) ($run['imported_conditions_count'] ?? 0), $conditionCount, $errors);

        $this->verifyZero('Importierte Transaktionen mit falscher/fehlender user_id', $this->countRunRowsWithInvalidUser($modulonPdo, 'banking_transactions', $runId, $userId), $errors);
        $this->verifyZero('Importierte Regeln mit falscher/fehlender user_id', $this->countRunRowsWithInvalidUser($modulonPdo, 'banking_recurring_rules', $runId, $userId), $errors);
        $this->verifyZero('Importierte Bedingungen mit falscher/fehlender user_id', $this->countRunRowsWithInvalidUser($modulonPdo, 'banking_recurring_rule_conditions', $runId, $userId), $errors);

        $this->verifyZero('Transaktionen ohne migration_run_id für Zieluser', $this->countRowsMissingMigrationRun($modulonPdo, 'banking_transactions', $userId), $errors);
        $this->verifyZero('Regeln ohne migration_run_id für Zieluser', $this->countRowsMissingMigrationRun($modulonPdo, 'banking_recurring_rules', $userId), $errors);

        $this->verifyZero('Conditions ohne Rule', $this->countOrphanConditions($modulonPdo), $errors);
        $this->verifyZero('Importierte Conditions ohne gültige importierte Rule', $this->countRunConditionsWithoutImportedRule($modulonPdo, $runId, $userId), $errors);

        $this->verifyZero('Doppelte Transaktions-legacy_id pro user_id', $this->countDuplicateLegacyIds($modulonPdo, 'banking_transactions'), $errors);
        $this->verifyZero('Doppelte Regel-legacy_id pro user_id', $this->countDuplicateLegacyIds($modulonPdo, 'banking_recurring_rules'), $errors);
        $this->verifyZero('Doppelte Condition-legacy_id pro user_id', $this->countDuplicateLegacyIds($modulonPdo, 'banking_recurring_rule_conditions'), $errors);
        $this->verifyZero('Hash-Konflikte pro user_id', $this->countHashConflicts($modulonPdo), $errors);

        if ((string) ($run['status'] ?? '') !== 'completed') {
            $this->verifyWarn('Migration-Run Status ist nicht completed: ' . (string) ($run['status'] ?? ''), $warnings);
        }

        $this->line('');
        if ($errors > 0) {
            $this->error(sprintf('Verify abgeschlossen mit %d Fehler(n) und %d Warnung(en).', $errors, $warnings));
            return 1;
        }

        if ($warnings > 0) {
            $this->warning(sprintf('Verify abgeschlossen mit 0 Fehlern und %d Warnung(en).', $warnings));
            return 0;
        }

        $this->ok('Verify abgeschlossen ohne Fehler.');
        return 0;
    }

    private function rollbackMigrationRun(PDO $pdo, int $runId, bool $confirm): int
    {
        $this->line('');
        $this->headline('Rollback Migration Run #' . $runId);

        $run = $this->fetchOne($pdo, 'SELECT * FROM banking_migration_runs WHERE id = :id LIMIT 1', ['id' => $runId]);
        if ($run === null) {
            $this->error('Migration Run nicht gefunden: ' . $runId);
            return 1;
        }

        $plan = $this->buildRollbackPlan($pdo, $runId);
        $this->printRollbackPlan($run, $plan, $confirm);

        if (!$confirm) {
            $this->warning('Rollback wurde NICHT ausgeführt. Für echte Löschung erneut mit --confirm starten.');
            return 0;
        }

        if ((string) ($run['status'] ?? '') === 'rolled_back' && $this->rollbackPlanHasNoRows($plan)) {
            $this->ok('Migration Run ist bereits als rolled_back markiert und enthält keine rollbackfähigen Daten mehr.');
            return 0;
        }

        try {
            $pdo->beginTransaction();

            $deleted = [];
            $deleted['banking_recurring_rule_conditions'] = $this->deleteByMigrationRun($pdo, 'banking_recurring_rule_conditions', $runId);
            $deleted['banking_recurring_rules'] = $this->deleteByMigrationRun($pdo, 'banking_recurring_rules', $runId);
            $deleted['banking_transactions'] = $this->deleteByMigrationRun($pdo, 'banking_transactions', $runId);
            $deleted['banking_import_batches'] = $this->deleteByMigrationRun($pdo, 'banking_import_batches', $runId);
            $deleted['banking_categories'] = $this->deleteRollbackCategories($pdo, $runId);
            $deleted['banking_accounts'] = $this->deleteRollbackAccounts($pdo, $runId);

            $notes = $this->appendRollbackNote($run['notes'] ?? null, $deleted);
            $statement = $pdo->prepare(
                "UPDATE banking_migration_runs
                 SET status = 'rolled_back',
                     notes = :notes,
                     finished_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $statement->execute([
                'id' => $runId,
                'notes' => $notes,
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->error('Rollback fehlgeschlagen, Transaction wurde zurückgerollt: ' . $exception->getMessage());
            return 1;
        }

        $this->line('');
        $this->headline('Rollback-Ergebnis');
        foreach ($deleted as $table => $count) {
            $this->line(sprintf('  %-42s %d gelöscht', $table, $count));
        }
        $this->ok('Migration Run wurde als rolled_back markiert.');

        return 0;
    }

    /**
     * @return array<string, int>
     */
    private function buildRollbackPlan(PDO $pdo, int $runId): array
    {
        $categoriesTotal = $this->countRowsForMigrationRun($pdo, 'banking_categories', $runId);
        $categoriesDeletable = $this->countRollbackCategories($pdo, $runId);
        $accountsTotal = $this->countRowsForMigrationRun($pdo, 'banking_accounts', $runId);
        $accountsDeletable = $this->countRollbackAccounts($pdo, $runId);

        return [
            'banking_recurring_rule_conditions' => $this->countRowsForMigrationRun($pdo, 'banking_recurring_rule_conditions', $runId),
            'banking_recurring_rules' => $this->countRowsForMigrationRun($pdo, 'banking_recurring_rules', $runId),
            'banking_transactions' => $this->countRowsForMigrationRun($pdo, 'banking_transactions', $runId),
            'banking_import_batches' => $this->countRowsForMigrationRun($pdo, 'banking_import_batches', $runId),
            'banking_categories_deletable' => $categoriesDeletable,
            'banking_categories_retained' => max(0, $categoriesTotal - $categoriesDeletable),
            'banking_accounts_deletable' => $accountsDeletable,
            'banking_accounts_retained' => max(0, $accountsTotal - $accountsDeletable),
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, int> $plan
     */
    private function printRollbackPlan(array $run, array $plan, bool $confirm): void
    {
        $this->line('  Status: ' . (string) ($run['status'] ?? ''));
        $this->line('  Ziel-User-ID: ' . (int) ($run['target_user_id'] ?? 0));
        $this->line('  Ausführung: ' . ($confirm ? 'BESTÄTIGT (--confirm)' : 'Vorschau ohne Löschung'));
        $this->line('');

        if ((string) ($run['status'] ?? '') === 'rolled_back') {
            $this->warning('Migration Run ist bereits als rolled_back markiert.');
        }

        $this->line('  Geplante Löschreihenfolge:');
        $this->line(sprintf('    %-40s %d', 'banking_recurring_rule_conditions', $plan['banking_recurring_rule_conditions']));
        $this->line(sprintf('    %-40s %d', 'banking_recurring_rules', $plan['banking_recurring_rules']));
        $this->line(sprintf('    %-40s %d', 'banking_transactions', $plan['banking_transactions']));
        $this->line(sprintf('    %-40s %d', 'banking_import_batches', $plan['banking_import_batches']));
        $this->line(sprintf('    %-40s %d', 'banking_categories löschbar', $plan['banking_categories_deletable']));
        $this->line(sprintf('    %-40s %d', 'banking_categories behalten', $plan['banking_categories_retained']));
        $this->line(sprintf('    %-40s %d', 'banking_accounts löschbar', $plan['banking_accounts_deletable']));
        $this->line(sprintf('    %-40s %d', 'banking_accounts behalten', $plan['banking_accounts_retained']));
        $this->line('    banking_migration_runs wird nicht gelöscht, sondern auf rolled_back markiert.');
    }

    /**
     * @param array<string, int> $plan
     */
    private function rollbackPlanHasNoRows(array $plan): bool
    {
        foreach ($plan as $count) {
            if ($count > 0) {
                return false;
            }
        }

        return true;
    }

    private function countRowsForMigrationRun(PDO $pdo, string $table, int $runId): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE migration_run_id = :run_id');
        $statement->execute(['run_id' => $runId]);

        return (int) $statement->fetchColumn();
    }

    private function countRollbackCategories(PDO $pdo, int $runId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM banking_categories cat
             WHERE cat.migration_run_id = :run_id
               AND NOT EXISTS (
                   SELECT 1
                   FROM banking_transactions t
                   WHERE t.category_id = cat.id
                     AND (t.migration_run_id IS NULL OR t.migration_run_id <> :run_id)
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM banking_recurring_rules r
                   WHERE r.category_id = cat.id
                     AND (r.migration_run_id IS NULL OR r.migration_run_id <> :run_id)
               )'
        );
        $statement->execute(['run_id' => $runId]);

        return (int) $statement->fetchColumn();
    }

    private function countRollbackAccounts(PDO $pdo, int $runId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM banking_accounts acc
             WHERE acc.migration_run_id = :run_id
               AND NOT EXISTS (
                   SELECT 1
                   FROM banking_transactions t
                   WHERE t.account_id = acc.id
                     AND (t.migration_run_id IS NULL OR t.migration_run_id <> :run_id)
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM banking_recurring_rules r
                   WHERE r.account_id = acc.id
                     AND (r.migration_run_id IS NULL OR r.migration_run_id <> :run_id)
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM banking_import_batches b
                   WHERE b.account_id = acc.id
                     AND (b.migration_run_id IS NULL OR b.migration_run_id <> :run_id)
               )'
        );
        $statement->execute(['run_id' => $runId]);

        return (int) $statement->fetchColumn();
    }

    private function deleteByMigrationRun(PDO $pdo, string $table, int $runId): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }
        $statement = $pdo->prepare('DELETE FROM `' . $table . '` WHERE migration_run_id = :run_id');
        $statement->execute(['run_id' => $runId]);

        return $statement->rowCount();
    }

    private function deleteRollbackCategories(PDO $pdo, int $runId): int
    {
        $statement = $pdo->prepare(
            'DELETE cat
             FROM banking_categories cat
             WHERE cat.migration_run_id = :run_id
               AND NOT EXISTS (SELECT 1 FROM banking_transactions t WHERE t.category_id = cat.id)
               AND NOT EXISTS (SELECT 1 FROM banking_recurring_rules r WHERE r.category_id = cat.id)'
        );
        $statement->execute(['run_id' => $runId]);

        return $statement->rowCount();
    }

    private function deleteRollbackAccounts(PDO $pdo, int $runId): int
    {
        $statement = $pdo->prepare(
            'DELETE acc
             FROM banking_accounts acc
             WHERE acc.migration_run_id = :run_id
               AND NOT EXISTS (SELECT 1 FROM banking_transactions t WHERE t.account_id = acc.id)
               AND NOT EXISTS (SELECT 1 FROM banking_recurring_rules r WHERE r.account_id = acc.id)
               AND NOT EXISTS (SELECT 1 FROM banking_import_batches b WHERE b.account_id = acc.id)'
        );
        $statement->execute(['run_id' => $runId]);

        return $statement->rowCount();
    }

    /**
     * @param array<string, int> $deleted
     */
    private function appendRollbackNote(mixed $existingNotes, array $deleted): string
    {
        $notes = [];
        if (is_string($existingNotes) && trim($existingNotes) !== '') {
            $decoded = json_decode($existingNotes, true);
            if (is_array($decoded)) {
                $notes = $decoded;
            } else {
                $notes['previous_notes'] = $existingNotes;
            }
        }

        $notes['rollback'] = [
            'deleted' => $deleted,
            'rolled_back_at' => date('c'),
        ];

        return json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function countRunRows(PDO $pdo, string $table, int $runId, int $userId): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE migration_run_id = :run_id AND user_id = :user_id');
        $statement->execute([
            'run_id' => $runId,
            'user_id' => $userId,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function countRunRowsWithInvalidUser(PDO $pdo, string $table, int $runId, int $userId): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE migration_run_id = :run_id AND user_id <> :user_id');
        $statement->execute([
            'run_id' => $runId,
            'user_id' => $userId,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function countRowsMissingMigrationRun(PDO $pdo, string $table, int $userId): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE user_id = :user_id AND migration_run_id IS NULL');
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function countRunConditionsWithoutImportedRule(PDO $pdo, int $runId, int $userId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM banking_recurring_rule_conditions c
             LEFT JOIN banking_recurring_rules r
               ON r.id = c.recurring_rule_id
              AND r.user_id = c.user_id
              AND r.migration_run_id = c.migration_run_id
             WHERE c.migration_run_id = :run_id
               AND c.user_id = :user_id
               AND r.id IS NULL'
        );
        $statement->execute([
            'run_id' => $runId,
            'user_id' => $userId,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function countDuplicateLegacyIds(PDO $pdo, string $table): int
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Ungültiger Tabellenname.');
        }
        $statement = $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT user_id, legacy_id
                 FROM `' . $table . '`
                 WHERE legacy_id IS NOT NULL
                 GROUP BY user_id, legacy_id
                 HAVING COUNT(*) > 1
             ) duplicates'
        );

        return (int) $statement->fetchColumn();
    }

    private function countHashConflicts(PDO $pdo): int
    {
        $statement = $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT user_id, transaction_hash
                 FROM banking_transactions
                 WHERE transaction_hash IS NOT NULL
                 GROUP BY user_id, transaction_hash
                 HAVING COUNT(*) > 1
             ) duplicates'
        );

        return (int) $statement->fetchColumn();
    }

    private function verifyEquals(string $label, int $expected, int $actual, int &$errors): void
    {
        if ($expected === $actual) {
            $this->ok(sprintf('%s: %d', $label, $actual));
            return;
        }

        $errors++;
        $this->error(sprintf('%s: erwartet=%d, ist=%d', $label, $expected, $actual));
    }

    private function verifyZero(string $label, int $actual, int &$errors): void
    {
        if ($actual === 0) {
            $this->ok($label . ': 0');
            return;
        }

        $errors++;
        $this->error(sprintf('%s: %d', $label, $actual));
    }

    private function verifyWarn(string $label, int &$warnings): void
    {
        $warnings++;
        $this->warning($label);
    }

    /**
     * @param array<string, array{inserted:int,skipped:int,updated:int}> $stats
     */
    private function printImportStats(array $stats): void
    {
        $this->line('');
        $this->headline('Import-Statistik');
        foreach ($stats as $section => $values) {
            $this->line(sprintf(
                '  %-34s importiert=%d aktualisiert=%d übersprungen=%d',
                $section,
                $values['inserted'],
                $values['updated'],
                $values['skipped']
            ));
        }
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function printVerifySummary(array $summary): void
    {
        $this->line('');
        $this->headline('Verify-Zusammenfassung');
        $this->line('  banking_transactions: ' . (int) ($summary['transactions'] ?? 0));
        $this->line('  banking_recurring_rules: ' . (int) ($summary['recurring_rules'] ?? 0));
        $this->line('  banking_recurring_rule_conditions: ' . (int) ($summary['recurring_rule_conditions'] ?? 0));
        $this->line('  orphan_conditions: ' . (int) ($summary['orphan_conditions'] ?? 0));

        $dateRange = is_array($summary['date_range'] ?? null) ? $summary['date_range'] : [];
        $this->line('  Datumsbereich: ' . (string) ($dateRange['min_date'] ?? '-') . ' bis ' . (string) ($dateRange['max_date'] ?? '-'));

        $this->line('  Status: ' . json_encode($summary['status_counts'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('  Währungen: ' . json_encode($summary['currency_counts'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    private function normalizeCategoryName(string $name): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($name));
        if ($normalized === null || $normalized === '') {
            return '';
        }

        return function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        return $string === '' ? null : $string;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        if ($string === '' || $string === '0000-00-00') {
            return null;
        }

        return substr($string, 0, 10);
    }

    private function timestampOrNull(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        if ($string === '' || str_starts_with($string, '0000-00-00')) {
            return null;
        }

        return $string;
    }

    private function decimalString(mixed $value): string
    {
        $string = str_replace(',', '.', trim((string) $value));
        if ($string === '' || !is_numeric($string)) {
            return '0.00';
        }

        return number_format((float) $string, 2, '.', '');
    }

    private function mapConditionField(string $field): string
    {
        return [
            'buchungstext' => 'booking_text',
            'verwendungszweck' => 'purpose',
            'beguenstigter_zahlungspflichtiger' => 'counterparty_name',
            'kontonummer_iban' => 'counterparty_iban',
            'bic' => 'counterparty_bic',
            'betrag' => 'amount',
            'waehrung' => 'currency',
            'info' => 'raw_info',
            'kategorie' => 'legacy_category_name',
            'status' => 'booking_status',
        ][$field] ?? $field;
    }

    /**
     * @param array<string, int> $legacyCounts
     */
    private function applyImport(PDO $legacyPdo, PDO $modulonPdo, int $userId, array $legacyCounts): int
    {
        $this->headline('Apply-Import');
        $this->warning('Schreibmodus aktiv. Legacy-DB bleibt read-only, Modulon-DB wird innerhalb einer Transaction beschrieben.');

        $stats = [
            'accounts' => ['inserted' => 0, 'skipped' => 0, 'updated' => 0],
            'categories' => ['inserted' => 0, 'skipped' => 0, 'updated' => 0],
            'transactions' => ['inserted' => 0, 'skipped' => 0, 'updated' => 0],
            'recurring_rules' => ['inserted' => 0, 'skipped' => 0, 'updated' => 0],
            'recurring_rule_conditions' => ['inserted' => 0, 'skipped' => 0, 'updated' => 0],
        ];

        try {
            $modulonPdo->beginTransaction();

            $migrationRunId = $this->createMigrationRun($modulonPdo, $userId, $legacyCounts);
            $importBatchId = $this->createImportBatch($modulonPdo, $userId, $migrationRunId);

            $accountMap = $this->importAccounts($legacyPdo, $modulonPdo, $userId, $migrationRunId, $stats['accounts']);
            $categoryMap = $this->importCategories($legacyPdo, $modulonPdo, $userId, $migrationRunId, $stats['categories']);

            $this->importTransactions(
                $legacyPdo,
                $modulonPdo,
                $userId,
                $migrationRunId,
                $importBatchId,
                $accountMap,
                $categoryMap,
                $stats['transactions']
            );
            $ruleMap = $this->importRecurringRules($legacyPdo, $modulonPdo, $userId, $migrationRunId, $stats['recurring_rules']);
            $this->importRecurringRuleConditions($legacyPdo, $modulonPdo, $userId, $migrationRunId, $ruleMap, $stats['recurring_rule_conditions']);

            $this->finishImportBatch($modulonPdo, $importBatchId, $stats['transactions']);
            $this->finishMigrationRun($modulonPdo, $migrationRunId, $stats);
            $verify = $this->verifySummary($modulonPdo, $userId);

            $modulonPdo->commit();
        } catch (Throwable $exception) {
            if ($modulonPdo->inTransaction()) {
                $modulonPdo->rollBack();
            }
            $this->error('Import fehlgeschlagen, Transaction wurde zurückgerollt: ' . $exception->getMessage());
            return 1;
        }

        $this->printImportStats($stats);
        $this->printVerifySummary($verify);
        $this->ok('Apply-Import abgeschlossen.');
        return 0;
    }

    /**
     * @param array<string, int> $legacyCounts
     */
    private function createMigrationRun(PDO $pdo, int $userId, array $legacyCounts): int
    {
        $statement = $pdo->prepare(
            "INSERT INTO banking_migration_runs
                (target_user_id, source_database, source_transactions_count, source_rules_count, source_conditions_count, status, started_at, created_at, updated_at)
             VALUES
                (:target_user_id, 'banking', :transactions, :rules, :conditions, 'running', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $statement->execute([
            'target_user_id' => $userId,
            'transactions' => (int) ($legacyCounts['transactions'] ?? 0),
            'rules' => (int) ($legacyCounts['recurring_rules'] ?? 0),
            'conditions' => (int) ($legacyCounts['recurring_rule_conditions'] ?? 0),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function createImportBatch(PDO $pdo, int $userId, int $migrationRunId): int
    {
        $statement = $pdo->prepare(
            "INSERT INTO banking_import_batches
                (user_id, migration_run_id, source_type, status, started_at, created_at, updated_at)
             VALUES
                (:user_id, :migration_run_id, 'legacy_migration', 'running', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $statement->execute([
            'user_id' => $userId,
            'migration_run_id' => $migrationRunId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{inserted:int,skipped:int,updated:int} $stats
     * @return array<string, int>
     */
    private function importAccounts(PDO $legacyPdo, PDO $modulonPdo, int $userId, int $migrationRunId, array &$stats): array
    {
        $rows = $legacyPdo
            ->query("SELECT DISTINCT auftragskonto, waehrung FROM transactions ORDER BY auftragskonto ASC")
            ->fetchAll();
        $map = [];

        foreach ($rows as $row) {
            $identifier = trim((string) ($row['auftragskonto'] ?? ''));
            if ($identifier === '') {
                $identifier = '(unbekannt)';
            }
            $currency = strtoupper(trim((string) ($row['waehrung'] ?? 'EUR')));
            if ($currency === '') {
                $currency = 'EUR';
            }

            $existing = $this->fetchOne($modulonPdo, 'SELECT id FROM banking_accounts WHERE user_id = :user_id AND account_identifier = :identifier LIMIT 1', [
                'user_id' => $userId,
                'identifier' => $identifier,
            ]);

            if (is_array($existing)) {
                $accountId = (int) ($existing['id'] ?? 0);
                $stats['skipped']++;
            } else {
                $statement = $modulonPdo->prepare(
                    'INSERT INTO banking_accounts
                        (user_id, migration_run_id, legacy_account_key, account_identifier, display_name, currency, created_at, updated_at)
                     VALUES
                        (:user_id, :migration_run_id, :legacy_account_key, :account_identifier, :display_name, :currency, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                );
                $statement->execute([
                    'user_id' => $userId,
                    'migration_run_id' => $migrationRunId,
                    'legacy_account_key' => $identifier,
                    'account_identifier' => $identifier,
                    'display_name' => $identifier,
                    'currency' => $currency,
                ]);
                $accountId = (int) $modulonPdo->lastInsertId();
                $stats['inserted']++;
            }

            $map[$identifier] = $accountId;
        }

        return $map;
    }

    /**
     * @param array{inserted:int,skipped:int,updated:int} $stats
     * @return array<string, int>
     */
    private function importCategories(PDO $legacyPdo, PDO $modulonPdo, int $userId, int $migrationRunId, array &$stats): array
    {
        $rows = $legacyPdo
            ->query("SELECT DISTINCT kategorie FROM transactions WHERE kategorie IS NOT NULL AND TRIM(kategorie) <> '' ORDER BY kategorie ASC")
            ->fetchAll();
        $map = [];
        $sortOrder = 10;

        foreach ($rows as $row) {
            $name = trim((string) ($row['kategorie'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized = $this->normalizeCategoryName($name);

            $existing = $this->fetchOne($modulonPdo, 'SELECT id FROM banking_categories WHERE user_id = :user_id AND normalized_name = :normalized LIMIT 1', [
                'user_id' => $userId,
                'normalized' => $normalized,
            ]);

            if (is_array($existing)) {
                $categoryId = (int) ($existing['id'] ?? 0);
                $stats['skipped']++;
            } else {
                $statement = $modulonPdo->prepare(
                    'INSERT INTO banking_categories
                        (user_id, migration_run_id, name, normalized_name, sort_order, created_at, updated_at)
                     VALUES
                        (:user_id, :migration_run_id, :name, :normalized_name, :sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                );
                $statement->execute([
                    'user_id' => $userId,
                    'migration_run_id' => $migrationRunId,
                    'name' => $name,
                    'normalized_name' => $normalized,
                    'sort_order' => $sortOrder,
                ]);
                $categoryId = (int) $modulonPdo->lastInsertId();
                $stats['inserted']++;
            }

            $map[$normalized] = $categoryId;
            $sortOrder += 10;
        }

        return $map;
    }

    /**
     * @param array<string, int> $accountMap
     * @param array<string, int> $categoryMap
     * @param array{inserted:int,skipped:int,updated:int} $stats
     */
    private function importTransactions(PDO $legacyPdo, PDO $modulonPdo, int $userId, int $migrationRunId, int $importBatchId, array $accountMap, array $categoryMap, array &$stats): void
    {
        $rows = $legacyPdo->query('SELECT * FROM transactions ORDER BY id ASC')->fetchAll();
        foreach ($rows as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $accountIdentifier = trim((string) ($row['auftragskonto'] ?? ''));
            if ($accountIdentifier === '') {
                $accountIdentifier = '(unbekannt)';
            }
            $categoryName = trim((string) ($row['kategorie'] ?? ''));
            $categoryId = $categoryName !== '' ? ($categoryMap[$this->normalizeCategoryName($categoryName)] ?? null) : null;
            $hash = trim((string) ($row['hash'] ?? ''));
            $payload = [
                'account_id' => $accountMap[$accountIdentifier] ?? null,
                'category_id' => $categoryId,
                'import_batch_id' => $importBatchId,
                'booking_date' => $this->dateOrNull($row['buchungstag'] ?? null) ?? '1970-01-01',
                'value_date' => $this->dateOrNull($row['valutadatum'] ?? null),
                'booking_text' => $this->nullableString($row['buchungstext'] ?? null),
                'purpose' => $this->nullableString($row['verwendungszweck'] ?? null),
                'counterparty_name' => $this->nullableString($row['beguenstigter_zahlungspflichtiger'] ?? null),
                'counterparty_iban' => $this->nullableString($row['kontonummer_iban'] ?? null),
                'counterparty_bic' => $this->nullableString($row['bic'] ?? null),
                'amount' => $this->decimalString($row['betrag'] ?? 0),
                'currency' => strtoupper(trim((string) ($row['waehrung'] ?? 'EUR'))) ?: 'EUR',
                'raw_info' => $this->nullableString($row['info'] ?? null),
                'legacy_category_name' => $categoryName !== '' ? $categoryName : null,
                'transaction_hash' => $hash !== '' ? $hash : null,
                'booking_status' => in_array((string) ($row['status'] ?? ''), ['gebucht', 'vorgemerkt'], true) ? (string) $row['status'] : 'gebucht',
                'legacy_created_at' => $this->timestampOrNull($row['created_at'] ?? null),
            ];

            $existing = $this->findExistingTransaction($modulonPdo, $userId, $legacyId, $payload['transaction_hash']);
            if ($existing === null) {
                $this->insertTransaction($modulonPdo, $userId, $migrationRunId, $legacyId, $payload);
                $stats['inserted']++;
                continue;
            }

            if ($this->rowsDiffer($existing, $payload, self::TRANSACTION_COLUMNS)) {
                $this->updateTransaction($modulonPdo, (int) $existing['id'], $migrationRunId, $legacyId, $payload);
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
        }
    }

    /**
     * @param array{inserted:int,skipped:int,updated:int} $stats
     * @return array<int, int>
     */
    private function importRecurringRules(PDO $legacyPdo, PDO $modulonPdo, int $userId, int $migrationRunId, array &$stats): array
    {
        $rows = $legacyPdo->query('SELECT * FROM recurring_rules ORDER BY id ASC')->fetchAll();
        $map = [];

        foreach ($rows as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $payload = [
                'account_id' => null,
                'category_id' => null,
                'name' => (string) ($row['name'] ?? ''),
                'interval_type' => (string) ($row['interval_type'] ?? ''),
                'notes' => $this->nullableString($row['notes'] ?? null),
                'rule_type' => $this->nullableString($row['rule_type'] ?? null),
                'group_label' => $this->nullableString($row['group_label'] ?? null),
                'active_from' => $this->dateOrNull($row['active_from'] ?? null),
                'active_to' => $this->dateOrNull($row['active_to'] ?? null),
                'period_mode' => $this->nullableString($row['period_mode'] ?? null),
                'due_day' => $this->intOrNull($row['due_day'] ?? null),
                'is_active' => 1,
                'legacy_created_at' => $this->timestampOrNull($row['created_at'] ?? null),
                'legacy_updated_at' => $this->timestampOrNull($row['updated_at'] ?? null),
            ];

            $existing = $this->findExistingByLegacy($modulonPdo, 'banking_recurring_rules', $userId, $legacyId);
            if ($existing === null) {
                $ruleId = $this->insertRecurringRule($modulonPdo, $userId, $migrationRunId, $legacyId, $payload);
                $stats['inserted']++;
            } else {
                $ruleId = (int) $existing['id'];
                if ($this->rowsDiffer($existing, $payload, self::RULE_COLUMNS)) {
                    $this->updateRecurringRule($modulonPdo, $ruleId, $migrationRunId, $payload);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            }

            $map[$legacyId] = $ruleId;
        }

        return $map;
    }

    /**
     * @param array<int, int> $ruleMap
     * @param array{inserted:int,skipped:int,updated:int} $stats
     */
    private function importRecurringRuleConditions(PDO $legacyPdo, PDO $modulonPdo, int $userId, int $migrationRunId, array $ruleMap, array &$stats): void
    {
        $rows = $legacyPdo->query('SELECT * FROM recurring_rule_conditions ORDER BY id ASC')->fetchAll();
        foreach ($rows as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $legacyRuleId = (int) ($row['rule_id'] ?? 0);
            $ruleId = $ruleMap[$legacyRuleId] ?? null;
            if ($ruleId === null) {
                throw new RuntimeException('Zielregel für Bedingung fehlt: legacy condition id ' . $legacyId);
            }

            $payload = [
                'recurring_rule_id' => $ruleId,
                'field' => $this->mapConditionField((string) ($row['field'] ?? '')),
                'operator' => (string) ($row['operator'] ?? ''),
                'value' => (string) ($row['value'] ?? ''),
                'legacy_created_at' => $this->timestampOrNull($row['created_at'] ?? null),
            ];

            $existing = $this->findExistingByLegacy($modulonPdo, 'banking_recurring_rule_conditions', $userId, $legacyId);
            if ($existing === null) {
                $this->insertCondition($modulonPdo, $userId, $migrationRunId, $legacyId, $payload);
                $stats['inserted']++;
                continue;
            }

            if ($this->rowsDiffer($existing, $payload, self::CONDITION_COLUMNS)) {
                $this->updateCondition($modulonPdo, (int) $existing['id'], $migrationRunId, $payload);
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
        }
    }

    private function printHelp(): void
    {
        $this->line('Modulon Banking Migration');
        $this->line('');
        $this->line('Aufrufe:');
        $this->line('  php bin/migrate-banking.php --dry-run');
        $this->line('  php bin/migrate-banking.php --apply --user-id=1');
        $this->line('  php bin/migrate-banking.php --verify --run-id=123');
        $this->line('  php bin/migrate-banking.php --rollback --run-id=123');
        $this->line('  php bin/migrate-banking.php --rollback --run-id=123 --confirm');
        $this->line('');
        $this->line('Hinweis: Ohne Modus wird --dry-run verwendet. Rollback löscht nur mit zusätzlichem --confirm.');
    }

    private function headline(string $text): void
    {
        $this->line($text);
        $this->line(str_repeat('-', strlen($text)));
    }

    private function ok(string $message): void
    {
        $this->line('[OK] ' . $message);
    }

    private function warning(string $message): void
    {
        $this->line('[WARN] ' . $message);
    }

    private function error(string $message): void
    {
        $this->line('[ERROR] ' . $message);
    }

    private function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}

exit((new BankingMigrationCli($argv))->run());
