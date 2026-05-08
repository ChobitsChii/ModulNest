<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Modules\Auth\AuthService;

final class UpdatesModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'updates',
            'name' => 'Updates',
            'route_prefix' => 'updates',
            'access_level' => 'admin',
            'description' => 'Offizielle ModulNest-Updates prüfen, vorbereiten und installieren.',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $authService = $context->service('authService');
        $controller = new UpdatesController(
            new UpdatesService($context->basePath),
            $context->session,
            (string) $context->config('app_version', ''),
            (string) $context->config('app_channel', 'alpha'),
            $authService instanceof AuthService ? $authService : null,
        );

        return new self($controller, $context->moduleRow('updates'));
    }

    public function __construct(
        private readonly UpdatesController $controller,
        private readonly ?array $moduleRow,
    ) {
    }

    public function key(): string
    {
        return 'updates';
    }

    public function routePrefix(): string
    {
        return 'updates';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new UpdatesAdminNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        // Updates sind ausschließlich im Adminbereich verfügbar.
    }

    public function registerAdminRoutes(Router $router): void
    {
        if (!$this->isNativeActive()) {
            return;
        }

        $router->get('/admin/updates', [$this->controller, 'index'], 'admin');
        $router->post('/admin/updates/check', [$this->controller, 'check'], 'admin');
        $router->post('/admin/updates/prepare', [$this->controller, 'prepare'], 'admin');
        $router->post('/admin/updates/install', [$this->controller, 'install'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'updates',
            'internal_name' => 'Updates',
            'controller' => UpdatesController::class,
            'implementation_path' => 'app/Modules/Updates/UpdatesController.php',
            'route_binding' => 'GET /admin/updates, POST /admin/updates/check, POST /admin/updates/prepare, POST /admin/updates/install',
        ];
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
