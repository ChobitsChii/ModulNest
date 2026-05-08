<?php

declare(strict_types=1);

use Modulon\Core\Env;

$secureCookieRaw = strtolower((string) Env::get('REMEMBER_COOKIE_SECURE', 'false'));
$secureCookie = in_array($secureCookieRaw, ['1', 'true', 'yes', 'on'], true);
$publicRegistrationRaw = strtolower((string) Env::get('PUBLIC_REGISTRATION_ENABLED', 'true'));
$publicRegistrationEnabled = in_array($publicRegistrationRaw, ['1', 'true', 'yes', 'on'], true);

return [
    // Registrierung
    'public_registration_enabled' => $publicRegistrationEnabled,

    // Session-Limits
    'session_idle_timeout' => (int) Env::get('SESSION_IDLE_TIMEOUT', '1800'),
    'session_absolute_timeout' => (int) Env::get('SESSION_ABSOLUTE_TIMEOUT', '28800'),

    // Remember-Me
    'remember_cookie_name' => Env::get('REMEMBER_COOKIE_NAME', 'modulon_remember'),
    'remember_token_lifetime' => (int) Env::get('REMEMBER_TOKEN_LIFETIME', '1209600'),
    'remember_cookie_secure' => $secureCookie,
    'remember_cookie_samesite' => Env::get('REMEMBER_COOKIE_SAMESITE', 'Lax'),

    // 2FA
    'totp_issuer' => Env::get('TOTP_ISSUER', 'Modulon'),
    'webauthn_rp_name' => Env::get('WEBAUTHN_RP_NAME', 'Modulon'),
    'webauthn_rp_id' => Env::get('WEBAUTHN_RP_ID', ''),
];
