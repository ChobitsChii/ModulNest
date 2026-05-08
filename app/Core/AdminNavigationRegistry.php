<?php

declare(strict_types=1);

namespace Modulon\Core;

final class AdminNavigationRegistry
{
    /**
     * @var array<int, array{key:string,label:string,url:string,description?:string,sort_order:int}>
     */
    private array $coreItems = [];

    /**
     * @var array<string, AdminNavigationProviderInterface>
     */
    private array $providers = [];

    public function registerCoreItem(string $key, string $label, string $url, int $sortOrder, string $description = ''): void
    {
        $key = trim(strtolower($key));
        $url = '/' . trim($url, '/');
        if ($key === '' || $label === '' || $url === '/') {
            return;
        }

        $this->coreItems[$key] = [
            'key' => $key,
            'label' => $label,
            'url' => $url,
            'description' => $description,
            'sort_order' => $sortOrder,
        ];
    }

    public function registerProvider(AdminNavigationProviderInterface $provider): void
    {
        $key = trim(strtolower($provider->moduleKey()));
        if ($key === '') {
            return;
        }

        $this->providers[$key] = $provider;
    }

    public function adminUrlForModule(string $moduleKey): ?string
    {
        $moduleKey = trim(strtolower($moduleKey));
        if ($moduleKey === '') {
            return null;
        }

        $provider = $this->providers[$moduleKey] ?? null;
        if ($provider === null) {
            return null;
        }

        foreach ($provider->items('/') as $item) {
            $itemKey = trim(strtolower((string) ($item['key'] ?? '')));
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            if ($itemKey === $moduleKey || $itemKey === '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description?:string}>
     */
    public function items(string $currentPath): array
    {
        $items = [];
        foreach ($this->coreItems as $item) {
            $items[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'url' => $item['url'],
                'description' => $item['description'] ?? '',
                'is_active' => $this->isActive((string) $item['url'], $currentPath),
                '_sort_order' => (int) ($item['sort_order'] ?? 0),
            ];
        }

        foreach ($this->providers as $provider) {
            foreach ($provider->items($currentPath) as $item) {
                $items[] = [
                    'key' => (string) ($item['key'] ?? ''),
                    'label' => (string) ($item['label'] ?? ''),
                    'url' => (string) ($item['url'] ?? '#'),
                    'description' => (string) ($item['description'] ?? ''),
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

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        if ($target === '') {
            return $current === '';
        }

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
