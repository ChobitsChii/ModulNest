<?php

declare(strict_types=1);

use Modulon\Core\Env;

return [
    'public_product_name' => 'ModulNest',
    'product_name' => Env::get('APP_PRODUCT_NAME', 'Modulon'),
    'core_name' => Env::get('APP_CORE_NAME', 'Modulon'),
    'core_label' => Env::get('APP_CORE_LABEL', 'Modulon Core'),
    'version' => '0.7.3',
    'channel' => 'alpha',
    'php_requirement' => '^8.3',
];
