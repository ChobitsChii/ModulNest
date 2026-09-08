<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

final class UpdateChannel
{
    public const STABLE = 'stable';
    public const PREVIEW = 'preview';
    public const SETTING_KEY = 'update_channel';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::STABLE, self::PREVIEW];
    }

    public static function normalize(mixed $value): string
    {
        return is_string($value) && in_array(strtolower(trim($value)), self::values(), true)
            ? strtolower(trim($value))
            : self::STABLE;
    }
}
