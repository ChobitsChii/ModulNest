<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function auth_csrf_routes_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('auth-csrf-routes-smoke-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokenManager = new CsrfTokenManager($session);
$token = $tokenManager->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokenManager))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');

$authRoutes = [
    '/login' => 'public',
    '/login/2fa/totp' => 'public',
    '/login/2fa/recovery' => 'public',
    '/webauthn/login/options' => 'public',
    '/webauthn/login/verify' => 'public',
    '/internal/register' => 'public',
    '/logout' => 'public',
    '/account/security/totp/start' => 'user',
    '/account/security/totp/confirm' => 'user',
    '/account/security/totp/disable' => 'user',
    '/account/security/recovery/regenerate' => 'user',
    '/account/security/webauthn/options' => 'user',
    '/account/security/webauthn/verify' => 'user',
    '/account/security/webauthn/delete' => 'user',
    '/profil/password' => 'user',
];
foreach ($authRoutes as $path => $access) {
    $router->post($path, $handler, $access);
}

/** @param array<string, string> $input @param array<string, string> $headers */
$dispatch = static function (string $path, array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request('POST', $path, $input, [], [], $headers));
    ob_start();
    $response->send();
    $body = ob_get_clean();

    return [http_response_code(), is_string($body) ? $body : ''];
};

foreach (array_keys($authRoutes) as $path) {
    [$status, $body] = $dispatch($path, ['_csrf' => $token]);
    auth_csrf_routes_assert($status === 200 && $body === 'handled', $path . ' akzeptiert keinen gültigen Formular-Token.');

    [$status] = $dispatch($path);
    auth_csrf_routes_assert($status === 419, $path . ' wird ohne Token nicht vom zentralen Guard blockiert.');

    [$status] = $dispatch($path, ['_csrf' => str_repeat('f', 64)]);
    auth_csrf_routes_assert($status === 419, $path . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('/webauthn/login/options', [], [
    'X-CsRf-ToKeN' => $token,
    'Accept' => 'application/json',
]);
auth_csrf_routes_assert($status === 200 && $body === 'handled', 'WebAuthn Options akzeptiert X-CSRF-Token nicht.');
[$status, $body] = $dispatch('/account/security/webauthn/verify', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
auth_csrf_routes_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'WebAuthn Verify liefert bei fehlendem Token keine JSON-419-Antwort.',
);

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokenManager->token();
[$status] = $dispatch('/login', ['_csrf' => $token]);
auth_csrf_routes_assert($status === 419, 'Login akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch('/login', ['_csrf' => $otherSessionToken]);
auth_csrf_routes_assert($status === 200 && $body === 'handled', 'Login akzeptiert den Token der aktuellen Session nicht.');

fwrite(STDOUT, "Auth CSRF routes smoke test passed.\n");
