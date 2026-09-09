<?php
declare(strict_types=1);
use Modulon\Core\Database\{Migration,SchemaHelper};
return new class implements Migration {public function key():string{return '20260906_000300_module_catalog_v2';}public function scope():string{return 'core';}public function moduleKey():?string{return null;}public function description():string{return 'Bindet verwaltete Modulinstallationen an eine verifizierte Katalogquelle.';}public function up(PDO $pdo,SchemaHelper $schema):void{$pdo->exec("ALTER TABLE module_installations ADD COLUMN IF NOT EXISTS catalog_source_id VARCHAR(120) NULL AFTER origin, ADD COLUMN IF NOT EXISTS catalog_sequence BIGINT UNSIGNED NULL AFTER catalog_source_id");}};
