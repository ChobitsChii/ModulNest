<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;
use Modulon\Core\View;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function csrf_foundation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('csrf-foundation-smoke-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokenManager = new CsrfTokenManager($session);
$token = $tokenManager->token();

csrf_foundation_assert(strlen($token) === 64 && ctype_xdigit($token), 'Der Token ist kein 32-Byte-Hexwert.');
csrf_foundation_assert($tokenManager->token() === $token, 'Der Token bleibt nicht innerhalb derselben Session stabil.');
csrf_foundation_assert($tokenManager->validate($token), 'Ein korrekter Token wird nicht akzeptiert.');
csrf_foundation_assert(!$tokenManager->validate(str_repeat('0', 64)), 'Ein falscher Token wird akzeptiert.');
csrf_foundation_assert(!$tokenManager->validate(null), 'Ein fehlender Token wird akzeptiert.');

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokenManager->token();
csrf_foundation_assert(!$tokenManager->validate($token), 'Ein Token aus einer anderen Session wird akzeptiert.');
csrf_foundation_assert($tokenManager->validate($otherSessionToken), 'Der Token der aktuellen Session wird nicht akzeptiert.');

$rotatedToken = $tokenManager->rotate();
csrf_foundation_assert($rotatedToken !== $otherSessionToken, 'Rotation erzeugt keinen neuen Token.');
csrf_foundation_assert(!$tokenManager->validate($otherSessionToken), 'Rotation lässt den alten Token gültig.');
csrf_foundation_assert($tokenManager->validate($rotatedToken), 'Der rotierte Token wird nicht akzeptiert.');
$tokenManager->invalidate();
csrf_foundation_assert(!$tokenManager->validate($rotatedToken), 'Invalidierung lässt den Token gültig.');
$token = $tokenManager->token();

$router = new Router();
$router->setCsrfGuard((new CsrfGuard($tokenManager))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');
$router->get('/get', $handler);
$router->addRoute('OPTIONS', '/options', $handler);
$router->post('/post', $handler);
$router->put('/put', $handler);
$router->patch('/patch', $handler);
$router->delete('/delete', $handler);
$router->post('/exempt', $handler, 'public', 'exempt');

/** @param array<string, mixed> $input @param array<string, string> $headers */
$dispatch = static function (string $method, string $path, array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, $input, [], [], $headers));
    ob_start();
    $response->send();
    $body = ob_get_clean();

    return [http_response_code(), is_string($body) ? $body : ''];
};

[$status, $body] = $dispatch('GET', '/get');
csrf_foundation_assert($status === 200 && $body === 'handled', 'GET ohne Token wird blockiert.');
[$status, $body] = $dispatch('HEAD', '/get');
csrf_foundation_assert($status === 200 && $body === 'handled', 'HEAD ohne Token wird blockiert.');
[$status, $body] = $dispatch('OPTIONS', '/options');
csrf_foundation_assert($status === 200 && $body === 'handled', 'OPTIONS ohne Token wird blockiert.');
[$status, $body] = $dispatch('POST', '/post', ['_csrf' => $token]);
csrf_foundation_assert($status === 200 && $body === 'handled', 'POST mit Formular-Token wird blockiert.');
[$status] = $dispatch('POST', '/post');
csrf_foundation_assert($status === 419, 'POST ohne Token wird nicht blockiert.');
[$status] = $dispatch('POST', '/post', ['_csrf' => str_repeat('f', 64)]);
csrf_foundation_assert($status === 419, 'POST mit falschem Token wird nicht blockiert.');

foreach (['PUT' => '/put', 'PATCH' => '/patch', 'DELETE' => '/delete'] as $method => $path) {
    [$status] = $dispatch($method, $path);
    csrf_foundation_assert($status === 419, $method . ' ohne Token wird nicht blockiert.');
    [$status, $body] = $dispatch($method, $path, ['_csrf' => $token]);
    csrf_foundation_assert($status === 200 && $body === 'handled', $method . ' mit Token wird blockiert.');
}

[$status, $body] = $dispatch('POST', '/exempt');
csrf_foundation_assert($status === 200 && $body === 'handled', 'Explizit ausgenommene Route wird blockiert.');
[$status, $body] = $dispatch('POST', '/post', [], ['x-csrf-token' => $token, 'Accept' => 'application/json']);
csrf_foundation_assert($status === 200 && $body === 'handled', 'X-CSRF-Token wird nicht akzeptiert.');
[$status, $body] = $dispatch('POST', '/post', ['_csrf' => $token], ['Content-Type' => 'application/json']);
csrf_foundation_assert($status === 200 && $body === 'handled', '_csrf aus einem JSON-Body wird nicht akzeptiert.');
[$status, $body] = $dispatch('POST', '/post');
csrf_foundation_assert($status === 419 && str_contains($body, 'Sicherheits-Token'), 'HTML-CSRF-Fehler liefert keine 419-Fehlerseite.');
[$status, $body] = $dispatch('POST', '/post', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
csrf_foundation_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'JSON-CSRF-Fehler liefert keine stabile 419-Antwort.',
);
csrf_foundation_assert(
    View::csrfField('<test>') === '<input type="hidden" name="_csrf" value="&lt;test&gt;">',
    'Das Standard-CSRF-Feld escaped den Token nicht korrekt.',
);

fwrite(STDOUT, "CSRF foundation smoke test passed.\n");
