<?php

declare(strict_types=1);

namespace Modulon\Core;

use RuntimeException;

final class View
{
    /**
     * @var null|callable(array<string, mixed>): array<string, mixed>
     */
    private static $composer = null;

    /**
     * Rendert eine View in das zentrale Layout.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        $viewsPath = dirname(__DIR__) . '/Views';
        $templatePath = $viewsPath . '/' . trim($template, '/') . '.php';
        $layoutPath = $viewsPath . '/layouts/app.php';

        if (!is_file($templatePath)) {
            throw new RuntimeException('View nicht gefunden: ' . $template);
        }

        if (!is_file($layoutPath)) {
            throw new RuntimeException('Layout nicht gefunden.');
        }

        $data = array_merge([
            'title' => 'Modulon',
            'auth' => [
                'is_authenticated' => false,
                'is_admin' => false,
                'user_name' => '',
            ],
            'current_path' => '/',
        ], $data);

        if (is_callable(self::$composer)) {
            $composed = (self::$composer)($data);
            if (is_array($composed)) {
                // Globaler Composer ist die zentrale Quelle für Layout-/Navbar-Zustand.
                $data = array_merge($data, $composed);
            }
        }

        $content = self::capture($templatePath, $data);
        $layoutData = array_merge($data, ['content' => $content]);

        return self::capture($layoutPath, $layoutData);
    }

    /**
     * Setzt einen globalen Composer für View-Daten.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $composer
     */
    public static function setComposer(callable $composer): void
    {
        self::$composer = $composer;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function capture(string $filePath, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require $filePath;
        $output = ob_get_clean();

        return is_string($output) ? $output : '';
    }
}
