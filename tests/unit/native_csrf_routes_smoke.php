<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function native_csrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_id('native-csrf-routes-smoke-' . bin2hex(random_bytes(4)));

$session = new Session();
$tokens = new CsrfTokenManager($session);
$token = $tokens->token();
$router = new Router();
$router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
$router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
$handler = static fn (Request $request): Response => new Response('handled');

$router->post('/profil/fantasy-cards', $handler, 'user');
$dispatch = static function (array $input = [], array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request('POST', '/profil/fantasy-cards', $input, [], [], $headers));
    ob_start();
    $response->send();

    return [http_response_code(), (string) ob_get_clean()];
};

[$status, $body] = $dispatch(['_csrf' => $token]);
native_csrf_assert($status === 200 && $body === 'handled', 'FantasyCards-Profil akzeptiert keinen gültigen Token.');
[$status] = $dispatch();
native_csrf_assert($status === 419, 'FantasyCards-Profil wird ohne Token nicht blockiert.');
[$status] = $dispatch(['_csrf' => str_repeat('f', 64)]);
native_csrf_assert($status === 419, 'FantasyCards-Profil wird mit falschem Token nicht blockiert.');
$session->remove(CsrfTokenManager::SESSION_KEY);
$otherToken = $tokens->token();
[$status] = $dispatch(['_csrf' => $token]);
native_csrf_assert($status === 419, 'FantasyCards-Profil akzeptiert einen Token aus einer anderen Session.');
[$status, $body] = $dispatch(['_csrf' => $otherToken]);
native_csrf_assert($status === 200 && $body === 'handled', 'FantasyCards-Profil akzeptiert den aktuellen Session-Token nicht.');

$root = dirname(__DIR__, 2);
$userModule = (string) file_get_contents($root . '/app/Modules/User/UserModule.php');
native_csrf_assert(
    str_contains($userModule, "'/profil/fantasy-cards', [\$this->controller, 'updateFantasyCardsProfile'], 'user');"),
    'FantasyCards-Profilroute nutzt nicht den sicheren Router-Default.'
);
$profileView = (string) file_get_contents($root . '/app/Views/user/partials/fantasy-cards.php');
native_csrf_assert(str_contains($profileView, 'View::csrfField'), 'FantasyCards-Profilformular enthält kein zentrales CSRF-Feld.');

foreach (glob($root . '/app/Modules/*/*Module.php') ?: [] as $moduleFile) {
    $source = (string) file_get_contents($moduleFile);
    preg_match_all('/\\$router->(?:post|put|patch|delete)\\([^;]+\\);/', $source, $matches);
    foreach ($matches[0] as $declaration) {
        native_csrf_assert(!str_contains($declaration, "'enforce'"), basename($moduleFile) . ' enthält noch eine temporäre CSRF-Policy.');
        native_csrf_assert(!str_contains($declaration, "'exempt'"), basename($moduleFile) . ' enthält eine produktive CSRF-Ausnahme.');
    }
}

$bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');
$nativeBootstrap = explode('if ($moduleRepository !== null)', $bootstrap, 2)[0];
preg_match_all('/\\$router->(?:post|put|patch|delete)\\([^;]+\\);/', $nativeBootstrap, $matches);
foreach ($matches[0] as $declaration) {
    native_csrf_assert(!str_contains($declaration, "'enforce'"), 'Bootstrap enthält noch eine temporäre CSRF-Policy.');
    native_csrf_assert(!str_contains($declaration, "'exempt'"), 'Bootstrap enthält eine produktive CSRF-Ausnahme.');
}

$fantasyModule = (string) file_get_contents($root . '/app/Modules/FantasyCards/FantasyCardsModule.php');
$fantasyController = (string) file_get_contents($root . '/app/Modules/FantasyCards/FantasyCardsController.php');
$fantasyAdminJs = (string) file_get_contents($root . '/public/assets/js/fantasycards-admin.js');
foreach ([$fantasyModule, $fantasyController, $fantasyAdminJs] as $source) {
    native_csrf_assert(!str_contains($source, '/admin/fantasycards/upload'), 'Historischer Upload-Alias ist noch vorhanden.');
}

$routerSource = (string) file_get_contents($root . '/app/Core/Router.php');
$guardSource = (string) file_get_contents($root . '/app/Core/CsrfGuard.php');
$envExample = (string) file_get_contents($root . '/.env.example');
native_csrf_assert(!str_contains($routerSource, "'enforce'"), 'Router enthält noch die temporäre enforce-Policy.');
native_csrf_assert(!str_contains($guardSource, 'enforcementEnabled'), 'CSRF-Guard ist noch konfigurierbar abschaltbar.');
native_csrf_assert(!str_contains($envExample, 'CSRF_ENFORCEMENT'), '.env.example enthält noch CSRF_ENFORCEMENT.');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app')) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());
    native_csrf_assert(!str_contains($source, 'CSRF_ENFORCEMENT'), $file->getPathname() . ' enthält noch den Migrationsschalter.');
    native_csrf_assert(!str_contains($source, "'enforce'"), $file->getPathname() . ' enthält noch die temporäre CSRF-Policy.');
}

fwrite(STDOUT, "Native CSRF routes smoke test passed.\n");
