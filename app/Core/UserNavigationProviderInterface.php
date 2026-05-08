<?php

declare(strict_types=1);

namespace Modulon\Core;

interface UserNavigationProviderInterface
{
    public function moduleKey(): string;

    /**
     * @return array<int, array{key:string,label:string,url:string,is_active:bool,sort_order?:int}>
     */
    public function items(string $currentPath): array;
}
