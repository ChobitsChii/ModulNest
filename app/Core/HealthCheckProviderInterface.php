<?php

declare(strict_types=1);

namespace Modulon\Core;

interface HealthCheckProviderInterface
{
    public function registerHealthChecks(HealthCheckRegistry $healthChecks): void;
}
