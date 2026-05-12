<?php

declare(strict_types=1);

use Modulon\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Modules\DataPortability\DataPortabilityProviderInterface;
use Modulon\Modules\DataPortability\DataPortabilityService;
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

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
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

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
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
}

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
