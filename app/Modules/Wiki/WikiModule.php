<?php

declare(strict_types=1);

namespace Modulon\Modules\Wiki;

use Modulon\Core\{AdminNavigationRegistry, ModuleContext, ModuleSubnavigationRegistry, NativeModuleInterface, Router, UserNavigationRegistry};

final class WikiModule implements NativeModuleInterface
{
    public static function metadata(): array { return ['key'=>'wiki','name'=>'Wiki','route_prefix'=>'wiki','access_level'=>'user','description'=>'Synchronisierte Markdown-Dokumentation aus einem öffentlichen GitHub-Repository.','show_in_header'=>true,'show_on_home'=>false]; }
    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) return null;
        $repository = new WikiRepository($context->pdo);
        $service = new WikiService($repository, $context->pdo, $context->basePath, new GitHubWikiClient());
        $auth = $context->service('authService');
        return new self(new WikiController($context->session, $repository, $service, $context->basePath, $auth instanceof \Modulon\Modules\Auth\AuthService ? $auth : null));
    }
    public function __construct(private readonly WikiController $controller) {}
    public function key(): string { return 'wiki'; }
    public function routePrefix(): string { return 'wiki'; }
    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void { $adminNavigation->registerProvider(new WikiAdminNavigationProvider()); }
    public function registerRoutes(Router $router): void { $router->get('/wiki', [$this->controller,'index'],'user'); $router->get('/wiki/assets/*',[$this->controller,'asset'],'user'); $router->get('/wiki/*',[$this->controller,'page'],'user'); }
    public function registerAdminRoutes(Router $router): void { $router->get('/admin/wiki',[$this->controller,'admin'],'admin');$router->post('/admin/wiki/save',[$this->controller,'adminSave'],'admin');$router->post('/admin/wiki/sync',[$this->controller,'sync'],'admin'); }
    public function nativeBinding(): array { return ['module_key'=>'wiki','internal_name'=>'Wiki','controller'=>WikiController::class,'implementation_path'=>'app/Modules/Wiki/WikiController.php','route_binding'=>'GET /wiki']; }
}
