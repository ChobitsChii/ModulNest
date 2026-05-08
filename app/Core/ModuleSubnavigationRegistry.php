<?php

declare(strict_types=1);

namespace Modulon\Core;

final class ModuleSubnavigationRegistry
{
    /**
     * @var array<string, ModuleSubnavigationProviderInterface>
     */
    private array $providers = [];

    public function register(ModuleSubnavigationProviderInterface $provider): void
    {
        $key = trim(strtolower($provider->moduleKey()));
        if ($key === '') {
            return;
        }

        $this->providers[$key] = $provider;
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description?:string}>
     */
    public function itemsFor(string $moduleKey, string $currentPath): array
    {
        $key = trim(strtolower($moduleKey));
        if ($key === '' || !isset($this->providers[$key])) {
            return [];
        }

        return $this->providers[$key]->items($currentPath);
    }
}
