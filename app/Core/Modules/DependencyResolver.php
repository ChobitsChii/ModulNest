<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use RuntimeException;
final class DependencyResolver
{
    /** @param array<string,string> $installed */
    public function assertSatisfied(ModuleManifest $manifest,array $installed): void {foreach($manifest->dependencies as $id=>$range){if(!isset($installed[$id]))throw new RuntimeException("Abhängigkeit fehlt: {$id}");if(!VersionConstraint::parse($range)->matches($installed[$id]))throw new RuntimeException("Abhängigkeit {$id} erfüllt {$range} nicht.");}foreach($manifest->conflicts as $id=>$range)if(isset($installed[$id])&&VersionConstraint::parse($range)->matches($installed[$id]))throw new RuntimeException("Konflikt mit {$id} {$installed[$id]}.");}
    /** @param array<string,list<string>> $graph @return list<string> */ public function order(array $graph): array {$state=[];$out=[];$visit=function(string $id)use(&$visit,&$state,&$out,$graph):void{if(($state[$id]??0)===1)throw new RuntimeException('Zyklische Modulabhängigkeit.');if(($state[$id]??0)===2)return;$state[$id]=1;foreach($graph[$id]??[] as $dep)$visit($dep);$state[$id]=2;$out[]=$id;};foreach(array_keys($graph) as $id)$visit($id);return $out;}
}
