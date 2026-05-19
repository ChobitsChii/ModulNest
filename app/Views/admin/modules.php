<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$modules = is_array($modules ?? null) ? $modules : [];
$legacyEntries = is_array($legacy_entries ?? null) ? $legacy_entries : [];
$adminSection = (string) ($admin_section ?? 'modules');
?>
<?php require __DIR__ . '/partials/nav.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Admin / Module</h1>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<div id="module-toggle-feedback" class="module-toggle-feedback small mb-2" aria-live="polite"></div>

<div class="card shadow-sm border-0 app-card mb-4">
    <div class="card-body">
        <h2 class="h6 text-uppercase text-body-secondary mb-3">Neues Modul anlegen</h2>
        <form method="post" action="/admin/modules/create" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="new_name">Name</label>
                <input id="new_name" class="form-control form-control-sm" type="text" name="name" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="new_description">Beschreibung</label>
                <input id="new_description" class="form-control form-control-sm" type="text" name="description" maxlength="255" placeholder="Kurze Beschreibung">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label mb-1" for="new_prefix">Route Prefix</label>
                <input id="new_prefix" class="form-control form-control-sm" type="text" name="route_prefix" placeholder="banking" required>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label mb-1" for="new_access">Zugriff</label>
                <select id="new_access" class="form-select form-select-sm" name="access_level">
                    <option value="public">public</option>
                    <option value="user">user</option>
                    <option value="admin" selected>admin</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label mb-1" for="new_handler">Typ</label>
                <select id="new_handler" class="form-select form-select-sm" name="handler">
                    <option value="native">native</option>
                    <option value="placeholder">placeholder</option>
                    <option value="legacy">legacy</option>
                </select>
            </div>
            <div class="col-12 col-md-2 js-new-legacy-field">
                <label class="form-label mb-1" for="new_legacy_entry">Legacy Entry</label>
                <input id="new_legacy_entry" class="form-control form-control-sm" list="legacy_entries" type="text" name="legacy_entry" placeholder="banking/index.php">
            </div>
            <div class="col-12 col-md-2 js-new-legacy-field">
                <label class="form-label mb-1" for="new_admin_entry">Admin Entry (optional)</label>
                <input id="new_admin_entry" class="form-control form-control-sm" list="legacy_entries" type="text" name="admin_entry" placeholder="admin/dashboard.php">
            </div>
            <div class="col-6 col-md-1">
                <div class="form-check">
                    <input id="new_active" class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                    <label class="form-check-label small" for="new_active">Aktiv</label>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="form-check">
                    <input id="new_show_in_header" class="form-check-input" type="checkbox" name="show_in_header" value="1" checked>
                    <label class="form-check-label small" for="new_show_in_header">Im Header anzeigen</label>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="form-check">
                    <input id="new_show_on_home" class="form-check-input" type="checkbox" name="show_on_home" value="1" checked>
                    <label class="form-check-label small" for="new_show_on_home">Auf Startseite anzeigen</label>
                </div>
            </div>
            <div class="col-6 col-md-2 js-new-overlay-field">
                <div class="form-check">
                    <input id="new_overlay" class="form-check-input" type="checkbox" name="enable_overlay" value="1">
                    <label class="form-check-label small" for="new_overlay">Overlay</label>
                </div>
            </div>
            <div class="col-6 col-md-12">
                <button type="submit" class="btn btn-primary btn-sm">Modul erstellen</button>
            </div>
        </form>
        <datalist id="legacy_entries">
            <?php foreach ($legacyEntries as $entry): ?>
                <option value="<?= htmlspecialchars((string) $entry, ENT_QUOTES, 'UTF-8') ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>
