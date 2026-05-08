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
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Retry-After: 120');
    echo '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Wartung</title></head><body style="font-family:system-ui,sans-serif;margin:3rem;line-height:1.5"><h1>ModulNest wird aktualisiert</h1><p>Die Installation ist gerade im Wartungsmodus. Bitte versuche es in wenigen Minuten erneut.</p></body></html>';
    exit;
}

$application = require $basePath . '/app/bootstrap.php';
$application->run();
