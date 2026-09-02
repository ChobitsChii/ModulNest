<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function updates_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

session_id('updates-csrf-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');
$routes = [
    '/admin/updates/check',
    '/admin/updates/prepare',
    '/admin/updates/install',
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
    updates_csrf_assert($status === 200 && $body === 'handled', $route . ' akzeptiert keinen gültigen Formular-Token.');

    [$status] = $dispatch($route);
    updates_csrf_assert($status === 419, $route . ' wird ohne Token nicht blockiert.');

    [$status] = $dispatch($route, ['_csrf' => str_repeat('f', 64)]);
    updates_csrf_assert($status === 419, $route . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('/admin/updates/check', [], [
    'X-CSRF-Token' => $token,
    'Accept' => 'application/json',
]);
updates_csrf_assert($status === 200 && $body === 'handled', 'Updates akzeptiert keinen Header-Token.');

[$status, $body] = $dispatch('/admin/updates/install', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
updates_csrf_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'Updates-JSON liefert keine zentrale 419-Antwort.'
);

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokens->token();
[$status] = $dispatch('/admin/updates/prepare', ['_csrf' => $token]);
updates_csrf_assert($status === 419, 'Updates akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch('/admin/updates/prepare', ['_csrf' => $otherSessionToken]);
updates_csrf_assert($status === 200 && $body === 'handled', 'Updates akzeptiert den aktuellen Session-Token nicht.');

$moduleSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Updates/UpdatesModule.php');
foreach ($routes as $route) {
    updates_csrf_assert(
        preg_match("~router->post\\('" . preg_quote($route, '~') . "'.*?'admin'\\);~", $moduleSource) === 1,
        $route . ' nutzt nicht den sicheren Router-Default.'
    );
}

$serviceSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Updates/UpdatesService.php');
updates_csrf_assert(str_contains($serviceSource, 'hash_file(\'sha256\', $downloadPath)'), 'Die SHA-256-Prüfung fehlt.');
updates_csrf_assert(str_contains($serviceSource, 'extractZipSafely($downloadPath, $stagingRoot)'), 'Die sichere Paketprüfung fehlt.');
updates_csrf_assert(str_contains($serviceSource, 'copyPreparedFiles($stagingPath, $backupPath,'), 'Die Backup-Installationslogik fehlt.');

fwrite(STDOUT, "Updates CSRF smoke test passed.\n");
