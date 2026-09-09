<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
final class RuntimeCacheManager
{
    public function publishFreshRelease(string $root): void {if(!function_exists('opcache_invalidate'))return;foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $item)if($item->isFile()&&str_ends_with($item->getFilename(),'.php'))@opcache_invalidate($item->getPathname(),true);}
}
