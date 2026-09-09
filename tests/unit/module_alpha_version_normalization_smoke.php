<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\Modules\ModuleOperationLock;
use Modulon\Core\Modules\ModulePackageInspector;
use Modulon\Development\AlphaModuleVersionNormalizer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/tools/development/AlphaModuleVersionNormalizer.php';

function normalization_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function normalization_env(string $path): array
{
    $values=[];
    foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$key,$value]=explode('=',$line,2);$values[trim($key)]=trim($value," \t\"'");}
    return $values;
}

function normalization_tree_hash(string $root): string
{
    $files=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $entry){$relative=str_replace('\\','/',substr($entry->getPathname(),strlen($root)+1));$files[$relative]=hash_file('sha256',$entry->getPathname());}
    ksort($files,SORT_STRING);
    return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}

$root=dirname(__DIR__,2);$env=normalization_env($root.'/.env');
$server=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='modulnest_normalize_'.bin2hex(random_bytes(5));$temporary=sys_get_temp_dir().'/modulnest-normalize-'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{
    $server->exec('USE `'.$database.'`');(new MigrationRunner($server,$root))->run(['Admin','Auth','Modules','User']);
    $definitions=[];
    foreach ([['example.known','1.4.0',str_repeat('a',64)],['example.unknown','1.5.0',str_repeat('b',64)]] as [$moduleId,$targetVersion,$packageHash]) {
        $releaseId='1.0.1-'.substr($packageHash,0,12);$releasePath=$temporary.'/modules/'.$moduleId.'/releases/'.$releaseId;mkdir($releasePath,0775,true);
        $manifest=['manifest_version'=>2,'id'=>$moduleId,'name'=>$moduleId,'description'=>'Normalization fixture','version'=>'1.0.1','license'=>'MIT','authors'=>[['name'=>'Fixture']],'homepage'=>'https://example.test','repository'=>'https://example.test/repository','route_prefix'=>str_replace('.','-',$moduleId),'access_level'=>'admin','requires'=>['core'=>'>=1.2.0 <3.0.0','php'=>'>=8.3.0','extensions'=>[]],'entrypoint'=>['class'=>'Fixture\\Module','file'=>'src/Module.php'],'autoload'=>['psr4'=>['Fixture\\'=>'src/']],'dependencies'=>(object)[],'optional_dependencies'=>(object)[],'conflicts'=>(object)[],'data'=>['schema_version'=>0,'compatible_schema'=>'=0','ownership'=>['tables'=>[],'settings'=>[],'storage'=>[],'uploads'=>[],'jobs'=>[]]]];
        file_put_contents($releasePath.'/module.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        $server->prepare("INSERT INTO modules(module_key,name,description,route_prefix,access_level,handler,is_active) VALUES(?,?,?,?,'admin','native',1)")->execute([$moduleId,$moduleId,'Fixture',str_replace('.','-',$moduleId)]);$rowId=(int)$server->lastInsertId();
        $server->prepare("INSERT INTO module_releases(module_id,version,release_id,release_path,manifest_json,sha256,status) VALUES(?,?,?,?,?,?,'ready')")->execute([$moduleId,'1.0.1',$releaseId,$releasePath,json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$packageHash]);
        $server->prepare("INSERT INTO module_installations(module_id,module_row_id,origin,catalog_source_id,catalog_sequence,installed_version,active_release_id,data_schema_version,health_status,package_sha256) VALUES(?,?,'catalog-managed','modulnest.dev',1,'1.0.1',?,0,'healthy',?)")->execute([$moduleId,$rowId,$releaseId,$packageHash]);
        $definitions[$moduleId]=['version'=>$targetVersion,'package_sha256'=>$moduleId==='example.unknown'?str_repeat('c',64):$packageHash,'tree_sha256'=>normalization_tree_hash($releasePath)];
    }
    $result=(new AlphaModuleVersionNormalizer($server,$temporary,'2.0.0-alpha.1',$definitions,new ModuleOperationLock($temporary.'/storage/locks/modules')))->normalize();
    normalization_assert($result['normalized']===['example.known'],'Exakt bekannter provisorischer Release wurde nicht normalisiert.');
    normalization_assert(($result['skipped']['example.unknown']??'')==='unbekannter Paket-Fingerprint','Unbekannter Release wurde nicht sicher übersprungen.');
    $known=$server->query("SELECT i.installed_version,r.version,i.package_sha256 FROM module_installations i JOIN module_releases r ON r.module_id=i.module_id AND r.release_id=i.active_release_id WHERE i.module_id='example.known'")->fetch(PDO::FETCH_ASSOC);
    normalization_assert($known['installed_version']==='1.4.0'&&$known['version']==='1.4.0'&&$known['package_sha256']===str_repeat('a',64),'Registry-/Release-Metadaten oder Paketprovenienz wurden falsch normalisiert.');
    $knownManifest=json_decode((string)file_get_contents($temporary.'/modules/example.known/releases/1.0.1-aaaaaaaaaaaa/module.json'),true);
    normalization_assert($knownManifest['version']==='1.4.0'&&$knownManifest['requires']['core']==='>=2.0.0-alpha.1 <3.0.0','Runtime-Manifest wurde nicht kanonisch normalisiert.');
    normalization_assert((string)$server->query("SELECT installed_version FROM module_installations WHERE module_id='example.unknown'")->fetchColumn()==='1.0.1','Unbekannter Release wurde umgeschrieben.');
    normalization_assert((int)$server->query("SELECT COUNT(*) FROM module_operations WHERE operation_type='version-normalize' AND status='succeeded'")->fetchColumn()===1,'Normalisierung wurde nicht journalisiert.');
}finally{$server->exec('DROP DATABASE IF EXISTS `'.$database.'`');if(is_dir($temporary))ModulePackageInspector::removeTree($temporary);}
fwrite(STDOUT,"Known alpha version normalization and unknown-release block passed.\n");
