<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

interface PageLinkProviderInterface
{
    /** @return list<array<string,mixed>> */
    public function listPublicHeaderPages(): array;

    /** @return list<array<string,mixed>> */
    public function listPublicFooterPages(): array;
}
