<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Modules\Wiki\{GitHubWikiClient, WikiMarkdownRenderer, WikiRepository, WikiService, WikiSyncException};

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function wiki_sync_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
/** @return array<string,string> */
function wiki_sync_env(string $path): array { $result=[];foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$key,$value]=explode('=',$line,2);$result[trim($key)]=trim($value," \t\"'");}return $result; }
function wiki_sync_zip(array $files): string { $path=tempnam(sys_get_temp_dir(),'wiki-zip-');$zip=new ZipArchive();$zip->open($path,ZipArchive::OVERWRITE);foreach($files as $name=>$content)$zip->addFromString('fixture-commit/'.$name,$content);$zip->close();$data=(string)file_get_contents($path);unlink($path);return $data; }

$base = dirname(__DIR__, 2); $env=wiki_sync_env($base.'/.env');
$server=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';charset='.($env['DB_CHARSET']??'utf8mb4'),$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='modulnest_wiki_smoke_'.bin2hex(random_bytes(4));$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$tmp=sys_get_temp_dir().'/modulnest-wiki-'.bin2hex(random_bytes(5));
try {
    $server->exec('USE `'.$database.'`');(new MigrationRunner($server,$base))->run(['Wiki']);
    $repo=new WikiRepository($server);$service=new WikiService($repo,$server,$tmp,new GitHubWikiClient());
    $invalid=false;try{$service->validateConfig('owner','repo','../../bad','docs',true);}catch(RuntimeException){$invalid=true;}wiki_sync_assert($invalid,'Unsafe refs must be rejected.');
    $invalid=false;try{$service->validateConfig('owner','repo','HEAD','docs/../private',true);}catch(RuntimeException){$invalid=true;}wiki_sync_assert($invalid,'Traversal in docs_root must be rejected.');
    $config=$service->validateConfig('ChobitsChii','ModulNest','','docs',true);$repo->saveSource($config);$source=$repo->source();wiki_sync_assert(is_array($source),'Source must be saved.');
    $first=wiki_sync_zip(['docs/README.md'=>"---\ntitle: Developer docs\norder: 1\n---\n# Ignored heading\n\n[Spec](development/module-spec.md)\n\n![Logo](images/logo.png)",'docs/development/module-spec.md'=>'# Module specification','docs/images/logo.png'=>'PNG']);
    $result=$service->syncArchive($source,$first,str_repeat('a',40));wiki_sync_assert($result['added']===2,'First sync must add both pages.');wiki_sync_assert(is_file($tmp.'/storage/wiki/content/README.md'),'Markdown cache must be written locally.');wiki_sync_assert($repo->page('index')['title']==='Developer docs','Frontmatter title must be indexed.');wiki_sync_assert($repo->rootPage()['relative_path']==='README.md','README.md must be the preferred Wiki root page.');wiki_sync_assert($repo->asset('images/logo.png')['mime_type']==='image/png','Image asset must be indexed.');
    $source=$repo->source();$second=wiki_sync_zip(['docs/README.md'=>'# Updated','docs/new.md'=>'# New']);$result=$service->syncArchive($source,$second,str_repeat('b',40));wiki_sync_assert($result['added']===1&&$result['changed']===1&&$result['deleted']===1,'Second sync must calculate add/change/delete.');wiki_sync_assert($repo->page('development/module-spec')===null,'Deleted page must leave the index.');
    $before=(string)file_get_contents($tmp.'/storage/wiki/content/README.md');$malicious=wiki_sync_zip(['../escape.md'=>'# no','docs/README.md'=>'# bad']);$blocked=false;try{$service->syncArchive($repo->source(),$malicious,str_repeat('c',40));}catch(WikiSyncException $e){$blocked=$e->safeCode==='unsafe_archive_path';}wiki_sync_assert($blocked,'ZIP slip must be blocked.');wiki_sync_assert((string)file_get_contents($tmp.'/storage/wiki/content/README.md')===$before,'Failed sync must preserve local content.');
    $large=wiki_sync_zip(['docs/README.md'=>'# valid','docs/large.md'=>str_repeat('x',1_500_001)]);$blocked=false;try{$service->syncArchive($repo->source(),$large,str_repeat('d',40));}catch(WikiSyncException $e){$blocked=$e->safeCode==='content_too_large';}wiki_sync_assert($blocked,'Oversized individual files must be blocked.');
    $deep=wiki_sync_zip(['docs/'.str_repeat('nested/',14).'page.md'=>'# deep']);$blocked=false;try{$service->syncArchive($repo->source(),$deep,str_repeat('e',40));}catch(WikiSyncException $e){$blocked=$e->safeCode==='unsafe_archive_path';}wiki_sync_assert($blocked,'Excessive archive path depth must be blocked.');
    $svg=wiki_sync_zip(['docs/README.md'=>'# valid','docs/image.svg'=>'<svg/>']);$service->syncArchive($repo->source(),$svg,str_repeat('f',40));wiki_sync_assert($repo->asset('image.svg')===null,'SVG must not be imported in Wiki v1.');
    $indexOnly=wiki_sync_zip(['docs/index.md'=>'# Index','docs/README.markdown'=>'# Secondary']);$service->syncArchive($repo->source(),$indexOnly,str_repeat('g',40));wiki_sync_assert($repo->rootPage()['relative_path']==='index.md','index.md must be preferred before supported .markdown root files.');
    $markdownOnly=wiki_sync_zip(['docs/README.markdown'=>'# Secondary']);$service->syncArchive($repo->source(),$markdownOnly,str_repeat('h',40));wiki_sync_assert($repo->rootPage()['relative_path']==='README.markdown','Supported .markdown root files must be usable.');
    $fallback=wiki_sync_zip(['docs/z-last.md'=>'# Z','docs/a-first.md'=>'# A']);$service->syncArchive($repo->source(),$fallback,str_repeat('i',40));wiki_sync_assert($repo->rootPage()['relative_path']==='a-first.md','First visible navigation page must be the deterministic final root fallback.');
    $titles=wiki_sync_zip(['docs/README.md'=>'# Start','docs/title-priority.md'=>"---\ntitle: Frontmatter-Titel\n---\n# Überschrift",'docs/heading-priority.md'=>'# Überschrift-Titel','docs/file-name.md'=>'Nur Text']);$service->syncArchive($repo->source(),$titles,str_repeat('k',40));wiki_sync_assert($repo->page('title-priority')['title']==='Frontmatter-Titel','Frontmatter titles must take priority over headings.');wiki_sync_assert($repo->page('heading-priority')['title']==='Überschrift-Titel','Headings must take priority over generated filenames.');wiki_sync_assert($repo->page('file-name')['title']==='File Name','Readable filenames must be the final title fallback.');
    $hidden=wiki_sync_zip(['docs/README.md'=>"---\nhidden: true\n---\n# Hidden"]);$service->syncArchive($repo->source(),$hidden,str_repeat('j',40));wiki_sync_assert($repo->rootPage()===null && $repo->visiblePageCount()===0,'No visible pages must lead to the Wiki empty state.');
    $renderer=new WikiMarkdownRenderer();$html=$renderer->render('[Nested](../README.md) ![Image](../images/logo.png) [Bad](data:text/html,x)','development/module-spec');wiki_sync_assert(str_contains($html,'/wiki/'),'Relative links must render to local wiki routes.');wiki_sync_assert(!str_contains($html,'data:text'),'Unsafe schemes must be blocked.');
} finally { $server->exec('DROP DATABASE IF EXISTS `'.$database.'`'); if(is_dir($tmp)){system('rm -rf '.escapeshellarg($tmp));} }
fwrite(STDOUT,"Wiki sync smoke passed.\n");
