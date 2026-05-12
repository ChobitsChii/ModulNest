<?php

declare(strict_types=1);

namespace Modulon\Modules\DataPortability;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\DataPortability\Providers\BankingDataPortabilityProvider;
use Modulon\Modules\DataPortability\Providers\DashboardDataPortabilityProvider;
use Modulon\Modules\DataPortability\Providers\NewsDataPortabilityProvider;
use Modulon\Modules\DataPortability\Providers\SneakPreviewDataPortabilityProvider;

final class DataPortabilityModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'data-portability',
            'name' => 'Export / Import',
            'route_prefix' => 'data-portability',
            'access_level' => 'admin',
            'description' => 'Moduldaten sicher zwischen ModulNest-Instanzen exportieren und importieren.',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) {
            return null;
        }

        $authService = $context->service('authService');
        $providers = [];
        if ($context->isNativeActive('dashboard')) {
            $providers['dashboard'] = new DashboardDataPortabilityProvider($context->pdo);
        }
        if ($context->isNativeActive('banking')) {
            $providers['banking'] = new BankingDataPortabilityProvider($context->pdo);
        }
        if ($context->isNativeActive('news')) {
            $providers['news'] = new NewsDataPortabilityProvider($context->pdo);
        }
        if ($context->isNativeActive('sneak-preview')) {
            $providers['sneak'] = new SneakPreviewDataPortabilityProvider($context->pdo, $context->basePath);
        }

        $controller = new DataPortabilityController(
            new DataPortabilityService(
                $context->basePath,
                (string) $context->config('app_version', '0.0.0'),
                $providers
            ),
            $context->session,
            $authService instanceof AuthService ? $authService : null,
            $context->isNativeActive('fantasy-cards'),
        );

        return new self($controller, $context->moduleRow('data-portability'), $context->isNativeActive('profil'));
    }

    public function __construct(
        private readonly DataPortabilityController $controller,
        private readonly ?array $moduleRow,
        private readonly bool $profileAvailable,
    ) {
    }

    public function key(): string
    {
        return 'data-portability';
    }

    public function routePrefix(): string
    {
        return 'data-portability';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $adminNavigation->registerProvider(new DataPortabilityAdminNavigationProvider());
        if ($this->profileAvailable) {
            $userNavigation->registerProvider(new DataPortabilityUserNavigationProvider());
        }
    }

    public function registerRoutes(Router $router): void
    {
        if (!$this->isNativeActive() || !$this->profileAvailable) {
            return;
        }

        $router->get('/profil/data-portability', [$this->controller, 'userIndex'], 'user');
        $router->post('/profil/data-portability/export', [$this->controller, 'userExport'], 'user');
        $router->post('/profil/data-portability/import/preview', [$this->controller, 'userPreviewImport'], 'user');
        $router->post('/profil/data-portability/import/run', [$this->controller, 'userRunImport'], 'user');
    }

    public function registerAdminRoutes(Router $router): void
    {
        if (!$this->isNativeActive()) {
            return;
        }

        $router->get('/admin/data-portability', [$this->controller, 'index'], 'admin');
        $router->post('/admin/data-portability/export', [$this->controller, 'export'], 'admin');
        $router->post('/admin/data-portability/import/preview', [$this->controller, 'previewImport'], 'admin');
        $router->post('/admin/data-portability/import/run', [$this->controller, 'runImport'], 'admin');
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'data-portability',
            'internal_name' => 'DataPortability',
            'controller' => DataPortabilityController::class,
            'implementation_path' => 'app/Modules/DataPortability/DataPortabilityController.php',
            'route_binding' => 'GET/POST /profil/data-portability/*, GET /admin/data-portability, POST /admin/data-portability/export, POST /admin/data-portability/import/preview, POST /admin/data-portability/import/run',
        ];
    }

    private function isNativeActive(): bool
    {
        return is_array($this->moduleRow)
            && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native';
    }
}
