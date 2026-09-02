<?php

declare(strict_types=1);

namespace Modulon\Core;

/** Einheitliche, CSP-freie Mindest-Header für alle HTTP-Antworten. */
final class SecurityHeaders
{
    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Same-origin Mail-/Legacy-Frames bleiben bewusst möglich.
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];
    }

    public static function apply(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach (self::defaults() as $name => $value) {
            header($name . ': ' . $value, false);
        }
    }
}
