<?php

declare(strict_types=1);
namespace Modulon\Modules\ExampleNotes;
use Modulon\Core\AdminNavigationProviderInterface;
final class ExampleNotesAdminNavigationProvider implements AdminNavigationProviderInterface
{
    public function moduleKey(): string { return 'example-notes'; }
    public function items(string $currentPath): array { $url = '/admin/example-notes'; return [['key' => 'example-notes', 'label' => 'Example Notes', 'url' => $url, 'description' => 'Einstellung des Referenzmoduls', 'is_active' => rtrim($currentPath, '/') === $url, 'sort_order' => 900]]; }
}
