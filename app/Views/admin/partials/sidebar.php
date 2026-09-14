<?php
declare(strict_types=1);

$adminNavGrouped = is_array($admin_nav_grouped ?? null) ? $admin_nav_grouped : [];
$sidebarSections = is_array($adminNavGrouped['sidebar_sections'] ?? null) ? $adminNavGrouped['sidebar_sections'] : [];
$currentPath = (string) ($current_path ?? '/admin');
$csrfToken = (string) ($csrf_token ?? '');
?>
<div class="card shadow-sm border-0 app-card admin-sidebar sticky-top" style="top: 1.5rem; z-index: 10;">
    <div class="card-body p-3">
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <span class="fw-semibold text-body-secondary small text-uppercase"><i class="bi bi-shield-check me-1"></i> Admin</span>
            <form method="post" action="/profil/admin-nav-layout" class="m-0">
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                <input type="hidden" name="admin_nav_layout" value="tabs">
                <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentPath, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-link btn-sm p-0 text-body-secondary text-decoration-none" title="Zu horizontalen Tabs wechseln">
                    <i class="bi bi-segmented-nav"></i>
                </button>
            </form>
        </div>

        <nav class="nav flex-column admin-sidebar-nav gap-1">
            <?php foreach ($sidebarSections as $sectionTitle => $sectionItems): ?>
                <div class="sidebar-section-heading text-uppercase text-body-tertiary fw-semibold small mt-2 mb-1 px-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                    <?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php foreach ($sectionItems as $item): ?>
                    <?php
                    $label = (string) ($item['label'] ?? '');
                    $url = (string) ($item['url'] ?? '#');
                    $isActive = (bool) ($item['is_active'] ?? false);
                    $icon = (string) ($item['icon'] ?? 'bi-circle');
                    ?>
                    <a class="nav-link py-2 px-2.5 rounded d-flex align-items-center gap-2 <?= $isActive ? 'active bg-primary text-white shadow-sm' : 'text-body-emphasis link-body-emphasis' ?>" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> <?= $isActive ? 'text-white' : 'text-body-secondary' ?>"></i>
                        <span class="small fw-medium"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="mt-4 pt-3 border-top">
            <form method="post" action="/profil/admin-nav-layout" class="m-0">
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                <input type="hidden" name="admin_nav_layout" value="tabs">
                <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentPath, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100 d-flex align-items-center justify-content-center gap-1">
                    <i class="bi bi-segmented-nav"></i>
                    <span>Zu Tabs wechseln</span>
                </button>
            </form>
        </div>
    </div>
</div>
