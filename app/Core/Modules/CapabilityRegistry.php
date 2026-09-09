<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use InvalidArgumentException;

final class CapabilityRegistry
{
    /** @var array<string,array<string,string>> */
    private array $providers = [];
    /** @var array<string,array<string,object>> */
    private array $instances = [];

    public function register(string $moduleId, string $capability, string $providerClass): void
    {
        ModuleId::assert($moduleId);
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $capability) !== 1) {
            throw new InvalidArgumentException('Capability-Key ist ungültig.');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $providerClass) !== 1) {
            throw new InvalidArgumentException('Capability-Providerklasse ist ungültig.');
        }
        if (isset($this->providers[$capability][$moduleId])) {
            throw new InvalidArgumentException('Capability ist für dieses Modul bereits registriert.');
        }
        $this->providers[$capability][$moduleId] = $providerClass;
        ksort($this->providers[$capability], SORT_STRING);
    }

    /** @return array<string,string> module ID => provider class */
    public function providers(string $capability): array
    {
        return $this->providers[$capability] ?? [];
    }

    public function registerInstance(string $moduleId, string $capability, object $provider): void
    {
        ModuleId::assert($moduleId);
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $capability) !== 1) {
            throw new InvalidArgumentException('Capability-Key ist ungültig.');
        }
        $declared = $this->providers[$capability][$moduleId] ?? null;
        if (is_string($declared) && !$provider instanceof $declared) {
            throw new InvalidArgumentException('Capability-Instanz entspricht nicht der Paketdeklaration.');
        }
        $existing = $this->instances[$capability][$moduleId] ?? null;
        if (is_object($existing) && $existing::class === $provider::class) {
            return;
        }
        if (is_object($existing)) {
            throw new InvalidArgumentException('Capability-Instanz ist für dieses Modul bereits registriert.');
        }
        $this->instances[$capability][$moduleId] = $provider;
        ksort($this->instances[$capability], SORT_STRING);
    }

    /** @return array<string,object> module ID => provider instance */
    public function instances(string $capability): array
    {
        return $this->instances[$capability] ?? [];
    }
}
