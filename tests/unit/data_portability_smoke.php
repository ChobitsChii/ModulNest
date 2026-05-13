<?php

declare(strict_types=1);

use Modulon\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Modules\DataPortability\DataPortabilityController;
use Modulon\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Modules\DataPortability\DataPortabilityProviderInterface;
use Modulon\Modules\DataPortability\DataPortabilityService;
use Modulon\Modules\DataPortability\Providers\BankingDataPortabilityProvider;
use Modulon\Modules\DataPortability\Providers\DashboardDataPortabilityProvider;
use Modulon\Modules\DataPortability\Providers\NewsDataPortabilityProvider;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class DataPortabilitySmokeProvider implements DataPortabilityProviderInterface
{
    /**
     * @param array<int,string> $scopes
     */
    public function __construct(private readonly array $scopes = ['admin', 'user'])
    {
    }

    public function key(): string { return 'smoke'; }
    public function label(): string { return 'Smoke'; }
    public function routePrefix(): string { return '/smoke'; }
    public function description(): string { return 'Smoke provider'; }
    public function schemaVersion(): int { return 1; }
    public function hasFiles(): bool { return false; }
    public function sensitivityNote(): string { return ''; }
    public function supportsReplaceImport(): bool { return false; }
    public function scopes(): array { return $this->scopes; }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        return [
            'files' => ['data.json' => ['schema_version' => 1, 'items' => [['id' => $userId, 'name' => 'Test']]]],
            'counts' => ['items' => 1],
            'warnings' => [],
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        return ['counts' => ['items' => count($payload['data']['items'] ?? [])], 'warnings' => [], 'can_import' => true];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array
    {
        return ['created' => count($payload['data']['items'] ?? []), 'updated' => 0, 'skipped' => 0, 'warnings' => []];
    }
}

final class DataPortabilityAdminOnlySmokeProvider implements DataPortabilityProviderInterface
{
    public function key(): string { return 'admin-only'; }
    public function label(): string { return 'Admin Only'; }
    public function routePrefix(): string { return '/admin-only'; }
    public function description(): string { return 'Admin only provider'; }
    public function schemaVersion(): int { return 1; }
    public function hasFiles(): bool { return false; }
    public function sensitivityNote(): string { return ''; }
    public function supportsReplaceImport(): bool { return false; }
    public function scopes(): array { return ['admin']; }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        return [
            'files' => ['data.json' => ['schema_version' => 1, 'items' => [['id' => $userId, 'name' => 'Admin']]]],
            'counts' => ['items' => 1],
            'warnings' => [],
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        return ['counts' => ['items' => count($payload['data']['items'] ?? [])], 'warnings' => [], 'can_import' => true];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array
    {
        return ['created' => count($payload['data']['items'] ?? []), 'updated' => 0, 'skipped' => 0, 'warnings' => []];
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "SKIP: ZipArchive is not available.\n");
    exit(0);
}

$base = sys_get_temp_dir() . '/modulon-data-portability-smoke-' . bin2hex(random_bytes(4));
mkdir($base . '/storage/data-portability', 0775, true);

$service = new DataPortabilityService($base, '9.9.9', [
    'smoke' => new DataPortabilitySmokeProvider(),
    'admin-only' => new DataPortabilityAdminOnlySmokeProvider(),
]);
$export = $service->createExport(['smoke'], 123);

$zip = new ZipArchive();
assert_true($zip->open($export['path']) === true, 'Export-ZIP kann geöffnet werden.');
assert_true($zip->locateName('manifest.json') !== false, 'manifest.json fehlt.');
assert_true($zip->locateName('modules/smoke/data.json') !== false, 'Moduldaten fehlen.');
$zip->close();

$preview = $service->previewArchive($export['path'], 123);
assert_true(($preview['manifest']['format_version'] ?? null) === 1, 'Format-Version falsch.');
assert_true(($preview['modules'][0]['can_import'] ?? false) === true, 'Preview markiert Provider nicht importierbar.');
assert_true(($service->previewArchive($export['path'], 123, 'admin', 'replace')['modules'][0]['can_import'] ?? true) === false, 'Ersetzen-Modus akzeptiert Provider ohne Replace-Support.');

$controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/DataPortability/DataPortabilityController.php');
assert_true(!str_contains($controllerSource, 'file_get_contents($export'), 'Export-Controller darf Export-ZIPs nicht komplett in den Response-Body laden.');
assert_true(str_contains($controllerSource, 'Response::downloadFile'), 'Export-Controller nutzt keine Streaming-Download-Response.');

assert_true(isset($service->providersForScope('user')['smoke']), 'User-Scope zeigt userfähigen Provider nicht.');
assert_true(!isset($service->providersForScope('user')['admin-only']), 'User-Scope zeigt admin-only Provider.');

$mixedExport = $service->createExport(['smoke', 'admin-only'], 123, 'admin');
$userPreview = $service->previewArchive($mixedExport['path'], 123, 'user');
$userKeys = array_map(static fn (array $module): string => (string) ($module['key'] ?? ''), $userPreview['modules']);
assert_true(in_array('smoke', $userKeys, true), 'User-Scope enthält userfähigen Provider nicht.');
assert_true(!in_array('admin-only', $userKeys, true), 'User-Scope zeigt admin-only Modul in der Vorschau.');

try {
    $service->createExport(['admin-only'], 123, 'user');
    assert_true(false, 'User-Scope konnte admin-only Provider exportieren.');
} catch (RuntimeException) {
}

$newsReflection = new ReflectionClass(NewsDataPortabilityProvider::class);
/** @var NewsDataPortabilityProvider $newsProviderWithoutDb */
$newsProviderWithoutDb = $newsReflection->newInstanceWithoutConstructor();
$newsScopeService = new DataPortabilityService($base, '9.9.9', ['news' => $newsProviderWithoutDb]);
assert_true(isset($newsScopeService->providersForScope('admin')['news']), 'News-Provider fehlt im Admin-Scope.');
assert_true(!isset($newsScopeService->providersForScope('user')['news']), 'News-Provider erscheint im User-Scope.');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: SQLite PDO driver is not available; News preview DB test skipped.\n");
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE news_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        excerpt TEXT NOT NULL,
        content TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT "news",
        version TEXT NULL,
        status TEXT NOT NULL DEFAULT "draft",
        published_at TEXT NULL,
        created_by INTEGER NULL,
        updated_by INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
    );
    $pdo->prepare(
        'INSERT INTO news_entries (title, slug, excerpt, content, type, version, status, published_at)
     VALUES (:title, :slug, :excerpt, :content, :type, :version, :status, :published_at)'
    )->execute([
        'title' => 'Existing',
        'slug' => 'existing-release',
        'excerpt' => 'Existing excerpt',
        'content' => 'Existing markdown',
        'type' => 'release',
        'version' => '1.0.0',
        'status' => 'published',
        'published_at' => '2026-05-01 10:00:00',
    ]);
    $pdo->prepare(
        'INSERT INTO news_entries (title, slug, excerpt, content, type, version, status, published_at)
     VALUES (:title, :slug, :excerpt, :content, :type, :version, :status, :published_at)'
    )->execute([
        'title' => 'Keep me only in merge',
        'slug' => 'keep-me',
        'excerpt' => 'Keep excerpt',
        'content' => 'Keep markdown',
        'type' => 'news',
        'version' => null,
        'status' => 'published',
        'published_at' => '2026-05-01 11:00:00',
    ]);

    $newsProvider = new NewsDataPortabilityProvider($pdo);
    $newsService = new DataPortabilityService($base, '9.9.9', ['news' => $newsProvider]);

    $newsImport = $base . '/news-import.zip';
    $zip = new ZipArchive();
    $zip->open($newsImport, ZipArchive::CREATE);
    $zip->addFromString('manifest.json', json_encode([
        'format_version' => 1,
        'product' => 'ModulNest',
        'core' => 'Modulon',
        'app_version' => '9.9.9',
        'created_at' => gmdate('c'),
        'scope' => 'module-data',
        'access_scope' => 'admin',
        'modules' => [[
            'key' => 'news',
            'label' => 'News',
            'description' => 'News',
            'schema_version' => 1,
            'has_files' => false,
            'data_files' => ['modules/news/entries.json'],
            'files' => [],
            'file_count' => 0,
            'counts' => ['entries' => 3],
            'warnings' => [],
        ]],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->addFromString('modules/news/entries.json', json_encode([
        'schema_version' => 1,
        'entries' => [
            [
                'title' => 'Existing updated',
                'slug' => 'existing-release',
                'excerpt' => 'Updated excerpt',
                'content' => 'Updated **markdown**',
                'type' => 'release',
                'version' => '1.0.1',
                'status' => 'published',
                'published_at' => '2026-05-02 10:00:00',
            ],
            [
                'title' => 'New release',
                'slug' => 'new-release',
                'excerpt' => 'New excerpt',
                'content' => 'New markdown',
                'type' => 'release',
                'version' => '1.1.0',
                'status' => 'draft',
                'published_at' => null,
            ],
            [
                'title' => 'Invalid slug',
                'slug' => '',
                'excerpt' => 'Invalid excerpt',
                'content' => 'Invalid markdown',
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->close();

    $newsPreview = $newsService->previewArchive($newsImport, 1, 'admin');
    $newsCounts = $newsPreview['modules'][0]['counts'] ?? [];
    assert_true(($newsCounts['new'] ?? null) === 1, 'News-Preview erkennt neue Einträge nicht.');
    assert_true(($newsCounts['update'] ?? null) === 1, 'News-Preview erkennt Updates nicht.');
    assert_true(($newsCounts['invalid'] ?? null) === 1, 'News-Preview erkennt ungültige Einträge nicht.');
    assert_true($newsService->previewArchive($newsImport, 1, 'user')['modules'] === [], 'News erscheint in User-Preview.');
    $newsReplace = $newsService->importArchive($newsImport, 1, 'admin', 'replace');
    assert_true(($newsReplace['results']['news']['created'] ?? null) === 2, 'News-Replace importiert gültige Einträge nicht neu.');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM news_entries')->fetchColumn() === 2, 'News-Replace löscht vorhandene News nicht vor dem Import.');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM news_entries WHERE slug = "keep-me"')->fetchColumn() === 0, 'News-Replace lässt alte News stehen.');

    $bankingPdo = new PDO('sqlite::memory:');
    $bankingPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bankingPdo->exec('CREATE TABLE banking_migration_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, target_user_id INTEGER NOT NULL
    )');
    $bankingPdo->exec('CREATE TABLE banking_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, migration_run_id INTEGER NULL,
        legacy_account_key TEXT NULL, account_identifier TEXT NOT NULL, display_name TEXT NOT NULL,
        iban TEXT NULL, bic TEXT NULL, currency TEXT NOT NULL, is_active INTEGER NOT NULL,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $bankingPdo->exec('CREATE TABLE banking_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, migration_run_id INTEGER NULL,
        name TEXT NOT NULL, normalized_name TEXT NOT NULL, color TEXT NULL, sort_order INTEGER NOT NULL,
        is_active INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $bankingPdo->exec('CREATE TABLE banking_import_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NULL, migration_run_id INTEGER NULL,
        source_type TEXT NOT NULL, original_filename TEXT NULL, file_sha256 TEXT NULL, status TEXT NOT NULL,
        imported_count INTEGER NOT NULL, updated_count INTEGER NOT NULL, skipped_count INTEGER NOT NULL, error_count INTEGER NOT NULL,
        error_summary TEXT NULL, started_at TEXT NULL, finished_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $bankingPdo->exec('CREATE TABLE banking_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NULL, category_id INTEGER NULL,
        import_batch_id INTEGER NULL, booking_date TEXT NULL, value_date TEXT NULL, booking_text TEXT NOT NULL,
        purpose TEXT NOT NULL, counterparty_name TEXT NULL, counterparty_iban TEXT NULL, counterparty_bic TEXT NULL,
        amount TEXT NOT NULL, currency TEXT NOT NULL, raw_info TEXT NULL, legacy_category_name TEXT NULL,
        transaction_hash TEXT NOT NULL, booking_status TEXT NOT NULL, legacy_id INTEGER NULL, migration_run_id INTEGER NULL,
        legacy_created_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $bankingPdo->exec('CREATE TABLE banking_recurring_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NULL, category_id INTEGER NULL,
        migration_run_id INTEGER NULL, legacy_id INTEGER NULL, name TEXT NOT NULL, interval_type TEXT NOT NULL,
        notes TEXT NULL, rule_type TEXT NULL, group_label TEXT NULL, active_from TEXT NULL, active_to TEXT NULL,
        period_mode TEXT NULL, due_day TEXT NULL, is_active INTEGER NOT NULL, legacy_created_at TEXT NULL,
        legacy_updated_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $bankingPdo->exec('CREATE TABLE banking_recurring_rule_conditions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, recurring_rule_id INTEGER NOT NULL,
        migration_run_id INTEGER NULL, legacy_id INTEGER NULL, field TEXT NOT NULL, operator TEXT NOT NULL,
        value TEXT NOT NULL, legacy_created_at TEXT NULL, created_at TEXT NOT NULL
    )');
    $bankingPdo->exec('CREATE TABLE banking_dashboard_cache (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, cache_scope TEXT NOT NULL, period_key TEXT NOT NULL,
        data_hash TEXT NOT NULL, payload_json TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');

    $bankingPdo->exec("INSERT INTO banking_accounts (user_id, migration_run_id, legacy_account_key, account_identifier, display_name, iban, bic, currency, is_active, created_at, updated_at)
        VALUES (1, NULL, 'legacy-main', 'DEMO-ACCOUNT', 'Demo Konto', NULL, NULL, 'EUR', 1, '2026-05-01 00:00:00', '2026-05-01 00:00:00')");
    $bankingPdo->exec("INSERT INTO banking_categories (user_id, migration_run_id, name, normalized_name, color, sort_order, is_active, created_at, updated_at)
        VALUES (1, NULL, 'Miete', 'miete', '#abcdef', 1, 1, '2026-05-01 00:00:00', '2026-05-01 00:00:00')");
    $bankingPdo->exec("INSERT INTO banking_import_batches (user_id, account_id, migration_run_id, source_type, original_filename, file_sha256, status, imported_count, updated_count, skipped_count, error_count, error_summary, started_at, finished_at, created_at, updated_at)
        VALUES (1, 1, NULL, 'csv', 'demo.csv', 'abc', 'completed', 1, 0, 0, 0, NULL, '2026-05-01 00:00:00', '2026-05-01 00:00:00', '2026-05-01 00:00:00', '2026-05-01 00:00:00')");
    $bankingPdo->exec("INSERT INTO banking_transactions (user_id, account_id, category_id, import_batch_id, booking_date, value_date, booking_text, purpose, counterparty_name, counterparty_iban, counterparty_bic, amount, currency, raw_info, legacy_category_name, transaction_hash, booking_status, legacy_id, migration_run_id, legacy_created_at, created_at, updated_at)
        VALUES (1, 1, 1, 1, '2026-05-02', '2026-05-02', 'Dauerauftrag', 'Miete Mai', 'Vermieter', NULL, NULL, '-800.00', 'EUR', NULL, 'Miete', 'hash-demo-transaction', 'gebucht', 501, NULL, NULL, '2026-05-02 00:00:00', '2026-05-02 00:00:00')");
    $bankingPdo->exec("INSERT INTO banking_recurring_rules (user_id, account_id, category_id, migration_run_id, legacy_id, name, interval_type, notes, rule_type, group_label, active_from, active_to, period_mode, due_day, is_active, legacy_created_at, legacy_updated_at, created_at, updated_at)
        VALUES
        (1, 1, 1, NULL, 701, 'Miete', 'monthly', 'erste Regel', 'expense', 'Wohnen', '2026-01-01', NULL, 'fixed', '3', 1, NULL, NULL, '2026-05-01 00:00:00', '2026-05-01 00:00:00'),
        (1, 1, 1, NULL, 702, 'Miete', 'monthly', 'zweite Regel gleicher Name', 'expense', 'Wohnen', '2026-01-01', NULL, 'fixed', '3', 1, NULL, NULL, '2026-05-01 00:00:00', '2026-05-01 00:00:00')");
    $bankingPdo->exec("INSERT INTO banking_recurring_rule_conditions (user_id, recurring_rule_id, migration_run_id, legacy_id, field, operator, value, legacy_created_at, created_at)
        VALUES
        (1, 1, NULL, 801, 'purpose', 'contains', 'Miete', NULL, '2026-05-01 00:00:00'),
        (1, 2, NULL, 802, 'purpose', 'contains', 'Miete', NULL, '2026-05-01 00:00:00')");

    $bankingProvider = new BankingDataPortabilityProvider($bankingPdo);
    $bankingService = new DataPortabilityService($base, '9.9.9', ['banking' => $bankingProvider]);
    $bankingExport = $bankingService->createExport(['banking'], 1, 'user');
    $bankingZip = new ZipArchive();
    assert_true($bankingZip->open($bankingExport['path']) === true, 'Banking-Export-ZIP kann nicht geöffnet werden.');
    $recurringPayload = json_decode((string) $bankingZip->getFromName('modules/banking/recurring.json'), true);
    $bankingZip->close();
    assert_true(count($recurringPayload['rules'] ?? []) === 2, 'Banking-Export enthält nicht alle wiederkehrenden Regeln.');
    assert_true(count($recurringPayload['conditions'] ?? []) === 2, 'Banking-Export enthält nicht alle Filter/Bedingungen.');

    $bankingPreview = $bankingService->previewArchive($bankingExport['path'], 2, 'user');
    $bankingPreviewCounts = $bankingPreview['modules'][0]['counts'] ?? [];
    assert_true(($bankingPreviewCounts['recurring_rules'] ?? null) === 2, 'Banking-Preview zählt Regeln falsch.');
    assert_true(($bankingPreviewCounts['conditions'] ?? null) === 2, 'Banking-Preview zählt Filter/Bedingungen falsch.');

    $bankingImport = $bankingService->importArchive($bankingExport['path'], 2, 'user');
    $bankingStats = $bankingImport['results']['banking'] ?? [];
    assert_true(($bankingStats['details']['recurring_rules_created'] ?? null) === 2, 'Banking-Import legt gleichnamige Regeln nicht vollständig neu an.');
    assert_true(($bankingStats['details']['conditions_created'] ?? null) === 2, 'Banking-Import legt Filter/Bedingungen nicht vollständig an.');
    assert_true(str_contains((string) ($bankingStats['summary'] ?? ''), 'Regeln neu 2'), 'Banking-Import liefert keine klare Regel-Zusammenfassung.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_recurring_rules WHERE user_id = 2')->fetchColumn() === 2, 'Zieluser hat nicht alle Regeln.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_recurring_rule_conditions WHERE user_id = 2')->fetchColumn() === 2, 'Zieluser hat nicht alle Filter/Bedingungen.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(DISTINCT recurring_rule_id) FROM banking_recurring_rule_conditions WHERE user_id = 2')->fetchColumn() === 2, 'Filter/Bedingungen wurden nicht den jeweils importierten Regeln zugeordnet.');

    $bankingSecondImport = $bankingService->importArchive($bankingExport['path'], 2, 'user');
    $bankingSecondStats = $bankingSecondImport['results']['banking'] ?? [];
    assert_true(($bankingSecondStats['details']['transactions_skipped_duplicates'] ?? 0) >= 1, 'Banking-Import dedupliziert Buchungen nicht mehr.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_transactions WHERE user_id = 2')->fetchColumn() === 1, 'Buchungs-Deduplizierung erzeugt Duplikate.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_recurring_rules WHERE user_id = 2')->fetchColumn() === 4, 'Wiederholter Import darf gleichnamige Regeln nicht deduplizieren.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_recurring_rule_conditions WHERE user_id = 2')->fetchColumn() === 4, 'Wiederholter Import darf gleiche Filter/Bedingungen nicht deduplizieren.');
    $bankingPdo->exec("INSERT INTO banking_accounts (user_id, migration_run_id, legacy_account_key, account_identifier, display_name, iban, bic, currency, is_active, created_at, updated_at)
        VALUES (3, NULL, 'other', 'OTHER-ACCOUNT', 'Anderer User', NULL, NULL, 'EUR', 1, '2026-05-01 00:00:00', '2026-05-01 00:00:00')");
    $bankingReplace = $bankingService->importArchive($bankingExport['path'], 2, 'user', 'replace');
    assert_true(($bankingReplace['results']['banking']['replaced']['recurring_rules'] ?? null) === 4, 'Banking-Replace meldet gelöschte Regeln nicht korrekt.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_accounts WHERE user_id = 2')->fetchColumn() === 1, 'Banking-Replace stellt Zielkonten nicht vollständig wieder her.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_transactions WHERE user_id = 2')->fetchColumn() === 1, 'Banking-Replace stellt Zielbuchungen nicht korrekt wieder her.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_recurring_rules WHERE user_id = 2')->fetchColumn() === 2, 'Banking-Replace stellt Regeln nicht korrekt wieder her.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_recurring_rule_conditions WHERE user_id = 2')->fetchColumn() === 2, 'Banking-Replace stellt Filter/Bedingungen nicht korrekt wieder her.');
    assert_true((int) $bankingPdo->query('SELECT COUNT(*) FROM banking_accounts WHERE user_id = 3')->fetchColumn() === 1, 'Banking-Replace löscht Daten anderer User.');

    $dashboardPdo = new PDO('sqlite::memory:');
    $dashboardPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dashboardPdo->exec('CREATE TABLE dashboard_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, widget_type TEXT NOT NULL, title TEXT NOT NULL, sort_order INTEGER NOT NULL, layout_width TEXT NOT NULL, is_active INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $dashboardPdo->exec('CREATE TABLE dashboard_link_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, widget_id INTEGER NOT NULL, name TEXT NOT NULL, sort_order INTEGER NOT NULL, is_default INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $dashboardPdo->exec('CREATE TABLE dashboard_links (id INTEGER PRIMARY KEY AUTOINCREMENT, widget_id INTEGER NOT NULL, folder_id INTEGER NULL, title TEXT NOT NULL, url TEXT NOT NULL, sort_order INTEGER NOT NULL, is_active INTEGER NOT NULL, favicon_url TEXT NULL, favicon_host TEXT NULL, favicon_last_checked_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $dashboardPdo->exec('CREATE TABLE dashboard_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, widget_id INTEGER NOT NULL, title TEXT NOT NULL, details TEXT NULL, link_url TEXT NULL, priority TEXT NOT NULL, due_at TEXT NULL, is_active INTEGER NOT NULL, is_done INTEGER NOT NULL, done_at TEXT NULL, repeat_type TEXT NULL, repeat_time TEXT NULL, repeat_weekday INTEGER NULL, repeat_month_mode TEXT NULL, repeat_month_day INTEGER NULL, repeat_month_ordinal INTEGER NULL, repeat_month_weekday INTEGER NULL, sort_order INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $dashboardPdo->exec('CREATE TABLE dashboard_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, widget_id INTEGER NOT NULL, title TEXT NULL, content TEXT NOT NULL, textarea_height INTEGER NULL, sort_order INTEGER NOT NULL, is_pinned INTEGER NOT NULL, is_archived INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $dashboardPdo->exec("INSERT INTO dashboard_widgets (user_id, widget_type, title, sort_order, layout_width, is_active, created_at, updated_at) VALUES (1, 'links', 'Quelle', 1, 'half', 1, '2026-05-01 00:00:00', '2026-05-01 00:00:00'), (2, 'links', 'Ziel alt', 1, 'half', 1, '2026-05-01 00:00:00', '2026-05-01 00:00:00'), (3, 'links', 'Anderer User', 1, 'half', 1, '2026-05-01 00:00:00', '2026-05-01 00:00:00')");
    $dashboardPdo->exec("INSERT INTO dashboard_links (widget_id, folder_id, title, url, sort_order, is_active, favicon_url, favicon_host, favicon_last_checked_at, created_at, updated_at) VALUES (1, NULL, 'Quelle Link', 'https://example.com', 1, 1, NULL, NULL, NULL, '2026-05-01 00:00:00', '2026-05-01 00:00:00'), (2, NULL, 'Alt Link', 'https://old.example', 1, 1, NULL, NULL, NULL, '2026-05-01 00:00:00', '2026-05-01 00:00:00'), (3, NULL, 'Fremd Link', 'https://other.example', 1, 1, NULL, NULL, NULL, '2026-05-01 00:00:00', '2026-05-01 00:00:00')");
    $dashboardService = new DataPortabilityService($base, '9.9.9', ['dashboard' => new DashboardDataPortabilityProvider($dashboardPdo)]);
    $dashboardExport = $dashboardService->createExport(['dashboard'], 1, 'user');
    $dashboardService->importArchive($dashboardExport['path'], 2, 'user', 'replace');
    assert_true((int) $dashboardPdo->query('SELECT COUNT(*) FROM dashboard_widgets WHERE user_id = 2')->fetchColumn() === 1, 'Dashboard-Replace stellt Zielwidgets nicht korrekt wieder her.');
    assert_true((int) $dashboardPdo->query('SELECT COUNT(*) FROM dashboard_links WHERE widget_id IN (SELECT id FROM dashboard_widgets WHERE user_id = 2)')->fetchColumn() === 1, 'Dashboard-Replace stellt Ziellinks nicht korrekt wieder her.');
    assert_true((int) $dashboardPdo->query('SELECT COUNT(*) FROM dashboard_widgets WHERE user_id = 3')->fetchColumn() === 1, 'Dashboard-Replace löscht Daten anderer User.');
}

$controllerReflection = new ReflectionClass(DataPortabilityController::class);
$oversizedMethod = $controllerReflection->getMethod('buildOversizedPostMessage');
$oversizedMethod->setAccessible(true);
assert_true(is_string($oversizedMethod->invoke(null, 2 * 1024 * 1024, '1M', '512K')), 'Überschreitung von post_max_size wird nicht erkannt.');
assert_true($oversizedMethod->invoke(null, 512 * 1024, '1M', '512K') === null, 'Normale Upload-Größe wird fälschlich als zu groß erkannt.');

$invalid = $base . '/invalid.zip';
$zip = new ZipArchive();
$zip->open($invalid, ZipArchive::CREATE);
$zip->addFromString('modules/smoke/data.json', '{}');
$zip->close();
try {
    $service->previewArchive($invalid, 123);
    assert_true(false, 'Archiv ohne Manifest wurde akzeptiert.');
} catch (RuntimeException) {
}

$slip = $base . '/slip.zip';
$zip = new ZipArchive();
$zip->open($slip, ZipArchive::CREATE);
$zip->addFromString('../evil.txt', 'bad');
$zip->close();
try {
    $service->previewArchive($slip, 123);
    assert_true(false, 'ZIP-Slip-Pfad wurde akzeptiert.');
} catch (RuntimeException) {
}

fwrite(STDOUT, "Data portability smoke test passed.\n");
