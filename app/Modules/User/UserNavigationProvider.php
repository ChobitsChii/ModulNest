<?php

declare(strict_types=1);

namespace Modulon\Modules\User;

use Modulon\Core\UserNavigationProviderInterface;

final class UserNavigationProvider implements UserNavigationProviderInterface
{
    public function moduleKey(): string
    {
        return 'profil';
    }

    public function items(string $currentPath): array
    {
        return [
            [
                'key' => 'profile',
                'label' => 'Profil',
                'url' => '/profil',
                'is_active' => $this->isActive('/profil', $currentPath, false),
                'sort_order' => 10,
            ],
            [
                'key' => 'security',
                'label' => 'Sicherheit',
                'url' => '/profil/security',
                'is_active' => $this->isActive('/profil/security', $currentPath, true),
                'sort_order' => 20,
            ],
            [
                'key' => 'settings',
                'label' => 'Einstellungen',
                'url' => '/profil/settings',
                'is_active' => $this->isActive('/profil/settings', $currentPath, true),
                'sort_order' => 30,
            ],
        ];
    }

    private function isActive(string $url, string $currentPath, bool $allowSubPaths): bool
    {
        $target = rtrim('/' . trim($url, '/'), '/');
        $current = rtrim('/' . trim($currentPath, '/'), '/');

        if ($allowSubPaths) {
            return $current === $target || str_starts_with($current, $target . '/');
        }

        return $current === $target;
    }
}
