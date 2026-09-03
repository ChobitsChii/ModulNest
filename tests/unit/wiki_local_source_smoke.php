<?php
declare(strict_types=1);
use Modulon\Modules\Wiki\{LocalWikiPath, LocalWikiSource};
require dirname(__DIR__,2).'/vendor/autoload.php';
function local_wiki_assert(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
$root=dirname(__DIR__,2);$paths=new LocalWikiPath($root);
foreach(['docs','docs/development'] as $path)local_wiki_assert($paths->relative($path)===$path,'Valid local path must resolve.');
foreach(['..','../','../../etc','docs/../../etc','/etc','/srv/http/modulon/docs','docs%2f..%2f..%2fetc',"docs\0x",'docs\\..\\etc','storage','vendor','node_modules','.git'] as $path){try{$paths->relative($path);local_wiki_assert(false,"Unsafe path accepted: $path");}catch(RuntimeException){}}
$listing=$paths->directories('docs');local_wiki_assert($listing['path']==='docs'&&in_array('development',$listing['directories'],true),'Directory browser must return direct safe children only.');
$tmp=sys_get_temp_dir().'/modulnest-local-wiki-'.bin2hex(random_bytes(4));$outside=sys_get_temp_dir().'/modulnest-local-wiki-outside-'.bin2hex(random_bytes(4));
mkdir($tmp.'/docs/inside',0775,true);mkdir($outside,0775,true);file_put_contents($tmp.'/docs/inside/page.md','# Inside');file_put_contents($outside.'/escape.md','# Outside');
try {
    local_wiki_assert(symlink($tmp.'/docs/inside',$tmp.'/docs/internal-link'),'Internal symlink fixture must be creatable.');
    local_wiki_assert(symlink($outside,$tmp.'/docs/escape-link'),'External symlink fixture must be creatable.');
    $isolated=new LocalWikiPath($tmp);local_wiki_assert($isolated->relative('docs/internal-link')==='docs/internal-link','A selected symlink resolving inside the project root must be allowed.');
    try{$isolated->relative('docs/escape-link');local_wiki_assert(false,'A symlink escaping the project root must be rejected.');}catch(RuntimeException){}
    $isolatedListing=$isolated->directories('docs');local_wiki_assert(in_array('internal-link',$isolatedListing['directories'],true)&&!in_array('escape-link',$isolatedListing['directories'],true),'The picker must expose only safe symlink directories.');
    $archive=(new LocalWikiSource($isolated))->download('docs/internal-link')['archive'];$file=tempnam(sys_get_temp_dir(),'wiki-local-zip-');file_put_contents($file,$archive);$zip=new ZipArchive();local_wiki_assert($zip->open($file)===true,'The local archive must open.');local_wiki_assert($zip->locateName('local-source/docs/internal-link/page.md')!==false,'A safe selected symlink must be archived under its requested relative path.');$zip->close();unlink($file);
} finally {
    foreach([$tmp,$outside] as $directory){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){$entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($directory);}
}
fwrite(STDOUT,"Wiki local source smoke passed.\n");
