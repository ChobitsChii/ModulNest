<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use RuntimeException;

final class CatalogSourceFactory
{
    public function source(array $record): CatalogSourceInterface
    {
        return match ((string) ($record['source_type'] ?? '')) {
            'https' => new HttpCatalogSource((string) $record['id'], (string) $record['location']),
            'local' => new LocalCatalogSource((string) $record['id'], (string) $record['location']),
            default => throw new RuntimeException('Nicht unterstützter persistierter Katalogquellentyp.'),
        };
    }

    public function trust(array $record): CatalogTrustStore
    {
        $keys = $record['trusted_keys'] ?? null;
        $roots = $record['root_key_ids'] ?? null;
        if (!is_array($keys) || array_is_list($keys) || !is_array($roots) || !array_is_list($roots)) {
            throw new RuntimeException('Katalogquelle besitzt keine gültige Trust-Bindung.');
        }
        return new CatalogTrustStore($keys, array_values(array_map('strval', $roots)));
    }
}
