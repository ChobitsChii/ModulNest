<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use Modulon\Core\ModuleContext;use Modulon\Core\ModulePresentationRegistry;use Modulon\Core\NativeModuleInterface;use Modulon\Core\View;use PDO;use RuntimeException;use Throwable;
final class ManagedModuleLoader
{
    /** @return array<string,NativeModuleInterface> */ public static function createActiveModules(PDO $pdo,string $basePath,ModuleContext $context,?CapabilityRegistry $capabilities=null): array {
        try{$snapshot=ModuleRuntimeSnapshot::capture($pdo,new ModuleReleaseLocator($basePath));}catch(Throwable){return [];}
        (new PackageAutoloader($snapshot))->register();
        $modules=[];
        foreach($snapshot->all() as $release){
            $m=$release->manifest;
            
            // Presentation aus Manifest laden und registrieren
            ModulePresentationRegistry::loadFromManifest($m->id, $m->raw, $m->routePrefix);
            
            if($capabilities!==null){
                foreach(($m->raw['capabilities']??[]) as $key=>$provider){
                    if(is_string($key)&&is_string($provider))$capabilities->register($m->id,$key,$provider);
                }
            }
            if($m->viewsPath!==null)View::registerModuleRoot($m->id,$release->root.'/'.$m->viewsPath);
            $class=$m->entryClass;
            if(!is_a($class,NativeModuleInterface::class,true))throw new RuntimeException("Entrypoint implementiert NativeModuleInterface nicht: {$m->id}");
            $instance=$class::create($context);
            if($instance!==null)$modules[$instance->routePrefix()]=$instance;
        }
        return $modules;
    }
}
