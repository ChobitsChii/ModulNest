<?php
declare(strict_types=1);
namespace Modulon\Core\Modules\Catalog;
use RuntimeException;
final readonly class LocalCatalogSource implements CatalogSourceInterface
{
    public function __construct(private string $sourceId,private string $root){}
    public function id():string{return $this->sourceId;}
    public function identity():string{return 'local:'.(realpath($this->root)?:rtrim(str_replace('\\','/',$this->root),'/'));}
    public function read(string $location,int $maxBytes):string{$location=str_replace('\\','/',$location);if($location===''||str_starts_with($location,'/')||preg_match('#(^|/)\.\.(/|$)#',$location))throw new RuntimeException('Unsicherer Katalogpfad.');$root=realpath($this->root);$path=realpath($this->root.'/'.$location);if($root===false||$path===false||!str_starts_with($path,$root.'/')||!is_file($path)||is_link($path))throw new RuntimeException('Katalogdatei fehlt oder liegt außerhalb der Quelle.');$size=filesize($path);if($size===false||$size>$maxBytes)throw new RuntimeException('Katalogdatei überschreitet das Limit.');$data=file_get_contents($path);if(!is_string($data)||strlen($data)>$maxBytes)throw new RuntimeException('Katalogdatei kann nicht gelesen werden.');return $data;}
}
