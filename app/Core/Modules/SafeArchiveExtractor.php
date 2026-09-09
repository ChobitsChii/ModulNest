<?php
declare(strict_types=1);
namespace Modulon\Core\Modules;
use RuntimeException; use ZipArchive;
final class SafeArchiveExtractor
{
    public function __construct(private readonly PackageLimits $limits=new PackageLimits()){}
    /** @return list<string> */
    public function extract(string $archive,string $destination): array
    {
        if(!is_file($archive)||(filesize($archive)?:0)>$this->limits->maxArchiveBytes) throw new RuntimeException('Archiv fehlt oder ist zu groß.');
        $zip=new ZipArchive(); if($zip->open($archive)!==true) throw new RuntimeException('ZIP kann nicht geöffnet werden.');
        try {
            if($zip->numFiles>$this->limits->maxFiles) throw new RuntimeException('ZIP enthält zu viele Dateien.');
            $entries=[];$seen=[];$total=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i); if(!is_array($stat)) throw new RuntimeException('ZIP-Eintrag ist nicht lesbar.');
                $name=str_replace('\\','/',(string)$stat['name']);
                if($name===''||str_starts_with($name,'/')||preg_match('#(^|/)\.\.(/|$)#',$name)||preg_match('/^[A-Za-z]:/',$name)||str_contains($name,"\0")) throw new RuntimeException('Unsicherer ZIP-Pfad.');
                $collision=strtolower(rtrim($name,'/'));if(isset($seen[$collision]))throw new RuntimeException('Doppelter oder kollidierender ZIP-Pfad.');$seen[$collision]=true;
                $opsys=0;$attributes=0;$zip->getExternalAttributesIndex($i,$opsys,$attributes);$mode=($attributes>>16)&0170000; if(in_array($mode,[0120000,0010000,0020000,0060000,0140000],true)) throw new RuntimeException('Links und Special Files sind verboten.');
                $size=(int)($stat['size']??0); if($size>$this->limits->maxFileBytes||($total+=$size)>$this->limits->maxExpandedBytes) throw new RuntimeException('ZIP überschreitet Entpacklimits.');
                $entries[]=$name;
            }
            if(!is_dir($destination)&&!mkdir($destination,0775,true)&&!is_dir($destination)) throw new RuntimeException('Zielordner kann nicht erstellt werden.');
            foreach($entries as $i=>$name){ if(str_ends_with($name,'/')){ @mkdir($destination.'/'.$name,0775,true); continue; } $target=$destination.'/'.$name; @mkdir(dirname($target),0775,true); $in=$zip->getStream($name); if(!is_resource($in)) throw new RuntimeException('ZIP-Datei kann nicht gelesen werden.'); $out=fopen($target,'xb'); if(!is_resource($out)){fclose($in);throw new RuntimeException('Zieldatei kann nicht sicher erstellt werden.');} stream_copy_to_stream($in,$out);fclose($in);fclose($out); }
            return array_values(array_filter($entries,static fn($n)=>!str_ends_with($n,'/')));
        } finally { $zip->close(); }
    }
}
