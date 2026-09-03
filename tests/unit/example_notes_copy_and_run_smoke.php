<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Database\SchemaHelper;
use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleLoader;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\RotatingFileLogger;
use Modulon\Core\Router;
use Modulon\Core\Session;
use Modulon\Core\UserNavigationRegistry;
use Modulon\Modules\Admin\AppSettingRepository;
use Modulon\Modules\Modules\ModuleRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function example_notes_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function example_notes_env(string $path): array { $out=[]; foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { if (str_contains($line, '=') && !str_starts_with(trim($line), '#')) { [$k,$v]=explode('=', $line, 2); $out[trim($k)]=trim($v, " \t\"'"); } } return $out; }
function example_notes_copy_tree(string $source, string $target): void { if (!is_dir($target)) { mkdir($target, 0775, true); } foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) { $destination = $target . '/' . substr($entry->getPathname(), strlen($source) + 1); if ($entry->isDir()) { if (!is_dir($destination)) { mkdir($destination, 0775, true); } } else { copy($entry->getPathname(), $destination); } } }
function example_notes_remove_tree(string $path): void { if (!is_dir($path)) { return; } foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); } rmdir($path); }

$root = dirname(__DIR__, 2);
$source = $root . '/examples/modules/ExampleNotes';
example_notes_assert(is_dir($source), 'ExampleNotes-Quelle fehlt.');
example_notes_assert(!is_dir($root . '/app/Modules/ExampleNotes'), 'ExampleNotes darf im normalen Arbeitsbaum nicht unter app/Modules liegen.');

$copy = sys_get_temp_dir() . '/modulnest-example-notes-' . bin2hex(random_bytes(4));
mkdir($copy . '/app/Modules', 0775, true);
mkdir($copy . '/app/Views', 0775, true);
mkdir($copy . '/public/assets', 0775, true);
example_notes_copy_tree($source . '/app/Modules/ExampleNotes', $copy . '/app/Modules/ExampleNotes');
example_notes_copy_tree($source . '/app/Views/example-notes', $copy . '/app/Views/example-notes');
example_notes_copy_tree($source . '/public/assets', $copy . '/public/assets');

spl_autoload_register(static function (string $class) use ($copy): void {
    $prefix = 'Modulon\\Modules\\ExampleNotes\\';
    if (str_starts_with($class, $prefix)) {
        $file = $copy . '/app/Modules/ExampleNotes/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) { require_once $file; }
    }
}, true, true);

$env = example_notes_env($root . '/.env');
$server = new PDO('mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';charset=' . ($env['DB_CHARSET'] ?? 'utf8mb4'), $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'modulnest_example_notes_' . bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $server->exec('USE `' . $db . '`');
    $server->exec('CREATE TABLE modules (id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(120) NOT NULL, description TEXT NULL, route_prefix VARCHAR(120) NOT NULL, access_level VARCHAR(20) NOT NULL, handler VARCHAR(20) NOT NULL, legacy_entry TEXT NULL, admin_entry TEXT NULL, enable_overlay TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, show_in_header TINYINT(1) NOT NULL DEFAULT 0, show_on_home TINYINT(1) NOT NULL DEFAULT 0, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_example_prefix(route_prefix)) ENGINE=InnoDB');
    $server->exec('CREATE TABLE app_settings (`key` VARCHAR(190) NOT NULL, value TEXT NOT NULL, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(`key`)) ENGINE=InnoDB');
    $migration = require $copy . '/app/Modules/ExampleNotes/Database/Migrations/20260902_000100_example_notes.php';
    $migration->up($server, new SchemaHelper($server));
    example_notes_assert((int) $server->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'example_notes'")->fetchColumn() === 1, 'Migration hat example_notes nicht angelegt.');
    $repository = new ModuleRepository($server);
    example_notes_assert($repository->discoverNativeModules($copy) === 1, 'Auto-Discovery hat das kopierte Beispiel nicht registriert.');
    $row = $server->query("SELECT is_active FROM modules WHERE route_prefix = 'example-notes'")->fetch();
    example_notes_assert((int) $row['is_active'] === 0, 'Discovery muss das Beispiel zunächst deaktiviert registrieren.');
    $server->exec("UPDATE modules SET is_active = 1, handler = 'native' WHERE route_prefix = 'example-notes'");
    session_id('example-notes-' . bin2hex(random_bytes(4)));
    $session = new Session();
    $context = new ModuleContext($copy, $server, $session, [
        'appSettingRepository' => new AppSettingRepository($server),
        'moduleRepository' => $repository,
    ]);
    $module = NativeModuleLoader::createActiveModules($copy, $context)['example-notes'] ?? null;
    example_notes_assert($module instanceof \Modulon\Core\NativeModuleInterface, 'Aktiviertes Beispiel wurde nicht erstellt.');
    $userNavigation = new UserNavigationRegistry(); $adminNavigation = new AdminNavigationRegistry();
    $module->registerNavigation(new ModuleSubnavigationRegistry(), $adminNavigation, $userNavigation);
    example_notes_assert(count($userNavigation->items('/example-notes')) === 1 && count($adminNavigation->items('/admin/example-notes')) === 1, 'User- oder Admin-Navigation wurde nicht registriert.');
    $service = new \Modulon\Modules\ExampleNotes\ExampleNotesService(new \Modulon\Modules\ExampleNotes\ExampleNotesRepository($server), new AppSettingRepository($server), new RotatingFileLogger($copy));
    $noteId = $service->create(7, 'Copy-and-run note');
    example_notes_assert($service->toggle(7, $noteId), 'Service/Repository konnte die Beispielnotiz nicht toggeln.');
    $service->saveHint('Kopierter Admin-Hinweis');
    example_notes_assert($service->hint() === 'Kopierter Admin-Hinweis', 'Namespaced Admin-Einstellung wurde nicht gespeichert.');
    $router = new Router(); $tokens = new CsrfTokenManager($session);
    $router->setAccessGuard(static fn (Request $request, string $access): ?Response => null);
    $router->setCsrfGuard((new CsrfGuard($tokens))->handle(...));
    $module->registerRoutes($router);
    $bad = $router->dispatch(new Request('POST', '/example-notes/toggle', [], [], [], ['Accept' => 'application/json']));
    ob_start(); $bad->send(); $body = (string) ob_get_clean();
    example_notes_assert(http_response_code() === 419 && str_contains($body, 'csrf_token_invalid'), 'Toggle-Route wird ohne CSRF nicht zentral blockiert.');
    $ok = $router->dispatch(new Request('POST', '/example-notes/toggle', [], [], [], ['X-CSRF-Token' => $tokens->token(), 'Accept' => 'application/json']));
    ob_start(); $ok->send(); ob_end_clean();
    example_notes_assert(http_response_code() !== 419, 'Header-CSRF erreicht die Toggle-Route nicht.');
    example_notes_assert(is_file($copy . '/app/Views/example-notes/index.php') && is_file($copy . '/public/assets/js/example-notes.js'), 'Views oder Assets wurden nicht kopiert.');
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    example_notes_remove_tree($copy);
}
fwrite(STDOUT, "ExampleNotes copy-and-run smoke test passed.\n");
