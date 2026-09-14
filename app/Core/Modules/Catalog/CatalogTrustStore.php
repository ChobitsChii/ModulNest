<?php
declare(strict_types=1);
namespace Modulon\Core\Modules\Catalog;
use RuntimeException;
final readonly class CatalogTrustStore
{
    /** @param array<string,string> $base64PublicKeys @param list<string> $rootKeyIds */ public function __construct(private array $base64PublicKeys,private array $rootKeyIds=[]){ }
    public function publicKey(string $keyId):string{if(!isset($this->base64PublicKeys[$keyId]))throw new RuntimeException("Unbekannter Katalog-Signaturschlüssel: {$keyId}");$key=base64_decode($this->base64PublicKeys[$keyId],true);if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('Ungültiger Ed25519 Public Key.');return $key;}
    public function fingerprint(string $keyId):string{return hash('sha256',$this->publicKey($keyId));}
    public function isRootKeyAllowed(string $keyId):bool{return $this->rootKeyIds===[]||in_array($keyId,$this->rootKeyIds,true);}
    public function identity():string{$keys=$this->base64PublicKeys;ksort($keys,SORT_STRING);$roots=$this->rootKeyIds;sort($roots,SORT_STRING);return hash('sha256',json_encode(['keys'=>$keys,'roots'=>$roots],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
}
