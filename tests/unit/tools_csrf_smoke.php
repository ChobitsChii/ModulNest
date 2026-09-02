<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function tools_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

session_id('tools-csrf-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');
$routes = [
    '/admin/tools/network',
    '/admin/tools/speech',
    '/admin/tools/speech/delete',
];

foreach ($routes as $route) {
    $router->post($route, $handler, 'admin');
}

$dispatch = static function (string $route, array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request('POST', $route, $input, [], [], $headers));
    ob_start();
    $response->send();

    return [http_response_code(), (string) ob_get_clean()];
};

foreach ($routes as $route) {
    [$status, $body] = $dispatch($route, ['_csrf' => $token]);
    tools_csrf_assert($status === 200 && $body === 'handled', $route . ' akzeptiert keinen gültigen Formular-Token.');

    [$status] = $dispatch($route);
    tools_csrf_assert($status === 419, $route . ' wird ohne Token nicht blockiert.');

    [$status] = $dispatch($route, ['_csrf' => str_repeat('f', 64)]);
    tools_csrf_assert($status === 419, $route . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('/admin/tools/network', [], [
    'X-CSRF-Token' => $token,
    'Accept' => 'application/json',
]);
tools_csrf_assert($status === 200 && $body === 'handled', 'Network akzeptiert keinen Header-Token.');

[$status, $body] = $dispatch('/admin/tools/speech', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
tools_csrf_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'Tools-JSON liefert keine zentrale 419-Antwort.'
);

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokens->token();
[$status] = $dispatch('/admin/tools/speech/delete', ['_csrf' => $token]);
tools_csrf_assert($status === 419, 'Tools akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch('/admin/tools/speech/delete', ['_csrf' => $otherSessionToken]);
tools_csrf_assert($status === 200 && $body === 'handled', 'Tools akzeptiert den aktuellen Session-Token nicht.');

$moduleSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Tools/ToolsModule.php');
foreach ($routes as $route) {
    tools_csrf_assert(
        preg_match("~router->post\\('" . preg_quote($route, '~') . "'.*?'admin'\\);~", $moduleSource) === 1,
        $route . ' nutzt nicht den sicheren Router-Default.'
    );
}

$controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Tools/ToolsController.php');
tools_csrf_assert(str_contains($controllerSource, '$this->network->run($tool, $input)'), 'Die Network-Fachlogik fehlt.');
tools_csrf_assert(str_contains($controllerSource, '$this->speech->createUploadJob($file,'), 'Die Speech-Upload-Logik fehlt.');
tools_csrf_assert(str_contains($controllerSource, '$this->speech->deleteJob($jobId)'), 'Die Speech-Löschlogik fehlt.');

$javascript = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/tools.js');
tools_csrf_assert(str_contains($javascript, "form.querySelector('input[name=\"_csrf\"]')"), 'Der zentrale JS-CSRF-Helper fehlt.');
tools_csrf_assert(str_contains($javascript, "'X-CSRF-Token': csrfToken"), 'tools.js sendet keinen CSRF-Header.');
tools_csrf_assert(substr_count($javascript, 'body: new FormData(form)') === 2, 'Die FormData-Uploadpfade wurden verändert.');
tools_csrf_assert(!str_contains($javascript, 'name="csrf_token"'), 'tools.js enthält noch das alte CSRF-Feld.');

fwrite(STDOUT, "Tools CSRF smoke test passed.\n");
