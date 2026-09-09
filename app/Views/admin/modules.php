<?php
declare(strict_types=1);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$message = (string) ($message ?? ''); $error = (string) ($error ?? '');
$modules = is_array($modules ?? null) ? $modules : []; $legacyEntries = is_array($legacy_entries ?? null) ? $legacy_entries : [];
$selectedColumns = is_array($module_columns ?? null) ? $module_columns : []; $availableColumns = is_array($available_module_columns ?? null) ? $available_module_columns : [];
$csrfToken = (string) ($csrf_token ?? '');
$columnClass = static fn (string $key): string => in_array($key, $selectedColumns, true) ? '' : ' d-none';
$switch = static function (int $id, string $field, bool $checked, string $label, bool $disabled = false): string {
    return '<label class="module-flag-switch" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><input class="module-flag-switch-input js-module-toggle" type="checkbox" data-module-id="' . $id . '" data-field="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '') . '><span class="module-flag-switch-track" aria-hidden="true"></span></label>';
};
?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4"><div><h1 class="h4 mb-1">Modulverwaltung</h1><p class="text-body-secondary mb-0">Lokale Konfiguration, Sichtbarkeit, Zugriff und Aktivstatus der registrierten Module.</p></div></div>
<?php if ($message !== ''): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<div id="module-toggle-feedback" class="module-toggle-feedback small mb-2" aria-live="polite"></div>

<div class="card shadow-sm border-0 app-card mb-4"><div class="card-body">
    <h2 class="h6 text-uppercase text-body-secondary mb-2">Manuellen Moduleintrag anlegen</h2>
    <p class="small text-body-secondary">Für Legacy-Anwendungen oder geplante Platzhalter. Modul-v2-Pakete werden über den Modul-Katalog installiert.</p>
    <form method="post" action="/admin/modules/create" class="row g-3 align-items-end">
        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
        <div class="col-12 col-md-3"><label class="form-label mb-1" for="new_name">Name</label><input id="new_name" class="form-control form-control-sm" name="name" required></div>
        <div class="col-12 col-md-3"><label class="form-label mb-1" for="new_description">Beschreibung</label><input id="new_description" class="form-control form-control-sm" name="description" maxlength="255"></div>
        <div class="col-12 col-md-2"><label class="form-label mb-1" for="new_prefix">Route Prefix</label><input id="new_prefix" class="form-control form-control-sm" name="route_prefix" required></div>
        <div class="col-12 col-md-2"><label class="form-label mb-1" for="new_access">Zugriff</label><select id="new_access" class="form-select form-select-sm" name="access_level"><option>public</option><option>user</option><option selected>admin</option></select></div>
        <div class="col-12 col-md-2"><label class="form-label mb-1" for="new_handler">Typ</label><select id="new_handler" class="form-select form-select-sm" name="handler"><option value="placeholder">Placeholder</option><option value="legacy">Legacy</option></select></div>
        <div class="col-12 col-md-3 js-new-legacy-field"><label class="form-label mb-1" for="new_legacy_entry">Legacy Entry</label><input id="new_legacy_entry" class="form-control form-control-sm" list="legacy_entries" name="legacy_entry"></div>
        <div class="col-12 col-md-3 js-new-legacy-field"><label class="form-label mb-1" for="new_admin_entry">Admin Entry (optional)</label><input id="new_admin_entry" class="form-control form-control-sm" list="legacy_entries" name="admin_entry"></div>
        <div class="col-6 col-md-1"><div class="form-check"><input id="new_active" class="form-check-input" type="checkbox" name="is_active" value="1" checked><label class="form-check-label small" for="new_active">Aktiv</label></div></div>
        <div class="col-6 col-md-2"><div class="form-check"><input id="new_show_in_header" class="form-check-input" type="checkbox" name="show_in_header" value="1"><label class="form-check-label small" for="new_show_in_header">Header</label></div></div>
        <div class="col-6 col-md-2"><div class="form-check"><input id="new_show_on_home" class="form-check-input" type="checkbox" name="show_on_home" value="1"><label class="form-check-label small" for="new_show_on_home">Startseite</label></div></div>
        <div class="col-6 col-md-2 js-new-overlay-field"><div class="form-check"><input id="new_overlay" class="form-check-input" type="checkbox" name="enable_overlay" value="1"><label class="form-check-label small" for="new_overlay">Overlay</label></div></div>
        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Manuellen Eintrag erstellen</button></div>
    </form>
    <datalist id="legacy_entries"><?php foreach ($legacyEntries as $entry): ?><option value="<?= $e($entry) ?>"></option><?php endforeach; ?></datalist>
</div></div>

