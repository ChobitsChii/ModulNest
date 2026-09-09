<?php

declare(strict_types=1);

use Modulon\Core\Modules\ModuleManagementColumns;
use Modulon\Core\Modules\ModulePresentation;
use Modulon\Core\Request;
use Modulon\Core\Router;
use Modulon\Core\Session;
use Modulon\Modules\Admin\AdminController;
use Modulon\Modules\Auth\UserRepository;
use Modulon\Modules\Modules\ModuleRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function management_ux_assert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$router = new Router();
$ok = static fn (Request $request): \Modulon\Core\Response => new \Modulon\Core\Response('ok');
$router->get('/wiki', $ok, 'user'); $router->get('/admin/wiki', $ok, 'admin');
$router->get('/news', $ok); $router->get('/admin/news', $ok, 'admin');
$router->get('/admin/logs', $ok, 'admin'); $router->get('/dashboard', $ok, 'user');

$controller = new AdminController(null, null, null, new Session(), null, null, null, [], null, null, $router);
$present = new ReflectionMethod($controller, 'presentModules');
$rows = [
    ['id'=>1,'name'=>'Wiki','route_prefix'=>'wiki','access_level'=>'user','handler'=>'native','module_key'=>'modulnest.wiki','origin'=>'catalog-managed','installed_version'=>'1.3.0'],
    ['id'=>2,'name'=>'News','route_prefix'=>'news','access_level'=>'public','handler'=>'native','module_key'=>'modulnest.news','origin'=>'catalog-managed','installed_version'=>'1.2.0'],
    ['id'=>3,'name'=>'Logs','route_prefix'=>'logs','access_level'=>'admin','handler'=>'native','module_key'=>'modulnest.logs','origin'=>'catalog-managed','installed_version'=>'1.2.0'],
    ['id'=>4,'name'=>'Dashboard','route_prefix'=>'dashboard','access_level'=>'user','handler'=>'native','module_key'=>'modulnest.dashboard','origin'=>'catalog-managed','installed_version'=>'1.3.0'],
];
$presented = $present->invoke($controller, $rows);
management_ux_assert($presented[0]['module_url']==='/wiki' && $presented[0]['module_admin_url']==='/admin/wiki', 'Wiki benötigt App- und Admin-Link.');
management_ux_assert($presented[1]['module_url']==='/news' && $presented[1]['module_admin_url']==='/admin/news', 'News benötigt App- und Admin-Link.');
management_ux_assert($presented[2]['module_url']===null && $presented[2]['module_admin_url']==='/admin/logs', 'Logs darf keinen erfundenen App-Link haben.');
management_ux_assert($presented[3]['module_url']==='/dashboard' && $presented[3]['module_admin_url']===null, 'Dashboard benötigt ausschließlich den App-Link.');

management_ux_assert(ModulePresentation::type(['handler'=>'native','route_prefix'=>'wiki','module_key'=>null])==='v1', 'Native Bestand ist nicht Modul v1.');
management_ux_assert(ModulePresentation::type(['handler'=>'native','route_prefix'=>'profil','module_key'=>null])==='core', 'Core wird nicht erkannt.');
management_ux_assert(ModulePresentation::type(['handler'=>'legacy','route_prefix'=>'old','module_key'=>null])==='legacy', 'Legacy wird nicht erkannt.');
management_ux_assert(ModulePresentation::type(['handler'=>'placeholder','route_prefix'=>'later','module_key'=>null])==='placeholder', 'Placeholder wird nicht erkannt.');
management_ux_assert(ModulePresentation::typeBadgeClass('v2')==='text-bg-primary' && ModulePresentation::typeBadgeClass('legacy')==='text-bg-warning', 'Typ-Badges entsprechen nicht der Katalogsemantik.');
management_ux_assert(count(array_unique([ModulePresentation::accessBadgeClass('public'),ModulePresentation::accessBadgeClass('user'),ModulePresentation::accessBadgeClass('admin')]))===3, 'Access-Badges sind nicht unterscheidbar.');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE modules (id INTEGER PRIMARY KEY, module_key TEXT NULL, name TEXT, description TEXT, route_prefix TEXT, access_level TEXT, handler TEXT, legacy_entry TEXT, admin_entry TEXT, enable_overlay INTEGER, is_active INTEGER, sort_order INTEGER, show_in_header INTEGER, show_on_home INTEGER, updated_at TEXT)');
$pdo->exec("INSERT INTO modules VALUES(1,NULL,'Fixture','fixture','fixture','user','placeholder',NULL,NULL,0,1,10,0,0,NULL)");
$repository = new ModuleRepository($pdo);
$flagController = new AdminController($repository, null, null, new Session());
foreach (['show_in_header','show_on_home','is_active'] as $field) {
    $response = $flagController->toggleModuleFlags(new Request('POST','/admin/modules/toggle',['module_id'=>1,'field'=>$field,'enabled'=>1],[],[]));
    ob_start(); $response->send(); $json = json_decode((string) ob_get_clean(), true);
    management_ux_assert(($json['ok']??false)===true, $field . ' konnte nicht inline gespeichert werden.');
}
$state=$pdo->query('SELECT show_in_header,show_on_home,is_active FROM modules WHERE id=1')->fetch();
management_ux_assert($state===['show_in_header'=>1,'show_on_home'=>1,'is_active'=>1], 'Inline-Schalter speichern nicht dieselbe Registry-Wahrheit.');

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, module_management_columns TEXT NULL, updated_at TEXT)');
$pdo->exec('INSERT INTO users VALUES(1,NULL,NULL),(2,NULL,NULL)');
$users = new UserRepository($pdo);
$users->updateModuleManagementColumns(1, ['type','links']);
management_ux_assert($users->moduleManagementColumns(1)===['type','links'], 'Spaltenpräferenz wird nicht gespeichert/geladen.');
management_ux_assert($users->moduleManagementColumns(2)===ModuleManagementColumns::DEFAULTS, 'Spaltenpräferenz ist nicht benutzerspezifisch.');
$users->updateModuleManagementColumns(1, null);
management_ux_assert($users->moduleManagementColumns(1)===ModuleManagementColumns::DEFAULTS, 'Standard kann nicht wiederhergestellt werden.');

$view=(string)file_get_contents(dirname(__DIR__,2).'/app/Views/admin/modules.php');
$catalog=(string)file_get_contents(dirname(__DIR__,2).'/app/Views/admin/module-catalog/index.php');
$edit=(string)file_get_contents(dirname(__DIR__,2).'/app/Views/admin/module-edit.php');
management_ux_assert(str_contains($view,'Manuellen Moduleintrag anlegen') && !str_contains($view,'<option value="native">'), 'Anlege-UX bietet weiterhin Modul v1 an.');
management_ux_assert(str_contains($view,'module-columns-reset') && str_contains($view,'data-column="header"'), 'Spaltenauswahl ist unvollständig.');
management_ux_assert(str_contains($catalog,'ModulePresentation::typeBadgeClass') && str_contains($view,'module_type_badge'), 'Katalog und Verwaltung teilen die Badge-Semantik nicht.');
management_ux_assert(str_contains($edit,'$protected') && str_contains($edit,'Katalogdetails'), 'Managed-v2-Felder sind nicht geschützt.');

fwrite(STDOUT, "Module management UX smoke test passed.\n");
