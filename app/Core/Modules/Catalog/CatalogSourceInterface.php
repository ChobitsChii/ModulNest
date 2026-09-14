<?php
declare(strict_types=1);
namespace Modulon\Core\Modules\Catalog;
interface CatalogSourceInterface { public function id(): string; public function identity(): string; public function read(string $location,int $maxBytes): string; }
