<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function phase3_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('phase3-csrf-routes-smoke-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokenManager = new CsrfTokenManager($session);
$token = $tokenManager->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokenManager))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');

$routes = [
    '/admin/modules/create', '/admin/modules/update', '/admin/modules/toggle', '/admin/modules/reorder', '/admin/modules/delete',
    '/admin/users/create', '/admin/users/update', '/admin/users/toggle-block', '/admin/users/delete',
    '/admin/settings/registration', '/admin/settings/registration/toggle',
    '/profil/update', '/profil/settings', '/profil/password',
    '/admin/pages/create', '/admin/pages/update', '/admin/pages/delete', '/admin/pages/toggle', '/admin/pages/move',
    '/admin/news/create', '/admin/news/update', '/admin/news/delete',
];

foreach ($routes as $path) {
    $router->post($path, $handler, 'admin');
}

/** @param array<string, string> $input @param array<string, string> $headers */
$dispatch = static function (string $path, array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request('POST', $path, $input, [], [], $headers));
    ob_start();
    $response->send();
    $body = ob_get_clean();

    return [http_response_code(), is_string($body) ? $body : ''];
};

foreach ($routes as $path) {
    [$status, $body] = $dispatch($path, ['_csrf' => $token]);
    phase3_csrf_assert($status === 200 && $body === 'handled', $path . ' akzeptiert keinen gültigen Token.');

    [$status] = $dispatch($path);
    phase3_csrf_assert($status === 419, $path . ' wird ohne Token nicht blockiert.');

    [$status] = $dispatch($path, ['_csrf' => str_repeat('f', 64)]);
    phase3_csrf_assert($status === 419, $path . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('/admin/modules/toggle', [], [
    'X-CSRF-Token' => $token,
    'Accept' => 'application/json',
]);
phase3_csrf_assert($status === 200 && $body === 'handled', 'Admin-JSON-Aktion akzeptiert X-CSRF-Token nicht.');
[$status, $body] = $dispatch('/admin/pages/move', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
phase3_csrf_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'Pages-JSON-Aktion liefert keine zentrale JSON-419-Antwort.',
);

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokenManager->token();
[$status] = $dispatch('/admin/users/delete', ['_csrf' => $token]);
phase3_csrf_assert($status === 419, 'Admin-Aktion akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch('/admin/users/delete', ['_csrf' => $otherSessionToken]);
phase3_csrf_assert($status === 200 && $body === 'handled', 'Admin-Aktion akzeptiert den Token der aktuellen Session nicht.');

fwrite(STDOUT, "Phase 3 CSRF routes smoke test passed.\n");
