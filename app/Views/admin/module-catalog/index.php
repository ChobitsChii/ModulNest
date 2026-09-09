<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modules = is_array($modules ?? null) ? $modules : [];
$tab = (string) ($tab ?? 'entdecken');
$counts = is_array($counts ?? null) ? $counts : [];
$badgeClass = static fn (string $classification): string => \Modulon\Core\Modules\ModulePresentation::typeBadgeClass($classification);
?>
<link rel="stylesheet" href="/assets/css/module-catalog.css">
<div class="d-flex flex-wrap gap-3 align-items-start justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-1">Modul-Katalog</h1>
        <p class="text-body-secondary mb-0">Core, Modul-v1-Bestand und unabhängig verwaltete Modul-v2-Pakete auf einen Blick.</p>
    </div>
</div>

<?php if (($test_key_active ?? false) === true): ?>
    <div class="alert alert-warning small" data-catalog-test-key>
        <strong>Entwicklungsmodus:</strong> Der lokale Dev-Katalog verwendet ausschließlich einen TEST-ONLY-Signaturschlüssel.
    </div>
<?php endif; ?>
<?php if (($catalog_warning ?? '') !== ''): ?>
    <div class="alert alert-warning" data-catalog-warning><?= $e($catalog_warning) ?></div>
<?php endif; ?>
<?php if (($message ?? '') !== ''): ?>
    <div class="alert alert-success" data-catalog-message><?= $e($message) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
    <div class="alert alert-danger" data-catalog-error><?= $e($error) ?></div>
<?php endif; ?>

<?php if (($clean_install ?? false) === true): ?>
    <section class="card app-card border-0 shadow-sm mb-4" data-clean-install-selection>
        <div class="card-body p-4">
            <h2 class="h5">ModulNest einrichten</h2>
            <p class="text-body-secondary">Wähle die Produktmodule aus. Installation und Aktivierung laufen über denselben verifizierten Katalog-Lifecycle wie spätere Modulaktionen.</p>
            <?php if (($clean_install_modules ?? []) === []): ?>
                <div class="alert alert-warning mb-0">Der verifizierte Modulkatalog enthält derzeit keine kompatiblen Pakete.</div>
            <?php else: ?>
                <form method="post" action="/admin/module-catalog/action" class="catalog-action-form">
                    <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                    <input type="hidden" name="action" value="install-selected">
                    <div class="row g-2 mb-3">
                        <?php foreach ($clean_install_modules as $choice): ?>
                            <div class="col-12 col-md-6">
                                <label class="module-choice h-100">
                                    <input type="checkbox" name="module_ids[]" value="<?= $e($choice['id']) ?>" <?= !empty($choice['default_selected']) ? 'checked' : '' ?>>
                                    <span><strong><?= $e($choice['name']) ?></strong><br><span class="text-body-secondary small">Version <?= $e($choice['version']) ?></span></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary" type="submit">Ausgewählte Module installieren</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<nav class="nav nav-pills catalog-tabs mb-4" aria-label="Katalogbereiche">
    <?php foreach (['entdecken' => 'Entdecken', 'installiert' => 'Installiert', 'updates' => 'Updates'] as $key => $label): ?>
        <a class="nav-link<?= $tab === $key ? ' active' : '' ?>" href="/admin/module-catalog?bereich=<?= $key ?>">
            <?= $label ?> <span class="badge text-bg-light ms-1"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'updates' && ($batch_update_plan ?? []) !== []): ?>
    <section class="card app-card border-primary shadow-sm mb-4" data-batch-update-plan>
        <div class="card-body p-4">
            <h2 class="h5">Alle aktualisieren</h2>
            <p class="text-body-secondary">Die ausgewählten Updates werden nacheinander ausgeführt. Jedes Modul erhält seinen eigenen Backup- und Rollback-Schritt.</p>
            <form method="post" action="/admin/module-catalog/action" class="catalog-action-form">
                <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?><input type="hidden" name="action" value="update-selected">
                <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Auswahl</th><th>Modul</th><th>Version</th><th>Migrationen</th><th>Dependencies</th><th>Backup</th></tr></thead><tbody>
                <?php foreach ($batch_update_plan as $item): ?><tr><td><input class="form-check-input" type="checkbox" name="module_ids[]" value="<?= $e($item['module_id']) ?>" checked></td><td><?= $e($item['name']) ?></td><td><?= $e($item['from']) ?> → <?= $e($item['to']) ?></td><td><?= (int)$item['migration_count'] ?></td><td><?= $item['dependencies'] === [] ? 'keine' : $e(implode(', ', array_keys($item['dependencies']))) ?></td><td>wird erstellt und geprüft</td></tr><?php endforeach; ?>
                </tbody></table></div><button class="btn btn-primary" type="submit">Alle aktualisieren</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php if (is_array($batch_update_operation ?? null)): $batch=$batch_update_operation; ?>
    <section class="alert <?= ($batch['status']??'')==='failed'?'alert-danger':(($batch['status']??'')==='succeeded'?'alert-success':'alert-info') ?>" data-batch-update-status>
        <strong><?= ($batch['status']??'')==='failed'?'Modulupdates fehlgeschlagen':(($batch['status']??'')==='succeeded'?'Modulupdates abgeschlossen':'Modulupdates laufen') ?></strong>
        <div data-batch-summary><?= (int)($batch['current']??0) ?> / <?= (int)($batch['total']??0) ?></div>
        <ul class="mb-0 mt-2" data-batch-modules><?php foreach (($batch['modules']??[]) as $item): $state=(string)($item['status']??'waiting');$stateLabel=['succeeded'=>'erfolgreich','running'=>'wird aktualisiert','failed'=>'fehlgeschlagen','waiting'=>'wartet'][$state]??$state;?><li><?= $state==='succeeded'?'✓':($state==='running'?'→':'•') ?> <?= $e($item['name']??$item['module_id']) ?> – <?= $e($stateLabel) ?><?php if(!empty($item['error'])):?>: <?= $e($item['error']) ?><?php endif;?></li><?php endforeach;?></ul>
    </section>
