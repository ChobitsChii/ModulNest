<?php
declare(strict_types=1);
namespace Modulon\Core\Modules\Catalog;
final readonly class CatalogSnapshot { /** @param array<string,array<string,mixed>> $modules */ public function __construct(public string $sourceId,public array $root,public array $modules,public bool $fromCache=false,public ?string $warning=null){} }
