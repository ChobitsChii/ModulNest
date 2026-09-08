<?php

declare(strict_types=1);

use Modulon\Core\View;
use Modulon\Modules\Updates\UpdateChannel;
use Modulon\Modules\Updates\UpdatesService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function update_channel_assert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

function update_channel_metadata(string $version): string
{
    return json_encode([
        'latest'=>$version, 'channel'=>str_contains($version, '-')?'prerelease':'stable', 'php_requirement'=>'^8.3',
        'packages'=>['bundled'=>['url'=>'https://github.com/ChobitsChii/ModulNest/releases/download/v'.$version.'/modulnest-bundled-'.$version.'.zip','sha256'=>str_repeat('a',64),'needs_composer'=>false]],
        'changelog_url'=>'https://github.com/ChobitsChii/ModulNest/releases/tag/v'.$version, 'requires_migrations'=>false,
    ], JSON_THROW_ON_ERROR);
}

function update_channel_service(string $stable, string $preview): array
{
    $base=sys_get_temp_dir().'/modulnest-update-channel-'.bin2hex(random_bytes(5)); mkdir($base.'/storage',0775,true);
    $fetcher=static fn(string $url):string => str_ends_with($url,'prerelease.json') ? $preview : $stable;
    return [new UpdatesService($base,null,$fetcher),$base];
}

function update_channel_remove(string $path): void
{
    if (!is_dir($path)) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());} rmdir($path);
}

update_channel_assert(UpdateChannel::normalize(null)===UpdateChannel::STABLE, 'Bestehende Installationen starten nicht auf Stable.');
[$service,$base]=update_channel_service(update_channel_metadata('1.3.0'),update_channel_metadata('2.0.0-rc.1'));
try {
    $upgrade=$service->check('1.2.0',UpdateChannel::STABLE);
    update_channel_assert($upgrade['latest']==='1.3.0'&&$upgrade['available']===true,'1.2.0 erkennt 1.3.0 nicht.');
    $stable=$service->check('1.3.0',UpdateChannel::STABLE);
    update_channel_assert($stable['latest']==='1.3.0'&&$stable['available']===false,'Stable berücksichtigt den synthetischen RC.');
    $preview=$service->check('1.3.0',UpdateChannel::PREVIEW);
    update_channel_assert($preview['latest']==='2.0.0-rc.1'&&$preview['available']===true,'Preview erkennt 2.0.0-rc.1 nicht.');
} finally { update_channel_remove($base); }

[$service,$base]=update_channel_service(update_channel_metadata('2.0.0'),update_channel_metadata('2.0.0-rc.1'));
try {
    $stableWins=$service->check('2.0.0-rc.1',UpdateChannel::PREVIEW);
    update_channel_assert($stableWins['latest']==='2.0.0'&&$stableWins['available']===true,'2.0.0 stable überholt 2.0.0-rc.1 nicht.');
} finally { update_channel_remove($base); }

foreach(['2.0.0-alpha.1','2.0.0-beta.1','2.0.0-rc.1'] as $candidate){
    [$service,$base]=update_channel_service(update_channel_metadata('1.3.0'),update_channel_metadata($candidate));
    try { update_channel_assert($service->fetchMetadata(UpdateChannel::PREVIEW)['latest']===$candidate,$candidate.' wird nicht als Vorabversion akzeptiert.'); }
    finally { update_channel_remove($base); }
}

[$service,$base]=update_channel_service(update_channel_metadata('1.3.0'),'{kaputt');
try {
    $fallback=$service->check('1.2.0',UpdateChannel::PREVIEW);
    update_channel_assert($fallback['latest']==='1.3.0'&&$fallback['available']===true,'Ungültiger Vorab-Feed zerstört die stabile Prüfung.');
} finally { update_channel_remove($base); }

$html=View::render('updates/admin',['status'=>['installed_version'=>'1.3.0','channel'=>'stable','update_channel'=>'preview','feed_url'=>UpdatesService::UPDATE_FEED_URL,'prerelease_feed_url'=>UpdatesService::PRERELEASE_FEED_URL,'state'=>[]],'csrf_token'=>'fixture']);
update_channel_assert(str_contains($html,'Stable + Vorabversionen')&&str_contains($html,'Vorabversionen aktiviert')&&str_contains($html,'name="_csrf"'),'Updatekanal-UI oder CSRF-Feld fehlt.');

fwrite(STDOUT,"Update channel smoke test passed.\n");
