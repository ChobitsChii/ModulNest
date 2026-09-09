<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

interface RootPageProviderInterface
{
    public function view(): string;

    /**
     * @param array<string,mixed>|null $user
     * @param list<array<string,mixed>> $availableModules
     * @return array{audience:string,blocks:list<array<string,mixed>>}|null
     */
    public function build(?array $user, bool $isAdmin, array $availableModules): ?array;
}
