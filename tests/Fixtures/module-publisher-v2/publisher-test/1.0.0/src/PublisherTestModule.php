<?php

declare(strict_types=1);

namespace ModulNest\PublisherTest;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class PublisherTestModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'modulnest.publisher-test',
            'name' => 'Publisher Test',
            'route_prefix' => 'publisher-test',
            'access_level' => 'admin',
            'description' => 'Lokales Publisher-Fixture.',
            'show_in_header' => false,
            'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        return new self();
    }

    public static function healthCheck(): bool
    {
        return true;
    }

    public function key(): string { return 'modulnest.publisher-test'; }
    public function routePrefix(): string { return 'publisher-test'; }
    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void {}
    public function registerRoutes(Router $router): void {}
    public function registerAdminRoutes(Router $router): void {}

    public function nativeBinding(): array
    {
        return [
            'module_key' => 'modulnest.publisher-test',
            'internal_name' => 'PublisherTest',
            'controller' => self::class,
            'implementation_path' => __FILE__,
            'route_binding' => '',
        ];
    }
}
