<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\Modules\Catalog\CatalogService;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Modules\Admin\AppSettingRepository;
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
        $settings = $context->service('appSettingRepository');
        $catalogService = $context->service('catalogService');
        $controller = new UpdatesController(
            new UpdatesService($context->basePath, $context->pdo),
            $context->session,
            (string) $context->config('app_version', ''),
            (string) $context->config('app_channel', 'alpha'),
            $authService instanceof AuthService ? $authService : null,
            $settings instanceof AppSettingRepository ? $settings : null,
            $catalogService instanceof CatalogService ? $catalogService : null,
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
        $router->post('/admin/updates/channel', [$this->controller, 'updateChannelSetting'], 'admin');
        $router->post('/admin/updates/sources/add', [$this->controller, 'addSource'], 'admin');
        $router->post('/admin/updates/sources/activate', [$this->controller, 'activateSource'], 'admin');
        $router->post('/admin/updates/sources/delete', [$this->controller, 'deleteSource'], 'admin');
        $router->post('/admin/updates/sync-mirror', [$this->controller, 'syncMirror'], 'admin');
        $router->get('/admin/updates/mirror-status', [$this->controller, 'mirrorStatus'], 'admin');
        $router->get('/admin/updates/backup-db', [$this->controller, 'downloadDatabaseBackup'], 'admin');
        $router->post('/admin/updates/backup-db', [$this->controller, 'downloadDatabaseBackup'], 'admin');
        $router->post('/admin/updates/backups/delete', [$this->controller, 'deleteBackup'], 'admin');
        $router->get('/admin/api/updates/status', [$this->controller, 'notificationStatus'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'updates',
            'internal_name' => 'Updates',
            'controller' => UpdatesController::class,
            'implementation_path' => 'app/Modules/Updates/UpdatesController.php',
            'route_binding' => 'GET /admin/updates, POST /admin/updates/check, POST /admin/updates/prepare, POST /admin/updates/install, POST /admin/updates/channel, POST /admin/updates/sync-mirror, GET /admin/updates/mirror-status',
        ];
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
