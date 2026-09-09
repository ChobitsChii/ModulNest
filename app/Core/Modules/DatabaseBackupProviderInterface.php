<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
interface DatabaseBackupProviderInterface { /** @param list<string> $tables */ public function backup(string $moduleId,array $tables): string; public function verify(string $reference): void; public function restore(string $reference): void; }
