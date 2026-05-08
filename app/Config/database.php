<?php

declare(strict_types=1);

use Modulon\Core\Env;

return [
    'driver' => Env::get('DB_DRIVER', 'mysql'),
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::get('DB_PORT', '3306'),
    'name' => Env::get('DB_NAME', 'modulon'),
    'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    'user' => Env::get('DB_USER', 'root'),
    'pass' => Env::get('DB_PASS', ''),
];
