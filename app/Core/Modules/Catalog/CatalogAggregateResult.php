<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

final readonly class CatalogAggregateResult
{
    /**
     * @param array<string,array<string,mixed>> $records
     * @param array<string,CatalogSourceInterface> $sources
     * @param array<string,CatalogLoader> $loaders
     * @param array<string,CatalogSnapshot> $snapshots
     * @param array<string,string> $warnings
     */
    public function __construct(
        public array $records,
        public array $sources,
        public array $loaders,
        public array $snapshots,
        public array $warnings = [],
    ) {}
}
