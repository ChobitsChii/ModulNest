<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Modulon\Core\Modules\{DataSchemaConstraint,DependencyResolver,ModuleId,ModuleManifestReader,ModulePackageBuilder,ModulePackageInspector,PackageLimits,SafeArchiveExtractor,SemVer,VersionConstraint};
function v2_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function v2_throws(callable $fn,string $message):void{try{$fn();}catch(Throwable){return;}v2_assert(false,$message);}
$root=dirname(__DIR__,2);$fixture=$root.'/tests/Fixtures/module-packages-v2/example-notes-0.1.0';
foreach(['example.example-notes','modulnest.wiki','a.b'] as $id)v2_assert(ModuleId::isValid($id),"gültige ID abgelehnt: {$id}");
foreach(['Example.notes','example','example..notes','-x.notes','x.bad--name','x.bad_thing'] as $id)v2_assert(!ModuleId::isValid($id),"ungültige ID akzeptiert: {$id}");
v2_assert(SemVer::parse('1.0.0-alpha.1')->compare(SemVer::parse('1.0.0'))<0,'Prerelease-Reihenfolge falsch.');
v2_assert(VersionConstraint::parse('>=1.2.0 <2.0.0 || =3.0.0')->matches('1.9.9'),'AND/OR Range falsch.');
v2_assert(!VersionConstraint::parse('>=1.0.0')->matches('1.1.0-beta.1'),'Prerelease wurde implizit zugelassen.');
v2_assert(VersionConstraint::parse('=1.1.0-beta.1')->matches('1.1.0-beta.1'),'Exakter Prerelease fehlt.');
$alphaCoreRange = VersionConstraint::parse('>=2.0.0-alpha.1 <3.0.0');
foreach (['2.0.0-alpha.1', '2.0.0-alpha.2', '2.0.0-beta.1', '2.0.0-rc.1', '2.0.0', '2.1.0'] as $compatibleCore) {
    v2_assert($alphaCoreRange->matches($compatibleCore), 'Alpha-kompatible Core-Version wurde abgelehnt: ' . $compatibleCore);
}
foreach (['2.0.0-alpha.0', '3.0.0'] as $incompatibleCore) {
    v2_assert(!$alphaCoreRange->matches($incompatibleCore), 'Core-Version außerhalb der Alpha-Range wurde akzeptiert: ' . $incompatibleCore);
}
$stableOnlyRange = VersionConstraint::parse('>=2.0.0 <3.0.0');
v2_assert(!$stableOnlyRange->matches('2.1.0-beta.1') && $stableOnlyRange->matches('2.1.0'), 'Stable-only Constraint behandelt Prereleases nicht explizit restriktiv.');
v2_assert(DataSchemaConstraint::parse('>=1 <=2')->matches(2),'Datenschema-Range falsch.');
$manifest=(new ModuleManifestReader())->read($fixture.'/module.json');v2_assert($manifest->id==='example.example-notes'&&$manifest->schemaVersion===1,'Manifest falsch gelesen.');
v2_throws(fn()=>(new ModuleManifestReader())->read(__FILE__),'PHP wurde als Manifest akzeptiert.');
v2_throws(fn()=>VersionConstraint::parse('^1.0'),'nicht unterstützte Constraint-Syntax akzeptiert.');
v2_throws(fn()=>(new DependencyResolver())->order(['a'=>['b'],'b'=>['a']]),'Dependency-Zyklus akzeptiert.');
if(class_exists(ZipArchive::class)){$tmp=sys_get_temp_dir().'/modulnest-v2-'.bin2hex(random_bytes(6));mkdir($tmp);try{$zip=$tmp.'/example.zip';$zip2=$tmp.'/example-2.zip';$builder=new ModulePackageBuilder();$hash=$builder->build($fixture,$zip);$hash2=$builder->build($fixture,$zip2);v2_assert($hash===$hash2,'Paketbuild ist nicht reproduzierbar.');$info=(new ModulePackageInspector())->inspect($zip,$hash);v2_assert($info['manifest']->id===$manifest->id&&in_array('module.json',$info['files'],true),'deterministisches Paket nicht prüfbar.');v2_throws(fn()=>(new ModulePackageInspector())->inspect($zip,str_repeat('0',64)),'falscher Hash akzeptiert.');
foreach(['../escape','/absolute','C:\\windows','safe/../../escape'] as $index=>$badPath){$evil=$tmp.'/evil-'.$index.'.zip';$z=new ZipArchive();$z->open($evil,ZipArchive::CREATE);$z->addFromString($badPath,'x');$z->close();v2_throws(fn()=>(new SafeArchiveExtractor())->extract($evil,$tmp.'/out-'.$index),"unsicherer ZIP-Pfad akzeptiert: {$badPath}");}
$linkZip=$tmp.'/link.zip';$z=new ZipArchive();$z->open($linkZip,ZipArchive::CREATE);$z->addFromString('link','target');$z->setExternalAttributesName('link',ZipArchive::OPSYS_UNIX,0120777<<16);$z->close();v2_throws(fn()=>(new SafeArchiveExtractor())->extract($linkZip,$tmp.'/link-out'),'Symlink im ZIP akzeptiert.');
$bomb=$tmp.'/large.zip';$z=new ZipArchive();$z->open($bomb,ZipArchive::CREATE);$z->addFromString('large',str_repeat('x',1024));$z->close();v2_throws(fn()=>(new SafeArchiveExtractor(new PackageLimits(100000,100,10,100)))->extract($bomb,$tmp.'/large'),'Entpacklimit ignoriert.');
$badJson=$tmp.'/bad.json';file_put_contents($badJson,'{');v2_throws(fn()=>(new ModuleManifestReader())->read($badJson),'kaputtes JSON akzeptiert.');$badUtf=$tmp.'/utf.json';file_put_contents($badUtf,"\xFF");v2_throws(fn()=>(new ModuleManifestReader())->read($badUtf),'ungültiges UTF-8 akzeptiert.');$huge=$tmp.'/huge.json';file_put_contents($huge,str_repeat('x',ModuleManifestReader::MAX_BYTES+1));v2_throws(fn()=>(new ModuleManifestReader())->read($huge),'Manifestlimit ignoriert.');
}finally{\Modulon\Core\Modules\ModulePackageInspector::removeTree($tmp);}}
fwrite(STDOUT,"Module system v2 smoke passed.\n");
