<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function generator_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function generator_run(string $root, array $args, ?array &$json = null, ?string &$output = null): int {
    $command = 'php ' . escapeshellarg($root . '/tools/create-module.php');
    foreach ($args as $arg) { $command .= ' ' . escapeshellarg($arg); }
    exec($command . ' 2>&1', $out, $code);
    $output = implode("\n", $out);
    if (in_array('--format=json', $args, true)) { $json = json_decode($output, true); }
    return $code;
}
function generator_run_interactive(string $root, string $input, ?string &$output = null): int {
    $child = 'php ' . escapeshellarg($root . '/tools/create-module.php');
    $command = 'printf %s ' . escapeshellarg($input) . ' | script -q -e -c ' . escapeshellarg($child) . ' /dev/null';
    exec($command . ' 2>&1', $out, $code);
    $output = implode("\n", $out);
    return $code;
}
function generator_copy(string $source, string $target): void { mkdir($target . '/tools', 0775, true); copy($source . '/tools/create-module.php', $target . '/tools/create-module.php'); }
function generator_remove(string $path): void { if (!is_dir($path)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($path); }

$source = dirname(__DIR__, 2); $root = sys_get_temp_dir() . '/modulnest-generator-' . bin2hex(random_bytes(4)); generator_copy($source, $root);
try {
    generator_assert(generator_run($root, ['Test123']) === 0, 'Minimaler Generatorlauf fehlgeschlagen.');
    foreach (['app/Modules/Test123/Test123Module.php','app/Modules/Test123/Test123Controller.php','app/Views/test123/index.php'] as $file) { generator_assert(is_file($root . '/' . $file), "Minimaldatei fehlt: {$file}"); generator_assert(str_contains((string) shell_exec('php -l ' . escapeshellarg($root . '/' . $file)), 'No syntax errors'), "PHP-Syntax fehlgeschlagen: {$file}"); }
    generator_assert(generator_run($root, ['Test123']) !== 0, 'Vorhandener Modulordner wurde überschrieben.');
    spl_autoload_register(static function (string $class) use ($root): void { $prefix = 'Modulon\\Modules\\'; if (str_starts_with($class, $prefix)) { $relative = substr($class, strlen($prefix)); $path = $root . '/app/Modules/' . str_replace('\\', '/', $relative) . '.php'; if (is_file($path)) require_once $path; } }, true, true);
    $discovered = \Modulon\Core\NativeModuleLoader::discover($root);
    generator_assert(isset($discovered['test123']), 'Minimalmodul ist nicht auto-discoverbar.');
    $json = null; generator_assert(generator_run($root, ['Weather Alerts','--admin','--service','--repository','--migration','--main-navigation','--js','--css','--tests','--e2e','--non-interactive','--format=json'], $json) === 0, 'Voller Generatorlauf fehlgeschlagen.');
    generator_assert(is_array($json) && ($json['success'] ?? false) === true && ($json['module'] ?? '') === 'WeatherAlerts', 'JSON-Ausgabe ist nicht maschinenlesbar.');
    generator_assert(($json['navigation'] ?? null) === ['main'=>true, 'account'=>false], 'JSON-Plan beschreibt die Hauptnavigation nicht eindeutig.');
    foreach (['WeatherAlertsModule.php','WeatherAlertsController.php','WeatherAlertsService.php','WeatherAlertsRepository.php','WeatherAlertsAdminNavigationProvider.php'] as $file) generator_assert(is_file($root . '/app/Modules/WeatherAlerts/' . $file), "Volldatei fehlt: {$file}");
    generator_assert(!is_file($root . '/app/Modules/WeatherAlerts/WeatherAlertsAccountNavigationProvider.php'), 'Hauptnavigation hat fälschlich einen Account-Provider erzeugt.');
    generator_assert(is_file($root . '/public/assets/js/weather-alerts.js') && is_file($root . '/public/assets/css/weather-alerts.css'), 'Assets fehlen.');
    generator_assert(glob($root . '/app/Modules/WeatherAlerts/Database/Migrations/*_weather_alerts.php') !== [], 'Migration fehlt oder hat falsches Format.');
    generator_assert(str_contains((string) file_get_contents($root . '/public/assets/js/weather-alerts.js'), 'X-CSRF-Token'), 'Fetch-CSRF fehlt.');
    generator_assert(str_contains((string) file_get_contents($root . '/app/Views/weather-alerts/admin.php'), 'View::csrfField'), 'Admin-Form-CSRF fehlt.');
    require_once $root . '/app/Modules/WeatherAlerts/WeatherAlertsModule.php';
    generator_assert(\Modulon\Modules\WeatherAlerts\WeatherAlertsModule::metadata()['show_in_header'] === true, 'Hauptnavigation setzt show_in_header nicht.');

    generator_assert(generator_run($root, ['Account Menu','--account-navigation','--non-interactive']) === 0, 'Account-Navigation konnte nicht erzeugt werden.');
    generator_assert(is_file($root . '/app/Modules/AccountMenu/AccountMenuAccountNavigationProvider.php'), 'Account-Provider fehlt.');
    require_once $root . '/app/Modules/AccountMenu/AccountMenuModule.php';
    require_once $root . '/app/Modules/AccountMenu/AccountMenuAccountNavigationProvider.php';
    generator_assert(\Modulon\Modules\AccountMenu\AccountMenuModule::metadata()['show_in_header'] === false, 'Account-Navigation erscheint fälschlich in der Hauptnavigation.');
    $accountNavigation = new \Modulon\Core\UserNavigationRegistry();
    $accountNavigation->registerProvider(new \Modulon\Modules\AccountMenu\AccountMenuAccountNavigationProvider());
    generator_assert(count($accountNavigation->items('/account-menu')) === 1, 'Account-Navigation erscheint nicht im persönlichen Menü.');

    generator_assert(generator_run($root, ['Both Menu','--main-navigation','--account-navigation','--non-interactive']) === 0, 'Kombinierte Navigation konnte nicht erzeugt werden.');
    require_once $root . '/app/Modules/BothMenu/BothMenuModule.php';
    generator_assert(\Modulon\Modules\BothMenu\BothMenuModule::metadata()['show_in_header'] === true && is_file($root . '/app/Modules/BothMenu/BothMenuAccountNavigationProvider.php'), 'Kombinierte Navigation ist unvollständig.');

    $aliasJson = null; $aliasOutput = null;
    generator_assert(generator_run($root, ['Alias Nav','--navigation','--non-interactive','--format=json'], $aliasJson, $aliasOutput) === 0, 'Der --navigation-Alias fehlgeschlagen.');
    generator_assert(is_array($aliasJson) && ($aliasJson['navigation'] ?? null) === ['main'=>true, 'account'=>false] && in_array('--navigation is deprecated; use --main-navigation.', $aliasJson['warnings'] ?? [], true), 'Der --navigation-Alias ist nicht kompatibel oder nicht dokumentiert.');
    generator_assert(trim((string) $aliasOutput) !== '' && json_decode((string) $aliasOutput, true) !== null, 'Der JSON-Alias-Modus enthält Fremdausgabe.');
    $aliasText = null;
    $unused = null;
    generator_assert(generator_run($root, ['Alias Text','--navigation','--non-interactive'], $unused, $aliasText) === 0 && str_contains((string) $aliasText, 'Warning: --navigation is deprecated; use --main-navigation.'), 'Der Textmodus warnt nicht über --navigation.');
    $interactiveOutput = null;
    $interactiveInput = "Interactive Menu\nuser\nn\nn\nn\nn\ny\nn\nn\nn\nn\nn\n";
    generator_assert(generator_run_interactive($root, $interactiveInput, $interactiveOutput) === 0, 'Der interaktive Generatorlauf fehlgeschlagen.');
    generator_assert(is_file($root . '/app/Modules/InteractiveMenu/InteractiveMenuModule.php') && !is_file($root . '/app/Modules/InteractiveMenu/InteractiveMenuAccountNavigationProvider.php'), 'Der interaktive Assistent trennt Haupt- und Account-Navigation nicht.');
    require_once $root . '/app/Modules/InteractiveMenu/InteractiveMenuModule.php';
    generator_assert(\Modulon\Modules\InteractiveMenu\InteractiveMenuModule::metadata()['show_in_header'] === true && str_contains((string) $interactiveOutput, 'Hauptnavigation hinzufügen?') && str_contains((string) $interactiveOutput, 'Persönliches Account-Menü hinzufügen?'), 'Der interaktive Plan enthält keine getrennten Navigationsfragen.');
    generator_assert(generator_run($root, ['DryRun','--migration','--dry-run']) === 0 && !is_dir($root . '/app/Modules/DryRun'), 'Dry run hat Dateien erzeugt.');
    generator_assert(generator_run($root, ['Admin','--non-interactive']) !== 0 && generator_run($root, ['Bad/Name','--non-interactive']) !== 0, 'Ungültige/reservierte Namen werden nicht abgelehnt.');
    $errorJson = null; generator_assert(generator_run($root, ['Admin','--non-interactive','--format=json'], $errorJson) !== 0 && is_array($errorJson) && ($errorJson['success'] ?? true) === false, 'JSON-Fehlerausgabe ist nicht maschinenlesbar.');
} finally { generator_remove($root); }
fwrite(STDOUT, "Module generator smoke test passed.\n");