<?php endif; ?>

<?php if ($modules === []): ?>
    <div class="card app-card border-0 shadow-sm">
        <div class="card-body p-4">
            <h2 class="h5">Hier ist alles erledigt</h2>
            <p class="text-body-secondary mb-0"><?php
                if ($tab === 'updates') {
                    echo 'Für bereits als Modul v2 verwaltete Module liegen keine Aktualisierungen vor.';
                } elseif ($tab === 'entdecken') {
                    echo 'Alle verfügbaren Module sind auf dieser Installation bereits vorhanden.';
                } else {
                    echo 'In diesem Bereich sind derzeit keine Module vorhanden.';
                }
            ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="catalog-grid">
        <?php foreach ($modules as $module):
            $classification = (string) ($module['classification'] ?? 'local');
            $isV2 = $classification === 'v2';
        ?>
            <article class="card app-card border-0 shadow-sm catalog-card"
                     data-module-id="<?= $e($module['id']) ?>"
                     data-module-class="<?= $e($classification) ?>">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h5 mb-1"><?= $e($module['name']) ?></h2>
                            <span class="badge rounded-pill <?= $badgeClass($classification) ?>"><?= $e($module['classification_label']) ?></span>
                            <?php if ($isV2 && !in_array((string) $module['origin_label'], ['', 'Katalog'], true)): ?>
                                <span class="badge rounded-pill text-bg-secondary"><?= $e($module['origin_label']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($module['retained']) && !empty($module['has_owned_data'])): ?>
                                <span class="badge rounded-pill text-bg-warning">Daten vorhanden</span>
                            <?php elseif (!empty($module['retained'])): ?>
                                <span class="badge rounded-pill text-bg-secondary">Keine Moduldaten</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($classification === 'core'): ?>
                            <span class="small text-body-secondary text-nowrap">Core <?= $e($module['core_version'] ?? '') ?></span>
                        <?php elseif ($isV2 && ($module['installed_version'] ?? null) !== null): ?>
                            <span class="small text-body-secondary text-nowrap">Version <?= $e($module['installed_version']) ?></span>
                        <?php elseif ($isV2 && ($module['available_version'] ?? null) !== null): ?>
                            <span class="small text-body-secondary text-nowrap">Version <?= $e($module['available_version']) ?></span>
                        <?php endif; ?>
                    </div>

                    <p class="text-body-secondary mt-3 flex-grow-1"><?= $e($module['description'] ?? '') ?></p>
                    <div class="small mb-3 catalog-card-status">
                        <?php if (!empty($module['adoption_candidate']) && empty($module['adoptable'])): ?>
                            <?php $preflight = $module['adoption_preflight'] ?? []; ?>
                            <strong class="text-warning-emphasis"><?= ($preflight['status'] ?? '') === 'changed' ? 'Modul v1 wurde lokal verändert' : 'Automatische Umstellung nicht möglich' ?></strong><br>
                            <span class="text-body-secondary"><?= ($preflight['status'] ?? '') === 'changed' ? 'Automatische Umstellung nicht möglich.' : $e($preflight['message'] ?? 'Der Preflight wurde nicht bestanden.') ?></span>
                        <?php elseif (!empty($module['adoptable'])): ?>
                            <strong>Modul-v2-Version <?= $e($module['available_version']) ?> verfügbar</strong><br>
                            <span class="text-body-secondary">Der vorhandene Modul-v1-Stand bleibt bis zur manuellen Umstellung aktiv.</span>
                        <?php elseif (($module['latest_update_compatible'] ?? true) === false): ?>
                            <strong class="text-warning-emphasis">Update <?= $e($module['latest_version']) ?> nicht kompatibel</strong><br>
                            <span class="text-body-secondary"><?= $e($module['update_incompatibility_reason'] ?? 'Die Voraussetzungen werden nicht erfüllt.') ?></span>
                        <?php elseif (!empty($module['update_available'])): ?>
                            <strong>Update:</strong> <?= $e($module['installed_version']) ?> → <?= $e($module['available_version']) ?>
                        <?php elseif ($classification === 'core'): ?>
                            <strong>Status:</strong> Fester Bestandteil von ModulNest
                        <?php elseif (empty($module['compatible'])): ?>
                            <span class="text-warning-emphasis"><?= $e($module['incompatibility_reason']) ?></span>
                        <?php elseif (!empty($module['installed'])): ?>
                            <strong>Status:</strong> <?= !empty($module['active']) ? 'Aktiv' : 'Inaktiv' ?>
                        <?php else: ?>
                            <strong>Verfügbar:</strong> Version <?= $e($module['available_version']) ?>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex flex-wrap gap-2 catalog-card-actions">
                        <?php if ($isV2): ?>
                            <a class="btn btn-sm btn-outline-primary" href="/admin/module-catalog/<?= rawurlencode((string) $module['id']) ?>">Details</a>
                        <?php elseif (!empty($module['adoption_candidate'])): ?>
                            <a class="btn btn-sm btn-outline-primary" href="/admin/module-catalog/<?= rawurlencode((string) $module['id']) ?>">Details</a>
                            <?php if (!empty($module['adoptable'])): ?>
                            <a class="btn btn-sm btn-primary" href="/admin/module-catalog/<?= rawurlencode((string) $module['id']) ?>">Auf Modul v2 umstellen</a>
                            <?php elseif (!empty($module['reinstallable'])): ?>
                            <a class="btn btn-sm btn-primary" href="/admin/module-catalog/<?= rawurlencode((string) $module['id']) ?>#reinstall-v2">Modul v2 neu installieren</a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($tab === 'entdecken' && $isV2): ?>
                            <form method="post" action="/admin/module-catalog/action" class="catalog-action-form">
                                <?= \Modulon\Core\View::csrfField((string) ($csrf_token ?? '')) ?>
                                <input type="hidden" name="module_id" value="<?= $e($module['id']) ?>">
                                <input type="hidden" name="action" value="install">
                                <button class="btn btn-sm btn-primary" type="submit" <?= empty($module['compatible']) ? 'disabled' : '' ?>>Installieren</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<script>document.querySelectorAll('.catalog-action-form').forEach(form=>form.addEventListener('submit',()=>form.querySelectorAll('button').forEach(button=>{button.disabled=true;button.setAttribute('aria-busy','true');})));</script>
