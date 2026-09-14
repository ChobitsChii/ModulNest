<?php

declare(strict_types=1);

namespace Modulon\Core;

use Modulon\Core\Modules\Catalog\CatalogSourceRegistry;
use PDO;

final class ModuleContext
{
    /**
     * @param array<string, mixed> $services
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $basePath,
        public readonly ?PDO $pdo,
        public readonly Session $session,
        private array $services = [],
        private readonly array $config = [],
    ) {
    }

    public function service(string $name): mixed
    {
        return $this->services[$name] ?? null;
    }

    /** Stable public Core API for repository-management modules. */
    public function catalogSources(): ?CatalogSourceRegistry
    {
        $registry = $this->service('catalogSourceRegistry');
        return $registry instanceof CatalogSourceRegistry ? $registry : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function moduleRow(string $routePrefix): ?array
    {
        $repository = $this->service('moduleRepository');
        if (!is_object($repository) || !method_exists($repository, 'findActiveByPrefix')) {
            return null;
        }

        $row = $repository->findActiveByPrefix($routePrefix);
        return is_array($row) ? $row : null;
    }

    public function moduleAccess(string $routePrefix, string $default): string
    {
        $row = $this->moduleRow($routePrefix);
        $access = strtolower((string) ($row['access_level'] ?? $default));

        return in_array($access, ['public', 'user', 'admin'], true) ? $access : $default;
    }

    public function isNativeActive(string $routePrefix): bool
    {
        $row = $this->moduleRow($routePrefix);

        return is_array($row)
            && strtolower((string) ($row['handler'] ?? 'native')) === 'native';
    }

    public function config(string $name, mixed $default = null): mixed
    {
        return $this->config[$name] ?? $default;
    }

    public function registerService(string $name, mixed $service): void
    {
        $this->services[$name] = $service;
    }

    public function catalog(): ?\Modulon\Core\Modules\Catalog\CatalogService
    {
        $service = $this->service('catalogService');
        return $service instanceof \Modulon\Core\Modules\Catalog\CatalogService ? $service : null;
    }
}
