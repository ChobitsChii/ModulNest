<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use PDO; use RuntimeException;
final readonly class ModuleRuntimeSnapshot
{
    /** @param array<string,ActiveModuleRelease> $releases */ private function __construct(private array $releases){}
    public static function capture(PDO $pdo,ModuleReleaseLocator $locator,ModuleManifestReader $reader=new ModuleManifestReader()): self
    {
        $sql="SELECT i.module_id,i.active_release_id,r.version,r.release_path FROM module_installations i JOIN modules m ON m.id=i.module_row_id JOIN module_releases r ON r.module_id=i.module_id AND r.release_id=i.active_release_id WHERE m.is_active=1 AND i.active_release_id IS NOT NULL";
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);$result=[];
        foreach($rows as $row){$root=(string)$row['release_path'];if(!is_dir($root))$root=$locator->releaseRoot((string)$row['module_id'],(string)$row['active_release_id']);$manifest=$reader->read($root.'/module.json');if($manifest->id!==$row['module_id'])throw new RuntimeException('Runtime-Manifest und Registry widersprechen sich.');$result[$manifest->id]=new ActiveModuleRelease($manifest->id,(string)$row['active_release_id'],(string)$row['version'],$root,$manifest);}
        ksort($result,SORT_STRING);return new self($result);
    }
    /** @return array<string,ActiveModuleRelease> */ public function all(): array{return $this->releases;}
    public function get(string $moduleId): ?ActiveModuleRelease{return $this->releases[$moduleId]??null;}
}
