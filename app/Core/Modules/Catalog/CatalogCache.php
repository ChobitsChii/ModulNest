<?php
declare(strict_types=1);
namespace Modulon\Core\Modules\Catalog;
use RuntimeException;
final readonly class CatalogCache
{
    public function __construct(private string $root){}
    public function store(CatalogSnapshot $snapshot,?string $bindingHash=null):void{$dir=$this->root.'/'.$this->safe($snapshot->sourceId);if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Katalogcache kann nicht erstellt werden.');$payload=json_encode(['source_id'=>$snapshot->sourceId,'binding_hash'=>$bindingHash,'root'=>$snapshot->root,'modules'=>$snapshot->modules],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$wrapped=json_encode(['sha256'=>hash('sha256',$payload),'payload'=>base64_encode($payload)],JSON_THROW_ON_ERROR);$tmp=$dir.'/lkg-'.bin2hex(random_bytes(8)).'.tmp';if(file_put_contents($tmp,$wrapped,LOCK_EX)===false||!rename($tmp,$dir.'/lkg.json')){@unlink($tmp);throw new RuntimeException('Katalogcache konnte nicht atomar ersetzt werden.');}}
    public function load(string $sourceId,?string $bindingHash=null):?CatalogSnapshot{$path=$this->root.'/'.$this->safe($sourceId).'/lkg.json';if(!is_file($path))return null;$w=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);$payload=base64_decode((string)($w['payload']??''),true);if(!is_string($payload)||!hash_equals((string)($w['sha256']??''),hash('sha256',$payload)))throw new RuntimeException('LKG-Katalogcache ist beschädigt.');$d=json_decode($payload,true,64,JSON_THROW_ON_ERROR);if(($d['source_id']??null)!==$sourceId||!is_array($d['root']??null)||!is_array($d['modules']??null))throw new RuntimeException('LKG-Katalogcache ist ungültig.');$storedBinding=$d['binding_hash']??null;if($bindingHash!==null&&is_string($storedBinding)&&!hash_equals($bindingHash,$storedBinding))return null;return new CatalogSnapshot($sourceId,$d['root'],$d['modules'],true);}
    private function safe(string $id):string{if(!preg_match('/^[a-z0-9.-]+$/D',$id))throw new RuntimeException('Ungültige Katalogquellen-ID.');return $id;}
}
