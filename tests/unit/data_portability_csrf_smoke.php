<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function data_portability_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

session_id('data-portability-csrf-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');
$routes = [
    '/profil/data-portability/export' => 'user',
    '/profil/data-portability/import/preview' => 'user',
    '/profil/data-portability/import/run' => 'user',
    '/admin/data-portability/export' => 'admin',
    '/admin/data-portability/import/preview' => 'admin',
    '/admin/data-portability/import/run' => 'admin',
];

foreach ($routes as $route => $access) {
    $router->post($route, $handler, $access);
}

$dispatch = static function (string $route, array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request('POST', $route, $input, [], [], $headers));
    ob_start();
    $response->send();

    return [http_response_code(), (string) ob_get_clean()];
};

foreach (array_keys($routes) as $route) {
    [$status, $body] = $dispatch($route, ['_csrf' => $token]);
    data_portability_csrf_assert($status === 200 && $body === 'handled', $route . ' akzeptiert keinen gültigen Formular-Token.');

    [$status] = $dispatch($route);
    data_portability_csrf_assert($status === 419, $route . ' wird ohne Token nicht blockiert.');

    [$status] = $dispatch($route, ['_csrf' => str_repeat('f', 64)]);
    data_portability_csrf_assert($status === 419, $route . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('/admin/data-portability/import/preview', [], [
    'X-CSRF-Token' => $token,
    'Accept' => 'application/json',
]);
data_portability_csrf_assert($status === 200 && $body === 'handled', 'Data Portability akzeptiert keinen Header-Token.');

[$status, $body] = $dispatch('/profil/data-portability/import/run', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
data_portability_csrf_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'Data-Portability-JSON liefert keine zentrale 419-Antwort.'
);

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokens->token();
[$status] = $dispatch('/admin/data-portability/export', ['_csrf' => $token]);
data_portability_csrf_assert($status === 419, 'Data Portability akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch('/admin/data-portability/export', ['_csrf' => $otherSessionToken]);
data_portability_csrf_assert($status === 200 && $body === 'handled', 'Data Portability akzeptiert den aktuellen Session-Token nicht.');

$moduleSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/DataPortability/DataPortabilityModule.php');
foreach ($routes as $route => $access) {
    data_portability_csrf_assert(
        preg_match("~router->post\\('" . preg_quote($route, '~') . "'.*?'" . $access . "'\\);~", $moduleSource) === 1,
        $route . ' nutzt nicht den sicheren Router-Default.'
    );
}

$controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/DataPortability/DataPortabilityController.php');
data_portability_csrf_assert(!str_contains($controllerSource, 'data_portability_csrf_token'), 'Der alte allgemeine CSRF-Token ist noch vorhanden.');
data_portability_csrf_assert(str_contains($controllerSource, "ADMIN_IMPORT_TOKEN_KEY = 'data_portability_import_token'"), 'Der Admin-Workflow-Token fehlt.');
data_portability_csrf_assert(str_contains($controllerSource, "USER_IMPORT_TOKEN_KEY = 'data_portability_user_import_token'"), 'Der User-Workflow-Token fehlt.');
data_portability_csrf_assert(str_contains($controllerSource, '$this->session->get(self::ADMIN_IMPORT_TOKEN_KEY)'), 'Admin-Importlauf nutzt den Workflow-Token nicht.');
data_portability_csrf_assert(str_contains($controllerSource, '$this->session->get(self::USER_IMPORT_TOKEN_KEY)'), 'User-Importlauf nutzt den Workflow-Token nicht.');
data_portability_csrf_assert(str_contains($controllerSource, "throw new RuntimeException('Keine vorbereitete Import-Datei gefunden.')"), 'Importlauf lehnt fehlenden Workflow-Token nicht ab.');
data_portability_csrf_assert(str_contains($controllerSource, '$this->service->resolveImportPath($token)'), 'Importlauf löst den Workflow-Token nicht auf.');

$adminView = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/data-portability/admin.php');
$profileView = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/user/area.php');
data_portability_csrf_assert(!str_contains($adminView, 'name="csrf_token"'), 'Admin-View enthält noch ein altes CSRF-Feld.');
data_portability_csrf_assert(!str_contains($profileView, 'dataPortabilityCsrfToken'), 'Profil-View enthält noch den alten CSRF-Wert.');
data_portability_csrf_assert(str_contains($profileView, 'name="import_zip"'), 'Importdatei-Feld fehlt.');

fwrite(STDOUT, "Data Portability CSRF smoke test passed.\n");
