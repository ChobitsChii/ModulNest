<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\Modules\{ModuleLifecycleService,ModuleOperationLock,ModulePackageBuilder,ModulePackageInspector,PdoLogicalBackupProvider};

require dirname(__DIR__,2).'/vendor/autoload.php';

function backup_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function backup_env(string $path):array{$out=[];foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$key,$value]=explode('=',$line,2);$out[trim($key)]=trim($value," \t\"'");}return $out;}

$root=dirname(__DIR__,2);$env=backup_env($root.'/.env');$server=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='modulnest_backup_'.bin2hex(random_bytes(5));$temporary=sys_get_temp_dir().'/modulnest-backup-'.bin2hex(random_bytes(6));$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{
    mkdir($temporary.'/bin',0775,true);copy($root.'/bin/module-health.php',$temporary.'/bin/module-health.php');symlink($root.'/vendor',$temporary.'/vendor');$server->exec('USE `'.$database.'`');(new MigrationRunner($server,$root))->run([]);
    $builder=new ModulePackageBuilder();$old=$temporary.'/old.zip';$new=$temporary.'/new.zip';$oldHash=$builder->build($root.'/tests/Fixtures/module-packages-v2/example-notes-0.1.0',$old);$newHash=$builder->build($root.'/tests/Fixtures/module-packages-v2/example-notes-0.2.0',$new);
    $backupRoot=$temporary.'/storage/backups/modules';$provider=new PdoLogicalBackupProvider($server,$backupRoot);$lifecycle=new ModuleLifecycleService($server,$temporary,'1.2.0',$provider,new ModuleOperationLock($temporary.'/storage/locks/modules'));
    $lifecycle->install($old,$oldHash,true);$server->exec("INSERT INTO example_notes_v2(title,body) VALUES('Backup bleibt','vor dem Update')");$lifecycle->update($new,$newHash);
    $backups=glob($backupRoot.'/example.example-notes/*.json')?:[];backup_assert(count($backups)===1,'Echtes Fixture-Update hat nicht genau ein logisches Backup erzeugt.');$provider->verify($backups[0]);
    backup_assert((((int)fileperms($backups[0]))&0777)===0600,'Backup-Datei besitzt nicht 0600.');backup_assert(((((int)fileperms(dirname($backups[0])))&0007)===0),'Modul-Backupordner ist für andere Benutzer geöffnet.');
    $wrapper=json_decode((string)file_get_contents($backups[0]),true,8,JSON_THROW_ON_ERROR);$payload=json_decode((string)base64_decode((string)$wrapper['payload'],true),true,32,JSON_THROW_ON_ERROR);backup_assert(($payload['tables']['example_notes_v2']['rows'][0]['title']??'')==='Backup bleibt','Backup enthält den Vor-Update-Datenstand nicht.');
    backup_assert($lifecycle->inspect('example.example-notes')['installed_version']==='0.2.0','Fixture-Modulupdate wurde nicht abgeschlossen.');
}finally{$server->exec('DROP DATABASE IF EXISTS `'.$database.'`');if(is_link($temporary.'/vendor'))unlink($temporary.'/vendor');if(is_dir($temporary))ModulePackageInspector::removeTree($temporary);}
fwrite(STDOUT,"Module backup permissions and real fixture update passed.\n");
