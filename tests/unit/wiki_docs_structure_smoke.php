<?php

declare(strict_types=1);

use Modulon\Core\Database\MigrationRunner;
use Modulon\Modules\Wiki\{GitHubWikiClient, WikiMarkdownRenderer, WikiNavigationBuilder, WikiRepository, WikiService};

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function wiki_docs_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
/** @return array<string,string> */
function wiki_docs_env(string $path): array { $result=[]; foreach(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$key,$value]=explode('=',$line,2);$result[trim($key)]=trim($value," \t\"'");} return $result; }
function wiki_docs_add(ZipArchive $zip, string $base, string $path): void { foreach (scandir($path) ?: [] as $item) { if ($item === '.' || $item === '..') continue; $full=$path.'/'.$item; $relative=substr($full, strlen($base) + 1); if (is_dir($full)) { wiki_docs_add($zip, $base, $full); } elseif (is_file($full)) { $zip->addFile($full, 'source-commit/docs/'.$relative); } } }

$root=dirname(__DIR__,2);$env=wiki_docs_env($root.'/.env');
$server=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';charset='.($env['DB_CHARSET']??'utf8mb4'),$env['DB_USER']??'', $env['DB_PASS']??'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='modulnest_wiki_docs_'.bin2hex(random_bytes(4));$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$tmp=sys_get_temp_dir().'/modulnest-wiki-docs-'.bin2hex(random_bytes(5));$archive=tempnam(sys_get_temp_dir(),'wiki-docs-');
try {
    $zip=new ZipArchive();wiki_docs_assert($zip->open($archive, ZipArchive::OVERWRITE)===true,'The local documentation archive must be created.');wiki_docs_add($zip,$root.'/docs',$root.'/docs');$zip->close();
    $server->exec('USE `'.$database.'`');(new MigrationRunner($server,$root))->run(['Wiki']);
    $repo=new WikiRepository($server);$service=new WikiService($repo,$server,$tmp,new GitHubWikiClient());$repo->saveSource($service->validateConfig('ChobitsChii','ModulNest','main','docs',true));
    $result=$service->syncArchive($repo->source(),(string)file_get_contents($archive),str_repeat('d',40));wiki_docs_assert($result['added'] > 10,'The real local documentation tree must synchronise as Wiki pages.');
    $rootPage=$repo->rootPage();wiki_docs_assert(($rootPage['relative_path']??'')==='README.md','docs/README.md must become the preferred Wiki root page.');
    foreach(['development/example-module','modules/wiki','releases/0.5.0','releases/0.9.0','releases/1.0.0','releases/1.0.1','releases/1.1.0','releases/1.1.1','technical/tech-architecture'] as $route) wiki_docs_assert($repo->page($route)!==null,"Expected synchronised documentation route missing: {$route}");
    $navigation=(new WikiNavigationBuilder())->build($repo->pages(),'releases/1.1.1','index');wiki_docs_assert(array_column($navigation['groups'],'label')===['Development','Modules','Releases','Technical'],'The docs tree must produce distinct Development, Modules, Releases and Technical Wiki groups.');$releaseGroup=array_values(array_filter($navigation['groups'],static fn(array $group): bool => $group['label']==='Releases'))[0]??[];wiki_docs_assert(($releaseGroup['is_active']??false)===true&&($releaseGroup['landing']['route_path']??'')==='releases'&&in_array('ModulNest 1.1.1',array_column($releaseGroup['pages']??[],'title'),true),'Release landing pages must not be duplicated as children and releases must remain active.');
    $html=(new WikiMarkdownRenderer())->renderPage((string)file_get_contents($tmp.'/storage/wiki/content/README.md'),'README.md',(string)$rootPage['title']);wiki_docs_assert(str_contains($html['html'],'href="/wiki/development/example-module"')&&str_contains($html['html'],'href="/wiki/releases"'),'The documentation start page must link to the new Developer and Releases Wiki routes.');
} finally { if (is_file($archive)) unlink($archive);$server->exec('DROP DATABASE IF EXISTS `'.$database.'`');if(is_dir($tmp))system('rm -rf '.escapeshellarg($tmp)); }
fwrite(STDOUT,"Wiki documentation structure smoke passed.\n");