<div class="d-flex justify-content-end mb-2"><div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">Spalten</button>
    <div class="dropdown-menu dropdown-menu-end p-3 module-column-picker" id="module-column-picker">
        <?php foreach ($availableColumns as $key => $label): ?><div class="form-check"><input class="form-check-input js-module-column" type="checkbox" value="<?= $e($key) ?>" id="module_column_<?= $e($key) ?>"<?= in_array($key, $selectedColumns, true) ? ' checked' : '' ?>><label class="form-check-label" for="module_column_<?= $e($key) ?>"><?= $e($label) ?></label></div><?php endforeach; ?>
        <hr class="my-2"><button type="button" class="btn btn-sm btn-link px-0" id="module-columns-reset">Standard wiederherstellen</button>
    </div>
</div></div>

<div class="card shadow-sm border-0 app-card"><div class="card-body p-0"><div class="table-responsive module-management-table-wrap">
<table class="table table-hover align-middle mb-0 app-table module-management-table"><thead><tr>
    <th data-column="sort" class="ps-4 module-sort-column<?= $columnClass('sort') ?>">Sortierung</th><th class="ps-4">Modul</th>
    <?php foreach (['type'=>'Typ','version'=>'Version','route'=>'Route Prefix','access'=>'Zugriff','links'=>'Links','header'=>'Header','home'=>'Startseite','active'=>'Aktiv'] as $key=>$label): ?><th data-column="<?= $key ?>" class="<?= trim($columnClass($key)) ?>"><?= $label ?></th><?php endforeach; ?>
    <th data-column="actions" class="pe-4 text-end<?= $columnClass('actions') ?>">Aktionen</th>
</tr></thead><tbody id="js-module-sortable-body">
<?php if ($modules === []): ?><tr><td colspan="11" class="ps-4 text-body-secondary">Keine Module vorhanden.</td></tr><?php endif; ?>
<?php foreach ($modules as $row): $id=(int)($row['id']??0); $type=(string)($row['module_type']??'local'); $managed=!empty($row['managed']); $isCore=$type==='core'; ?>
<tr data-module-id="<?= $id ?>" class="module-sort-row">
    <td data-column="sort" class="ps-4 module-sort-column<?= $columnClass('sort') ?>"><button type="button" class="app-sort-handle module-sort-handle js-module-row-handle" aria-label="<?= $e($row['name']??'Modul') ?> sortieren"><span class="app-sort-handle-dots" aria-hidden="true"></span></button></td>
    <td class="ps-4 fw-medium"><?= $e($row['name']??'') ?><?php if (($row['origin_badge_label']??null)!==null): ?><span class="badge <?= \Modulon\Core\Modules\ModulePresentation::typeBadgeClass(strtolower((string)$row['origin_badge_label'])) ?> ms-1"><?= $e($row['origin_badge_label']) ?></span><?php endif; ?></td>
    <td data-column="type" class="<?= trim($columnClass('type')) ?>"><span class="badge rounded-pill <?= $e($row['module_type_badge']??'text-bg-info') ?>"><?= $e($row['module_type_label']??'Lokal') ?></span></td>
    <td data-column="version" class="<?= trim($columnClass('version')) ?>"><?= ($row['installed_version']??null)!==null?$e($row['installed_version']):'—' ?></td>
    <td data-column="route" class="<?= trim($columnClass('route')) ?>"><code><?= $e($row['route_prefix']??'') ?></code></td>
    <td data-column="access" class="<?= trim($columnClass('access')) ?>"><span class="badge rounded-pill <?= $e($row['access_badge']??'text-bg-secondary') ?>"><?= $e($row['access_level']??'public') ?></span></td>
    <td data-column="links" class="text-nowrap<?= $columnClass('links') ?>"><?php if (!empty($row['module_url'])): ?><a class="btn btn-sm btn-outline-secondary" href="<?= $e($row['module_url']) ?>">App</a><?php endif; ?><?php if (!empty($row['module_admin_url'])): ?><a class="btn btn-sm btn-outline-secondary ms-1" href="<?= $e($row['module_admin_url']) ?>">Admin</a><?php endif; ?><?php if (empty($row['module_url'])&&empty($row['module_admin_url'])): ?><span class="text-body-secondary">—</span><?php endif; ?></td>
    <td data-column="header" class="<?= trim($columnClass('header')) ?>"><?= $switch($id,'show_in_header',(int)($row['show_in_header']??0)===1,'Header-Sichtbarkeit') ?></td>
    <td data-column="home" class="<?= trim($columnClass('home')) ?>"><?= $switch($id,'show_on_home',(int)($row['show_on_home']??0)===1,'Startseiten-Sichtbarkeit') ?></td>
    <td data-column="active" class="<?= trim($columnClass('active')) ?>"><?= $switch($id,'is_active',(int)($row['is_active']??0)===1,'Aktivstatus',$isCore) ?></td>
    <td data-column="actions" class="pe-4 text-end text-nowrap<?= $columnClass('actions') ?>"><?php if ($managed): ?><a href="/admin/module-catalog/<?= rawurlencode((string)$row['module_key']) ?>" class="btn btn-sm btn-outline-primary">Katalogdetails</a><?php elseif ($isCore): ?><span class="small text-body-secondary">Core-verwaltet</span><?php else: ?><a href="/admin/modules/<?= $id ?>/edit" class="btn btn-sm btn-outline-secondary">Bearbeiten</a><form method="post" action="/admin/modules/delete" class="d-inline ms-1" onsubmit="return confirm('Modul wirklich löschen?');"><?= \Modulon\Core\View::csrfField($csrfToken) ?><input type="hidden" name="module_id" value="<?= $id ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Löschen</button></form><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
