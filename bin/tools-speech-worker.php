#!/usr/bin/env php
<?php

declare(strict_types=1);

use Modulon\Modules\Tools\ToolsSpeechService;

$options = getopt('', ['job-id:', 'base-path:']);
$basePath = (string) ($options['base-path'] ?? dirname(__DIR__));
$jobId = (string) ($options['job-id'] ?? '');

require $basePath . '/vendor/autoload.php';

if ($jobId === '') {
    fwrite(STDERR, "Missing --job-id.\n");
    exit(1);
}

$service = new ToolsSpeechService($basePath);
exit($service->processJob($jobId));
