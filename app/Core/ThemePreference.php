<?php

declare(strict_types=1);

namespace Modulon\Core;

final class ThemePreference
{
    public const SYSTEM = 'system';
    public const LIGHT = 'light';
    public const DARK = 'dark';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::SYSTEM, self::LIGHT, self::DARK];
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::modes(), true);
    }

    public static function normalize(mixed $value): string
    {
        return self::isValid($value) ? $value : self::SYSTEM;
    }
}
