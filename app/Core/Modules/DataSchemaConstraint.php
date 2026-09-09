<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use InvalidArgumentException;

final class DataSchemaConstraint
{
    /** @var list<array{operator:string,version:int}> */
    private array $comparators;

    private function __construct(array $comparators)
    {
        $this->comparators = $comparators;
    }

    public static function parse(string $constraint): self
    {
        $comparators = [];
        foreach (preg_split('/\s+/', trim($constraint)) ?: [] as $token) {
            if ($token === '' || preg_match('/^(>=|<=|>|<|=)?(0|[1-9][0-9]*)$/D', $token, $matches) !== 1) {
                throw new InvalidArgumentException('Ungültige Data-Schema-Bedingung.');
            }
            $comparators[] = ['operator' => $matches[1] !== '' ? $matches[1] : '=', 'version' => (int) $matches[2]];
        }
        if ($comparators === []) {
            throw new InvalidArgumentException('Leere Data-Schema-Bedingung.');
        }
        return new self($comparators);
    }

    public function matches(int $version): bool
    {
        foreach ($this->comparators as $item) {
            $comparison = $version <=> $item['version'];
            if (!match ($item['operator']) {
                '>' => $comparison > 0, '>=' => $comparison >= 0,
                '<' => $comparison < 0, '<=' => $comparison <= 0,
                '=' => $comparison === 0, default => false,
            }) {
                return false;
            }
        }
        return true;
    }
}
