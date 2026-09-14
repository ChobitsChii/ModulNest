<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use PDO;
use RuntimeException;

/** Deterministic module ownership resolver. Versions never decide between sources. */
final class CatalogSourceResolver
{
    /** @var array<string,list<string>> */
    private array $conflicts = [];

    public function __construct(private readonly PDO $pdo, private readonly CatalogAggregateResult $aggregate) {}

    public function snapshot(): CatalogSnapshot
    {
        $bindings = $this->installedBindings();
        $candidates = [];
        foreach ($this->aggregate->snapshots as $sourceId => $snapshot) {
            foreach ($snapshot->modules as $moduleId => $module) {
                $candidates[$moduleId][$sourceId] = $module;
            }
        }

        $resolved = [];
        $this->conflicts = [];
        foreach ($candidates as $moduleId => $bySource) {
            $sourceId = $this->selectSource((string) $moduleId, array_keys($bySource), $bindings[$moduleId] ?? null);
            if ($sourceId === null) continue;
            $resolved[$moduleId] = $bySource[$sourceId] + ['_catalog_source_id' => $sourceId];
        }
        ksort($resolved, SORT_STRING);
        $sequence = 0;
        foreach ($this->aggregate->snapshots as $snapshot) $sequence = max($sequence, (int) ($snapshot->root['sequence'] ?? 0));
        return new CatalogSnapshot('catalog.aggregate', ['sequence'=>$sequence, 'sources'=>array_keys($this->aggregate->snapshots)], $resolved);
    }

    /** @return array<string,list<string>> */
    public function conflicts(): array { return $this->conflicts; }

    /** @return array{record:array<string,mixed>,source:CatalogSourceInterface,loader:CatalogLoader,snapshot:CatalogSnapshot,module:array<string,mixed>} */
    public function context(string $moduleId, ?string $sourceId = null): array
    {
        if ($sourceId === null) {
            $module = $this->snapshot()->modules[$moduleId] ?? null;
            $sourceId = is_array($module) ? (string) ($module['_catalog_source_id'] ?? '') : '';
        } else {
            $module = $this->aggregate->snapshots[$sourceId]->modules[$moduleId] ?? null;
        }
        if ($sourceId === '' || !is_array($module)
            || !isset($this->aggregate->records[$sourceId], $this->aggregate->sources[$sourceId], $this->aggregate->loaders[$sourceId], $this->aggregate->snapshots[$sourceId])
        ) throw new RuntimeException('Modul ist in der gewählten vertrauenswürdigen Katalogquelle nicht verfügbar.');
        return [
            'record'=>$this->aggregate->records[$sourceId], 'source'=>$this->aggregate->sources[$sourceId],
            'loader'=>$this->aggregate->loaders[$sourceId], 'snapshot'=>$this->aggregate->snapshots[$sourceId], 'module'=>$module,
        ];
    }

    /** @param list<string> $sourceIds */
    private function selectSource(string $moduleId, array $sourceIds, ?string $installedSource): ?string
    {
        if ($installedSource !== null) return in_array($installedSource, $sourceIds, true) ? $installedSource : null;
        $highest = max(array_map(fn (string $id): int => (int) $this->aggregate->records[$id]['priority'], $sourceIds));
        $top = array_values(array_filter($sourceIds, fn (string $id): bool => (int) $this->aggregate->records[$id]['priority'] === $highest));
        if (count($top) === 1) return $top[0];
        $official = array_values(array_filter($top, fn (string $id): bool => (bool) $this->aggregate->records[$id]['is_official']));
        if (count($official) === 1) return $official[0];
        sort($top, SORT_STRING);
        $this->conflicts[$moduleId] = $top;
        return null;
    }

    /** @return array<string,string> */
    private function installedBindings(): array
    {
        $statement = $this->pdo->query(
            "SELECT module_id,catalog_source_id FROM module_installations
             WHERE origin='catalog-managed' AND catalog_source_id IS NOT NULL"
        );
        $bindings = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $bindings[(string) $row['module_id']] = (string) $row['catalog_source_id'];
        return $bindings;
    }
}
