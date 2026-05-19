<?php

declare(strict_types=1);

namespace Modulon\Modules\Dashboard;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\HealthCheckProviderInterface;
use Modulon\Core\HealthCheckRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class DashboardModule implements NativeModuleInterface, HealthCheckProviderInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'dashboard',
            'name' => 'Dashboard',
            'route_prefix' => 'dashboard',
            'access_level' => 'user',
            'description' => 'Persönliches Dashboard.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $controller = new DashboardController(
            new DashboardRepository($context->pdo),
            $context->session,
            $context->service('authService'),
            $context->service('userRepository'),
        );

        return new self($controller, $context->moduleAccess('dashboard', 'user'));
    }

    public function __construct(
        private readonly DashboardController $controller,
        private readonly string $access,
    ) {
    }

    public function key(): string
    {
        return 'dashboard';
    }

    public function routePrefix(): string
    {
        return 'dashboard';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
    }

    public function registerHealthChecks(HealthCheckRegistry $healthChecks): void
    {
        $healthChecks->addWritableDirectory(
            'dir_storage_favicons',
            'Verzeichnis storage/favicons/',
            dirname(__DIR__, 3) . '/storage/favicons',
            'error',
        );
        $healthChecks->addWritableDirectory(
            'dir_public_favicons',
            'Verzeichnis public/assets/favicons/',
            dirname(__DIR__, 3) . '/public/assets/favicons',
            'error',
        );
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/dashboard', [$this->controller, 'index'], $this->access);
        $router->get('/dashboard/favicons/*', [$this->controller, 'serveFavicon'], 'user');
        $router->post('/dashboard/links/analyze', [$this->controller, 'analyzeLink'], 'user');
        $router->post('/dashboard/links/save', [$this->controller, 'storeLink'], 'user');
        $router->post('/dashboard/links/update', [$this->controller, 'updateLink'], 'user');
        $router->post('/dashboard/links/delete', [$this->controller, 'deleteLink'], 'user');
        $router->post('/dashboard/links/folders/create', [$this->controller, 'createFolder'], 'user');
        $router->post('/dashboard/widgets/create', [$this->controller, 'createWidget'], 'user');
        $router->post('/dashboard/widgets/update', [$this->controller, 'updateWidget'], 'user');
        $router->post('/dashboard/widgets/reorder', [$this->controller, 'reorderWidgets'], 'user');
        $router->post('/dashboard/widgets/delete', [$this->controller, 'deleteWidget'], 'user');
        $router->post('/dashboard/tasks/create', [$this->controller, 'createTask'], 'user');
        $router->post('/dashboard/tasks/update', [$this->controller, 'updateTask'], 'user');
        $router->post('/dashboard/tasks/delete', [$this->controller, 'deleteTask'], 'user');
        $router->post('/dashboard/tasks/toggle', [$this->controller, 'toggleTask'], 'user');
        $router->post('/dashboard/tasks/archive', [$this->controller, 'archiveTask'], 'user');
        $router->post('/dashboard/settings/auto-refresh', [$this->controller, 'updateAutoRefreshSettings'], 'user');
        $router->post('/dashboard/notes/create', [$this->controller, 'createNote'], 'user');
        $router->post('/dashboard/notes/update', [$this->controller, 'updateNote'], 'user');
        $router->post('/dashboard/notes/delete', [$this->controller, 'deleteNote'], 'user');
        $router->post('/dashboard/notes/archive', [$this->controller, 'archiveNote'], 'user');
    }

    public function registerAdminRoutes(Router $router): void
    {
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'dashboard',
            'internal_name' => 'Dashboard',
            'controller' => DashboardController::class,
            'implementation_path' => 'app/Modules/Dashboard/DashboardController.php',
            'route_binding' => 'GET /dashboard, POST /dashboard/widgets/*, POST /dashboard/links/*, POST /dashboard/tasks/create, POST /dashboard/tasks/toggle, POST /dashboard/tasks/archive, POST /dashboard/notes/*',
        ];
    }
}
