<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use RuntimeException;
final class ModuleManifestReader
{
    public const MAX_BYTES = 262144;
    public function read(string $path): ModuleManifest
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('module.json fehlt oder ist kein reguläres File.');
        $size=filesize($path); if($size===false||$size>self::MAX_BYTES) throw new RuntimeException('module.json überschreitet das Größenlimit.');
        $json=file_get_contents($path); if($json===false||!mb_check_encoding($json,'UTF-8')) throw new RuntimeException('module.json ist nicht gültiges UTF-8.');
        try { $data=json_decode($json,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); } catch(\JsonException $e){ throw new RuntimeException('module.json ist kein gültiges JSON.',0,$e); }
        if(!is_array($data)||array_is_list($data)) throw new RuntimeException('module.json muss ein Objekt enthalten.');
        return ModuleManifest::fromArray($data);
    }
}
