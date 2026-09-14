<?php
declare(strict_types=1);

$adminSection = (string) ($admin_section ?? '');
$adminNavGrouped = is_array($admin_nav_grouped ?? null) ? $admin_nav_grouped : [];
$primaryItems = is_array($adminNavGrouped['primary'] ?? null) ? $adminNavGrouped['primary'] : [];
$secondaryItems = is_array($adminNavGrouped['secondary'] ?? null) ? $adminNavGrouped['secondary'] : [];
$activeSecondary = is_array($adminNavGrouped['active_secondary'] ?? null) ? $adminNavGrouped['active_secondary'] : null;

// Fallback if not grouped:
if ($primaryItems === []) {
    $adminNavItems = is_array($admin_nav_items ?? null) ? $admin_nav_items : [];
    if ($adminNavItems === []) {
        $primaryItems = [
            ['key' => 'modules', 'label' => 'Modulverwaltung', 'url' => '/admin/modules', 'is_active' => $adminSection === 'modules', 'icon' => 'bi-grid'],
            ['key' => 'users', 'label' => 'Benutzerverwaltung', 'url' => '/admin/users', 'is_active' => $adminSection === 'users', 'icon' => 'bi-people'],
        ];
    } else {
        $primaryItems = $adminNavItems;
    }
}
$currentPath = (string) ($current_path ?? '/admin');
$csrfToken = (string) ($csrf_token ?? '');
?>
<div class="admin-nav-bar mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between border-bottom">
        <ul class="nav nav-tabs border-bottom-0 flex-grow-1 mb-0" role="tablist">
        <?php foreach ($primaryItems as $item): ?>
            <?php
            $label = (string) ($item['label'] ?? '');
            $url = (string) ($item['url'] ?? '#');
            $isActive = (bool) ($item['is_active'] ?? false);
            $icon = (string) ($item['icon'] ?? 'bi-circle');
            ?>
            <li class="nav-item">
                <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> me-1"></i>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
        <?php endforeach; ?>

        <?php if ($secondaryItems !== []): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle<?= $activeSecondary !== null ? ' active' : '' ?>" data-bs-toggle="dropdown" data-bs-display="static" href="#" role="button" aria-expanded="false">
                    <?php if ($activeSecondary !== null): ?>
                        <i class="bi <?= htmlspecialchars((string) ($activeSecondary['icon'] ?? 'bi-grid-3x3-gap'), ENT_QUOTES, 'UTF-8') ?> me-1"></i>
                        <?= htmlspecialchars((string) ($activeSecondary['label'] ?? 'Modul'), ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        <i class="bi bi-grid-3x3-gap me-1"></i>
                        Weitere Modul-Bereiche <span class="badge text-bg-secondary ms-1"><?= count($secondaryItems) ?></span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><h6 class="dropdown-header text-uppercase small">Aktive Fachmodule</h6></li>
                    <?php foreach ($secondaryItems as $secItem): ?>
                        <?php
                        $secLabel = (string) ($secItem['label'] ?? '');
                        $secUrl = (string) ($secItem['url'] ?? '#');
                        $secIsActive = (bool) ($secItem['is_active'] ?? false);
                        $secIcon = (string) ($secItem['icon'] ?? 'bi-circle');
                        ?>
                        <li>
                            <a class="dropdown-item d-flex align-items-center<?= $secIsActive ? ' active' : '' ?>" href="<?= htmlspecialchars($secUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi <?= htmlspecialchars($secIcon, ENT_QUOTES, 'UTF-8') ?> me-2 text-body-secondary"></i>
                                <span><?= htmlspecialchars($secLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endif; ?>
    </ul>

    <div class="d-none d-md-flex align-items-center gap-2 mb-2 mb-md-0">
        <form method="post" action="/profil/admin-nav-layout" class="m-0">
            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
            <input type="hidden" name="admin_nav_layout" value="sidebar">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentPath, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1 py-1 px-2" title="Zur vertikalen Sidebar-Navigation wechseln">
                <i class="bi bi-layout-sidebar-inset"></i>
                <span class="small">Sidebar-Layout</span>
            </button>
        </form>
        </div>
    </div>
</div>
