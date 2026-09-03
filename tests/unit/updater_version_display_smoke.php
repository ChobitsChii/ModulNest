<?php

declare(strict_types=1);

use Modulon\Core\View;
use Modulon\Modules\Updates\UpdatesService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function updater_version_display_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function updater_version_display_remove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$base = sys_get_temp_dir() . '/modulnest-updater-version-display-' . bin2hex(random_bytes(5));
mkdir($base . '/storage/updates', 0775, true);

try {
    file_put_contents($base . '/storage/updates/state.json', json_encode([
        'last_install' => [
            'status' => 'installed',
            'from_version' => '1.0.1',
            'version' => '1.1.0',
            'installed_at' => '2026-09-03T12:00:00+00:00',
        ],
    ], JSON_PRETTY_PRINT));

    $successfulStatus = (new UpdatesService($base))->status('1.0.1', 'stable');
    updater_version_display_assert(
        ($successfulStatus['installed_version'] ?? '') === '1.1.0',
        'Die direkt nach erfolgreicher Installation gerenderte Statusseite zeigt nicht die verifizierte neue Version.',
    );
    $html = View::render('updates/admin', [
        'title' => 'Updates',
        'current_path' => '/admin/updates',
        'csrf_token' => 'test-token',
        'status' => $successfulStatus,
    ]);
    updater_version_display_assert(
        str_contains($html, 'Installiert:</span> <strong>1.1.0</strong>'),
        'Die Updates-View rendert nach erfolgreicher Installation noch die alte Version.',
    );

    file_put_contents($base . '/storage/updates/state.json', json_encode([
        'prepared' => [
            'status' => 'prepared',
            'from_version' => '1.0.1',
            'version' => '1.1.0',
        ],
        'recovery_required' => [
            'status' => 'recovery_required',
            'version' => '1.1.0',
        ],
    ], JSON_PRETTY_PRINT));

    $failedStatus = (new UpdatesService($base))->status('1.0.1', 'stable');
    updater_version_display_assert(
        ($failedStatus['installed_version'] ?? '') === '1.0.1',
        'Ein fehlgeschlagenes Update darf keine neue installierte Version vortäuschen.',
    );
} finally {
    updater_version_display_remove($base);
}

fwrite(STDOUT, "Updater version display smoke test passed.\n");
