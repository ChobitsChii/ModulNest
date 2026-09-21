<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use PDO; use RuntimeException;
final readonly class PdoLogicalBackupProvider implements DatabaseBackupProviderInterface
{
    public function __construct(private PDO $pdo,private string $backupRoot){}
    public function backup(string $moduleId,array $tables): string {
        ModuleId::assert($moduleId);
        if(!is_dir($this->backupRoot)&&!@mkdir($this->backupRoot,0770,true)&&!is_dir($this->backupRoot))throw new RuntimeException('Das Modul-Backup-Verzeichnis ist für den Webprozess nicht beschreibbar.');
        if(is_link($this->backupRoot)||!is_writable($this->backupRoot))throw new RuntimeException('Das Modul-Backup-Verzeichnis ist für den Webprozess nicht beschreibbar.');
        $dir=$this->backupRoot.'/'.$moduleId;
        if(!is_dir($dir)&&!@mkdir($dir,0770)&&!is_dir($dir))throw new RuntimeException('Modul-Backupordner kann nicht sicher erstellt werden.');
        if(is_link($dir)||!is_writable($dir))throw new RuntimeException('Der Modul-Backupordner ist für den Webprozess nicht beschreibbar.');
        @chmod($dir,0770);

        $path=$dir.'/'.gmdate('YmdHis').'-'.bin2hex(random_bytes(6)).'.json';
        $tempPayloadPath=$path.'.raw';
        $raw=fopen($tempPayloadPath,'wb');
        if($raw===false)throw new RuntimeException('Backup kann nicht geschrieben werden.');

        try{
            fwrite($raw,'{"format":"modulnest-pdo-logical-v1","module_id":'.json_encode($moduleId).',"created_at":'.json_encode(gmdate(DATE_ATOM)).',"tables":{');
            $tIndex=0;
            foreach($tables as $table){
                $this->assertTable($table);
                $check=$this->pdo->prepare('SHOW TABLES LIKE ?');
                $check->execute([$table]);
                if($check->fetchColumn()===false){
                    continue;
                }
                $create=$this->pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);
                if(!is_array($create))throw new RuntimeException("Tabelle fehlt: {$table}");
                if($tIndex>0)fwrite($raw,',');
                $tIndex++;
                fwrite($raw,json_encode((string)$table).':{"create":'.json_encode((string)$create[1]).',"rows":[');
                $stmt=$this->pdo->query('SELECT * FROM `'.$table.'`');
                $rIndex=0;
                while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
                    if($rIndex>0)fwrite($raw,',');
                    $rIndex++;
                    fwrite($raw,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                }
                fwrite($raw,']}');
            }
            fwrite($raw,'}}');
        }finally{
            fclose($raw);
        }

        $sha256=hash_file('sha256',$tempPayloadPath);
        $out=fopen($path,'wb');
        if($out===false){@unlink($tempPayloadPath);throw new RuntimeException('Backup kann nicht geschrieben werden.');}
        $in=fopen($tempPayloadPath,'rb');
        if($in===false){fclose($out);@unlink($tempPayloadPath);throw new RuntimeException('Temp-Backup kann nicht gelesen werden.');}
        try{
            fwrite($out,'{"sha256":'.json_encode($sha256).',"payload":"');
            $chunkSize=65535; // multiple of 3 bytes for base64 without padding
            while(!feof($in)){
                $chunk=fread($in,$chunkSize);
                if($chunk!==false&&$chunk!==''){
                    fwrite($out,base64_encode($chunk));
                }
            }
            fwrite($out,'"}');
        }finally{
            fclose($in);
            fclose($out);
            @unlink($tempPayloadPath);
        }

        if(!@chmod($path,0600)||((int)fileperms($path)&0777)!==0600){@unlink($path);throw new RuntimeException('Backup-Dateirechte konnten nicht sicher gesetzt werden.');}
        $this->verify($path);
        return $path;
    }
    public function verify(string $reference): void {
        $this->verifyBackupFile($reference);
    }
    private function verifyBackupFile(string $path): void {
        if(!is_file($path))throw new RuntimeException('Backup fehlt.');
        $in=fopen($path,'rb');
        if($in===false)throw new RuntimeException('Backup kann nicht geöffnet werden.');
        try{
            $header=fread($in,256);
            if(!is_string($header)||preg_match('/^\{\"sha256\":\"([a-f0-9]{64})\",\"payload\":\"/',$header,$matches)!==1){
                throw new RuntimeException('Backup-Header ungültig.');
            }
            $expectedSha=$matches[1];
            $payloadOffset=strlen($matches[0]);
            fseek($in,$payloadOffset);

            $hasher=hash_init('sha256');
            $b64ChunkSize=65536; // multiple of 4 bytes
            $remainder='';
            while(!feof($in)){
                $rawB64=fread($in,$b64ChunkSize);
                if($rawB64===false||$rawB64==='')break;
                $quotePos=strpos($rawB64,'"');
                if($quotePos!==false){
                    $rawB64=substr($rawB64,0,$quotePos);
                    $chunkToDecode=$remainder.$rawB64;
                    if($chunkToDecode!==''){
                        $decoded=base64_decode($chunkToDecode,true);
                        if(!is_string($decoded))throw new RuntimeException('Ungültiges Base64 im Backup.');
                        hash_update($hasher,$decoded);
                    }
                    break;
                }
                $chunkToDecode=$remainder.$rawB64;
                $validLen=strlen($chunkToDecode)-(strlen($chunkToDecode)%4);
                if($validLen>0){
                    $decoded=base64_decode(substr($chunkToDecode,0,$validLen),true);
                    if(!is_string($decoded))throw new RuntimeException('Ungültiges Base64 im Backup.');
                    hash_update($hasher,$decoded);
                    $remainder=substr($chunkToDecode,$validLen);
                }else{
                    $remainder=$chunkToDecode;
                }
            }
            $actualSha=hash_final($hasher);
            if(!hash_equals($expectedSha,$actualSha))throw new RuntimeException('Backup-Verifikation fehlgeschlagen.');
        }finally{
            fclose($in);
        }
    }
    public function restore(string $reference): void {
        $data=$this->decode($reference);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try{
            foreach($data['tables'] as $table=>$definition){
                $this->assertTable((string)$table);
                $this->pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
                $this->pdo->exec((string)$definition['create']);
                foreach($definition['rows'] as $row){
                    if(!is_array($row)||$row===[])continue;
                    $cols=array_keys($row);
                    foreach($cols as $col)$this->assertTable((string)$col);
                    $sql='INSERT INTO `'.$table.'` (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')';
                    $this->pdo->prepare($sql)->execute(array_values($row));
                }
            }
        }finally{
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }
    public function decode(string $path): array {
        if(!is_file($path))throw new RuntimeException('Backup fehlt.');
        @ini_set('memory_limit','1024M');
        $wrapper=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        $json=base64_decode((string)($wrapper['payload']??''),true);
        if(!is_string($json)||!hash_equals((string)($wrapper['sha256']??''),hash('sha256',$json)))throw new RuntimeException('Backup-Verifikation fehlgeschlagen.');
        $data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(($data['format']??'')!=='modulnest-pdo-logical-v1'||!is_array($data['tables']??null))throw new RuntimeException('Unbekanntes Backupformat.');
        return $data;
    }
    private function assertTable(string $value): void {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$value))throw new RuntimeException('Unsicherer SQL-Identifier.');
    }
}
