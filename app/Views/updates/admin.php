<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$status = is_array($status ?? null) ? $status : [];
$state = is_array($status['state'] ?? null) ? $status['state'] : [];
$lastCheck = is_array($state['last_check'] ?? null) ? $state['last_check'] : [];
$prepared = is_array($state['prepared'] ?? null) ? $state['prepared'] : [];
$lastInstall = is_array($state['last_install'] ?? null) ? $state['last_install'] : [];
$recovery = is_array($state['recovery_required'] ?? null) ? $state['recovery_required'] : [];
$metadata = is_array($lastCheck['metadata'] ?? null) ? $lastCheck['metadata'] : [];
$package = is_array($lastCheck['package'] ?? null) ? $lastCheck['package'] : [];
$csrfToken = (string) ($csrf_token ?? '');
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$installedVersion = (string) ($status['installed_version'] ?? '');
$installedReleaseLabel = (string) ($status['installed_release_label'] ?? 'Stable');
$feedUrl = (string) ($status['feed_url'] ?? '');
$prereleaseFeedUrl = (string) ($status['prerelease_feed_url'] ?? '');
$updateChannel = (string) ($status['update_channel'] ?? 'stable');
$updateChannelLabel = (string) ($status['update_channel_label'] ?? 'Stable');
$timezoneName = (string) ($status['timezone_name'] ?? '');
$updateAvailable = (bool) ($lastCheck['available'] ?? false);
$mirror = is_array($mirror_status ?? null) ? $mirror_status : [];
$mirrorConfigured = (bool) ($mirror['configured'] ?? false);
$mirrorRunning = (bool) ($mirror['is_running'] ?? false);
$mirrorExecutable = (bool) ($mirror['script_executable'] ?? false);
$backupsOverview = is_array($backups_overview ?? null) ? $backups_overview : ['total_size' => 0, 'total_size_formatted' => '0 B', 'backups_count' => 0, 'items' => []];
$backupItems = is_array($backupsOverview['items'] ?? null) ? $backupsOverview['items'] : [];

