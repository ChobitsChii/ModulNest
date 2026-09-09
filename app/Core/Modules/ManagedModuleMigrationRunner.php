<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use Modulon\Core\Database\Migration;use Modulon\Core\Database\SchemaHelper;use PDO;use RuntimeException;
final readonly class ManagedModuleMigrationRunner
{
    public function __construct(private PDO $pdo){}
    public function run(ModuleManifest $manifest,string $root): void {if($manifest->migrationsPath===null)return;$files=glob($root.'/'.$manifest->migrationsPath.'/*.php')?:[];sort($files,SORT_STRING);$schema=new SchemaHelper($this->pdo);foreach($files as $file){$migration=require $file;if(!$migration instanceof Migration||$migration->scope()!=='module'||$migration->moduleKey()!==$manifest->id)throw new RuntimeException('Ungültige oder fremde Modulmigration.');$key=$migration->key();$existing=$this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration_key=?');$existing->execute([$key]);$checksum=hash_file('sha256',$file);$stored=$existing->fetchColumn();if($stored!==false){if(!hash_equals((string)$stored,(string)$checksum))throw new RuntimeException("Migration-Checksum stimmt nicht mehr: {$key}");continue;}$migration->up($this->pdo,$schema);$insert=$this->pdo->prepare('INSERT INTO schema_migrations(migration_key,scope,module_key,description,checksum) VALUES(?,?,?,?,?)');$insert->execute([$key,'module',$manifest->id,$migration->description(),$checksum]);}}
}