<script>(()=>{const panel=document.querySelector('[data-batch-update-status]');if(!panel)return;const esc=value=>{const node=document.createElement('span');node.textContent=String(value??'');return node.innerHTML;};const labels={succeeded:'erfolgreich',running:'wird aktualisiert',failed:'fehlgeschlagen',waiting:'wartet'};const render=operation=>{if(!operation)return;panel.querySelector('[data-batch-summary]').textContent=(operation.current||0)+' / '+(operation.total||0);panel.querySelector('[data-batch-modules]').innerHTML=(operation.modules||[]).map(item=>'<li>'+(item.status==='succeeded'?'✓':item.status==='running'?'→':'•')+' '+esc(item.name||item.module_id)+' – '+esc(labels[item.status]||item.status||labels.waiting)+(item.error?': '+esc(item.error):'')+'</li>').join('');if(operation.status==='running'){setTimeout(poll,1500);return;}location.reload();};const poll=()=>fetch('/admin/module-catalog-update/status',{headers:{Accept:'application/json'},cache:'no-store'}).then(r=>r.ok?r.json():Promise.reject()).then(d=>render(d.operation)).catch(()=>setTimeout(poll,3000));<?php if (($batch_update_operation['status']??'') === 'running'): ?>poll();<?php endif; ?>})();</script>
