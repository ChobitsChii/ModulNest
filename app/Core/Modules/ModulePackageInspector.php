<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use RuntimeException;
final class ModulePackageInspector
{
    public function __construct(private readonly ModuleManifestReader $reader=new ModuleManifestReader(),private readonly SafeArchiveExtractor $extractor=new SafeArchiveExtractor()){}
    /** @return array{manifest:ModuleManifest,sha256:string,files:list<string>} */
    public function inspect(string $archive,?string $expectedSha256=null): array
    {
        $hash=hash_file('sha256',$archive); if(!is_string($hash)) throw new RuntimeException('Paket-Hash kann nicht berechnet werden.');
        if($expectedSha256!==null&&!hash_equals(strtolower($expectedSha256),$hash)) throw new RuntimeException('Paket-Hash stimmt nicht.');
        $temp=sys_get_temp_dir().'/modulnest-package-'.bin2hex(random_bytes(12));
        try { $files=$this->extractor->extract($archive,$temp); $manifest=$this->reader->read($temp.'/module.json');
            if(!is_file($temp.'/LICENSE')) throw new RuntimeException('Paket enthält keine LICENSE-Datei.');
            foreach([$manifest->entryFile,...array_values($manifest->psr4)] as $required) if(!file_exists($temp.'/'.rtrim($required,'/'))) throw new RuntimeException("Manifestpfad fehlt: {$required}");
            return ['manifest'=>$manifest,'sha256'=>$hash,'files'=>$files];
        } finally { self::removeTree($temp); }
    }
    public static function removeTree(string $root): void { if(!is_dir($root))return; foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());} rmdir($root); }
}
