<?php

declare(strict_types=1);
namespace Modulon\Modules\ExampleNotes;
use Modulon\Core\UserNavigationProviderInterface;
final class ExampleNotesUserNavigationProvider implements UserNavigationProviderInterface
{
    public function moduleKey(): string { return 'example-notes'; }
    public function items(string $currentPath): array { return [['key' => 'example-notes', 'label' => 'Example Notes', 'url' => '/example-notes', 'is_active' => rtrim($currentPath, '/') === '/example-notes', 'sort_order' => 90]]; }
}
