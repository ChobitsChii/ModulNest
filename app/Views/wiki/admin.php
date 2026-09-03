<?php
declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$source = is_array($source ?? null) ? $source : null;
$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$status = (string) ($source['last_sync_status'] ?? 'never');
$hasSuccessfulSync = $source !== null && (string) ($source['last_commit_sha'] ?? '') !== '';
$exampleSource = $source === null;
$statusLabel = $status === 'success' ? 'Erfolgreich' : ($status === 'failed' ? 'Fehlgeschlagen' : 'Noch nicht synchronisiert');
$statusClass = $status === 'success' ? 'text-bg-success' : ($status === 'failed' ? 'text-bg-danger' : 'text-bg-secondary');
$sourcePathUnavailable = $status === 'failed' && (string) ($source['last_error_code'] ?? '') === 'no_markdown_found';
?>
<div class="row g-4 wiki-admin">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Admin</p>
                <h1 class="h4 mb-2">Wiki verwalten</h1>
                <p class="text-body-secondary mb-0">Synchronisiere Markdown-Dokumentation aus einem öffentlichen GitHub-Repository und stelle sie lokal als Wiki bereit.</p>
                <?php if ($message !== ''): ?><div class="alert alert-success mt-3 mb-0" role="status"><?= $e($message) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert alert-danger mt-3 mb-0" role="alert"><?= $e($error) ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <section class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h5 mb-1">GitHub-Quelle</h2>
                <p class="text-body-secondary small mb-4">Es werden ausschließlich öffentliche GitHub-Repositories unterstützt. Die Inhalte bleiben nach einer erfolgreichen Synchronisierung lokal verfügbar.</p>
                <form method="post" action="/admin/wiki/save" class="row g-3" data-wiki-source-form>
                    <?= \Modulon\Core\View::csrfField($csrf_token) ?>
                    <div class="col-12">
                        <label class="form-label" for="wiki_url">GitHub-URL <span class="text-body-secondary">(optional)</span></label>
                        <input class="form-control" id="wiki_url" type="url" inputmode="url" autocomplete="off" placeholder="https://github.com/ChobitsChii/ModulNest/tree/main/docs" data-wiki-url>
                        <div class="form-text">Alternativ kannst du hier die URL zum Repository oder Dokumentationsordner einfügen. Die Felder darunter werden automatisch ausgefüllt.</div>
                        <div class="invalid-feedback" data-wiki-url-feedback></div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="wiki_owner">GitHub-Benutzer oder Organisation</label>
                        <input class="form-control" id="wiki_owner" required name="owner" maxlength="39" autocomplete="off" value="<?= $e($source['repository_owner'] ?? 'ChobitsChii') ?>">
                        <div class="form-text">Zum Beispiel: ChobitsChii</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="wiki_repository">Repository</label>
                        <input class="form-control" id="wiki_repository" required name="repository" maxlength="100" autocomplete="off" value="<?= $e($source['repository_name'] ?? 'ModulNest') ?>">
                        <div class="form-text">Zum Beispiel: ModulNest</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="wiki_ref">Branch oder Tag</label>
                        <input class="form-control" id="wiki_ref" name="ref" maxlength="160" value="<?= $e($source['ref_name'] ?? 'main') ?>">
                        <div class="form-text">Normalerweise <code>main</code>. Alternativ kann ein bestimmter Release-Tag verwendet werden.</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="wiki_docs_root">Dokumentationsordner</label>
                        <input class="form-control" id="wiki_docs_root" required name="docs_root" maxlength="255" value="<?= $e($source['docs_root'] ?? 'docs') ?>">
                        <div class="form-text">Pfad innerhalb des Repositories, z. B. <code>docs</code>.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" id="wiki_enabled" type="checkbox" name="enabled" value="1" <?= !isset($source['enabled']) || (int) $source['enabled'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="wiki_enabled">Quelle aktiviert</label>
                        </div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Konfiguration speichern</button></div>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-5">
        <section class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4 d-flex flex-column">
                <h2 class="h5 mb-3">Synchronisationsstatus</h2>
                <?php if (!$hasSuccessfulSync): ?>
                    <p class="text-body-secondary">Noch keine Dokumentation synchronisiert.</p>
                <?php endif; ?>
                <?php if ($source !== null): ?>
                    <dl class="row small mb-4">
                        <dt class="col-sm-5 text-body-secondary">Status</dt><dd class="col-sm-7"><span class="badge <?= $statusClass ?>"><?= $e($statusLabel) ?></span></dd>
                        <dt class="col-sm-5 text-body-secondary">Repository</dt><dd class="col-sm-7 text-break"><?= $e((string) $source['repository_owner'] . '/' . (string) $source['repository_name']) ?></dd>
                        <dt class="col-sm-5 text-body-secondary">Branch oder Tag</dt><dd class="col-sm-7"><?= $e($source['ref_name'] ?? 'main') ?></dd>
                        <dt class="col-sm-5 text-body-secondary">Dokumentationsordner</dt><dd class="col-sm-7 text-break"><?= $e($source['docs_root'] ?? 'docs') ?></dd>
                        <dt class="col-sm-5 text-body-secondary">Letzter Sync</dt><dd class="col-sm-7"><?= $e($source['last_sync_at'] ?? '—') ?></dd>
                        <dt class="col-sm-5 text-body-secondary">Commit</dt><dd class="col-sm-7 font-monospace"><?= $e(!empty($source['last_commit_sha']) ? substr((string) $source['last_commit_sha'], 0, 12) : '—') ?></dd>
                        <dt class="col-sm-5 text-body-secondary">Seiten</dt><dd class="col-sm-7"><?= (int) $page_count ?></dd>
                        <dt class="col-sm-5 text-body-secondary">Bilder</dt><dd class="col-sm-7"><?= (int) $asset_count ?></dd>
                    </dl>
                    <?php if ($status === 'failed'): ?>
                        <div class="alert alert-warning small" role="alert">
                            <?php if ($sourcePathUnavailable): ?>Der Dokumentationsordner enthält in der veröffentlichten Quelle noch keine unterstützten Markdown-Dateien. Prüfe Branch und Dokumentationsordner; bei ModulNest ist <code>docs/development</code> erst nutzbar, sobald diese Dokumentation öffentlich verfügbar ist.<?php else: ?>Die letzte Synchronisierung ist fehlgeschlagen. Der zuvor synchronisierte lokale Wiki-Stand bleibt verfügbar.<?php endif; ?>
                            <?php if (!empty($source['last_error_code'])): ?><details class="mt-2"><summary>Technische Details</summary><code><?= $e($source['last_error_code']) ?></code></details><?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-body-secondary">Speichere zuerst eine Quelle. Für ModulNest sind die vorgeschlagenen Werte bereits eingetragen.</p>
                <?php endif; ?>
                <form method="post" action="/admin/wiki/sync" class="mt-auto">
                    <?= \Modulon\Core\View::csrfField($csrf_token) ?>
                    <button class="btn btn-outline-primary" type="submit" <?= $source === null ? 'disabled' : '' ?>>Jetzt synchronisieren</button>
                </form>
            </div>
        </section>
    </div>
</div>
<script src="/assets/js/wiki.js" defer></script>
