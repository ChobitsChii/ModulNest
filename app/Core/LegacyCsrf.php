<?php

declare(strict_types=1);

namespace Modulon\Core;

/**
 * Bewusste CSRF-Bridge für eingebundene Legacy-PHP-Anwendungen.
 *
 * Legacy-Code verwendet damit denselben Session-Token wie native Module,
 * ohne sich auf Dispatcher-Globals verlassen zu müssen.
 */
final class LegacyCsrf
{
    public static function token(): string
    {
        return (new CsrfTokenManager(new Session()))->token();
    }

    public static function field(): string
    {
        return View::csrfField(self::token());
    }
}
