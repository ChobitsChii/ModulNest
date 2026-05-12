<?php

declare(strict_types=1);

namespace Modulon\Modules\SneakPreview;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\HealthCheckProviderInterface;
use Modulon\Core\HealthCheckRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class SneakPreviewModule implements NativeModuleInterface, HealthCheckProviderInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'sneak-preview',
            'name' => 'Sneak Preview',
            'route_prefix' => 'sneak-preview',
            'access_level' => 'public',
            'description' => 'Öffentliche Sneak-Preview-Liste mit Modulon-Adminbereich.',
            'show_in_header' => true,
            'show_on_home' => true,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $repository = new SneakPreviewRepository($context->pdo);
        $controller = new SneakPreviewController(
            $repository,
            new SneakPreviewTmdbService($repository, $context->basePath),
            $context->session,
            $context->service('authService'),
        );

        return new self(
            $controller,
            $context->moduleRow('sneak-preview'),
            $context->moduleAccess('sneak-preview', 'public'),
        );
    }

    public function __construct(
        private readonly SneakPreviewController $controller,
        private readonly ?array $moduleRow,
        private readonly string $access,
    ) {
    }

    public function key(): string
    {
        return 'sneak-preview';
    }

    public function routePrefix(): string
    {
        return 'sneak-preview';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new SneakPreviewAdminNavigationProvider());
    }

    public function registerHealthChecks(HealthCheckRegistry $healthChecks): void
    {
        $healthChecks->addWritableDirectory(
            'dir_public_sneak_preview_posters',
            'Verzeichnis public/assets/sneak-preview/posters/',
            dirname(__DIR__, 3) . '/public/assets/sneak-preview/posters',
            'error',
        );
    }

    public function registerRoutes(Router $router): void
    {
        if (!$this->isNativeActive()) {
            return;
        }

        $router->get('/sneak-preview', [$this->controller, 'index'], $this->access);
        $router->get('/sneak-preview/*', [$this->controller, 'index'], $this->access);
    }

    public function registerAdminRoutes(Router $router): void
    {
        $router->get('/admin/sneak-preview', [$this->controller, 'adminIndex'], 'admin');
        $router->get('/admin/sneak-preview/*', [$this->controller, 'adminSubRoute'], 'admin');
        $router->post('/admin/sneak-preview/save', [$this->controller, 'save'], 'admin');
        $router->post('/admin/sneak-preview/delete', [$this->controller, 'delete'], 'admin');
        $router->post('/admin/sneak-preview/settings', [$this->controller, 'saveSettings'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'sneak-preview',
            'internal_name' => 'Sneak Preview',
            'controller' => SneakPreviewController::class,
            'implementation_path' => 'app/Modules/SneakPreview/SneakPreviewController.php',
            'route_binding' => 'GET /sneak-preview, GET /admin/sneak-preview, POST /admin/sneak-preview/*',
        ];
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
