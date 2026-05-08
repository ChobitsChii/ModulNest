<?php

declare(strict_types=1);

namespace Modulon\Core;

final class UserNavigationRegistry
{
    /**
     * @var array<string, UserNavigationProviderInterface>
     */
    private array $providers = [];

    public function registerProvider(UserNavigationProviderInterface $provider): void
    {
        $key = trim(strtolower($provider->moduleKey()));
        if ($key === '') {
            return;
        }

        $this->providers[$key] = $provider;
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool}>
     */
    public function items(string $currentPath): array
    {
        $items = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->items($currentPath) as $item) {
                $label = trim((string) ($item['label'] ?? ''));
                $url = trim((string) ($item['url'] ?? ''));
                if ($label === '' || $url === '') {
                    continue;
                }

                $items[] = [
                    'key' => (string) ($item['key'] ?? ''),
                    'label' => $label,
                    'url' => $url,
                    'is_active' => (bool) ($item['is_active'] ?? false),
                    '_sort_order' => (int) ($item['sort_order'] ?? 1000),
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            $order = ((int) ($a['_sort_order'] ?? 0)) <=> ((int) ($b['_sort_order'] ?? 0));
            if ($order !== 0) {
                return $order;
            }

            return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return array_map(static function (array $item): array {
            unset($item['_sort_order']);
            return $item;
        }, $items);
    }

    public function hasItem(string $moduleKey, string $itemKey): bool
    {
        $moduleKey = trim(strtolower($moduleKey));
        $itemKey = trim(strtolower($itemKey));
        if ($moduleKey === '' || $itemKey === '') {
            return false;
        }

        $provider = $this->providers[$moduleKey] ?? null;
        if ($provider === null) {
            return false;
        }

        foreach ($provider->items('/') as $item) {
            if (trim(strtolower((string) ($item['key'] ?? ''))) === $itemKey) {
                return true;
            }
        }

        return false;
    }
}
