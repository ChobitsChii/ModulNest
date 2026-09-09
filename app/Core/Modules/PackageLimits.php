<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
final readonly class PackageLimits { public function __construct(public int $maxArchiveBytes=52428800,public int $maxExpandedBytes=209715200,public int $maxFiles=5000,public int $maxFileBytes=52428800){} }
