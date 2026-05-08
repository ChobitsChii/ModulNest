<?php

declare(strict_types=1);

namespace Modulon\Modules\Modules;

use Modulon\Core\NativeModuleLoader;
use PDO;

final class ModuleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, description, route_prefix, access_level, handler, legacy_entry, admin_entry, enable_overlay, is_active, sort_order, show_in_header, show_on_home
             FROM modules
             ORDER BY sort_order ASC, id ASC'
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, description, route_prefix, access_level, handler, legacy_entry, admin_entry, enable_overlay, sort_order, show_in_header, show_on_home
             FROM modules
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function routePrefixExists(string $prefix, int $excludeId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM modules WHERE route_prefix = :prefix AND id <> :exclude_id LIMIT 1'
        );
        $statement->execute([
            'prefix' => $prefix,
            'exclude_id' => $excludeId,
        ]);

        return $statement->fetch() !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByPrefix(string $prefix): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, description, route_prefix, access_level, handler, legacy_entry, admin_entry, enable_overlay, is_active, sort_order, show_in_header, show_on_home
             FROM modules
             WHERE route_prefix = :prefix AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['prefix' => trim($prefix, '/')]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, description, route_prefix, access_level, handler, legacy_entry, admin_entry, enable_overlay, is_active, sort_order, show_in_header, show_on_home
             FROM modules
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function nameExists(string $name, int $excludeId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM modules WHERE name = :name AND id <> :exclude_id LIMIT 1'
        );
        $statement->execute([
            'name' => $name,
            'exclude_id' => $excludeId,
        ]);

        return $statement->fetch() !== false;
    }

    public function updateModule(int $id, string $routePrefix, string $accessLevel, bool $isActive): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE modules
             SET route_prefix = :route_prefix,
                 access_level = :access_level,
                 handler = :handler,
                 legacy_entry = :legacy_entry,
                 admin_entry = :admin_entry,
                 enable_overlay = :enable_overlay,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'route_prefix' => $routePrefix,
            'access_level' => $accessLevel,
            'handler' => 'native',
            'legacy_entry' => null,
            'admin_entry' => null,
            'enable_overlay' => 0,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    public function ensureBuiltinNativeModules(): void
    {
        $this->pdo->exec(
            'ALTER TABLE modules ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0'
        );
        $this->pdo->exec(
            'ALTER TABLE modules ADD COLUMN IF NOT EXISTS show_in_header TINYINT(1) NOT NULL DEFAULT 1'
        );
        $this->pdo->exec(
            'ALTER TABLE modules ADD COLUMN IF NOT EXISTS show_on_home TINYINT(1) NOT NULL DEFAULT 1'
        );
        $this->pdo->exec(
            "ALTER TABLE modules MODIFY COLUMN handler ENUM('native', 'placeholder', 'legacy') NOT NULL DEFAULT 'native'"
        );

        $this->initializeSortOrderIfMissing();
    }

    public function discoverNativeModules(string $basePath): int
    {
        $this->ensureBuiltinNativeModules();

        $created = 0;
        $insert = $this->pdo->prepare(
            'INSERT INTO modules
                (name, description, route_prefix, access_level, handler, legacy_entry, admin_entry, enable_overlay, is_active, sort_order, show_in_header, show_on_home)
             VALUES
                (:name, :description, :route_prefix, :access_level, :handler, NULL, NULL, 0, 0, :sort_order, :show_in_header, :show_on_home)'
        );

        foreach (NativeModuleLoader::discover($basePath) as $prefix => $class) {
            if ($this->routePrefixExists($prefix, 0)) {
                continue;
            }

            $metadata = $class::metadata();
            $insert->execute([
                'name' => (string) ($metadata['name'] ?? ucfirst($prefix)),
                'description' => $this->normalizeDiscoveredDescription((string) ($metadata['description'] ?? '')),
                'route_prefix' => $prefix,
                'access_level' => $this->normalizeDiscoveredAccess((string) ($metadata['access_level'] ?? 'admin')),
                'handler' => 'native',
                'sort_order' => $this->nextSortOrder(),
                'show_in_header' => !empty($metadata['show_in_header']) ? 1 : 0,
                'show_on_home' => !empty($metadata['show_on_home']) ? 1 : 0,
            ]);
            $created++;
        }

        return $created;
    }

    public function createModule(
        string $name,
        ?string $description,
        string $routePrefix,
        string $accessLevel,
        string $handler,
        ?string $legacyEntry,
        ?string $adminEntry,
        bool $enableOverlay,
        bool $isActive,
        bool $showInHeader,
        bool $showOnHome,
    ): void {
        $sortOrder = $this->nextSortOrder();
        $statement = $this->pdo->prepare(
            'INSERT INTO modules (name, description, route_prefix, access_level, handler, legacy_entry, admin_entry, enable_overlay, is_active, sort_order, show_in_header, show_on_home)
             VALUES (:name, :description, :route_prefix, :access_level, :handler, :legacy_entry, :admin_entry, :enable_overlay, :is_active, :sort_order, :show_in_header, :show_on_home)'
        );
        $statement->execute([
            'name' => $name,
            'description' => $description,
            'route_prefix' => $routePrefix,
            'access_level' => $accessLevel,
            'handler' => $handler,
            'legacy_entry' => $legacyEntry,
            'admin_entry' => $adminEntry,
            'enable_overlay' => $enableOverlay ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
            'sort_order' => $sortOrder,
            'show_in_header' => $showInHeader ? 1 : 0,
            'show_on_home' => $showOnHome ? 1 : 0,
        ]);
    }

    public function updateModuleAdvanced(
        int $id,
        string $name,
        ?string $description,
        string $routePrefix,
        string $accessLevel,
        string $handler,
        ?string $legacyEntry,
        ?string $adminEntry,
        bool $enableOverlay,
        bool $isActive,
        bool $showInHeader,
        bool $showOnHome,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE modules
             SET name = :name,
                 description = :description,
                 route_prefix = :route_prefix,
                 access_level = :access_level,
                 handler = :handler,
                 legacy_entry = :legacy_entry,
                 admin_entry = :admin_entry,
                 enable_overlay = :enable_overlay,
                 is_active = :is_active,
                 show_in_header = :show_in_header,
                 show_on_home = :show_on_home,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'route_prefix' => $routePrefix,
            'access_level' => $accessLevel,
            'handler' => $handler,
            'legacy_entry' => $legacyEntry,
            'admin_entry' => $adminEntry,
            'enable_overlay' => $enableOverlay ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
            'show_in_header' => $showInHeader ? 1 : 0,
            'show_on_home' => $showOnHome ? 1 : 0,
        ]);
    }

    public function updateFlags(int $id, bool $enableOverlay, bool $isActive): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE modules
             SET enable_overlay = :enable_overlay,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'enable_overlay' => $enableOverlay ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    public function deleteModule(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM modules WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * @param array<int, int> $moduleIds
     */
    public function reorderModules(array $moduleIds): void
    {
        $order = 10;
        $uniqueIds = [];
        foreach ($moduleIds as $moduleId) {
            if ($moduleId > 0 && !in_array($moduleId, $uniqueIds, true)) {
                $uniqueIds[] = $moduleId;
            }
        }

        if ($uniqueIds === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE modules
             SET sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($uniqueIds as $moduleId) {
                $statement->execute([
                    'id' => $moduleId,
                    'sort_order' => $order,
                ]);
                $order += 10;
            }
            $this->pdo->commit();
        } catch (\Throwable) {
            $this->pdo->rollBack();
            throw new \RuntimeException('Module sort order update failed.');
        }
    }

    private function nextSortOrder(): int
    {
        $maxSortOrder = $this->pdo
            ->query('SELECT MAX(sort_order) FROM modules')
            ->fetchColumn();

        $maxValue = is_numeric($maxSortOrder) ? (int) $maxSortOrder : 0;
        return $maxValue + 10;
    }

    private function initializeSortOrderIfMissing(): void
    {
        $hasAnySortedModule = $this->pdo
            ->query('SELECT COUNT(*) FROM modules WHERE sort_order <> 0')
            ->fetchColumn();

        if ((int) $hasAnySortedModule > 0) {
            return;
        }

        $ids = $this->pdo
            ->query('SELECT id FROM modules ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($ids) || $ids === []) {
            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE modules
             SET sort_order = :sort_order
             WHERE id = :id'
        );

        $order = 10;
        foreach ($ids as $id) {
            $update->execute([
                'id' => (int) $id,
                'sort_order' => $order,
            ]);
            $order += 10;
        }
    }

    private function ensureModuleHasSortOrder(string $routePrefix): void
    {
        $statement = $this->pdo->prepare('SELECT id, sort_order FROM modules WHERE route_prefix = :route_prefix LIMIT 1');
        $statement->execute(['route_prefix' => $routePrefix]);
        $module = $statement->fetch();
        if (!is_array($module) || (int) ($module['sort_order'] ?? 0) !== 0) {
            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE modules
             SET sort_order = :sort_order
             WHERE id = :id'
        );
        $update->execute([
            'id' => (int) ($module['id'] ?? 0),
            'sort_order' => $this->nextSortOrder(),
        ]);
    }

    private function normalizeDiscoveredAccess(string $access): string
    {
        $access = strtolower(trim($access));
        return in_array($access, ['public', 'user', 'admin'], true) ? $access : 'admin';
    }

    private function normalizeDiscoveredDescription(string $description): ?string
    {
        $description = trim($description);
        return $description !== '' ? mb_substr($description, 0, 255) : null;
    }
}
