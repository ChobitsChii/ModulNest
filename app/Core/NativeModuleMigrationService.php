<?php

declare(strict_types=1);

namespace Modulon\Core;

use Modulon\Core\Database\MigrationRunner;
use PDO;
use RuntimeException;

final class NativeModuleMigrationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $basePath,
    ) {
    }

    /**
     * @return array{executed:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
     */
    public function runForRoutePrefix(string $routePrefix): array
    {
        $directory = $this->moduleDirectoryForRoutePrefix($routePrefix);
        if ($directory === null) {
            return [
                'executed' => [],
                'skipped' => [],
                'errors' => [],
            ];
        }

        return (new MigrationRunner($this->pdo, $this->basePath))->run([$directory]);
    }

    private function moduleDirectoryForRoutePrefix(string $routePrefix): ?string
    {
        $routePrefix = trim($routePrefix, '/');
        if ($routePrefix === '') {
            throw new RuntimeException('Route Prefix für Modul-Migration ist leer.');
        }

        $class = NativeModuleLoader::discover($this->basePath)[$routePrefix] ?? null;
        if ($class === null) {
            throw new RuntimeException('Natives Modul für Route Prefix "' . $routePrefix . '" wurde nicht gefunden.');
        }

        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        if (!is_string($file) || $file === '') {
            throw new RuntimeException('Modulpfad für "' . $routePrefix . '" konnte nicht ermittelt werden.');
        }

        return basename(dirname($file));
    }
}
