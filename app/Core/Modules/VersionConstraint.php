<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use InvalidArgumentException;

final class VersionConstraint
{
    /** @var list<list<array{operator:string,version:SemVer}>> */
    private array $alternatives;

    private function __construct(array $alternatives)
    {
        $this->alternatives = $alternatives;
    }

    public static function parse(string $constraint): self
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            throw new InvalidArgumentException('Leere Versionsbedingung.');
        }
        $alternatives = [];
        foreach (preg_split('/\s*\|\|\s*/', $constraint) ?: [] as $alternative) {
            if ($alternative === '') {
                throw new InvalidArgumentException('Ungültige leere OR-Bedingung.');
            }
            $comparators = [];
            foreach (preg_split('/\s+/', trim($alternative)) ?: [] as $token) {
                if (preg_match('/^(>=|<=|>|<|=)?(.+)$/D', $token, $matches) !== 1) {
                    throw new InvalidArgumentException('Ungültiger Versionsvergleich.');
                }
                $comparators[] = [
                    'operator' => $matches[1] !== '' ? $matches[1] : '=',
                    'version' => SemVer::parse($matches[2]),
                ];
            }
            if ($comparators === []) {
                throw new InvalidArgumentException('Versionsbedingung ohne Vergleich.');
            }
            $alternatives[] = $comparators;
        }

        return new self($alternatives);
    }

    public function matches(string|SemVer $version): bool
    {
        $candidate = is_string($version) ? SemVer::parse($version) : $version;
        foreach ($this->alternatives as $comparators) {
            $allowsPreRelease = false;
            foreach ($comparators as $comparator) {
                if ($comparator['version']->isPreRelease()) {
                    $allowsPreRelease = true;
                    break;
                }
            }
            if ($candidate->isPreRelease() && !$allowsPreRelease) {
                continue;
            }
            $matches = true;
            foreach ($comparators as $comparator) {
                $comparison = $candidate->compare($comparator['version']);
                $matches = $matches && match ($comparator['operator']) {
                    '>' => $comparison > 0,
                    '>=' => $comparison >= 0,
                    '<' => $comparison < 0,
                    '<=' => $comparison <= 0,
                    '=' => $comparison === 0,
                    default => false,
                };
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
