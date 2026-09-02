<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\LegacyCsrf;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function legacy_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('legacy-csrf-smoke-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
legacy_csrf_assert(LegacyCsrf::token() === $token, 'LegacyCsrf nutzt nicht den zentralen Session-Token.');
legacy_csrf_assert(LegacyCsrf::field() === '<input type="hidden" name="_csrf" value="' . $token . '">', 'LegacyCsrf erzeugt kein kanonisches Hidden Field.');

$session->set(CsrfTokenManager::SESSION_KEY, '<legacy&"token>');
legacy_csrf_assert(
    LegacyCsrf::field() === '<input type="hidden" name="_csrf" value="&lt;legacy&amp;&quot;token&gt;">',
    'LegacyCsrf escaped den Tokenwert nicht HTML-sicher.'
);
$rotatedToken = $tokens->rotate();
legacy_csrf_assert(LegacyCsrf::token() === $rotatedToken, 'LegacyCsrf spiegelt eine Tokenrotation nicht wider.');

$router = new Router();
$router->setAccessGuard(static function (Request $request, string $access): ?Response {
    return $access === 'admin' ? new Response('access denied', 403) : null;
});
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('legacy handled');
foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
    $router->{$method}('/legacy/*', $handler, 'public');
}
$router->post('/legacy-admin/*', $handler, 'admin');

$dispatch = static function (string $method, string $path, array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, $input, [], [], $headers));
    ob_start();
    $response->send();

    return [http_response_code(), (string) ob_get_clean()];
};

[$status, $body] = $dispatch('GET', '/legacy/index.php');
legacy_csrf_assert($status === 200 && $body === 'legacy handled', 'Legacy-GET ist nicht frei erreichbar.');

foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    [$status, $body] = $dispatch($method, '/legacy/action.php', ['_csrf' => $rotatedToken]);
    legacy_csrf_assert($status === 200 && $body === 'legacy handled', $method . ' akzeptiert keinen gültigen zentralen Token.');

    [$status] = $dispatch($method, '/legacy/action.php');
    legacy_csrf_assert($status === 419, $method . ' wird ohne zentralen Token nicht blockiert.');

    [$status] = $dispatch($method, '/legacy/action.php', ['_csrf' => str_repeat('f', 64)]);
    legacy_csrf_assert($status === 419, $method . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('POST', '/legacy/action.php', [], [
    'X-CSRF-Token' => $rotatedToken,
    'Accept' => 'application/json',
]);
legacy_csrf_assert($status === 200 && $body === 'legacy handled', 'Legacy-POST akzeptiert keinen zentralen Header-Token.');
[$status, $body] = $dispatch('POST', '/legacy/action.php', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
legacy_csrf_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'Legacy-JSON-Anfrage liefert keine zentrale 419-Antwort.'
);

[$status, $body] = $dispatch('POST', '/legacy-admin/action.php');
legacy_csrf_assert($status === 403 && $body === 'access denied', 'Access-Guard läuft nicht vor dem CSRF-Guard.');

$bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/app/bootstrap.php');
foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
    legacy_csrf_assert(
        str_contains($bootstrap, "\$router->{$method}(\$basePathPrefix . '/*', \$legacyDispatcher, \$access);"),
        'Legacy-Dispatcher registriert ' . strtoupper($method) . ' nicht.'
    );
}
$legacyDispatcher = strstr($bootstrap, "if (\$handler === 'legacy'");
legacy_csrf_assert(is_string($legacyDispatcher) && !str_contains($legacyDispatcher, "'exempt'"), 'Legacy-Dispatcher enthält eine produktive CSRF-Ausnahme.');

fwrite(STDOUT, "Legacy CSRF smoke test passed.\n");
