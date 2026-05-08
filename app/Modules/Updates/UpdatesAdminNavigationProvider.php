<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

use Modulon\Core\AdminNavigationProviderInterface;

final class UpdatesAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'updates';
    }

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description:string,sort_order:int}>
     */
    public function items(string $currentPath): array
    {
        $url = '/admin/updates';

        return [[
            'key' => 'updates',
            'label' => 'Updates',
            'url' => $url,
            'description' => 'ModulNest aktualisieren',
            'is_active' => $this->isActive($url, $currentPath),
            'sort_order' => 25,
        ]];
    }

    private function isActive(string $url, string $currentPath): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        return $current === $target || str_starts_with($current, $target . '/');
    }
}
