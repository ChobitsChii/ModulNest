<?php

declare(strict_types=1);

namespace Modulon\Core;

use RuntimeException;

final class BackgroundProcessLaunchException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $safeMessage, ?\Throwable $previous = null)
    {
        parent::__construct($safeMessage, 0, $previous);
    }
}
