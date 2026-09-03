<?php

declare(strict_types=1);

namespace Modulon\Modules\ExampleNotes;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\RotatingFileLogger;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Modules\Admin\AppSettingRepository;

final class ExampleNotesModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'example-notes',
            'name' => 'Example Notes',
            'route_prefix' => 'example-notes',
            'access_level' => 'user',
            'description' => 'Kleines Referenzmodul für die native Modul-API.',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $settings = $context->service('appSettingRepository');
        if (!$context->pdo instanceof \PDO || !$settings instanceof AppSettingRepository) {
            return null;
        }

        $repository = new ExampleNotesRepository($context->pdo);
        $service = new ExampleNotesService($repository, $settings, new RotatingFileLogger($context->basePath));

        return new self(
            new ExampleNotesController($service, $context->service('authService'), $context->session),
            $context->moduleRow('example-notes'),
        );
    }

    public function __construct(
        private readonly ExampleNotesController $controller,
        private readonly ?array $moduleRow,
    ) {
    }

    public function key(): string { return 'example-notes'; }
    public function routePrefix(): string { return 'example-notes'; }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $userNavigation->registerProvider(new ExampleNotesUserNavigationProvider());
        $adminNavigation->registerProvider(new ExampleNotesAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        if (!$this->isActive()) { return; }
        $router->get('/example-notes', [$this->controller, 'index'], 'user');
        $router->post('/example-notes/create', [$this->controller, 'create'], 'user');
        $router->post('/example-notes/toggle', [$this->controller, 'toggle'], 'user');
    }

    public function registerAdminRoutes(Router $router): void
    {
        if (!$this->isActive()) { return; }
        $router->get('/admin/example-notes', [$this->controller, 'admin'], 'admin');
        $router->post('/admin/example-notes/settings', [$this->controller, 'saveSettings'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'example-notes',
            'internal_name' => 'ExampleNotes',
            'controller' => ExampleNotesController::class,
            'implementation_path' => 'app/Modules/ExampleNotes/ExampleNotesController.php',
            'route_binding' => 'GET /example-notes, POST /example-notes/create, POST /example-notes/toggle, GET /admin/example-notes, POST /admin/example-notes/settings',
        ];
    }

    private function isActive(): bool
    {
        return is_array($this->moduleRow) && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
