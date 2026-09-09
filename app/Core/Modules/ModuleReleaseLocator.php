<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use RuntimeException;
final readonly class ModuleReleaseLocator
{
    public function __construct(private string $basePath){}
    public function releaseRoot(string $moduleId,string $releaseId): string { ModuleId::assert($moduleId); $this->assertReleaseId($releaseId); return $this->basePath.'/modules/'.$moduleId.'/releases/'.$releaseId; }
    public function storageRoot(string $moduleId): string { ModuleId::assert($moduleId); return $this->basePath.'/storage/modules/'.$moduleId; }
    public function cacheRoot(string $moduleId): string { ModuleId::assert($moduleId); return $this->basePath.'/storage/cache/modules/'.$moduleId; }
    public function publicAssetRoot(string $moduleId,string $releaseId): string { ModuleId::assert($moduleId);$this->assertReleaseId($releaseId);return $this->basePath.'/public/assets/modules/'.$moduleId.'/'.$releaseId; }
    public function publicAssetUrl(string $moduleId,string $releaseId,string $asset=''): string { $this->assertReleaseId($releaseId);ModuleId::assert($moduleId);if(str_contains($asset,'..')||str_starts_with($asset,'/'))throw new RuntimeException('Unsicherer Assetpfad.');return '/assets/modules/'.$moduleId.'/'.$releaseId.'/'.ltrim($asset,'/'); }
    private function assertReleaseId(string $id): void { if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,95}$/D',$id))throw new RuntimeException('Ungültige Release-ID.'); }
}
