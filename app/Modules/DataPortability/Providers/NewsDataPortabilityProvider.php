<?php

declare(strict_types=1);

namespace Modulon\Modules\DataPortability\Providers;

use Modulon\Modules\DataPortability\DataPortabilityArchiveReader;
use Modulon\Modules\DataPortability\DataPortabilityFileCollector;
use Modulon\Modules\DataPortability\DataPortabilityProviderInterface;
use PDO;
use Throwable;

final class NewsDataPortabilityProvider implements DataPortabilityProviderInterface
{
    /**
     * @var array<int,string>
     */
    private const ALLOWED_TYPES = ['news', 'update', 'release', 'note'];
    /**
     * @var array<int,string>
     */
    private const ALLOWED_STATUSES = ['draft', 'published'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function key(): string
    {
        return 'news';
    }

    public function label(): string
    {
        return 'News';
    }

    public function routePrefix(): string
    {
        return '/news';
    }

    public function description(): string
    {
        return 'News- und Changelog-Einträge dieser Instanz.';
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
        return 'News-Export enthält veröffentlichte Inhaltsdaten und Markdown-Quelltext.';
    }

    public function scopes(): array
    {
        return ['admin'];
    }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        $entries = $this->fetchAll(
            'SELECT title, slug, excerpt, content, type, version, status, published_at, created_at, updated_at
             FROM news_entries
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC'
        );

        return [
            'files' => [
                'entries.json' => [
                    'schema_version' => $this->schemaVersion(),
                    'entries' => $entries,
                ],
            ],
            'counts' => [
                'entries' => count($entries),
            ],
            'warnings' => [$this->sensitivityNote()],
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $entries = $this->entriesFromPayload($payload);
        $created = 0;
        $updated = 0;
        $invalid = 0;
        $warnings = [$this->sensitivityNote()];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                $invalid++;
                continue;
            }
            $slug = $this->normalizeSlug($entry['slug'] ?? '');
            if ($slug === null) {
                $invalid++;
                continue;
            }
            if ($this->findBySlug($slug) !== null) {
                $updated++;
            } else {
                $created++;
            }
        }

        if ($invalid > 0) {
            $warnings[] = $invalid . ' Eintrag/Einträge haben einen ungültigen oder leeren Slug und werden übersprungen.';
        }

        return [
            'counts' => [
                'entries' => count($entries),
                'new' => $created,
                'update' => $updated,
                'invalid' => $invalid,
            ],
            'warnings' => $warnings,
            'can_import' => $created + $updated > 0,
        ];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($this->entriesFromPayload($payload) as $entry) {
                if (!is_array($entry)) {
                    $skipped++;
                    continue;
                }

                $data = $this->normalizeEntry($entry, $targetUserId);
                if ($data === null) {
                    $skipped++;
                    continue;
                }

                $existing = $this->findBySlug((string) $data['slug']);
                if ($existing !== null) {
                    $this->update((int) $existing['id'], $data);
                    $updated++;
                } else {
                    $this->insert($data);
                    $created++;
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if ($skipped > 0) {
            $warnings[] = $skipped . ' Eintrag/Einträge wurden wegen ungültiger Daten übersprungen.';
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,mixed>
     */
    private function entriesFromPayload(array $payload): array
    {
        $entries = $payload['entries']['entries'] ?? [];

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>|null
     */
    private function normalizeEntry(array $entry, int $adminUserId): ?array
    {
        $slug = $this->normalizeSlug($entry['slug'] ?? '');
        $title = trim((string) ($entry['title'] ?? ''));
        $excerpt = trim((string) ($entry['excerpt'] ?? ''));
        $content = trim((string) ($entry['content'] ?? ''));
        if ($slug === null || $title === '' || $excerpt === '' || $content === '') {
            return null;
        }

        $type = strtolower(trim((string) ($entry['type'] ?? 'news')));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $type = 'news';
        }

        $status = strtolower(trim((string) ($entry['status'] ?? 'draft')));
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'draft';
        }

        return [
            'title' => mb_substr($title, 0, 180),
            'slug' => $slug,
            'excerpt' => mb_substr($excerpt, 0, 400),
            'content' => $content,
            'type' => $type,
            'version' => $this->nullableString($entry['version'] ?? null, 30),
            'status' => $status,
            'published_at' => $this->nullableDateTime($entry['published_at'] ?? null),
            'created_at' => $this->nullableDateTime($entry['created_at'] ?? null),
            'updated_at' => $this->nullableDateTime($entry['updated_at'] ?? null),
            'created_by' => $adminUserId > 0 ? $adminUserId : null,
            'updated_by' => $adminUserId > 0 ? $adminUserId : null,
        ];
    }

    private function normalizeSlug(mixed $value): ?string
    {
        $slug = strtolower(trim((string) $value));
        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,179}$/', $slug) !== 1) {
            return null;
        }

        return $slug;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT id FROM news_entries WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insert(array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO news_entries
                (title, slug, excerpt, content, type, version, status, published_at, created_by, updated_by, created_at, updated_at)
             VALUES
                (:title, :slug, :excerpt, :content, :type, :version, :status, :published_at, :created_by, :updated_by, COALESCE(:created_at, CURRENT_TIMESTAMP), COALESCE(:updated_at, CURRENT_TIMESTAMP))'
        );
        $statement->execute($this->insertParams($data));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function update(int $id, array $data): void
    {
        $params = $this->updateParams($data);
        $params['id'] = $id;
        $statement = $this->pdo->prepare(
            'UPDATE news_entries
             SET title = :title,
                 slug = :slug,
                 excerpt = :excerpt,
                 content = :content,
                 type = :type,
                 version = :version,
                 status = :status,
                 published_at = :published_at,
                 updated_by = :updated_by,
                 updated_at = COALESCE(:updated_at, CURRENT_TIMESTAMP)
             WHERE id = :id'
        );
        $statement->execute($params);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function insertParams(array $data): array
    {
        return [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'excerpt' => $data['excerpt'],
            'content' => $data['content'],
            'type' => $data['type'],
            'version' => $data['version'],
            'status' => $data['status'],
            'published_at' => $data['published_at'],
            'created_by' => $data['created_by'],
            'updated_by' => $data['updated_by'],
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at'],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function updateParams(array $data): array
    {
        return [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'excerpt' => $data['excerpt'],
            'content' => $data['content'],
            'type' => $data['type'],
            'version' => $data['version'],
            'status' => $data['status'],
            'published_at' => $data['published_at'],
            'updated_by' => $data['updated_by'],
            'updated_at' => $data['updated_at'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $rows = $statement !== false ? $statement->fetchAll() : [];

        return is_array($rows) ? $rows : [];
    }
}
