<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use DateTimeImmutable;
use Throwable;

/** Loads each enabled source independently; every source keeps its own LKG. */
final class CatalogAggregateLoader
{
    /** @param null|callable():DateTimeImmutable $clock */
    public function __construct(
        private readonly CatalogSourceRegistry $registry,
        private readonly CatalogCache $cache,
        private readonly CatalogSourceFactory $factory = new CatalogSourceFactory(),
        private readonly mixed $clock = null,
    ) {}

    public function refreshAll(): CatalogAggregateResult
    {
        $records = $sources = $loaders = $snapshots = $warnings = [];
        foreach ($this->registry->enabled() as $record) {
            $id = (string) $record['id'];
            $records[$id] = $record;
            try {
                $source = $this->factory->source($record);
                $loader = new CatalogLoader($this->factory->trust($record), $this->cache, $this->clock);
                $snapshot = $loader->refreshOrLastKnownGood($source);
                $sources[$id] = $source;
                $loaders[$id] = $loader;
                $snapshots[$id] = $snapshot;
                if ($snapshot->warning === null) {
                    $this->registry->recordRefreshSuccess($id);
                } else {
                    $warnings[$id] = $snapshot->warning;
                    $this->registry->recordRefreshFailure($id, 'catalog_refresh_lkg', $snapshot->warning);
                }
            } catch (Throwable $error) {
                $warnings[$id] = 'Katalogquelle ist nicht verfügbar: ' . $error->getMessage();
                $this->registry->recordRefreshFailure($id, 'catalog_refresh_failed', $error->getMessage());
            }
        }
        return new CatalogAggregateResult($records, $sources, $loaders, $snapshots, $warnings);
    }
}
