<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function fantasycards_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

session_id('fantasycards-csrf-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');
$routes = [
    '/fantasy-cards/boosters/claim' => 'user',
    '/fantasy-cards/boosters/open' => 'user',
    '/admin/fantasy-cards/upload' => 'admin',
    '/admin/fantasy-cards/sets/save' => 'admin',
    '/admin/fantasy-cards/sets/toggle' => 'admin',
    '/admin/fantasy-cards/cards/save' => 'admin',
    '/admin/fantasy-cards/cards/toggle' => 'admin',
    '/admin/fantasy-cards/cards/inline' => 'admin',
    '/admin/fantasy-cards/cards/reorder' => 'admin',
    '/admin/fantasy-cards/cards/bulk' => 'admin',
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
    fantasycards_csrf_assert($status === 200 && $body === 'handled', $route . ' akzeptiert keinen gültigen Formular-Token.');

    [$status] = $dispatch($route);
    fantasycards_csrf_assert($status === 419, $route . ' wird ohne Token nicht blockiert.');

    [$status] = $dispatch($route, ['_csrf' => str_repeat('f', 64)]);
    fantasycards_csrf_assert($status === 419, $route . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('/admin/fantasy-cards/cards/inline', [], [
    'X-CSRF-Token' => $token,
    'Accept' => 'application/json',
]);
fantasycards_csrf_assert($status === 200 && $body === 'handled', 'FantasyCards akzeptiert keinen Header-Token.');

[$status, $body] = $dispatch('/admin/fantasy-cards/upload', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
fantasycards_csrf_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'FantasyCards-JSON liefert keine zentrale 419-Antwort.'
);

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokens->token();
[$status] = $dispatch('/fantasy-cards/boosters/claim', ['_csrf' => $token]);
fantasycards_csrf_assert($status === 419, 'FantasyCards akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch('/fantasy-cards/boosters/open', ['_csrf' => $otherSessionToken]);
fantasycards_csrf_assert($status === 200 && $body === 'handled', 'FantasyCards akzeptiert den aktuellen Session-Token nicht.');

$moduleSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/FantasyCards/FantasyCardsModule.php');
fantasycards_csrf_assert(!str_contains($moduleSource, "'exempt'"), 'FantasyCards enthält eine unbeabsichtigte CSRF-Ausnahme.');
fantasycards_csrf_assert(!str_contains($moduleSource, "'enforce'"), 'FantasyCards enthält noch eine temporäre CSRF-Policy.');

$controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/FantasyCards/FantasyCardsController.php');
fantasycards_csrf_assert(!str_contains($controllerSource, 'fantasycards_admin_token'), 'Der alte FantasyCards-CSRF-Token ist noch vorhanden.');
fantasycards_csrf_assert(!str_contains($controllerSource, 'validToken('), 'Die alte FantasyCards-CSRF-Validierung ist noch vorhanden.');
foreach ([
    '$this->boosterService->claimFreeBooster(',
    '$this->boosterService->openBooster(',
    '$this->uploadService->handleUpload(',
    '$this->repository->saveSet(',
    '$this->repository->saveCard(',
    '$this->repository->toggleSet(',
    '$this->repository->toggleCard(',
    '$this->repository->reorderCards(',
    '$this->repository->bulkUpdateCards(',
] as $fragment) {
    fantasycards_csrf_assert(str_contains($controllerSource, $fragment), 'Fachlogik fehlt: ' . $fragment);
}

$adminJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/fantasycards-admin.js');
$boosterJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/fantasycards-booster.js');
fantasycards_csrf_assert(!str_contains($adminJs, "body.set('csrf_token'"), 'Admin-JS enthält noch alte CSRF-Payloads.');
fantasycards_csrf_assert(!str_contains($boosterJs, "body.set('csrf_token'"), 'Booster-JS enthält noch alte CSRF-Payloads.');
fantasycards_csrf_assert(str_contains($adminJs, "'X-CSRF-Token': csrfToken"), 'Admin-JS sendet keinen CSRF-Header.');
fantasycards_csrf_assert(str_contains($boosterJs, "'X-CSRF-Token': button.dataset.csrfToken"), 'Booster-JS sendet keinen CSRF-Header.');
fantasycards_csrf_assert(str_contains($adminJs, "xhr.setRequestHeader('X-CSRF-Token', csrfToken)"), 'Upload-XHR sendet keinen CSRF-Header.');
fantasycards_csrf_assert(str_contains($adminJs, 'const data = new FormData(form)'), 'Upload verwendet kein FormData mehr.');
fantasycards_csrf_assert(str_contains($adminJs, "data.append('cards[]', file)"), 'Upload-Dateien werden nicht mehr übertragen.');

foreach (['admin-set-form.php', 'admin-card-form.php', 'admin-upload.php', 'boosters.php'] as $view) {
    $viewSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/fantasy-cards/' . $view);
    fantasycards_csrf_assert(!str_contains($viewSource, 'name="csrf_token"'), $view . ' enthält noch ein altes CSRF-Feld.');
    fantasycards_csrf_assert(str_contains($viewSource, 'View::csrfField'), $view . ' nutzt kein zentrales CSRF-Feld.');
}

fwrite(STDOUT, "FantasyCards CSRF smoke test passed.\n");
