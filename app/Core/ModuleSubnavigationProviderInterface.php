<?php

declare(strict_types=1);

namespace Modulon\Core;

interface ModuleSubnavigationProviderInterface
{
    public function moduleKey(): string;

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,description?:string}>
     */
    public function items(string $currentPath): array;
}
