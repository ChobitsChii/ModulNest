<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use RuntimeException;use ZipArchive;
final class ModulePackageBuilder
{
    public function build(string $source,string $target): string {$manifest=(new ModuleManifestReader())->read($source.'/module.json');$files=[];foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS)) as $item){if($item->isLink()||!$item->isFile())throw new RuntimeException('Paketquellen dürfen keine Links/Special Files enthalten.');$relative=str_replace('\\','/',substr($item->getPathname(),strlen(rtrim($source,'/'))+1));$files[$relative]=$item->getPathname();}ksort($files,SORT_STRING);$zip=new ZipArchive();if($zip->open($target,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Ziel-ZIP kann nicht erzeugt werden.');foreach($files as $relative=>$path){$zip->addFile($path,$relative);$zip->setMtimeName($relative,315532800);}$zip->close();return hash_file('sha256',$target)?:throw new RuntimeException('Hash fehlgeschlagen.');}
}
