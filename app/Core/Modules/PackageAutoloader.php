<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
final class PackageAutoloader
{
    private bool $registered=false;
    public function __construct(private readonly ModuleRuntimeSnapshot $snapshot){}
    public function register(): void {if($this->registered)return;$mappings=[];foreach($this->snapshot->all() as $release)foreach($release->manifest->psr4 as $prefix=>$path)$mappings[]=[$prefix,$release->root.'/'.rtrim($path,'/')];usort($mappings,static fn($a,$b)=>strlen($b[0])<=>strlen($a[0]));spl_autoload_register(static function(string $class)use($mappings):void{foreach($mappings as [$prefix,$root]){if(!str_starts_with($class,$prefix))continue;$path=$root.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require $path;return;}},true,true);$this->registered=true;}
}
