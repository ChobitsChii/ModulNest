<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function phase4_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('phase4-csrf-routes-smoke-' . bin2hex(random_bytes(4)));
$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');

$routes = [
    '/dashboard/links/analyze', '/dashboard/links/save', '/dashboard/links/update', '/dashboard/links/delete', '/dashboard/links/folders/create',
    '/dashboard/widgets/create', '/dashboard/widgets/update', '/dashboard/widgets/reorder', '/dashboard/widgets/delete',
    '/dashboard/tasks/create', '/dashboard/tasks/update', '/dashboard/tasks/delete', '/dashboard/tasks/toggle', '/dashboard/tasks/archive',
    '/dashboard/settings/auto-refresh', '/dashboard/notes/create', '/dashboard/notes/update', '/dashboard/notes/delete', '/dashboard/notes/archive',
    '/mail/accounts', '/mail/accounts/1/update', '/mail/accounts/1/folders/refresh', '/mail/accounts/1/senders/include',
    '/mail/messages/send', '/mail/messages/whitelist',
];
foreach ($routes as $path) {
    $router->post($path, $handler, 'user');
}

/** @param array<string, string> $input @param array<string, string> $headers */
$dispatch = static function (string $path, array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request('POST', $path, $input, [], [], $headers));
    ob_start();
    $response->send();
    return [http_response_code(), (string) ob_get_clean()];
};

foreach ($routes as $path) {
    [$status, $body] = $dispatch($path, ['_csrf' => $token]);
    phase4_csrf_assert($status === 200 && $body === 'handled', $path . ' akzeptiert keinen gültigen Token.');
    [$status] = $dispatch($path);
    phase4_csrf_assert($status === 419, $path . ' wird ohne Token nicht blockiert.');
}
[$status, $body] = $dispatch('/dashboard/widgets/update', [], ['X-CSRF-Token' => $token, 'Accept' => 'application/json']);
phase4_csrf_assert($status === 200 && $body === 'handled', 'Dashboard-JSON akzeptiert keinen Header-Token.');
[$status, $body] = $dispatch('/mail/accounts/1/folders/refresh', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
phase4_csrf_assert($status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid', 'Mail-JSON liefert keine zentrale 419-Antwort.');
$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokens->token();
[$status] = $dispatch('/dashboard/tasks/archive', ['_csrf' => $token]);
phase4_csrf_assert($status === 419, 'Dashboard akzeptiert einen sessionfremden Token.');
[$status, $body] = $dispatch('/mail/messages/send', ['_csrf' => $otherSessionToken]);
phase4_csrf_assert($status === 200 && $body === 'handled', 'Mail akzeptiert den aktuellen Session-Token nicht.');

fwrite(STDOUT, "Phase 4 CSRF routes smoke test passed.\n");
