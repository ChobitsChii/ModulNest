<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use InvalidArgumentException;

final class ModuleId
{
    private const SEGMENT = '[a-z][a-z0-9]*(?:-[a-z0-9]+)*';

    public static function isValid(string $id): bool
    {
        return strlen($id) <= 127
            && preg_match('/^(?:' . self::SEGMENT . ')\.(?:' . self::SEGMENT . ')$/D', $id) === 1
            && max(array_map('strlen', explode('.', $id))) <= 63;
    }

    public static function assert(string $id): string
    {
        if (!self::isValid($id)) {
            throw new InvalidArgumentException('Ungültige namespaced Modul-ID.');
        }

        return $id;
    }

    public static function moduleSegment(string $id): string
    {
        self::assert($id);
        return explode('.', $id, 2)[1];
    }
}
