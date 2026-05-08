<?php

declare(strict_types=1);

namespace Modulon\Core;

interface NativeModuleInterface
{
    /**
     * @return array{
     *   key:string,
     *   name:string,
     *   route_prefix:string,
     *   access_level:string,
     *   description?:string,
     *   show_in_header?:bool,
     *   show_on_home?:bool,
     *   core?:bool
     * }
     */
    public static function metadata(): array;

    public static function create(ModuleContext $context): ?NativeModuleInterface;

    public function key(): string;

    public function routePrefix(): string;

    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void;

    public function registerRoutes(Router $router): void;

    public function registerAdminRoutes(Router $router): void;

    /**
     * @return array{module_key:string,internal_name:string,controller:string,implementation_path:string,route_binding:string}
     */
    public function nativeBinding(): array;
}