</div>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 app-table">
                <thead>
                <tr>
                    <th class="ps-4 module-sort-column">Sortierung</th>
                    <th class="ps-4">Modul</th>
                    <th>Route Prefix</th>
                    <th>Zugriff</th>
                    <th>Links</th>
                    <th>Aktiv</th>
                    <th class="pe-4 text-end">Aktionen</th>
                </tr>
                </thead>
                <tbody id="js-module-sortable-body">
                <?php if ($modules === []): ?>
                    <tr><td colspan="7" class="ps-4 text-body-secondary">Keine Module vorhanden.</td></tr>
                <?php else: ?>
                    <?php foreach ($modules as $row): ?>
                        <?php
                        $id = (int) ($row['id'] ?? 0);
                        $access = (string) ($row['access_level'] ?? 'public');
                        $handler = (string) ($row['handler'] ?? 'placeholder');
                        $handlerLower = strtolower($handler);
                        $moduleUrl = (string) ($row['module_url'] ?? '');
                        $moduleAdminUrl = (string) ($row['module_admin_url'] ?? '');
                        $isActive = (int) ($row['is_active'] ?? 0) === 1;
                        ?>
                        <tr data-module-id="<?= $id ?>" class="module-sort-row">
                            <td class="ps-4 module-sort-column">
                                <button
                                    type="button"
                                    class="app-sort-handle module-sort-handle js-module-row-handle"
                                    aria-label="Modul sortieren"
                                    title="Per Drag & Drop sortieren"
                                >⋮⋮</button>
                            </td>
                            <td class="ps-4 fw-medium">
                                <?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars($handlerLower, ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><code><?= htmlspecialchars((string) ($row['route_prefix'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($access, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="text-nowrap">
                                <?php if ($moduleUrl !== ''): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($moduleUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">App</a>
                                <?php endif; ?>
                                <?php if ($moduleAdminUrl !== ''): ?>
                                    <a class="btn btn-sm btn-outline-secondary ms-1" href="<?= htmlspecialchars($moduleAdminUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Admin</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <label class="module-flag-switch" title="Aktiv">
                                    <input
                                        class="module-flag-switch-input js-module-toggle"
                                        type="checkbox"
                                        data-module-id="<?= $id ?>"
                                        data-field="is_active"
                                        <?= $isActive ? 'checked' : '' ?>
                                    >
                                    <span class="module-flag-switch-track" aria-hidden="true"></span>
                                </label>
                            </td>
                            <td class="pe-4 text-end">
                                <a href="/admin/modules/<?= $id ?>/edit" class="btn btn-sm btn-outline-secondary">Bearbeiten</a>
                                <form method="post" action="/admin/modules/delete" class="d-inline ms-1" onsubmit="return confirm('Modul wirklich löschen? Dieser Vorgang kann nicht rückgängig gemacht werden.');">
                                    <input type="hidden" name="module_id" value="<?= $id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
(() => {
    const createHandler = document.getElementById('new_handler');
    const createLegacyFields = Array.from(document.querySelectorAll('.js-new-legacy-field'));
    const createOverlayField = document.querySelector('.js-new-overlay-field');
    const createLegacyEntry = document.getElementById('new_legacy_entry');
    const createAdminEntry = document.getElementById('new_admin_entry');
    const createOverlay = document.getElementById('new_overlay');
    const feedback = document.getElementById('module-toggle-feedback');
    const sortableBody = document.getElementById('js-module-sortable-body');
    const setFeedback = (text, isError = false) => {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.classList.toggle('is-success', text !== '' && !isError);
        feedback.classList.toggle('is-error', isError);
        if (text !== '') {
            window.setTimeout(() => {
                if (feedback.textContent === text) {
                    feedback.textContent = '';
                    feedback.classList.remove('is-success');
                    feedback.classList.remove('is-error');
                }
            }, 2600);
        }
    };

    const syncCreateFieldsByType = () => {
        if (!createHandler) return;
        const isLegacy = createHandler.value === 'legacy';
        createLegacyFields.forEach((field) => {
            field.classList.toggle('d-none', !isLegacy);
        });
        if (createLegacyEntry) createLegacyEntry.required = isLegacy;
        if (createOverlayField) createOverlayField.classList.toggle('d-none', !isLegacy);
        if (createOverlay && !isLegacy) createOverlay.checked = false;
        if (!isLegacy) {
            if (createLegacyEntry) createLegacyEntry.value = '';
            if (createAdminEntry) createAdminEntry.value = '';
        }
    };

    if (createHandler) {
        createHandler.addEventListener('change', syncCreateFieldsByType);
        syncCreateFieldsByType();
    }

    document.querySelectorAll('.js-module-toggle').forEach((toggle) => {
        toggle.addEventListener('change', async () => {
            const previous = !toggle.checked;
            toggle.disabled = true;

            try {
                const response = await fetch('/admin/modules/toggle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        module_id: Number(toggle.dataset.moduleId || 0),
                        field: String(toggle.dataset.field || ''),
                        enabled: toggle.checked ? 1 : 0
                    })
                });

                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || 'Speichern fehlgeschlagen.');
                }
                setFeedback('Status gespeichert.');
            } catch (error) {
                toggle.checked = previous;
                setFeedback(error instanceof Error ? error.message : 'Speichern fehlgeschlagen.', true);
            } finally {
                toggle.disabled = false;
            }
        });
    });

    if (sortableBody && window.Sortable) {
        let previousOrder = [];

        const getModuleOrder = () => Array.from(sortableBody.querySelectorAll('tr[data-module-id]'))
            .map((row) => Number(row.dataset.moduleId || 0))
            .filter((id) => id > 0);

        const restoreModuleOrder = (order) => {
            const rowMap = new Map(
                Array.from(sortableBody.querySelectorAll('tr[data-module-id]'))
                    .map((row) => [Number(row.dataset.moduleId || 0), row])
            );
            order.forEach((id) => {
                const row = rowMap.get(id);
                if (row) {
                    sortableBody.appendChild(row);
                }
            });
        };

        new Sortable(sortableBody, {
            handle: '.js-module-row-handle',
            draggable: 'tr[data-module-id]',
            animation: 140,
            ghostClass: 'module-sort-ghost',
            chosenClass: 'module-sort-chosen',
            onStart: () => {
                previousOrder = getModuleOrder();
                sortableBody.classList.add('is-sorting');
            },
            onEnd: async () => {
                sortableBody.classList.remove('is-sorting');
                const orderedIds = getModuleOrder();

                try {
                    const response = await fetch('/admin/modules/reorder', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ module_ids: orderedIds })
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || 'Sortierung konnte nicht gespeichert werden.');
                    }
                    setFeedback('Reihenfolge gespeichert.');
                } catch (error) {
                    restoreModuleOrder(previousOrder);
                    setFeedback(error instanceof Error ? error.message : 'Sortierung konnte nicht gespeichert werden.', true);
                }
            }
        });
    }
})();
</script>
