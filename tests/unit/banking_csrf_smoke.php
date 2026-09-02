<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function banking_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

session_id('banking-csrf-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');
$routes = [
    '/banking/import',
    '/banking/transactions/duplicates/delete',
    '/banking/recurring',
];

foreach ($routes as $route) {
    $router->post($route, $handler, 'user');
}

$dispatch = static function (string $route, array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request('POST', $route, $input, [], [], $headers));
    ob_start();
    $response->send();

    return [http_response_code(), (string) ob_get_clean()];
};

foreach ($routes as $route) {
    [$status, $body] = $dispatch($route, ['_csrf' => $token]);
    banking_csrf_assert($status === 200 && $body === 'handled', $route . ' akzeptiert keinen gültigen Formular-Token.');

    [$status] = $dispatch($route);
    banking_csrf_assert($status === 419, $route . ' wird ohne Token nicht blockiert.');

    [$status] = $dispatch($route, ['_csrf' => str_repeat('f', 64)]);
    banking_csrf_assert($status === 419, $route . ' wird mit falschem Token nicht blockiert.');
}

[$status, $body] = $dispatch('/banking/transactions/duplicates/delete', [], [
    'X-CSRF-Token' => $token,
    'Accept' => 'application/json',
]);
banking_csrf_assert($status === 200 && $body === 'handled', 'Banking akzeptiert keinen Header-Token.');

[$status, $body] = $dispatch('/banking/recurring', [], ['Accept' => 'application/json']);
$json = json_decode($body, true);
banking_csrf_assert(
    $status === 419 && is_array($json) && ($json['error'] ?? null) === 'csrf_token_invalid',
    'Banking-JSON liefert keine zentrale 419-Antwort.'
);

$session->remove(CsrfTokenManager::SESSION_KEY);
$otherSessionToken = $tokens->token();
[$status] = $dispatch('/banking/import', ['_csrf' => $token]);
banking_csrf_assert($status === 419, 'Banking akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch('/banking/import', ['_csrf' => $otherSessionToken]);
banking_csrf_assert($status === 200 && $body === 'handled', 'Banking akzeptiert den aktuellen Session-Token nicht.');

$moduleSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Banking/BankingModule.php');
foreach ($routes as $route) {
    banking_csrf_assert(
        preg_match("~router->post\\('" . preg_quote($route, '~') . "'.*?\\$" . 'this->access' . "\\);~", $moduleSource) === 1,
        $route . ' nutzt nicht den sicheren Router-Default.'
    );
}

$controllerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Banking/BankingController.php');
banking_csrf_assert(str_contains($controllerSource, '$this->csvImport->importForUser('), 'Die Import-Fachlogik fehlt.');
banking_csrf_assert(str_contains($controllerSource, '$this->transactionList->deleteDuplicatesForUser($userId, $ids)'), 'Die Duplikat-Fachlogik fehlt.');
banking_csrf_assert(str_contains($controllerSource, '$this->recurringRules->saveRuleForUser($userId, $formData)'), 'Die Recurring-Fachlogik fehlt.');
banking_csrf_assert(!str_contains($controllerSource, 'banking_import_token'), 'Der alte Import-CSRF-Token ist noch vorhanden.');
banking_csrf_assert(!str_contains($controllerSource, 'banking_duplicate_token'), 'Der alte Duplikat-CSRF-Token ist noch vorhanden.');
banking_csrf_assert(!str_contains($controllerSource, 'banking_recurring_token'), 'Der alte Recurring-CSRF-Token ist noch vorhanden.');

$transactionsView = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/banking/transactions.php');
$recurringView = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/banking/recurring.php');
$importView = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/banking/import.php');
banking_csrf_assert(str_contains($transactionsView, 'name="delete_ids[]"'), 'Die Duplikat-IDs fehlen.');
banking_csrf_assert(str_contains($transactionsView, 'name="protected_keep_ids[]"'), 'Die geschützten Behalt-IDs fehlen.');
banking_csrf_assert(str_contains($recurringView, 'name="rule_id"'), 'Die Recurring-Regel-ID fehlt.');
banking_csrf_assert(str_contains($importView, 'name="csv_file"'), 'Das Import-Dateifeld fehlt.');

fwrite(STDOUT, "Banking CSRF smoke test passed.\n");
