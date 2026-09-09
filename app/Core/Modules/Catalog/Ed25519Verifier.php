<?php
declare(strict_types=1);
namespace Modulon\Core\Modules\Catalog;
use RuntimeException;
final readonly class Ed25519Verifier
{
    public function __construct(private CatalogTrustStore $trust){}
    public function verify(string $payload,string $keyId,string $base64Signature,?string $expectedFingerprint=null):void{$signature=base64_decode($base64Signature,true);if(!is_string($signature)||strlen($signature)!==64)throw new RuntimeException('Ungültige Ed25519-Signatur.');$key=$this->trust->publicKey($keyId);if($expectedFingerprint!==null&&!hash_equals(strtolower($expectedFingerprint),$this->trust->fingerprint($keyId)))throw new RuntimeException('Signing-Key-Fingerprint stimmt nicht.');if(!\ParagonIE_Sodium_Compat::crypto_sign_verify_detached($signature,$payload,$key))throw new RuntimeException('Ed25519-Signaturprüfung fehlgeschlagen.');}
}
