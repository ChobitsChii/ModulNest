<?php

declare(strict_types=1);

namespace Modulon\Core;

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
        private readonly array $services = [],
        private readonly array $config = [],
    ) {
    }

    public function service(string $name): mixed
    {
        return $this->services[$name] ?? null;
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
}
