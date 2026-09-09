<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

final class ModulePresentation
{
    /** @var list<string> */
    private const CORE_PREFIXES = ['admin', 'auth', 'modules', 'profil', 'updates'];

    /** @param array<string,mixed> $module */
    public static function type(array $module): string
    {
        $origin = strtolower((string) ($module['origin'] ?? ''));
        if ($origin === 'core') {
            return 'core';
        }
        if (($module['module_key'] ?? null) !== null) {
            return $origin === 'legacy' ? 'legacy' : 'v2';
        }

        $handler = strtolower((string) ($module['handler'] ?? 'placeholder'));
        if ($handler === 'native') {
            $prefix = trim(strtolower((string) ($module['route_prefix'] ?? '')), '/');
            return in_array($prefix, self::CORE_PREFIXES, true) ? 'core' : 'v1';
        }

        return in_array($handler, ['legacy', 'placeholder'], true) ? $handler : 'local';
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'core' => 'Core',
            'v1' => 'Modul v1',
            'v2' => 'Modul v2',
            'legacy' => 'Legacy',
            'placeholder' => 'Placeholder',
            'manual' => 'Manuell',
            default => 'Lokal',
        };
    }

    public static function typeBadgeClass(string $type): string
    {
        return match ($type) {
            'core' => 'text-bg-dark',
            'v1' => 'text-bg-secondary',
            'v2' => 'text-bg-primary',
            'legacy' => 'text-bg-warning',
            'placeholder' => 'text-bg-info',
            'manual' => 'text-bg-secondary',
            default => 'text-bg-info',
        };
    }

    public static function originLabel(string $origin): ?string
    {
        return match (strtolower(trim($origin))) {
            'manual-v2' => 'Manuell',
            'local' => 'Lokal',
            default => null,
        };
    }

    public static function accessBadgeClass(string $access): string
    {
        return match (strtolower(trim($access))) {
            'public' => 'text-bg-success',
            'user' => 'text-bg-primary',
            'admin' => 'text-bg-dark',
            default => 'text-bg-secondary',
        };
    }
}
