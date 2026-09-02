<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

$autoloadPath = $basePath . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    http_response_code(500);
    echo 'Composer-Autoload fehlt. Bitte zuerst "composer install" ausführen.';
    exit;
}

require $autoloadPath;

$envPath = $basePath . '/.env';
\Modulon\Core\Env::load($envPath);

$appEnv = (string) \Modulon\Core\Env::get('APP_ENV', 'production');
$appDebug = \Modulon\Core\Env::getBool('APP_DEBUG', false);
\Modulon\Core\ErrorHandler::register($basePath, $appEnv, $appDebug);

$maintenanceFlag = $basePath . '/storage/maintenance.flag';
if (is_file($maintenanceFlag)) {
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($requestPath === '/recovery' || str_starts_with($requestPath, '/recovery/')) {
        require $basePath . '/recovery.php';
        exit;
    }
    $maintenance = json_decode((string) @file_get_contents($maintenanceFlag), true);
    $recoveryRequired = is_array($maintenance) && !empty($maintenance['recovery_required']);
    http_response_code(503);
    \Modulon\Core\SecurityHeaders::apply();
    header('Content-Type: text/html; charset=UTF-8');
    header('Retry-After: 120');
    $message = $recoveryRequired
        ? 'Ein Update oder eine Migration benötigt eine Betreiber-Recovery. Die Anwendung bleibt bis zur Prüfung im Wartungsmodus. Administratoren können den geschützten Recovery-Bereich öffnen.'
        : 'Die Installation ist gerade im Wartungsmodus. Bitte versuche es in wenigen Minuten erneut.';
    echo '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Wartung</title></head><body style="font-family:system-ui,sans-serif;margin:3rem;line-height:1.5"><h1>ModulNest wird aktualisiert</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}

$application = require $basePath . '/app/bootstrap.php';
$application->run();
