<?php

declare(strict_types=1);

namespace Modulon\Core;

final class NativeModuleLoader
{
    /**
     * @var array<int, string>
     */
    private const CORE_MODULE_DIRECTORIES = ['Admin', 'Auth', 'Modules'];

    /**
     * @return array<string, class-string<NativeModuleInterface>>
     */
    public static function discover(string $basePath): array
    {
        $modulesPath = rtrim($basePath, '/') . '/app/Modules';
        if (!is_dir($modulesPath)) {
            return [];
        }

        $classes = [];
        $directories = glob($modulesPath . '/*', GLOB_ONLYDIR);
        if (!is_array($directories)) {
            return [];
        }

        foreach ($directories as $directory) {
            $name = basename($directory);
            if (in_array($name, self::CORE_MODULE_DIRECTORIES, true)) {
                continue;
            }

            $class = 'Modulon\\Modules\\' . $name . '\\' . $name . 'Module';
            if (!class_exists($class) || !is_subclass_of($class, NativeModuleInterface::class)) {
                continue;
            }

            /** @var class-string<NativeModuleInterface> $class */
            $metadata = $class::metadata();
            $prefix = trim((string) ($metadata['route_prefix'] ?? ''), '/');
            if ($prefix === '') {
                continue;
            }

            $classes[$prefix] = $class;
        }

        ksort($classes);
        return $classes;
    }

    /**
     * @return array<string, NativeModuleInterface>
     */
    public static function createActiveModules(string $basePath, ModuleContext $context): array
    {
        $modules = [];
        foreach (self::discover($basePath) as $prefix => $class) {
            if (!$context->isNativeActive($prefix)) {
                continue;
            }

            $module = $class::create($context);
            if ($module instanceof NativeModuleInterface) {
                $modules[$prefix] = $module;
            }
        }

        return $modules;
    }
}
