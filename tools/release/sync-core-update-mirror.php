#!/usr/bin/env php
<?php

declare(strict_types=1);

function mirrorFail(string $message): never
{
    fwrite(STDERR, "Core mirror sync failed: {$message}\n");
    exit(1);
}

function mirrorFetch(string $url, int $limit): string
{
    if (!str_starts_with($url, 'https://')) mirrorFail('HTTPS source required');
    $context = stream_context_create(['http' => [
        'timeout' => 60,
        'follow_location' => 1,
        'max_redirects' => 5,
        'user_agent' => 'ModulNest-Core-Mirror/1.0',
    ]]);
    $handle = @fopen($url, 'rb', false, $context);
    if (!is_resource($handle)) mirrorFail('source is unavailable');
    try {
        $bytes = stream_get_contents($handle, $limit + 1);
    } finally {
        fclose($handle);
    }
    if (!is_string($bytes) || strlen($bytes) > $limit) mirrorFail('source exceeds size limit');
    return $bytes;
}

function mirrorWrite(string $path, string $bytes): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) mirrorFail('staging directory cannot be created');
    if (file_put_contents($path, $bytes, LOCK_EX) === false) mirrorFail('staging file cannot be written');
}

$options = getopt('', ['source::', 'public-base::', 'target:']);
$source = rtrim((string) ($options['source'] ?? 'https://raw.githubusercontent.com/ChobitsChii/ModulNest/main/build/update'), '/');
$publicBase = rtrim((string) ($options['public-base'] ?? 'https://updates.modulnest.de/core'), '/');
$target = rtrim((string) ($options['target'] ?? ''), '/');
if (!str_starts_with($source, 'https://') || !str_starts_with($publicBase, 'https://') || $target === '' || !str_starts_with($target, '/')) {
    mirrorFail('absolute --target and HTTPS source/public base are required');
}

$stage = dirname($target) . '/.sync-' . basename($target) . '-' . bin2hex(random_bytes(6));
if (!mkdir($stage, 0775, true)) mirrorFail('staging root cannot be created');
foreach (['stable.json', 'prerelease.json'] as $feedName) {
    $feed = json_decode(mirrorFetch($source . '/' . $feedName, 1048576), true, 32, JSON_THROW_ON_ERROR);
    $version = (string) ($feed['latest'] ?? '');
    if (preg_match('/^\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?$/D', $version) !== 1 || !is_array($feed['packages'] ?? null)) {
        mirrorFail('invalid update feed');
    }
    foreach (['source', 'bundled'] as $type) {
        $package = $feed['packages'][$type] ?? null;
        if (!is_array($package) || !preg_match('/^[a-f0-9]{64}$/D', (string) ($package['sha256'] ?? ''))) mirrorFail('invalid package metadata');
        $bytes = mirrorFetch((string) ($package['url'] ?? ''), 268435456);
        if (!hash_equals((string) $package['sha256'], hash('sha256', $bytes))) mirrorFail('core package hash mismatch');
        $filename = basename((string) parse_url((string) $package['url'], PHP_URL_PATH));
        if (preg_match('/^modulnest-(?:source|bundled)-[A-Za-z0-9.-]+\.zip$/D', $filename) !== 1) mirrorFail('unsafe package filename');
        mirrorWrite($stage . '/releases/' . $version . '/' . $filename, $bytes);
        $feed['packages'][$type]['url'] = $publicBase . '/releases/' . rawurlencode($version) . '/' . rawurlencode($filename);
    }
    mirrorWrite($stage . '/' . $feedName, json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

$previous = dirname($target) . '/' . basename($target) . '.previous-' . gmdate('YmdHis');
if (is_dir($target) && !rename($target, $previous)) mirrorFail('current mirror cannot be retained');
if (!rename($stage, $target)) {
    if (is_dir($previous)) @rename($previous, $target);
    mirrorFail('atomic mirror switch failed');
}
fwrite(STDOUT, "Core update mirror synchronized.\n");
