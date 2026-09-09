<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use InvalidArgumentException;

final class SemVer
{
    /** @param list<string> $preRelease */
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly array $preRelease,
        public readonly string $value,
    ) {
    }

    public static function parse(string $version): self
    {
        $pattern = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
            . '(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
        if (preg_match($pattern, $version, $matches) !== 1) {
            throw new InvalidArgumentException('Ungültige SemVer-Version: ' . $version);
        }

        return new self(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            isset($matches[4]) && $matches[4] !== '' ? explode('.', $matches[4]) : [],
            $version,
        );
    }

    public function compare(self $other): int
    {
        foreach (['major', 'minor', 'patch'] as $part) {
            $comparison = $this->{$part} <=> $other->{$part};
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        if ($this->preRelease === [] || $other->preRelease === []) {
            return $this->preRelease === $other->preRelease ? 0 : ($this->preRelease === [] ? 1 : -1);
        }
        $length = max(count($this->preRelease), count($other->preRelease));
        for ($index = 0; $index < $length; $index++) {
            if (!isset($this->preRelease[$index])) {
                return -1;
            }
            if (!isset($other->preRelease[$index])) {
                return 1;
            }
            $left = $this->preRelease[$index];
            $right = $other->preRelease[$index];
            if ($left === $right) {
                continue;
            }
            $leftNumeric = ctype_digit($left);
            $rightNumeric = ctype_digit($right);
            if ($leftNumeric && $rightNumeric) {
                return (int) $left <=> (int) $right;
            }
            if ($leftNumeric !== $rightNumeric) {
                return $leftNumeric ? -1 : 1;
            }
            return strcmp($left, $right) <=> 0;
        }

        return 0;
    }

    public function isPreRelease(): bool
    {
        return $this->preRelease !== [];
    }
}
