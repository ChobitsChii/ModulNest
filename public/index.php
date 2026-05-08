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

$application = require $basePath . '/app/bootstrap.php';
$application->run();
