<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

final class ModuleManagementColumns
{
    /** @var array<string,string> */
    public const AVAILABLE = [
        'sort' => 'Sortierung',
        'type' => 'Typ',
        'version' => 'Version',
        'route' => 'Route Prefix',
        'access' => 'Zugriff',
        'links' => 'Links',
        'header' => 'Header',
        'home' => 'Startseite',
        'active' => 'Aktiv',
        'actions' => 'Aktionen',
    ];

    /** @var list<string> */
    public const DEFAULTS = ['sort', 'type', 'version', 'access', 'links', 'header', 'home', 'active', 'actions'];

    /** @return list<string> */
    public static function normalize(mixed $columns): array
    {
        if (!is_array($columns)) {
            return self::DEFAULTS;
        }
        $normalized = [];
        foreach ($columns as $column) {
            if (is_string($column) && isset(self::AVAILABLE[$column]) && !in_array($column, $normalized, true)) {
                $normalized[] = $column;
            }
        }
        return $normalized;
    }
}
