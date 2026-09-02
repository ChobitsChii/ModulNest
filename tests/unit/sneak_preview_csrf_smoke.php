<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function sneak_preview_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

session_id('sneak-preview-csrf-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');
$routes = [
    '/admin/sneak-preview/save',
    '/admin/sneak-preview/delete',
    '/admin/sneak-preview/settings',
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
    sneak_preview_csrf_assert($status === 200 && $body === 'handled', $route . ' akzeptiert keinen gültigen Formular-Token.');

    [$status] = $dispatch($route);
    sneak_preview_csrf_assert($status === 419, $route . ' wird ohne Token nicht blockiert.');

    [$status] = $dispatch($route, ['_csrf' => str_repeat('f', 64)]);
    sneak_preview_csrf_assert($status === 419, $route . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('/admin/sneak-preview/save', [], [
    'X-CSRF-Token' => $token,
    'Accept' => 'application/json',
]);
sneak_preview_csrf_assert($status === 200 && $body === 'handled', 'Sneak Preview akzeptiert keinen Header-Token.');

[$status, $body] = $dispatch('/admin/sneak-preview/delete', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
sneak_preview_csrf_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'Sneak-Preview-JSON liefert keine zentrale 419-Antwort.'
);

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokens->token();
[$status] = $dispatch('/admin/sneak-preview/settings', ['_csrf' => $token]);
sneak_preview_csrf_assert($status === 419, 'Sneak Preview akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch('/admin/sneak-preview/settings', ['_csrf' => $otherSessionToken]);
sneak_preview_csrf_assert($status === 200 && $body === 'handled', 'Sneak Preview akzeptiert den aktuellen Session-Token nicht.');

$moduleSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/SneakPreview/SneakPreviewModule.php');
foreach ($routes as $route) {
    sneak_preview_csrf_assert(
        preg_match("~router->post\\('" . preg_quote($route, '~') . "'.*?'admin'\\);~", $moduleSource) === 1,
        $route . ' nutzt nicht den sicheren Router-Default.'
    );
}

$controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/SneakPreview/SneakPreviewController.php');
foreach (['sneak_preview_form_token', 'sneak_preview_delete_token', 'sneak_preview_settings_token'] as $legacyKey) {
    sneak_preview_csrf_assert(!str_contains($controllerSource, $legacyKey), 'Der alte Session-Key ' . $legacyKey . ' ist noch vorhanden.');
}
sneak_preview_csrf_assert(str_contains($controllerSource, '$this->repository->saveMovie($normalized, $adminId)'), 'Die Save-Fachlogik fehlt.');
sneak_preview_csrf_assert(str_contains($controllerSource, '$this->repository->deleteMovie($id)'), 'Die Delete-Fachlogik fehlt.');
sneak_preview_csrf_assert(str_contains($controllerSource, '$this->repository->saveDisplayFields($fields)'), 'Die Settings-Fachlogik fehlt.');

foreach (['form.php', 'settings.php', 'partials/table.php'] as $view) {
    $viewSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/sneak-preview/' . $view);
    sneak_preview_csrf_assert(!str_contains($viewSource, 'name="csrf_token"'), $view . ' enthält noch ein altes CSRF-Feld.');
    sneak_preview_csrf_assert(str_contains($viewSource, 'View::csrfField'), $view . ' nutzt kein zentrales CSRF-Feld.');
}

fwrite(STDOUT, "Sneak Preview CSRF smoke test passed.\n");
