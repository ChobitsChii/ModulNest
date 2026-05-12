<?php

declare(strict_types=1);

namespace Modulon\Modules\User;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Modules\FantasyCards\FantasyCardsProfileService;
use Modulon\Modules\FantasyCards\FantasyCardsRepository;

final class UserModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'profil',
            'name' => 'Profil',
            'route_prefix' => 'profil',
            'access_level' => 'user',
            'description' => 'Benutzerprofil und Einstellungen.',
            'show_in_header' => true,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $fantasyCardsProfile = null;
        if ($context->pdo !== null && $context->isNativeActive('fantasy-cards')) {
            $fantasyCardsProfile = new FantasyCardsProfileService(
                $context->pdo,
                new FantasyCardsRepository($context->pdo),
            );
        }

        $controller = new UserController(
            $context->service('authService'),
            $context->service('userRepository'),
            $context->session,
            $fantasyCardsProfile,
            $context->isNativeActive('data-portability'),
        );

        return new self($controller, $context->moduleAccess('profil', 'user'));
    }

    public function __construct(
        private readonly UserController $controller,
        private readonly string $access,
    ) {
    }

    public function key(): string
    {
        return 'profil';
    }

    public function routePrefix(): string
    {
        return 'profil';
    }

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void
    {
        $userNavigation->registerProvider(new UserNavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/profil', [$this->controller, 'profile'], $this->access);
        $router->get('/profil/*', [$this->controller, 'subRoute'], $this->access);
        $router->post('/profil/update', [$this->controller, 'updateProfile'], 'user');
        $router->post('/profil/password', [$this->controller, 'updatePassword'], 'user');
        $router->post('/profil/settings', [$this->controller, 'updateSettings'], 'user');
        $router->post('/profil/fantasy-cards', [$this->controller, 'updateFantasyCardsProfile'], 'user');
    }

    public function registerAdminRoutes(Router $router): void
    {
    }

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'profil',
            'internal_name' => 'User / Profil',
            'controller' => UserController::class,
            'implementation_path' => 'app/Modules/User/UserController.php',
        'route_binding' => 'GET /profil, GET /profil/*, POST /profil/update, POST /profil/password, POST /profil/settings, POST /profil/fantasy-cards',
        ];
    }
}
