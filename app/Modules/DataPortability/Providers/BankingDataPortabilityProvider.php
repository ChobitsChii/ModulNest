<?php

declare(strict_types=1);

namespace Modulon\Modules\DataPortability\Providers;

use Modulon\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Modules\DataPortability\DataPortabilityProviderInterface;
use PDO;
use Throwable;

final class BankingDataPortabilityProvider implements DataPortabilityProviderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function key(): string
    {
        return 'banking';
    }

    public function label(): string
    {
        return 'Banking';
    }

    public function routePrefix(): string
    {
        return '/banking';
    }

    public function description(): string
    {
        return 'Konten, Kategorien, Umsätze und wiederkehrende Regeln des aktuellen Benutzers.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function hasFiles(): bool
    {
        return false;
    }

    public function sensitivityNote(): string
    {
        return 'Banking-Exporte enthalten persönliche Finanzdaten. ZIP-Datei sicher aufbewahren und nicht öffentlich teilen.';
    }

    public function scopes(): array
    {
        return ['admin', 'user'];
    }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        $accounts = $this->scopedRows('banking_accounts', $userId);
        $categories = $this->scopedRows('banking_categories', $userId);
        $batches = $this->scopedRows('banking_import_batches', $userId);
        $transactions = $this->scopedRows('banking_transactions', $userId);
        $rules = $this->scopedRows('banking_recurring_rules', $userId);
        $conditions = $this->conditionsForRules(array_column($rules, 'id'));

        return [
            'files' => [
                'accounts.json' => ['schema_version' => $this->schemaVersion(), 'accounts' => $this->withRefs($accounts, 'account')],
                'categories.json' => ['schema_version' => $this->schemaVersion(), 'categories' => $this->withRefs($categories, 'category')],
                'transactions.json' => [
                    'schema_version' => $this->schemaVersion(),
                    'import_batches' => $this->withAccountRefs($batches, 'batch'),
                    'transactions' => $this->withBankingRefs($transactions),
                ],
                'recurring.json' => [
                    'schema_version' => $this->schemaVersion(),
                    'rules' => $this->withAccountCategoryRefs($rules, 'rule'),
                    'conditions' => $this->withRuleRefs($conditions),
                ],
            ],
            'counts' => [
                'accounts' => count($accounts),
                'categories' => count($categories),
                'transactions' => count($transactions),
                'recurring_rules' => count($rules),
                'conditions' => count($conditions),
            ],
            'warnings' => [$this->sensitivityNote()],
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        return [
            'counts' => [
                'accounts' => count($payload['accounts']['accounts'] ?? []),
                'categories' => count($payload['categories']['categories'] ?? []),
                'transactions' => count($payload['transactions']['transactions'] ?? []),
                'recurring_rules' => count($payload['recurring']['rules'] ?? []),
                'conditions' => count($payload['recurring']['conditions'] ?? []),
            ],
            'warnings' => [
                $this->sensitivityNote(),
                'Import löscht keine bestehenden Banking-Daten. Umsätze werden user-scoped über Hash, Legacy-ID oder abgeleiteten Kernfeld-Hash dedupliziert.',
            ],
            'can_import' => true,
        ];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $accountMap = [];
        $categoryMap = [];
        $batchMap = [];
        $ruleMap = [];

        $this->pdo->beginTransaction();
        try {
            foreach (($payload['accounts']['accounts'] ?? []) as $account) {
                if (!is_array($account)) {
                    $skipped++;
                    continue;
                }
                $existing = $this->findOne('SELECT id FROM banking_accounts WHERE user_id = :user_id AND account_identifier = :identifier', [
                    'user_id' => $targetUserId,
                    'identifier' => (string) ($account['account_identifier'] ?? ''),
                ]);
                if ($existing) {
                    $accountMap[(string) ($account['_export_ref'] ?? '')] = (int) $existing['id'];
                    $skipped++;
                    continue;
                }
                $accountMap[(string) ($account['_export_ref'] ?? '')] = $this->insert('banking_accounts', [
                    'user_id' => $targetUserId,
                    'migration_run_id' => null,
                    'legacy_account_key' => $this->nullable($account['legacy_account_key'] ?? null),
                    'account_identifier' => (string) ($account['account_identifier'] ?? ''),
                    'display_name' => (string) ($account['display_name'] ?? $account['account_identifier'] ?? 'Importiertes Konto'),
                    'iban' => $this->nullable($account['iban'] ?? null),
                    'bic' => $this->nullable($account['bic'] ?? null),
                    'currency' => (string) ($account['currency'] ?? 'EUR'),
                    'is_active' => (int) ($account['is_active'] ?? 1),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }

            foreach (($payload['categories']['categories'] ?? []) as $category) {
                if (!is_array($category)) {
                    $skipped++;
                    continue;
                }
                $normalized = (string) ($category['normalized_name'] ?? $this->normalize((string) ($category['name'] ?? '')));
                $existing = $this->findOne('SELECT id FROM banking_categories WHERE user_id = :user_id AND normalized_name = :name', [
                    'user_id' => $targetUserId,
                    'name' => $normalized,
                ]);
                if ($existing) {
                    $categoryMap[(string) ($category['_export_ref'] ?? '')] = (int) $existing['id'];
                    $skipped++;
                    continue;
                }
                $categoryMap[(string) ($category['_export_ref'] ?? '')] = $this->insert('banking_categories', [
                    'user_id' => $targetUserId,
                    'migration_run_id' => null,
                    'name' => (string) ($category['name'] ?? 'Ohne Kategorie'),
                    'normalized_name' => $normalized,
                    'color' => $this->nullable($category['color'] ?? null),
                    'sort_order' => (int) ($category['sort_order'] ?? 0),
                    'is_active' => (int) ($category['is_active'] ?? 1),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }

            foreach (($payload['transactions']['import_batches'] ?? []) as $batch) {
                if (!is_array($batch)) {
                    $skipped++;
                    continue;
                }
                $batchMap[(string) ($batch['_export_ref'] ?? '')] = $this->insert('banking_import_batches', [
                    'user_id' => $targetUserId,
                    'account_id' => $accountMap[(string) ($batch['_account_ref'] ?? '')] ?? null,
                    'migration_run_id' => null,
                    'source_type' => 'other',
                    'original_filename' => $this->nullable($batch['original_filename'] ?? null),
                    'file_sha256' => $this->nullable($batch['file_sha256'] ?? null),
                    'status' => 'completed',
                    'imported_count' => (int) ($batch['imported_count'] ?? 0),
                    'updated_count' => (int) ($batch['updated_count'] ?? 0),
                    'skipped_count' => (int) ($batch['skipped_count'] ?? 0),
                    'error_count' => (int) ($batch['error_count'] ?? 0),
                    'error_summary' => $this->nullable($batch['error_summary'] ?? null),
                    'started_at' => $this->nullable($batch['started_at'] ?? null),
                    'finished_at' => $this->nullable($batch['finished_at'] ?? null),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }

            foreach (($payload['transactions']['transactions'] ?? []) as $transaction) {
                if (!is_array($transaction)) {
                    $skipped++;
                    continue;
                }
                $hash = trim((string) ($transaction['transaction_hash'] ?? ''));
                if ($hash === '') {
                    $hash = $this->transactionHash($transaction);
                }
                if ($this->transactionExists($targetUserId, $transaction, $hash)) {
                    $skipped++;
                    continue;
                }
                $this->insert('banking_transactions', [
                    'user_id' => $targetUserId,
                    'account_id' => $accountMap[(string) ($transaction['_account_ref'] ?? '')] ?? null,
                    'category_id' => $categoryMap[(string) ($transaction['_category_ref'] ?? '')] ?? null,
                    'import_batch_id' => $batchMap[(string) ($transaction['_batch_ref'] ?? '')] ?? null,
                    'booking_date' => $this->nullable($transaction['booking_date'] ?? null),
                    'value_date' => $this->nullable($transaction['value_date'] ?? null),
                    'booking_text' => (string) ($transaction['booking_text'] ?? ''),
                    'purpose' => (string) ($transaction['purpose'] ?? ''),
                    'counterparty_name' => $this->nullable($transaction['counterparty_name'] ?? null),
                    'counterparty_iban' => $this->nullable($transaction['counterparty_iban'] ?? null),
                    'counterparty_bic' => $this->nullable($transaction['counterparty_bic'] ?? null),
                    'amount' => (string) ($transaction['amount'] ?? '0.00'),
                    'currency' => (string) ($transaction['currency'] ?? 'EUR'),
                    'raw_info' => $this->nullable($transaction['raw_info'] ?? null),
                    'legacy_category_name' => $this->nullable($transaction['legacy_category_name'] ?? null),
                    'transaction_hash' => $hash,
                    'booking_status' => (string) ($transaction['booking_status'] ?? 'gebucht'),
                    'legacy_id' => $this->availableLegacyId('banking_transactions', $targetUserId, $this->nullable($transaction['legacy_id'] ?? null)),
                    'migration_run_id' => null,
                    'legacy_created_at' => $this->nullable($transaction['legacy_created_at'] ?? null),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }

            foreach (($payload['recurring']['rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    $skipped++;
                    continue;
                }
                $existing = $this->findRecurringRule($targetUserId, $rule);
                if ($existing) {
                    $ruleMap[(string) ($rule['_export_ref'] ?? '')] = (int) $existing['id'];
                    $skipped++;
                    continue;
                }
                $ruleMap[(string) ($rule['_export_ref'] ?? '')] = $this->insert('banking_recurring_rules', [
                    'user_id' => $targetUserId,
                    'account_id' => $accountMap[(string) ($rule['_account_ref'] ?? '')] ?? null,
                    'category_id' => $categoryMap[(string) ($rule['_category_ref'] ?? '')] ?? null,
                    'migration_run_id' => null,
                    'name' => (string) ($rule['name'] ?? 'Importierte Regel'),
                    'interval_type' => (string) ($rule['interval_type'] ?? 'monthly'),
                    'notes' => $this->nullable($rule['notes'] ?? null),
                    'rule_type' => $this->nullable($rule['rule_type'] ?? null),
                    'group_label' => $this->nullable($rule['group_label'] ?? null),
                    'active_from' => $this->nullable($rule['active_from'] ?? null),
                    'active_to' => $this->nullable($rule['active_to'] ?? null),
                    'period_mode' => $this->nullable($rule['period_mode'] ?? null),
                    'due_day' => $this->nullable($rule['due_day'] ?? null),
                    'is_active' => (int) ($rule['is_active'] ?? 1),
                    'legacy_id' => $this->availableLegacyId('banking_recurring_rules', $targetUserId, $this->nullable($rule['legacy_id'] ?? null)),
                    'legacy_created_at' => $this->nullable($rule['legacy_created_at'] ?? null),
                    'legacy_updated_at' => $this->nullable($rule['legacy_updated_at'] ?? null),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $created++;
            }

            foreach (($payload['recurring']['conditions'] ?? []) as $condition) {
                $ruleId = is_array($condition) ? ($ruleMap[(string) ($condition['_rule_ref'] ?? '')] ?? null) : null;
                if (!is_array($condition) || !$ruleId) {
                    $skipped++;
                    continue;
                }
                if ($this->conditionExists($ruleId, $condition)) {
                    $skipped++;
                    continue;
                }
                $this->insert('banking_recurring_rule_conditions', [
                    'user_id' => $targetUserId,
                    'recurring_rule_id' => $ruleId,
                    'migration_run_id' => null,
                    'legacy_id' => $this->availableConditionLegacyId($targetUserId, $ruleId, $this->nullable($condition['legacy_id'] ?? null)),
                    'field' => (string) ($condition['field'] ?? $condition['field_name'] ?? ''),
                    'operator' => (string) ($condition['operator'] ?? 'contains'),
                    'value' => (string) ($condition['value'] ?? ''),
                    'legacy_created_at' => $this->nullable($condition['legacy_created_at'] ?? null),
                    'created_at' => $this->now(),
                ]);
                $created++;
            }

            $this->pdo->commit();
        } catch (Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => []];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scopedRows(string $table, int $userId): array
    {
        return $this->fetchAll('SELECT * FROM ' . $table . ' WHERE user_id = :user_id ORDER BY id', ['user_id' => $userId]);
    }

    /**
     * @param array<int,mixed> $ruleIds
     * @return array<int,array<string,mixed>>
     */
    private function conditionsForRules(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));

        return $this->fetchAll('SELECT * FROM banking_recurring_rule_conditions WHERE recurring_rule_id IN (' . $placeholders . ') ORDER BY recurring_rule_id, id', array_values($ruleIds));
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function findOne(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withRefs(array $rows, string $prefix): array
    {
        foreach ($rows as &$row) {
            $row['_export_ref'] = $prefix . '-' . (string) ($row['id'] ?? '');
            unset($row['id'], $row['user_id'], $row['migration_run_id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withAccountRefs(array $rows, string $prefix): array
    {
        foreach ($rows as &$row) {
            $row['_export_ref'] = $prefix . '-' . (string) ($row['id'] ?? '');
            $row['_account_ref'] = isset($row['account_id']) && $row['account_id'] !== null ? 'account-' . (string) $row['account_id'] : null;
            unset($row['id'], $row['user_id'], $row['account_id'], $row['migration_run_id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withAccountCategoryRefs(array $rows, string $prefix): array
    {
        foreach ($rows as &$row) {
            $row['_export_ref'] = $prefix . '-' . (string) ($row['id'] ?? '');
            $row['_account_ref'] = isset($row['account_id']) && $row['account_id'] !== null ? 'account-' . (string) $row['account_id'] : null;
            $row['_category_ref'] = isset($row['category_id']) && $row['category_id'] !== null ? 'category-' . (string) $row['category_id'] : null;
            unset($row['id'], $row['user_id'], $row['account_id'], $row['category_id'], $row['migration_run_id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withBankingRefs(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['_export_ref'] = 'transaction-' . (string) ($row['id'] ?? '');
            $row['_account_ref'] = isset($row['account_id']) && $row['account_id'] !== null ? 'account-' . (string) $row['account_id'] : null;
            $row['_category_ref'] = isset($row['category_id']) && $row['category_id'] !== null ? 'category-' . (string) $row['category_id'] : null;
            $row['_batch_ref'] = isset($row['import_batch_id']) && $row['import_batch_id'] !== null ? 'batch-' . (string) $row['import_batch_id'] : null;
            unset($row['id'], $row['user_id'], $row['account_id'], $row['category_id'], $row['import_batch_id'], $row['migration_run_id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withRuleRefs(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['_export_ref'] = 'condition-' . (string) ($row['id'] ?? '');
            $row['_rule_ref'] = 'rule-' . (string) ($row['recurring_rule_id'] ?? '');
            unset($row['id'], $row['user_id'], $row['recurring_rule_id'], $row['migration_run_id']);
        }
        unset($row);

        return $rows;
    }

    private function transactionExists(int $userId, array $transaction, string $hash): bool
    {
        if ($hash !== '' && $this->findOne('SELECT id FROM banking_transactions WHERE user_id = :user_id AND transaction_hash = :hash', ['user_id' => $userId, 'hash' => $hash])) {
            return true;
        }
        $legacyId = $this->nullable($transaction['legacy_id'] ?? null);
        if ($legacyId !== null && $this->findOne('SELECT id FROM banking_transactions WHERE user_id = :user_id AND legacy_id = :legacy_id', ['user_id' => $userId, 'legacy_id' => $legacyId])) {
            return true;
        }

        return false;
    }

    private function transactionHash(array $transaction): string
    {
        return hash('sha256', implode('|', [
            (string) ($transaction['booking_date'] ?? ''),
            (string) ($transaction['value_date'] ?? ''),
            mb_strtolower(trim((string) ($transaction['booking_text'] ?? ''))),
            mb_strtolower(trim((string) ($transaction['purpose'] ?? ''))),
            mb_strtolower(trim((string) ($transaction['counterparty_name'] ?? ''))),
            preg_replace('/\s+/', '', mb_strtoupper((string) ($transaction['counterparty_iban'] ?? ''))),
            number_format((float) str_replace(',', '.', (string) ($transaction['amount'] ?? '0')), 2, '.', ''),
            (string) ($transaction['currency'] ?? 'EUR'),
            (string) ($transaction['booking_status'] ?? ''),
        ]));
    }

    private function findRecurringRule(int $userId, array $rule): ?array
    {
        $legacyId = $this->nullable($rule['legacy_id'] ?? null);
        if ($legacyId !== null) {
            $existing = $this->findOne('SELECT id FROM banking_recurring_rules WHERE user_id = :user_id AND legacy_id = :legacy_id', ['user_id' => $userId, 'legacy_id' => $legacyId]);
            if ($existing) {
                return $existing;
            }
        }

        return $this->findOne(
            'SELECT id FROM banking_recurring_rules WHERE user_id = :user_id AND name = :name AND interval_type = :interval_type AND COALESCE(group_label, "") = :group_label',
            [
                'user_id' => $userId,
                'name' => (string) ($rule['name'] ?? ''),
                'interval_type' => (string) ($rule['interval_type'] ?? 'monthly'),
                'group_label' => (string) ($rule['group_label'] ?? ''),
            ]
        );
    }

    private function conditionExists(int $ruleId, array $condition): bool
    {
        return $this->findOne(
            'SELECT id FROM banking_recurring_rule_conditions WHERE recurring_rule_id = :rule_id AND field = :field AND operator = :operator AND value = :value',
            [
                'rule_id' => $ruleId,
                'field' => (string) ($condition['field'] ?? $condition['field_name'] ?? ''),
                'operator' => (string) ($condition['operator'] ?? ''),
                'value' => (string) ($condition['value'] ?? ''),
            ]
        ) !== null;
    }

    private function availableLegacyId(string $table, int $userId, mixed $legacyId): mixed
    {
        if ($legacyId === null || $legacyId === '') {
            return null;
        }
        $existing = $this->findOne('SELECT id FROM ' . $table . ' WHERE user_id = :user_id AND legacy_id = :legacy_id', ['user_id' => $userId, 'legacy_id' => $legacyId]);

        return $existing ? null : $legacyId;
    }

    private function availableConditionLegacyId(int $userId, int $ruleId, mixed $legacyId): mixed
    {
        if ($legacyId === null || $legacyId === '') {
            return null;
        }
        $existing = $this->findOne(
            'SELECT id FROM banking_recurring_rule_conditions WHERE (user_id = :user_id OR recurring_rule_id = :rule_id) AND legacy_id = :legacy_id',
            ['user_id' => $userId, 'rule_id' => $ruleId, 'legacy_id' => $legacyId]
        );

        return $existing ? null : $legacyId;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function nullable(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
