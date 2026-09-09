<?php

declare(strict_types=1);

use Modulon\Core\View;
use Modulon\Modules\Updates\UpdateChannel;
use Modulon\Modules\Updates\UpdatesService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function update_channel_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function update_channel_metadata(string $version): string
{
    return json_encode([
        'latest' => $version,
        'channel' => str_contains($version, '-') ? 'prerelease' : 'stable',
        'packages' => ['bundled' => [
            'url' => 'https://github.com/ChobitsChii/ModulNest/releases/download/v' . $version . '/modulnest-bundled-' . $version . '.zip',
            'sha256' => str_repeat('a', 64),
            'needs_composer' => false,
        ]],
        'requires_migrations' => false,
    ], JSON_THROW_ON_ERROR);
}

/** @return array{UpdatesService,string} */
function update_channel_service(string $stable, string $preview): array
{
    $base = sys_get_temp_dir() . '/modulnest-update-channel-' . bin2hex(random_bytes(5));
    mkdir($base . '/storage', 0775, true);
    $fetcher = static fn (string $url): string => str_ends_with($url, 'prerelease.json') ? $preview : $stable;

    return [new UpdatesService($base, null, $fetcher), $base];
}

function update_channel_remove(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

update_channel_assert(UpdateChannel::normalize(null) === UpdateChannel::STABLE, 'Der Default ist nicht Stable.');
update_channel_assert(UpdateChannel::label('stable') === 'Stable', 'Stable wird nicht benutzerfreundlich beschriftet.');
update_channel_assert(UpdateChannel::label('preview') === 'Stable + Vorabversionen', 'Preview wird intern statt benutzerfreundlich beschriftet.');
update_channel_assert(UpdateChannel::releaseLabel('2.0.0-alpha.1') === 'Alpha', 'Alpha-Label fehlt.');
update_channel_assert(UpdateChannel::releaseLabel('2.0.0-beta.1') === 'Beta', 'Beta-Label fehlt.');
update_channel_assert(UpdateChannel::releaseLabel('2.0.0-rc.1') === 'Release Candidate', 'RC-Label fehlt.');
update_channel_assert(UpdateChannel::releaseLabel('2.0.0') === 'Stable', 'Stable-Release-Label fehlt.');
update_channel_assert(UpdateChannel::releaseLabel('2.0.0', 'rc') === 'Stable', 'Die alte Request-Konfiguration überstimmt die installierte Stable-Version.');

[$service, $base] = update_channel_service(update_channel_metadata('1.3.0'), update_channel_metadata('2.0.0-rc.1'));
try {
    update_channel_assert($service->check('1.3.0', UpdateChannel::STABLE)['latest'] === '1.3.0', 'Stable berücksichtigt den RC.');
    update_channel_assert($service->check('1.3.0', UpdateChannel::PREVIEW)['latest'] === '2.0.0-rc.1', 'Preview erkennt den RC nicht.');
    $status = $service->status('2.0.0-rc.1', 'rc', UpdateChannel::PREVIEW);
    update_channel_assert($status['installed_release_label'] === 'Release Candidate', 'Installierte Release-Art ist falsch.');
    update_channel_assert($status['update_channel_label'] === 'Stable + Vorabversionen', 'Updatekanal-Label ist falsch.');
} finally {
    update_channel_remove($base);
}

[$service, $base] = update_channel_service(update_channel_metadata('2.0.0'), update_channel_metadata('2.0.0-rc.1'));
try {
    update_channel_assert($service->check('2.0.0-rc.1', UpdateChannel::PREVIEW)['latest'] === '2.0.0', 'Stable 2.0.0 überholt den RC nicht.');
} finally {
    update_channel_remove($base);
}

$html = View::render('updates/admin', [
    'status' => [
        'installed_version' => '2.0.0-rc.1',
        'installed_release_label' => 'Release Candidate',
        'update_channel' => 'preview',
        'update_channel_label' => 'Stable + Vorabversionen',
        'feed_url' => UpdatesService::UPDATE_FEED_URL,
        'prerelease_feed_url' => UpdatesService::PRERELEASE_FEED_URL,
        'state' => [],
    ],
    'csrf_token' => 'fixture',
]);
update_channel_assert(str_contains($html, '2.0.0-rc.1 (Release Candidate)'), 'Installiertes Release wird nicht eindeutig angezeigt.');
update_channel_assert(str_contains($html, 'Updatekanal:</span> <strong>Stable + Vorabversionen'), 'Updatekanal fehlt im Kopf.');
update_channel_assert(str_contains($html, '<details class="updates-channel-details">'), 'Updatekanal-Konfiguration ist nicht standardmäßig eingeklappt.');
update_channel_assert(str_contains($html, 'Vorabversionen aktiviert') && str_contains($html, 'Ändern'), 'Kompakter Kanalstatus fehlt.');
update_channel_assert(str_contains($html, 'aria-expanded="false"') && str_contains($html, '▾') && str_contains($html, '▴'), 'Zugänglicher Disclosure-Zustand oder Chevron fehlt.');
update_channel_assert(str_contains($html, 'name="_csrf"'), 'CSRF-Feld fehlt.');
update_channel_assert(!str_contains($html, 'Channel:</span>'), 'Veraltete Channel-Anzeige ist noch sichtbar.');

$css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
update_channel_assert(str_contains($css, '.updates-channel-details[open] .updates-channel-open-label'), 'Der Öffnen-/Schließen-Zustand ist nicht gestaltet.');
update_channel_assert(str_contains($css, 'justify-content: flex-start'), 'Updatekanal-Aktion bleibt am rechten Bildschirmrand stehen.');
update_channel_assert(str_contains($css, '@media (max-width: 575.98px)') && str_contains($css, 'flex-direction: column'), 'Mobile Darstellung fehlt.');
update_channel_assert(str_contains($css, 'var(--app-primary)') && str_contains($html, 'text-bg-warning'), 'Theme-fähige Fokus-/Warnungsdarstellung fehlt.');

fwrite(STDOUT, "Update channel UX smoke test passed.\n");