$activeTab = (string) ($_GET['tab'] ?? 'updates');
if (!in_array($activeTab, ['updates', 'sources'], true)) {
    $activeTab = 'updates';
}

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
                        <div><span>Installiert:</span> <strong><?= $e($installedVersion) ?> (<?= $e($installedReleaseLabel) ?>)</strong></div>
                        <div><span>Updatekanal:</span> <strong><?= $e($updateChannelLabel) ?></strong></div>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div>
                <?php endif; ?>
                <?php if ($recovery !== []): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert">
                        <strong>Update-Recovery erforderlich.</strong>
                        <?= $e((string) ($recovery['message'] ?? 'Die Installation wurde nicht als erfolgreich markiert.')) ?>
                        <?php if ((string) ($recovery['backup_path'] ?? '') !== ''): ?>
                            <div class="mt-1">Backup: <code><?= $e((string) $recovery['backup_path']) ?></code></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <nav class="nav nav-pills" aria-label="Update-Bereiche">
            <a class="nav-link<?= $activeTab === 'updates' ? ' active' : '' ?>" href="/admin/updates?tab=updates">
                <i class="bi bi-arrow-repeat me-1"></i> System-Updates
            </a>
            <a class="nav-link<?= $activeTab === 'sources' ? ' active' : '' ?>" href="/admin/updates?tab=sources">
                <i class="bi bi-hdd-network me-1"></i> Update-Quellen
                <span class="badge text-bg-light ms-1"><?= count($sources ?? []) ?></span>
            </a>
        </nav>
    </div>

    <?php if ($activeTab === 'updates'): ?>
        <style>
            .history-collapse-toggle {
                cursor: pointer;
                user-select: none;
                transition: background-color 0.15s ease;
                border-radius: var(--bs-border-radius);
                padding: 0.5rem 0.75rem;
                margin: -0.5rem -0.75rem;
            }
            .history-collapse-toggle:hover {
                background-color: rgba(var(--bs-primary-rgb), 0.05);
            }
            .history-collapse-toggle .history-chevron {
                transition: transform 0.2s ease;
            }
            .history-collapse-toggle[aria-expanded="true"] .history-chevron {
                transform: rotate(180deg);
            }
        </style>
        <div class="col-12">
            <div class="card shadow-sm border-0 app-card">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-0 history-collapse-toggle"
                         role="button"
                         tabindex="0"
                         data-bs-toggle="collapse"
                         data-bs-target="#collapseUpdateChannel"
                         aria-expanded="false"
                         aria-controls="collapseUpdateChannel">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <i class="bi bi-funnel text-primary"></i>
                            <h2 class="h6 mb-0">Updatekanal</h2>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <?= $e($updateChannelLabel) ?>
                            </span>
                            <?php if ($updateChannel === 'preview'): ?>
                                <span class="badge text-bg-warning">Vorabversionen aktiviert</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-primary small fw-semibold">
                            <span>Kanal einstellen</span>
                            <i class="bi bi-chevron-down history-chevron"></i>
                        </div>
                    </div>

                    <div class="collapse mt-3" id="collapseUpdateChannel">
                        <div class="border-top pt-3">
                            <p class="text-body-secondary small">Diese Einstellung gilt für die gesamte ModulNest-Installation.</p>
                            <form method="post" action="/admin/updates/channel">
                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="update_channel" value="stable" id="update_channel_stable"<?= $updateChannel === 'stable' ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="update_channel_stable"><strong>Stable</strong><br><span class="small text-body-secondary">Nur fertige, empfohlene Releases.</span></label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="update_channel" value="preview" id="update_channel_preview"<?= $updateChannel === 'preview' ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="update_channel_preview"><strong>Stable + Vorabversionen</strong><br><span class="small text-body-secondary">Zusätzlich Alpha-, Beta- und Release-Candidate-Versionen. Diese können noch Fehler enthalten.</span></label>
                                </div>
                                <button class="btn btn-primary btn-sm mt-3" type="submit">Updatekanal speichern</button>
                            </form>
                            <?php if ($updateChannel === 'preview'): ?>
                                <div class="alert alert-warning small mt-3 mb-0" role="status"><strong>Vorabversionen sind aktiviert.</strong> Stable-Releases werden weiterhin berücksichtigt und ersetzen ältere Vorabversionen automatisch.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm border-0 app-card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 mb-3">1. Prüfen</h2>
                    <dl class="mb-3 small">
                        <dt class="text-body-secondary">Updatequelle</dt>
                        <dd class="text-break"><?= $externalLink($feedUrl, 'updates.modulnest.de – offizielle Quelle') ?></dd>
                        <?php if ($updateChannel === 'preview'): ?>
                            <dt class="text-body-secondary">Vorab-Feed</dt>
                            <dd class="text-break"><?= $externalLink($prereleaseFeedUrl, 'updates.modulnest.de – offizielle Vorabquelle') ?></dd>
                        <?php endif; ?>
                    </dl>
                    <form method="post" action="/admin/updates/check">
                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
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
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
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
                    <div class="alert alert-info small d-flex flex-column gap-2" role="alert">
                        <div>
                            <i class="bi bi-shield-check text-success me-1"></i>
                            <strong>Automatische Sicherung vor Installation:</strong> Unmittelbar vor dem Überschreiben der Dateien wird automatisch ein vollständiges Backup aller betroffenen Dateien sowie der aktuellen Datenbank unter <code>storage/backups/updates/</code> als ZIP archiviert.
                        </div>
                        <div class="border-top border-info-subtle pt-2 mt-1">
                            <div class="fw-semibold mb-1">
                                <i class="bi bi-database me-1"></i>Manuelles Live-Backup:
                            </div>
                            <form method="post" action="/admin/updates/backup-db" class="d-inline">
                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Erstellt in Echtzeit ein frisches Backup der aktuellen Live-Datenbank">
                                    <i class="bi bi-file-earmark-zip me-1"></i>Aktuelle Live-Datenbank sichern (.zip)
                                </button>
                            </form>
                            <div class="text-body-secondary mt-1" style="font-size: 0.78rem;">
                                Erzeugt in Echtzeit einen tagesaktuellen SQL-Dump des jetzigen Live-Zustands.
                            </div>
                        </div>
                    </div>
                    <?php if ($prepared !== []): ?>
                        <form method="post" action="/admin/updates/install" onsubmit="return confirm('Update jetzt installieren? Vor dem Überschreiben wird automatisch ein vollständiges Datenbank- und Datei-Backup erstellt.');">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <button class="btn btn-danger" type="submit">Vorbereitetes Update installieren</button>
                        </form>
                        <?php if (!empty($prepared['requires_migrations'])): ?>
                            <p class="text-body-secondary small mt-3 mb-0">Dieses Release enthält mögliche Datenbankänderungen. Automatische Migrationen werden während der Installation ausgeführt, sofern Migrationen im Paket enthalten sind.</p>
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
                            <?php
                            $latestBackupMatch = null;
                            foreach ($backupItems as $bItem) {
                                if ((string) ($bItem['backup_path'] ?? '') === (string) ($lastInstall['backup_path'] ?? '') || (string) ($bItem['id'] ?? '') === basename((string) ($lastInstall['backup_path'] ?? ''))) {
                                    $latestBackupMatch = $bItem;
                                    break;
                                }
                            }
                            ?>
                            <?php if ($latestBackupMatch !== null): ?>
                                <div class="col-12 col-md-6">Größe dieses Backups: <strong><?= $e((string) $latestBackupMatch['total_size_formatted']) ?></strong> <span class="text-body-secondary small">(inkl. komprimiertem Datenbank-Backup)</span></div>
                            <?php endif; ?>
                            <?php if (!empty($lastInstall['database_backup'])): ?>
                                <div class="col-12 d-flex flex-wrap align-items-center gap-2">
                                    <span><i class="bi bi-file-earmark-zip text-success me-1"></i>Datenbank-Backup: <span class="text-break"><strong><?= $e(basename((string) $lastInstall['database_backup'])) ?></strong> (gesichert)</span></span>
                                    <?php if ($latestBackupMatch !== null): ?>
                                        <a href="/admin/updates/backup-db?id=<?= urlencode((string) $latestBackupMatch['id']) ?>" class="btn btn-sm btn-outline-success py-0 px-2" title="SQL-Dump (.zip) dieses Updates herunterladen">
                                            <i class="bi bi-download me-1"></i>Dump herunterladen
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (is_array($lastInstall['migrations'] ?? null)): ?>
                                <?php $migrations = $lastInstall['migrations']; ?>
                                <?php
                                $executedMigrations = count(is_array($migrations['executed'] ?? null) ? $migrations['executed'] : []);
                                $skippedMigrations = count(is_array($migrations['skipped'] ?? null) ? $migrations['skipped'] : []);
                                $migrationErrors = count(is_array($migrations['errors'] ?? null) ? $migrations['errors'] : []);
                                ?>
                                <div class="col-12">
                                    <div class="alert <?= $migrationErrors > 0 ? 'alert-warning' : 'alert-success' ?> small mb-0" role="status">
                                        <?php if ($migrationErrors > 0): ?>
                                            Automatische Migrationen wurden ausgeführt, dabei wurden Fehler gemeldet. Bitte Details im Update-Log prüfen.
                                        <?php else: ?>
                                            Dieses Update enthielt mögliche Datenbankänderungen. Automatische Migrationen wurden ausgeführt, sofern Migrationen im Paket enthalten waren.
                                        <?php endif; ?>
                                        <div class="mt-1">
                                            Migrationen:
                                            <?= $e((string) $executedMigrations) ?> ausgeführt,
                                            <?= $e((string) $skippedMigrations) ?> übersprungen,
                                            <?= $e((string) $migrationErrors) ?> Fehler
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ((string) ($lastInstall['migration_note'] ?? '') !== ''): ?>
                                <div class="col-12 text-body-secondary"><?= $e((string) ($lastInstall['migration_note'] ?? '')) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card shadow-sm border-0 app-card">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-0 history-collapse-toggle"
                         role="button"
                         tabindex="0"
                         data-bs-toggle="collapse"
                         data-bs-target="#collapseUpdateHistory"
                         aria-expanded="false"
                         aria-controls="collapseUpdateHistory">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <i class="bi bi-clock-history text-primary"></i>
                            <h2 class="h6 mb-0">Update-Historie & Backups</h2>
                            <span class="badge bg-secondary-subtle text-secondary border">
                                <?= $e((string) ($backupsOverview['backups_count'] ?? 0)) ?> Backups
                            </span>
                            <span class="badge bg-info-subtle text-info border">
                                <?= $e((string) ($backupsOverview['total_size_formatted'] ?? '0 B')) ?> Belegter Speicherplatz
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-primary small fw-semibold">
                            <span>Historie ein-/ausklappen</span>
                            <i class="bi bi-chevron-down history-chevron"></i>
                        </div>
                    </div>

                    <div class="collapse mt-3" id="collapseUpdateHistory">
                        <div class="border-top pt-3">
                            <?php if ($backupItems === []): ?>
                                <p class="text-body-secondary small mb-0">Bisher wurden keine archivierten Update-Backups gefunden.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0 small">
                                        <thead>
                                            <tr class="table-light">
                                                <th>Datum</th>
                                                <th>Version</th>
                                                <th>Backup-Größe</th>
                                                <th>SQL-Dump</th>
                                                <th class="text-end">Aktionen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($backupItems as $item): ?>
                                                <tr>
                                                    <td class="text-nowrap">
                                                        <i class="bi bi-calendar-event me-1 text-body-secondary"></i>
                                                        <strong><?= $e((string) ($item['created_at_formatted'] ?? $item['created_at'] ?? '')) ?></strong>
                                                    </td>
                                                    <td class="text-nowrap">
                                                        <?php if (!empty($item['from_version'])): ?>
                                                            <?= $e((string) $item['from_version']) ?> <i class="bi bi-arrow-right text-body-secondary mx-1"></i>
                                                        <?php endif; ?>
                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                                            <?= $e((string) ($item['version'] ?? '')) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-nowrap">
                                                        <strong><?= $e((string) ($item['total_size_formatted'] ?? '0 B')) ?></strong>
                                                        <span class="text-body-secondary small ms-1">(<?= $e((string) ($item['file_count'] ?? 0)) ?> Dateien)</span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($item['has_database_backup'])): ?>
                                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                                    <i class="bi bi-shield-check me-1"></i>Vorhanden (<?= $e((string) ($item['database_backup_size_formatted'] ?? '')) ?>)
                                                                </span>
                                                                <a href="/admin/updates/backup-db?id=<?= urlencode((string) $item['id']) ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="SQL-Dump (.zip) dieses Updates herunterladen">
                                                                    <i class="bi bi-download me-1"></i>Herunterladen
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-body-secondary border">
                                                                <i class="bi bi-dash-circle me-1"></i>Nicht enthalten
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end text-nowrap">
                                                        <form method="post" action="/admin/updates/backups/delete" onsubmit="return confirm('Möchtest du das Backup von <?= $e((string) ($item['version'] ?? $item['id'])) ?> (<?= $e((string) ($item['total_size_formatted'] ?? '0 B')) ?>) wirklich unwiderruflich löschen?');" class="d-inline">
                                                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                            <input type="hidden" name="id" value="<?= $e((string) $item['id']) ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Backup löschen, um Speicherplatz freizugeben">
                                                                <i class="bi bi-trash me-1"></i>Löschen
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($activeTab === 'sources'): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 app-card mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1">Vorhandene Update-Quellen</h2>
                            <p class="text-body-secondary small mb-0">Modulon bezieht System-Updates und Sicherheitsaktualisierungen von der hier ausgewählten Update-Quelle.</p>
                        </div>
                    </div>

                    <div class="table-responsive mb-4">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Quelle</th>
                                <th>Basis-URL</th>
                                <th>Status</th>
                                <th class="text-end">Aktionen</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sources as $src):
                                $isOfficial = !empty($src['is_official']);
                                $isActive = !empty($src['is_active']);
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= $e($src['name'] ?? '') ?></div>
                                        <?php if ($isOfficial): ?>
                                            <span class="badge text-bg-primary">Offiziell</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Benutzerdefiniert</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code class="text-break"><?= $e($src['base_url'] ?? '') ?></code>
                                    </td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-light border text-body-secondary">Inaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2">
                                            <?php if (!$isActive): ?>
                                                <form method="post" action="/admin/updates/sources/activate" class="d-inline">
                                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                    <input type="hidden" name="id" value="<?= $e($src['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Aktivieren</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (!$isOfficial): ?>
                                                <form method="post" action="/admin/updates/sources/delete" class="d-inline" onsubmit="return confirm('Update-Quelle wirklich löschen?');">
                                                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                                    <input type="hidden" name="id" value="<?= $e($src['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Quelle löschen"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="border-top pt-4">
                        <h3 class="h6 mb-3">Neue Update-Quelle hinzufügen</h3>
                        <form method="post" action="/admin/updates/sources/add" class="row g-3 align-items-end">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <div class="col-12 col-md-5">
                                <label class="form-label small" for="src_name">Bezeichnung</label>
                                <input type="text" class="form-control form-control-sm" id="src_name" name="name" placeholder="z. B. Eigener Update-Spiegel" required>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label small" for="src_url">Basis-URL (HTTPS)</label>
                                <input type="url" class="form-control form-control-sm" id="src_url" name="base_url" placeholder="https://updates.example.com/core" required>
                            </div>
                            <div class="col-12 col-md-2">
                                <button type="submit" class="btn btn-primary btn-sm w-100">Hinzufügen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
