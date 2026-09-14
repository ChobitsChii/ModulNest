<?php
declare(strict_types=1);
namespace Modulon\Core\Modules\Catalog;
use RuntimeException;
final readonly class HttpCatalogSource implements CatalogSourceInterface
{
    public function __construct(private string $sourceId,private string $baseUrl,private int $timeoutSeconds=10){if(!str_starts_with($baseUrl,'https://'))throw new RuntimeException('Katalogquelle muss HTTPS verwenden.');}
    public function id():string{return $this->sourceId;}
    public function identity():string{return 'https:'.rtrim($this->baseUrl,'/');}
    public function read(string $location,int $maxBytes):string{if($location===''||str_starts_with($location,'/')||str_contains($location,'..')||preg_match('#^[a-z]+:#i',$location))throw new RuntimeException('Unsicherer Katalogpfad.');$context=stream_context_create(['http'=>['timeout'=>$this->timeoutSeconds,'follow_location'=>0,'ignore_errors'=>false,'header'=>"Accept: application/json\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);$handle=@fopen(rtrim($this->baseUrl,'/').'/'.$location,'rb',false,$context);if(!is_resource($handle))throw new RuntimeException('Katalogquelle ist nicht erreichbar.');try{$data=stream_get_contents($handle,$maxBytes+1);if(!is_string($data)||strlen($data)>$maxBytes)throw new RuntimeException('Katalogantwort überschreitet das Limit.');return $data;}finally{fclose($handle);}}
}
