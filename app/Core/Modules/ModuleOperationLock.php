<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use RuntimeException;
final class ModuleOperationLock
{
    /** @var resource|null */ private $handle=null;
    public function __construct(private readonly string $directory){}
    public function acquire(string $moduleId): void {ModuleId::assert($moduleId);if(!is_dir($this->directory)&&!mkdir($this->directory,0775,true)&&!is_dir($this->directory))throw new RuntimeException('Lockordner kann nicht erstellt werden.');$this->handle=fopen($this->directory.'/'.$moduleId.'.lock','c');if(!is_resource($this->handle)||!flock($this->handle,LOCK_EX|LOCK_NB))throw new RuntimeException('Für das Modul läuft bereits eine Operation.');}
    public function release(): void {if(is_resource($this->handle)){flock($this->handle,LOCK_UN);fclose($this->handle);$this->handle=null;}}
    public function __destruct(){$this->release();}
}
