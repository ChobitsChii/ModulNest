<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$status = is_array($status ?? null) ? $status : [];
$state = is_array($status['state'] ?? null) ? $status['state'] : [];
$lastCheck = is_array($state['last_check'] ?? null) ? $state['last_check'] : [];
$prepared = is_array($state['prepared'] ?? null) ? $state['prepared'] : [];
$lastInstall = is_array($state['last_install'] ?? null) ? $state['last_install'] : [];
$metadata = is_array($lastCheck['metadata'] ?? null) ? $lastCheck['metadata'] : [];
$package = is_array($lastCheck['package'] ?? null) ? $lastCheck['package'] : [];
$csrf = (string) ($csrf_token ?? '');
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$installedVersion = (string) ($status['installed_version'] ?? '');
$channel = (string) ($status['channel'] ?? '');
$feedUrl = (string) ($status['feed_url'] ?? '');
$timezoneName = (string) ($status['timezone_name'] ?? '');
$updateAvailable = (bool) ($lastCheck['available'] ?? false);
$externalIcon = '<svg class="external-link-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10.5 2a.5.5 0 0 0 0 1h1.793L6.146 9.146a.5.5 0 1 0 .708.708L13 3.707V5.5a.5.5 0 0 0 1 0v-3A.5.5 0 0 0 13.5 2h-3Z"/><path fill="currentColor" d="M3.5 4A1.5 1.5 0 0 0 2 5.5v7A1.5 1.5 0 0 0 3.5 14h7a1.5 1.5 0 0 0 1.5-1.5v-4a.5.5 0 0 0-1 0v4a.5.5 0 0 1-.5.5h-7a.5.5 0 0 1-.5-.5v-7a.5.5 0 0 1 .5-.5h4a.5.5 0 0 0 0-1h-4Z"/></svg>';
$externalLink = static function (string $url, string $label = '') use ($e, $externalIcon): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $text = $label !== '' ? $label : $url;

    return '<a class="external-link" href="' . $e($url) . '" target="_blank" rel="noopener noreferrer">'
        . $e($text)
        . $externalIcon
        . '</a>';
};
?>
<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Admin</p>
                        <h1 class="h4 mb-2">ModulNest Updates</h1>
                        <p class="text-body-secondary mb-0">Offizielle Releases prüfen, herunterladen und nach SHA256-Prüfung installieren.</p>
                    </div>
                    <div class="updates-version-meta small">
                        <div><span>Installiert:</span> <strong><?= $e($installedVersion) ?></strong></div>
                        <div><span>Channel:</span> <strong><?= $e($channel) ?></strong></div>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h6 mb-3">1. Prüfen</h2>
                <dl class="mb-3 small">
                    <dt class="text-body-secondary">Updatequelle</dt>
                    <dd class="text-break"><?= $externalLink($feedUrl) ?></dd>
                </dl>
                <form method="post" action="/admin/updates/check">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <button class="btn btn-primary" type="submit">Nach Updates suchen</button>
                </form>
                <?php if ($lastCheck !== []): ?>
                    <hr>
                    <div class="small">
                        <div>
                            Letzte Prüfung:
                            <?= $e((string) ($lastCheck['checked_at_local'] ?? '')) ?>
                            <?php if ($timezoneName !== ''): ?>
                                <span class="text-body-secondary">· <?= $e($timezoneName) ?></span>
                            <?php endif; ?>
                        </div>
                        <div>Neueste Version: <strong><?= $e((string) ($lastCheck['latest'] ?? '')) ?></strong></div>
                        <?php if ($updateAvailable): ?>
                            <span class="badge text-bg-warning mt-2">Update verfügbar</span>
                        <?php else: ?>
                            <span class="badge text-bg-success mt-2">Aktuell</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h6 mb-3">2. Vorbereiten</h2>
                <?php if ($updateAvailable): ?>
                    <dl class="small mb-3">
                        <dt class="text-body-secondary">Pakettyp</dt>
                        <dd><?= $e((string) ($package['type'] ?? 'bundled')) ?> <span class="text-body-secondary">(empfohlen)</span></dd>
                        <dt class="text-body-secondary">Download</dt>
                        <dd class="text-break"><?= $externalLink((string) ($package['url'] ?? '')) ?></dd>
                        <dt class="text-body-secondary">SHA256</dt>
                        <dd class="text-break font-monospace"><?= $e((string) ($package['sha256'] ?? '')) ?></dd>
                        <?php if ((string) ($metadata['changelog_url'] ?? '') !== ''): ?>
                            <dt class="text-body-secondary">Release</dt>
                            <dd><?= $externalLink((string) ($metadata['changelog_url'] ?? ''), 'Changelog öffnen') ?></dd>
                        <?php endif; ?>
                    </dl>
                    <form method="post" action="/admin/updates/prepare">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                        <button class="btn btn-primary" type="submit">Update vorbereiten</button>
                    </form>
                <?php else: ?>
                    <p class="text-body-secondary mb-0">Suche zuerst nach Updates. Wenn ein neueres Release verfügbar ist, kann es hier vorbereitet werden.</p>
                <?php endif; ?>

                <?php if ($prepared !== []): ?>
                    <hr>
                    <div class="small">
                        <div>Vorbereitet: <strong><?= $e((string) ($prepared['version'] ?? '')) ?></strong></div>
                        <?php if ((string) ($prepared['prepared_at_local'] ?? '') !== ''): ?>
                            <div>
                                Vorbereitet am:
                                <?= $e((string) ($prepared['prepared_at_local'] ?? '')) ?>
                                <?php if ($timezoneName !== ''): ?>
                                    <span class="text-body-secondary">· <?= $e($timezoneName) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div>Staging: <span class="text-break"><?= $e((string) ($prepared['staging_path'] ?? '')) ?></span></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h6 mb-3">3. Installieren</h2>
                <div class="alert alert-warning small" role="alert">
                    Bitte vor Updates ein Datenbank-Backup erstellen. Dateien werden vor dem Überschreiben unter <code>storage/backups/updates/</code> gesichert.
                </div>
                <?php if ($prepared !== []): ?>
                    <form method="post" action="/admin/updates/install" onsubmit="return confirm('Update jetzt installieren? Bitte stelle sicher, dass ein Datenbank-Backup vorhanden ist.');">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                        <button class="btn btn-danger" type="submit">Vorbereitetes Update installieren</button>
                    </form>
                    <?php if (!empty($prepared['requires_migrations'])): ?>
                        <p class="text-body-secondary small mt-3 mb-0">Dieses Release meldet mögliche Datenbankänderungen. v1 führt keine riskanten Migrationen automatisch aus.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-body-secondary mb-0">Noch kein geprüftes Update vorbereitet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($lastInstall !== []): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 app-card">
                <div class="card-body p-4">
                    <h2 class="h6 mb-3">Letzte Installation</h2>
                    <div class="row g-3 small">
                        <div class="col-12 col-md-3">Von: <strong><?= $e((string) ($lastInstall['from_version'] ?? '')) ?></strong></div>
                        <div class="col-12 col-md-3">Auf: <strong><?= $e((string) ($lastInstall['version'] ?? '')) ?></strong></div>
                        <div class="col-12 col-md-3">Dateien: <strong><?= $e((string) ($lastInstall['copied_files'] ?? '0')) ?></strong></div>
                        <div class="col-12 col-md-3">Backups: <strong><?= $e((string) ($lastInstall['backed_up_files'] ?? '0')) ?></strong></div>
                        <?php if ((string) ($lastInstall['installed_at_local'] ?? '') !== ''): ?>
                            <div class="col-12">
                                Installiert am:
                                <strong><?= $e((string) ($lastInstall['installed_at_local'] ?? '')) ?></strong>
                                <?php if ($timezoneName !== ''): ?>
                                    <span class="text-body-secondary">· <?= $e($timezoneName) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">Backup-Pfad: <span class="text-break"><?= $e((string) ($lastInstall['backup_path'] ?? '')) ?></span></div>
                        <?php if ((string) ($lastInstall['migration_note'] ?? '') !== ''): ?>
                            <div class="col-12 text-body-secondary"><?= $e((string) ($lastInstall['migration_note'] ?? '')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
