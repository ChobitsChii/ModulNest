<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$adminSection = (string) ($admin_section ?? 'modules');
$module = is_array($module ?? null) ? $module : [];
$legacyEntries = is_array($legacy_entries ?? null) ? $legacy_entries : [];

$moduleId = (int) ($module['id'] ?? 0);
$name = (string) ($module['name'] ?? '');
$description = (string) ($module['description'] ?? '');
$routePrefix = (string) ($module['route_prefix'] ?? '');
$accessLevel = (string) ($module['access_level'] ?? 'public');
$handler = (string) ($module['handler'] ?? 'placeholder');
$legacyEntry = (string) ($module['legacy_entry'] ?? '');
$adminEntry = (string) ($module['admin_entry'] ?? '');
$enableOverlay = (int) ($module['enable_overlay'] ?? 0) === 1;
$moduleIsActive = (int) ($module['is_active'] ?? 0) === 1;
$showInHeader = (int) ($module['show_in_header'] ?? 1) === 1;
$showOnHome = (int) ($module['show_on_home'] ?? 1) === 1;
$nativeBinding = is_array($native_binding ?? null) ? $native_binding : null;
$csrfToken = (string) ($csrf_token ?? '');
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Modul bearbeiten</h1>
    <a href="/admin/modules" class="btn btn-outline-secondary btn-sm">Zurück zur Übersicht</a>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body">
        <form method="post" action="/admin/modules/update" class="row g-3 align-items-end">
            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
            <input type="hidden" name="module_id" value="<?= $moduleId ?>">

            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="module_name">Name</label>
                <input id="module_name" class="form-control" type="text" name="name" value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="module_description">Beschreibung</label>
                <input id="module_description" class="form-control" type="text" name="description" maxlength="255" value="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>" placeholder="Kurze Beschreibung">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="module_prefix">Route Prefix</label>
                <input id="module_prefix" class="form-control" type="text" name="route_prefix" value="<?= htmlspecialchars($routePrefix, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="module_access">Zugriff</label>
                <select id="module_access" class="form-select" name="access_level">
                    <?php foreach (['public', 'user', 'admin'] as $level): ?>
                        <option value="<?= $level ?>"<?= $accessLevel === $level ? ' selected' : '' ?>><?= $level ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="module_handler">Typ</label>
                <select id="module_handler" class="form-select" name="handler">
                    <?php foreach (['native', 'placeholder', 'legacy'] as $type): ?>
                        <option value="<?= $type ?>"<?= $handler === $type ? ' selected' : '' ?>><?= $type ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-4 js-edit-legacy-field">
                <label class="form-label mb-1" for="module_legacy_entry">Legacy Entry</label>
                <input id="module_legacy_entry" class="form-control" list="legacy_entries" type="text" name="legacy_entry" value="<?= htmlspecialchars($legacyEntry, ENT_QUOTES, 'UTF-8') ?>" placeholder="banking/index.php">
            </div>

            <div class="col-12 col-md-4 js-edit-legacy-field">
                <label class="form-label mb-1" for="module_admin_entry">Admin Entry</label>
                <input id="module_admin_entry" class="form-control" list="legacy_entries" type="text" name="admin_entry" value="<?= htmlspecialchars($adminEntry, ENT_QUOTES, 'UTF-8') ?>" placeholder="admin/dashboard.php">
            </div>

            <div class="col-6 col-md-2 js-edit-overlay-field">
                <div class="form-check">
                    <input id="module_overlay" class="form-check-input" type="checkbox" name="enable_overlay" value="1"<?= $enableOverlay ? ' checked' : '' ?>>
                    <label class="form-check-label" for="module_overlay">Overlay</label>
                </div>
            </div>

            <div class="col-12">
                <div class="d-flex flex-column flex-md-row gap-3 gap-md-4 align-items-md-center pt-2">
                    <div class="form-check mb-0">
                        <input id="module_active" class="form-check-input" type="checkbox" name="is_active" value="1"<?= $moduleIsActive ? ' checked' : '' ?>>
                        <label class="form-check-label" for="module_active">Aktiv</label>
                    </div>
                    <div class="form-check mb-0">
                        <input id="module_show_in_header" class="form-check-input" type="checkbox" name="show_in_header" value="1"<?= $showInHeader ? ' checked' : '' ?>>
                        <label class="form-check-label" for="module_show_in_header">Im Header anzeigen</label>
                    </div>
                    <div class="form-check mb-0">
                        <input id="module_show_on_home" class="form-check-input" type="checkbox" name="show_on_home" value="1"<?= $showOnHome ? ' checked' : '' ?>>
                        <label class="form-check-label" for="module_show_on_home">Auf Startseite anzeigen</label>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">Speichern</button>
                <a href="/admin/modules" class="btn btn-outline-secondary ms-1">Abbrechen</a>
            </div>

            <div class="col-12 js-edit-native-field">
                <hr class="my-2">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Interner Einstieg (Read-only)</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1" for="native_module_key">Modul-Key</label>
                        <input id="native_module_key" class="form-control" type="text" readonly value="<?= htmlspecialchars((string) ($nativeBinding['module_key'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1" for="native_internal_name">Interner Modulname</label>
                        <input id="native_internal_name" class="form-control" type="text" readonly value="<?= htmlspecialchars((string) ($nativeBinding['internal_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1" for="native_controller">Handler / Controller</label>
                        <input id="native_controller" class="form-control" type="text" readonly value="<?= htmlspecialchars((string) ($nativeBinding['controller'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1" for="native_path">Implementierungspfad</label>
                        <input id="native_path" class="form-control" type="text" readonly value="<?= htmlspecialchars((string) ($nativeBinding['implementation_path'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1" for="native_routes">Routenbindung</label>
                        <input id="native_routes" class="form-control" type="text" readonly value="<?= htmlspecialchars((string) ($nativeBinding['route_binding'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <p class="small text-body-secondary mt-3 mb-0">
                    Diese Felder sind rein informativ. Die technische Zuordnung nativer Module wird zentral in Modulon definiert.
                </p>
            </div>
        </form>
        <datalist id="legacy_entries">
            <?php foreach ($legacyEntries as $entry): ?>
                <option value="<?= htmlspecialchars((string) $entry, ENT_QUOTES, 'UTF-8') ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>
</div>

<script>
(() => {
    const handler = document.getElementById('module_handler');
    const legacyFields = Array.from(document.querySelectorAll('.js-edit-legacy-field'));
    const overlayField = document.querySelector('.js-edit-overlay-field');
    const legacyEntry = document.getElementById('module_legacy_entry');
    const adminEntry = document.getElementById('module_admin_entry');
    const overlay = document.getElementById('module_overlay');
    const nativeField = document.querySelector('.js-edit-native-field');
    if (!handler) return;

    const syncByType = () => {
        const isLegacy = handler.value === 'legacy';
        const isNative = handler.value === 'native';
        legacyFields.forEach((field) => {
            field.classList.toggle('d-none', !isLegacy);
        });
        if (legacyEntry) legacyEntry.required = isLegacy;
        if (overlayField) overlayField.classList.toggle('d-none', !isLegacy);
        if (nativeField) nativeField.classList.toggle('d-none', !isNative);
        if (!isLegacy) {
            if (overlay) overlay.checked = false;
            if (legacyEntry) legacyEntry.value = '';
            if (adminEntry) adminEntry.value = '';
        }
    };

    handler.addEventListener('change', syncByType);
    syncByType();
})();
</script>
