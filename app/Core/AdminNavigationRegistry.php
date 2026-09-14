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

            public const DEFAULT_ICONS = [
        'dashboard' => 'bi-speedometer2',
        'module-catalog' => 'bi-box-seam',
        'catalog' => 'bi-box-seam',
        'modules' => 'bi-grid',
        'updates' => 'bi-arrow-repeat',
        'users' => 'bi-people',
        'systeminfo' => 'bi-info-circle',
        'logs' => 'bi-journal-text',
        'banking' => 'bi-wallet2',
        'calendar' => 'bi-calendar3',
        'wiki' => 'bi-book',
        'news' => 'bi-newspaper',
        'pages' => 'bi-file-earmark-text',
        'fantasy-cards' => 'bi-suit-spade',
        'tools' => 'bi-tools',
        'mail' => 'bi-envelope',
        'homepage' => 'bi-house-gear',
        'data-portability' => 'bi-cloud-arrow-down',
        'repository-manager' => 'bi-hdd-network',
        'mirror' => 'bi-hdd-stack',
        'sneak-preview' => 'bi-stars',
        'profil' => 'bi-person-circle',
        'profile' => 'bi-person-circle',
        'settings' => 'bi-gear',
        'security' => 'bi-shield-lock',
    ];

    private const PRIMARY_KEYS = ['dashboard', 'module-catalog', 'modules', 'updates', 'users', 'systeminfo', 'logs'];

    public static function iconForKey(string $key): string
    {
        $key = trim(strtolower($key));
        if (str_starts_with($key, 'modulnest.')) {
            $key = substr($key, 10);
        }
        $key = str_replace('_', '-', $key);
        return self::DEFAULT_ICONS[$key] ?? 'bi-circle';
    }

    /**
     * @return array{
     *     all: array<int, array{key:string,label:string,url:string,is_active:bool,description?:string,icon:string}>,
     *     primary: array<int, array{key:string,label:string,url:string,is_active:bool,description?:string,icon:string}>,
     *     secondary: array<int, array{key:string,label:string,url:string,is_active:bool,description?:string,icon:string}>,
     *     active_secondary: ?array{key:string,label:string,url:string,is_active:bool,description?:string,icon:string},
     *     sidebar_sections: array<string, array<int, array{key:string,label:string,url:string,is_active:bool,description?:string,icon:string}>>
     * }
     */
    public function groupedItems(string $currentPath): array
    {
        $allItems = $this->items($currentPath);
        $primary = [];
        $secondary = [];
        $activeSecondary = null;

        foreach ($allItems as $item) {
            $item['icon'] = self::iconForKey((string) ($item['key'] ?? ''));
            $key = (string) ($item['key'] ?? '');
            if (in_array($key, self::PRIMARY_KEYS, true)) {
                $primary[] = $item;
            } else {
                $secondary[] = $item;
                if (!empty($item['is_active'])) {
                    $activeSecondary = $item;
                }
            }
        }

        $sidebarSections = [
            'Verwaltung' => array_values(array_filter($primary, static fn (array $i): bool => in_array($i['key'], ['dashboard', 'module-catalog', 'modules', 'updates', 'users'], true))),
            'Module & Apps' => $secondary,
            'System' => array_values(array_filter($primary, static fn (array $i): bool => in_array($i['key'], ['systeminfo', 'logs'], true))),
        ];

        return [
            'all' => array_map(static function (array $item): array {
                $item['icon'] = self::iconForKey((string) ($item['key'] ?? ''));
                return $item;
            }, $allItems),
            'primary' => $primary,
            'secondary' => $secondary,
            'active_secondary' => $activeSecondary,
            'sidebar_sections' => array_filter($sidebarSections, static fn (array $items): bool => $items !== []),
        ];
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

        if ($target === '/admin/module-catalog' && ($current === '/admin/module-catalog' || str_starts_with($current, '/admin/module-catalog/') || $current === '/admin/repository-manager' || str_starts_with($current, '/admin/repository-manager/'))) {
            return true;
        }

        if ($target === '/admin') {
            return $current === '/admin';
        }

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