(() => {
 const feedback=document.getElementById('module-toggle-feedback'), body=document.getElementById('js-module-sortable-body');
 const headers={'X-CSRF-Token':<?= json_encode($csrfToken,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,'Accept':'application/json','Content-Type':'application/json'};
 const tell=(text,error=false)=>{if(!feedback)return;feedback.textContent=text;feedback.classList.toggle('is-success',!error);feedback.classList.toggle('is-error',error);};
 const post=async(url,payload)=>{const response=await fetch(url,{method:'POST',headers,body:JSON.stringify(payload)}),data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Speichern fehlgeschlagen.');return data;};
 const handler=document.getElementById('new_handler'); const syncCreate=()=>{const legacy=handler?.value==='legacy';document.querySelectorAll('.js-new-legacy-field,.js-new-overlay-field').forEach(node=>node.classList.toggle('d-none',!legacy));const entry=document.getElementById('new_legacy_entry');if(entry)entry.required=legacy;}; handler?.addEventListener('change',syncCreate);syncCreate();
 document.querySelectorAll('.js-module-toggle').forEach(toggle=>toggle.addEventListener('change',async()=>{const previous=!toggle.checked;toggle.disabled=true;try{await post('/admin/modules/toggle',{module_id:Number(toggle.dataset.moduleId),field:toggle.dataset.field,enabled:toggle.checked?1:0});tell('Einstellung gespeichert.');}catch(error){toggle.checked=previous;tell(error instanceof Error?error.message:'Speichern fehlgeschlagen.',true);}finally{toggle.disabled=false;}}));
 const applyColumns=columns=>{const active=new Set(columns);document.querySelectorAll('[data-column]').forEach(cell=>cell.classList.toggle('d-none',!active.has(cell.dataset.column)));document.querySelectorAll('.js-module-column').forEach(input=>{input.checked=active.has(input.value);});};
 document.querySelectorAll('.js-module-column').forEach(input=>input.addEventListener('change',async()=>{const selected=Array.from(document.querySelectorAll('.js-module-column:checked')).map(item=>item.value),previous=input.checked?selected.filter(key=>key!==input.value):[...selected,input.value];applyColumns(selected);try{const data=await post('/admin/modules/columns',{columns:selected});applyColumns(data.columns);tell('Spaltenauswahl gespeichert.');}catch(error){applyColumns(previous);tell(error instanceof Error?error.message:'Spaltenauswahl konnte nicht gespeichert werden.',true);}}));
 document.getElementById('module-columns-reset')?.addEventListener('click',async()=>{try{const data=await post('/admin/modules/columns',{reset:true});applyColumns(data.columns);tell('Standard wiederhergestellt.');}catch(error){tell(error instanceof Error?error.message:'Zurücksetzen fehlgeschlagen.',true);}});
 if(body&&window.Sortable){let before=[];const order=()=>Array.from(body.querySelectorAll('tr[data-module-id]')).map(row=>Number(row.dataset.moduleId));new Sortable(body,{handle:'.js-module-row-handle',draggable:'tr[data-module-id]',animation:140,onStart:()=>{before=order();},onEnd:async()=>{try{await post('/admin/modules/reorder',{module_ids:order()});tell('Reihenfolge gespeichert.');}catch(error){const rows=new Map(Array.from(body.querySelectorAll('tr[data-module-id]')).map(row=>[Number(row.dataset.moduleId),row]));before.forEach(id=>body.appendChild(rows.get(id)));tell(error instanceof Error?error.message:'Sortierung fehlgeschlagen.',true);}}});}
})();
</script>
