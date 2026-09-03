<?php
declare(strict_types=1);
namespace Modulon\Modules\Wiki;
use Modulon\Core\AdminNavigationProviderInterface;
final class WikiAdminNavigationProvider implements AdminNavigationProviderInterface { public function moduleKey(): string { return 'wiki'; } public function items(string $currentPath): array { return [['key'=>'wiki','label'=>'Wiki','url'=>'/admin/wiki','description'=>'Wiki administration','is_active'=>rtrim($currentPath,'/')==='/admin/wiki','sort_order'=>900]]; } }
