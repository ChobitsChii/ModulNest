<?php
declare(strict_types=1);

$auth = is_array($auth ?? null) ? $auth : [];
$isAuthenticated = (bool) ($auth['is_authenticated'] ?? false);
$isAdmin = (bool) ($auth['is_admin'] ?? false);
$userName = (string) ($auth['user_name'] ?? '');
$userEmail = (string) ($auth['user_email'] ?? '');
$currentUser = is_array($auth['user'] ?? null) ? $auth['user'] : ['name' => $userName, 'avatar_path' => $auth['user_avatar_path'] ?? '', 'id' => $auth['user_id'] ?? 0];
$currentPath = (string) ($current_path ?? '/');
$navModules = is_array($nav_modules ?? null) ? $nav_modules : [];
$launcherModules = is_array($launcher_modules ?? null) ? $launcher_modules : $navModules;
$userHeaderModules = isset($user_header_modules) && is_array($user_header_modules) ? $user_header_modules : null;
$publicRegistrationEnabled = (bool) ($public_registration_enabled ?? true);
$adminItems = is_array($admin_nav_items ?? null) ? $admin_nav_items : [];
$userItems = is_array($user_nav_items ?? null) ? $user_nav_items : [];
$pagesModuleActive = (bool) ($pages_module_active ?? false);
$pagesHeaderUngrouped = is_array($pages_header_ungrouped ?? null) ? $pages_header_ungrouped : [];
$pagesHeaderGroups = is_array($pages_header_groups ?? null) ? $pages_header_groups : [];
$productMeta = is_array($product_meta ?? null) ? $product_meta : [];
$productName = (string) ($productMeta['product_name'] ?? 'Modulon');
$csrfToken = (string) ($csrf_token ?? '');
$themeCandidate = (string) ($theme_mode ?? 'system');
$themeMode = in_array($themeCandidate, ['system', 'light', 'dark'], true) ? $themeCandidate : 'system';
$themeSwitcherVisible = (bool) ($theme_switcher_visible ?? true);
$themeLabels = ['system' => 'System', 'light' => 'Hell', 'dark' => 'Dunkel'];
$themeIcons = ['system' => 'bi-display', 'light' => 'bi-sun', 'dark' => 'bi-moon-stars'];

$isActive = static function (string $path) use ($currentPath): string {
    $normalizedPath = rtrim($path, '/');
    $normalizedCurrent = rtrim($currentPath, '/');

    if ($normalizedPath === '') {
        return $normalizedCurrent === '' ? 'active' : '';
    }

    return $normalizedCurrent === $normalizedPath || str_starts_with($normalizedCurrent, $normalizedPath . '/') ? 'active' : '';
};

$defaultPinnedKeys = array_values(array_map(
    static fn (array $m): string => (string) ($m['prefix'] ?? $m['key'] ?? ''),
    array_filter($launcherModules, static fn (array $m): bool => (bool) ($m['show_in_header'] ?? true))
));

$canonicalKeys = array_values(array_map(
    static fn (array $m): string => (string) ($m['prefix'] ?? $m['key'] ?? ''),
    $launcherModules
));

// Reorder launcher modules if user has customized header order
if ($userHeaderModules !== null) {
    $customOrderMap = array_flip($userHeaderModules);
    usort($launcherModules, static function (array $a, array $b) use ($customOrderMap): int {
        $keyA = (string) ($a['prefix'] ?? $a['key'] ?? '');
        $keyB = (string) ($b['prefix'] ?? $b['key'] ?? '');
        $posA = $customOrderMap[$keyA] ?? 999;
        $posB = $customOrderMap[$keyB] ?? 999;
        return $posA <=> $posB;
    });
}
?>
<nav class="navbar navbar-expand-lg app-navbar border-bottom py-2">
    <div class="container app-container">
        <div class="app-header-flex">
            <div class="app-header-left d-flex align-items-center gap-2">
            <a class="navbar-brand fw-semibold app-brand me-1" href="/">
                <img src="/assets/img/modulon-icon.svg" alt="" class="app-brand-icon">
                <span class="app-brand-text"><?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?></span>
            </a>

            <?php if ($isAuthenticated && $launcherModules !== []): ?>
                <div class="dropdown app-launcher-container" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" data-default-pinned="<?= htmlspecialchars(json_encode($defaultPinnedKeys, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>" data-canonical-keys="<?= htmlspecialchars(json_encode($canonicalKeys, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="btn app-launcher-btn" id="appLauncherDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Alle Module & Apps" aria-label="Alle Module & Apps">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-start app-launcher-menu shadow-lg p-0" aria-labelledby="appLauncherDropdown">
                        
                        <!-- Hauptansicht des Launchers -->
                        <div class="app-launcher-panel js-launcher-main-panel">
                            <div class="app-launcher-header px-3 py-2 border-bottom d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-grid-3x3-gap-fill text-primary"></i>
                                    <span class="fw-semibold small">Module & Apps</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0 app-launcher-sort-toggle js-launcher-sort-toggle" title="Kacheln per Drag & Drop sortieren">
                                        <i class="bi bi-arrows-move"></i> <small class="js-sort-label">Sortieren</small>
                                    </button>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-body-secondary small app-launcher-reset-btn js-launcher-reset-btn<?= $userHeaderModules === null ? ' d-none' : '' ?>" title="Header auf Standard zurücksetzen">
                                        <i class="bi bi-arrow-counterclockwise"></i> <small>Standard</small>
                                    </button>
                                </div>
                            </div>
                            <div class="app-launcher-body p-3">
                                <div class="app-launcher-hint text-body-secondary small mb-2 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-1">
                                        <i class="bi bi-pin-angle text-primary"></i>
                                        <span>Mit <i class="bi bi-pin-angle"></i> an Header anheften:</span>
                                    </div>
                                    <span class="badge bg-secondary-subtle text-secondary d-none js-sort-badge">Sortier-Modus aktiv</span>
                                </div>
                                <div class="app-launcher-grid js-launcher-grid">
                                    <?php foreach ($launcherModules as $module): ?>
                                        <?php
                                        $mPrefix = (string) ($module['prefix'] ?? $module['key'] ?? '');
                                        $mName = (string) ($module['name'] ?? 'Modul');
                                        $mUrl = (string) ($module['url'] ?? '/');
                                        $presentation = \Modulon\Core\ModulePresentationRegistry::presentation($mPrefix);
                                        $mIcon = $presentation['icon'];
                                        $mBg = $presentation['bg'];
                                        $mText = $presentation['text'];
                                        $mCat = $presentation['category'];
                                        $mDesc = trim((string) ($module['description'] ?? ''));
                                        if ($mDesc === '') {
                                            $mDesc = $mCat;
                                        }
                                        $mChildren = is_array($module['children'] ?? null) ? $module['children'] : [];
                                        $isPinned = $userHeaderModules !== null
                                            ? in_array($mPrefix, $userHeaderModules, true)
                                            : ((bool) ($module['show_in_header'] ?? true));
                                        ?>
                                        <div class="app-launcher-card" data-module-key="<?= htmlspecialchars($mPrefix, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="d-flex align-items-start justify-content-between mb-1">
                                                <a href="<?= htmlspecialchars($mUrl, ENT_QUOTES, 'UTF-8') ?>" class="app-launcher-icon-box" style="background:<?= htmlspecialchars($mBg, ENT_QUOTES, 'UTF-8') ?>; color:<?= htmlspecialchars($mText, ENT_QUOTES, 'UTF-8') ?>;" title="<?= htmlspecialchars($mName, ENT_QUOTES, 'UTF-8') ?> öffnen">
                                                    <i class="bi <?= htmlspecialchars($mIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                                </a>
                                                <div class="d-flex align-items-center gap-1">
                                                    <span class="app-launcher-drag-handle js-drag-handle d-none" title="Ziehen zum Verschieben">
                                                        <i class="bi bi-grip-vertical"></i>
                                                    </span>
                                                    <button type="button" class="app-launcher-pin-btn <?= $isPinned ? 'is-pinned' : '' ?>" data-module-key="<?= htmlspecialchars($mPrefix, ENT_QUOTES, 'UTF-8') ?>" title="<?= $isPinned ? 'Aus Header-Leiste lösen' : 'An Header-Leiste anheften' ?>" aria-label="<?= $isPinned ? 'Aus Header lösen' : 'An Header anheften' ?>">
                                                        <i class="bi <?= $isPinned ? 'bi-pin-angle-fill' : 'bi-pin-angle' ?>"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <a href="<?= htmlspecialchars($mUrl, ENT_QUOTES, 'UTF-8') ?>" class="app-launcher-title text-truncate d-block fw-semibold text-body text-decoration-none" title="<?= htmlspecialchars($mName, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($mName, ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                            <p class="app-launcher-desc" title="<?= htmlspecialchars($mDesc, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($mDesc, ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                            <div class="app-launcher-card-footer mt-auto">
                                                <?php if ($mChildren !== []): ?>
                                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none app-launcher-sub-btn js-open-subview" data-subview-target="subview-<?= htmlspecialchars($mPrefix, ENT_QUOTES, 'UTF-8') ?>" title="Unterseiten anzeigen">
                                                        <small class="d-inline-flex align-items-center gap-1"><i class="bi bi-list-nested"></i> <?= count($mChildren) ?> Links <i class="bi bi-chevron-right" style="font-size:0.65rem;"></i></small>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="app-launcher-cat small text-body-tertiary text-truncate d-block"><?= htmlspecialchars($mCat, ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Untermenü-Ansichten für Module mit Links (z. B. Banking, Tools) -->
                        <?php foreach ($launcherModules as $module): ?>
                            <?php
                            $mPrefix = (string) ($module['prefix'] ?? $module['key'] ?? '');
                            $mChildren = is_array($module['children'] ?? null) ? $module['children'] : [];
                            if ($mChildren === []) {
                                continue;
                            }
                            $mName = (string) ($module['name'] ?? 'Modul');
                            $mUrl = (string) ($module['url'] ?? '/');
                            $presentation = \Modulon\Core\ModulePresentationRegistry::presentation($mPrefix);
                            $mIcon = $presentation['icon'];
                            $mBg = $presentation['bg'];
                            $mText = $presentation['text'];
                            ?>
                            <div class="app-launcher-panel app-launcher-subview js-launcher-subview d-none" id="subview-<?= htmlspecialchars($mPrefix, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="app-launcher-header px-3 py-2 border-bottom d-flex align-items-center justify-content-between">
                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-body small js-close-subview d-inline-flex align-items-center gap-1">
                                        <i class="bi bi-arrow-left"></i> <span class="fw-semibold">Alle Module</span>
                                    </button>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="app-launcher-icon-box" style="width:24px;height:24px;font-size:0.85rem;background:<?= htmlspecialchars($mBg, ENT_QUOTES, 'UTF-8') ?>; color:<?= htmlspecialchars($mText, ENT_QUOTES, 'UTF-8') ?>;">
                                            <i class="bi <?= htmlspecialchars($mIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                        </span>
                                        <span class="small fw-semibold text-truncate"><?= htmlspecialchars($mName, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                                <div class="app-launcher-body p-3">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="small text-body-secondary fw-semibold text-uppercase" style="font-size:0.75rem;">Bereiche & Aktionen</span>
                                        <a href="<?= htmlspecialchars($mUrl, ENT_QUOTES, 'UTF-8') ?>" class="small text-primary text-decoration-none">Übersicht <i class="bi bi-box-arrow-up-right" style="font-size:0.75rem;"></i></a>
                                    </div>
                                    <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                                        <?php foreach ($mChildren as $child): ?>
                                            <?php
                                            $cUrl = (string) ($child['url'] ?? '#');
                                            $cLabel = (string) ($child['label'] ?? '');
                                            $cKey = (string) ($child['key'] ?? $child['prefix'] ?? $cLabel);
                                            $cIcon = !empty($child['icon']) ? (string)$child['icon'] : \Modulon\Core\ModulePresentationRegistry::icon($cKey);
                                            $isChildActive = (bool) ($child['is_active'] ?? false);
                                            ?>
                                            <a href="<?= htmlspecialchars($cUrl, ENT_QUOTES, 'UTF-8') ?>" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between py-2 px-3<?= $isChildActive ? ' active' : '' ?>">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi <?= htmlspecialchars($cIcon, ENT_QUOTES, 'UTF-8') ?> text-body-secondary"></i>
                                                    <span class="small fw-medium"><?= htmlspecialchars($cLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                                <i class="bi bi-chevron-right text-body-tertiary" style="font-size:0.75rem;"></i>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    </div>
                </div>
            <?php endif; ?>
        </div>

            <div class="app-header-middle">
                <div class="app-header-nav-scroller position-relative d-flex align-items-center">
                    <button type="button" class="btn app-nav-scroll-btn app-nav-scroll-left js-nav-scroll-left d-none" aria-label="Nach links scrollen" title="Nach links scrollen">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <ul class="navbar-nav app-main-nav mb-2 mb-lg-0 js-header-main-nav">
                    <?php
                    // Build master map of all launcher modules for seamless header toggling
                    $renderedKeys = [];
                    ?>
                    <?php foreach ($launcherModules as $module): ?>
                        <?php
                        $moduleUrl = (string) ($module['url'] ?? '/');
                        $moduleName = (string) ($module['name'] ?? 'Modul');
                        $moduleKey = (string) ($module['prefix'] ?? $module['key'] ?? $moduleName);
                        if ($moduleKey === 'pages') {
                            continue;
                        }
                        $moduleIcon = \Modulon\Core\ModulePresentationRegistry::icon($moduleKey);
                        $children = is_array($module['children'] ?? null) ? $module['children'] : [];
                        $hasChildren = $children !== [];
                        $activeClass = $isActive($moduleUrl);
                        $isCurrentlyPinned = $userHeaderModules !== null
                            ? in_array($moduleKey, $userHeaderModules, true)
                            : ((bool) ($module['show_in_header'] ?? true));
                        $renderedKeys[] = $moduleKey;
                        ?>
                        <li class="nav-item<?= $hasChildren ? ' dropdown' : '' ?> js-header-item" data-module-key="<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>" style="<?= $isCurrentlyPinned ? '' : 'display: none !important;' ?>">
                            <?php if ($hasChildren): ?>
                                <a class="nav-link app-nav-link dropdown-toggle <?= $activeClass ?> d-inline-flex align-items-center gap-1" href="<?= htmlspecialchars($moduleUrl, ENT_QUOTES, 'UTF-8') ?>" id="module-nav-<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>" role="button" data-bs-toggle="dropdown" data-app-nav-dropdown-link aria-expanded="false">
                                    <i class="bi <?= htmlspecialchars($moduleIcon, ENT_QUOTES, 'UTF-8') ?> text-body-secondary"></i>
                                    <span><?= htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                                <ul class="dropdown-menu app-module-dropdown" aria-labelledby="module-nav-<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php foreach ($children as $child): ?>
                                        <?php
                                        $childUrl = (string) ($child['url'] ?? '#');
                                        $cLabel = (string) ($child['label'] ?? '');
                                        $childKey = (string) ($child['key'] ?? $child['prefix'] ?? $cLabel);
                                        $childIcon = !empty($child['icon']) ? (string)$child['icon'] : \Modulon\Core\ModulePresentationRegistry::icon($childKey);
                                        $isChildActive = (bool) ($child['is_active'] ?? false);
                                        ?>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2<?= $isChildActive ? ' active' : '' ?>" href="<?= htmlspecialchars($childUrl, ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi <?= htmlspecialchars($childIcon, ENT_QUOTES, 'UTF-8') ?> text-body-secondary"></i>
                                                <span><?= htmlspecialchars($cLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <a class="nav-link app-nav-link <?= $activeClass ?> d-inline-flex align-items-center gap-1" href="<?= htmlspecialchars($moduleUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi <?= htmlspecialchars($moduleIcon, ENT_QUOTES, 'UTF-8') ?> text-body-secondary"></i>
                                    <span><?= htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>

                    <?php if ($pagesModuleActive): ?>
                        <?php
                        $isPagesPinned = $userHeaderModules !== null
                            ? in_array('pages', $userHeaderModules, true)
                            : true;
                        ?>
                        <?php foreach ($pagesHeaderUngrouped as $page): ?>
                            <?php
                            $pageUrl = (string) ($page['url'] ?? '/pages');
                            $pageTitle = (string) ($page['title'] ?? 'Seite');
                            $pageIcon = \Modulon\Core\ModulePresentationRegistry::icon('pages');
                            ?>
                            <li class="nav-item js-header-item" data-module-key="pages" style="<?= $isPagesPinned ? '' : 'display: none !important;' ?>">
                                <a class="nav-link app-nav-link <?= $isActive($pageUrl) ?> d-inline-flex align-items-center gap-1" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi <?= htmlspecialchars($pageIcon, ENT_QUOTES, 'UTF-8') ?> text-body-secondary"></i>
                                    <span><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($pagesHeaderGroups as $groupName => $items): ?>
                            <?php
                            $groupItems = is_array($items) ? array_values($items) : [];
                            if ($groupItems === []) {
                                continue;
                            }
                            if (count($groupItems) === 1) {
                                $single = $groupItems[0];
                                $singleUrl = (string) ($single['url'] ?? '/pages');
                                $singleTitle = (string) ($single['title'] ?? (string) $groupName);
                                ?>
                                <li class="nav-item js-header-item" data-module-key="pages" style="<?= $isPagesPinned ? '' : 'display: none !important;' ?>">
                                    <a class="nav-link app-nav-link <?= $isActive($singleUrl) ?> d-inline-flex align-items-center gap-1" href="<?= htmlspecialchars($singleUrl, ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-file-earmark-text text-body-secondary"></i>
                                        <span><?= htmlspecialchars($singleTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                    </a>
                                </li>
                                <?php
                                continue;
                            }

                            $groupActive = '';
                            foreach ($groupItems as $groupItem) {
                                $groupItemUrl = (string) ($groupItem['url'] ?? '/pages');
                                if ($isActive($groupItemUrl) !== '') {
                                    $groupActive = 'active';
                                    break;
                                }
                            }
                            ?>
                            <li class="nav-item dropdown js-header-item" data-module-key="pages" style="<?= $isPagesPinned ? '' : 'display: none !important;' ?>">
                                <a class="nav-link app-nav-link dropdown-toggle <?= $groupActive ?> d-inline-flex align-items-center gap-1" href="/pages" id="pages-group-<?= htmlspecialchars((string) md5((string) $groupName), ENT_QUOTES, 'UTF-8') ?>" role="button" data-bs-toggle="dropdown" data-app-nav-dropdown-link aria-expanded="false">
                                    <i class="bi bi-folder text-body-secondary"></i>
                                    <span><?= htmlspecialchars((string) $groupName, ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                                <ul class="dropdown-menu app-module-dropdown" aria-labelledby="pages-group-<?= htmlspecialchars((string) md5((string) $groupName), ENT_QUOTES, 'UTF-8') ?>">
                                    <?php foreach ($groupItems as $groupItem): ?>
                                        <?php
                                        $groupItemUrl = (string) ($groupItem['url'] ?? '/pages');
                                        $groupItemLabel = (string) ($groupItem['title'] ?? 'Seite');
                                        ?>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2<?= $isActive($groupItemUrl) !== '' ? ' active' : '' ?>" href="<?= htmlspecialchars($groupItemUrl, ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-file-earmark-text text-body-secondary"></i>
                                                <span><?= htmlspecialchars($groupItemLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                    <button type="button" class="btn app-nav-scroll-btn app-nav-scroll-right js-nav-scroll-right d-none" aria-label="Nach rechts scrollen" title="Nach rechts scrollen">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>

            <div class="app-header-right">
                <?php if ($isAdmin): ?>
                    <?php
                    $adminNavGrouped = is_array($admin_nav_grouped ?? null) ? $admin_nav_grouped : [];
                    $adminPrimary = is_array($adminNavGrouped['primary'] ?? null) ? $adminNavGrouped['primary'] : $adminItems;
                    $adminSecondary = is_array($adminNavGrouped['secondary'] ?? null) ? $adminNavGrouped['secondary'] : [];
                    ?>
                    <div class="nav-item dropdown app-admin-nav">
                        <a class="nav-link app-nav-link app-admin-link dropdown-toggle <?= $isActive('/admin') ?>" href="/admin/modules" id="admin-nav-dropdown" role="button" data-bs-toggle="dropdown" data-bs-display="static" data-app-nav-dropdown-link aria-expanded="false">
                            Admin
                            <span id="admin-update-badge" class="badge rounded-pill bg-warning text-dark ms-1 d-none" title="Updates verfügbar">!</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end app-module-dropdown shadow-sm" aria-labelledby="admin-nav-dropdown">
                            <li><h6 class="dropdown-header text-uppercase small text-body-tertiary">Verwaltung & System</h6></li>
                            <?php foreach ($adminPrimary as $item): ?>
                                <?php
                                $adminUrl = (string) ($item['url'] ?? '#');
                                $adminLabel = (string) ($item['label'] ?? '');
                                $adminActive = (bool) ($item['is_active'] ?? false);
                                $adminKey = (string) ($item['key'] ?? '');
                                $adminIcon = (string) ($item['icon'] ?? \Modulon\Core\AdminNavigationRegistry::iconForKey($adminKey));
                                ?>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2<?= $adminActive ? ' active' : '' ?>" href="<?= htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi <?= htmlspecialchars($adminIcon, ENT_QUOTES, 'UTF-8') ?> text-body-secondary"></i>
                                        <span class="flex-grow-1"><?= htmlspecialchars($adminLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($adminKey === 'updates'): ?>
                                            <span class="badge rounded-pill bg-warning text-dark ms-auto admin-updates-item-badge d-none" title="Updates verfügbar">!</span>
                                        <?php elseif ($adminKey === 'module-catalog'): ?>
                                            <span class="badge rounded-pill bg-warning text-dark ms-auto admin-catalog-item-badge d-none" title="Modul-Updates verfügbar">!</span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>

                            <?php if ($adminSecondary !== []): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header text-uppercase small text-body-tertiary">Weitere Modul-Bereiche</h6></li>
                                <?php foreach ($adminSecondary as $item): ?>
                                    <?php
                                    $adminUrl = (string) ($item['url'] ?? '#');
                                    $adminLabel = (string) ($item['label'] ?? '');
                                    $adminActive = (bool) ($item['is_active'] ?? false);
                                    $adminKey = (string) ($item['key'] ?? '');
                                    $adminIcon = (string) ($item['icon'] ?? \Modulon\Core\AdminNavigationRegistry::iconForKey($adminKey));
                                    ?>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2<?= $adminActive ? ' active' : '' ?>" href="<?= htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi <?= htmlspecialchars($adminIcon, ENT_QUOTES, 'UTF-8') ?> text-body-secondary"></i>
                                            <span class="flex-grow-1"><?= htmlspecialchars($adminLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($adminKey === 'updates'): ?>
                                                <span class="badge rounded-pill bg-warning text-dark ms-auto admin-updates-item-badge d-none" title="Updates verfügbar">!</span>
                                            <?php elseif ($adminKey === 'module-catalog'): ?>
                                                <span class="badge rounded-pill bg-warning text-dark ms-auto admin-catalog-item-badge d-none" title="Modul-Updates verfügbar">!</span>
                                            <?php endif; ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="app-user-zone">
                    <?php if ($themeSwitcherVisible): ?>
                        <div class="nav-item dropdown app-theme-switcher" data-theme-switcher data-authenticated="<?= $isAuthenticated ? 'true' : 'false' ?>" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="app-theme-button dropdown-toggle" type="button" id="theme-switcher-dropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Darstellung: <?= htmlspecialchars($themeLabels[$themeMode], ENT_QUOTES, 'UTF-8') ?>" title="Darstellung ändern">
                                <i class="bi <?= htmlspecialchars($themeIcons[$themeMode], ENT_QUOTES, 'UTF-8') ?>" data-theme-current-icon aria-hidden="true"></i>
                                <span class="visually-hidden" data-theme-current-label><?= htmlspecialchars($themeLabels[$themeMode], ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end app-module-dropdown app-theme-menu" aria-labelledby="theme-switcher-dropdown">
                                <?php foreach ($themeLabels as $mode => $label): ?>
                                    <li>
                                        <button class="dropdown-item app-theme-option d-flex align-items-center gap-2<?= $themeMode === $mode ? ' active' : '' ?>" type="button" data-theme-option="<?= $mode ?>"<?= $themeMode === $mode ? ' aria-current="true"' : '' ?>>
                                            <i class="bi <?= htmlspecialchars($themeIcons[$mode], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                            <i class="bi bi-check-lg ms-auto app-theme-check" aria-hidden="true"></i>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <span class="visually-hidden" data-theme-status role="status" aria-live="polite"></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($isAuthenticated): ?>
                        <?php if ($userItems !== []): ?>
                            <div class="nav-item dropdown">
                                <button type="button" class="app-user-chip dropdown-toggle shadow-sm" id="user-nav-dropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    <?= \Modulon\Core\UserAvatarHelper::render($currentUser, 24) ?>
                                    <span class="app-user-name fw-medium"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($isAdmin): ?>
                                        <span class="app-role-badge">Admin</span>
                                    <?php endif; ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end app-module-dropdown shadow-sm" aria-labelledby="user-nav-dropdown" style="min-width: 220px;">
                                    <li class="app-user-profile-header d-flex align-items-center gap-2">
                                        <?= \Modulon\Core\UserAvatarHelper::render($currentUser, 36) ?>
                                        <div class="overflow-hidden">
                                            <div class="fw-semibold text-truncate small"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php if ($userEmail !== ''): ?>
                                                <div class="text-body-secondary text-truncate" style="font-size: 0.75rem;"><?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php foreach ($userItems as $item): ?>
                                        <?php
                                        $userUrl = (string) ($item['url'] ?? '#');
                                        $userLabel = (string) ($item['label'] ?? '');
                                        $userActive = (bool) ($item['is_active'] ?? false);
                                        $userItemIcon = match (strtolower(trim($userLabel))) {
                                            'profil' => 'bi-person-circle',
                                            'sicherheit' => 'bi-shield-lock',
                                            'einstellungen' => 'bi-sliders',
                                            'sammelkarten' => 'bi-suit-spade',
                                            'meine daten' => 'bi-cloud-arrow-down',
                                            default => 'bi-circle',
                                        };
                                        ?>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2<?= $userActive ? ' active' : '' ?>" href="<?= htmlspecialchars($userUrl, ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi <?= htmlspecialchars($userItemIcon, ENT_QUOTES, 'UTF-8') ?> text-body-secondary"></i>
                                                <span><?= htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <span class="app-user-chip">
                                <?= \Modulon\Core\UserAvatarHelper::render($currentUser, 24) ?>
                                <span class="app-user-name fw-medium"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($isAdmin): ?>
                                    <span class="app-role-badge">Admin</span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <form method="post" action="/logout" class="m-0">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Abmelden">Logout</button>
                        </form>
                    <?php else: ?>
                        <div class="app-auth-actions">
                            <?php if ($publicRegistrationEnabled): ?>
                                <a href="/internal/register" class="btn btn-outline-secondary btn-sm">Registrieren</a>
                            <?php endif; ?>
                            <a href="/login" class="btn btn-primary btn-sm">Login</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</nav>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const launcher = document.querySelector('.app-launcher-container');
    if (!launcher) return;

    const csrfToken = launcher.dataset.csrfToken || '';
    const defaultPinned = JSON.parse(launcher.dataset.defaultPinned || '[]');
    const canonicalKeys = JSON.parse(launcher.dataset.canonicalKeys || '[]');
    const mainPanel = launcher.querySelector('.js-launcher-main-panel');
    const subviews = launcher.querySelectorAll('.js-launcher-subview');
    const grid = launcher.querySelector('.js-launcher-grid');
    const headerNav = document.querySelector('.js-header-main-nav');
    const resetBtn = launcher.querySelector('.js-launcher-reset-btn');
    const sortToggle = launcher.querySelector('.js-launcher-sort-toggle');
    const sortLabel = launcher.querySelector('.js-sort-label');
    const sortBadge = launcher.querySelector('.js-sort-badge');
    const dragHandles = launcher.querySelectorAll('.js-drag-handle');

    let isSortMode = false;


    // Header Floating Dropdowns & Horizontal Mousewheel Scroller
    function initHeaderFloatingDropdowns() {
        if (!headerNav) return;
        let activeMenu = null;
        let activeItem = null;
        let hideTimer = null;

        function positionMenu(item, menu) {
            const rect = item.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 2) + 'px';
            menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
            menu.style.zIndex = '1250';
            menu.style.display = 'block';
        }

        function show(item) {
            clearTimeout(hideTimer);
            const menu = item.querySelector('.dropdown-menu');
            if (!menu) return;
            if (activeMenu && activeMenu !== menu) {
                activeMenu.style.display = 'none';
            }
            activeItem = item;
            activeMenu = menu;
            positionMenu(item, menu);
            item.classList.add('show');
            const toggle = item.querySelector('.dropdown-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
        }

        function hide() {
            hideTimer = setTimeout(() => {
                if (activeMenu) {
                    activeMenu.style.display = 'none';
                    activeMenu = null;
                }
                if (activeItem) {
                    activeItem.classList.remove('show');
                    const toggle = activeItem.querySelector('.dropdown-toggle');
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');
                    activeItem = null;
                }
            }, 120);
        }

        headerNav.querySelectorAll('.dropdown').forEach(item => {
            item.addEventListener('mouseenter', () => show(item));
            item.addEventListener('mouseleave', () => hide());

            const menu = item.querySelector('.dropdown-menu');
            if (menu) {
                menu.addEventListener('mouseenter', () => clearTimeout(hideTimer));
                menu.addEventListener('mouseleave', () => hide());
            }

            const toggle = item.querySelector('.dropdown-toggle');
            if (toggle) {
                toggle.addEventListener('click', (e) => {
                    if (activeMenu === menu) {
                        hide();
                    } else {
                        show(item);
                    }
                });
            }
        });

        function updateScrollButtons() {
            const leftBtn = document.querySelector('.js-nav-scroll-left');
            const rightBtn = document.querySelector('.js-nav-scroll-right');
            if (!leftBtn || !rightBtn) return;
            const maxScroll = headerNav.scrollWidth - headerNav.clientWidth;
            if (maxScroll <= 4) {
                leftBtn.classList.add('d-none');
                rightBtn.classList.add('d-none');
                return;
            }
            if (headerNav.scrollLeft > 6) {
                leftBtn.classList.remove('d-none');
            } else {
                leftBtn.classList.add('d-none');
            }
            if (headerNav.scrollLeft < maxScroll - 6) {
                rightBtn.classList.remove('d-none');
            } else {
                rightBtn.classList.add('d-none');
            }
        }

        const leftBtn = document.querySelector('.js-nav-scroll-left');
        const rightBtn = document.querySelector('.js-nav-scroll-right');
        if (leftBtn) {
            leftBtn.addEventListener('click', () => {
                headerNav.scrollBy({ left: -220, behavior: 'smooth' });
                setTimeout(updateScrollButtons, 220);
            });
        }
        if (rightBtn) {
            rightBtn.addEventListener('click', () => {
                headerNav.scrollBy({ left: 220, behavior: 'smooth' });
                setTimeout(updateScrollButtons, 220);
            });
        }

        headerNav.addEventListener('scroll', () => {
            updateScrollButtons();
            if (activeItem && activeMenu) {
                positionMenu(activeItem, activeMenu);
            }
        }, { passive: true });

        headerNav.addEventListener('wheel', (e) => {
            if (e.deltaY !== 0 && headerNav.scrollWidth > headerNav.clientWidth) {
                e.preventDefault();
                headerNav.scrollLeft += e.deltaY * 2.8;
                updateScrollButtons();
                if (activeItem && activeMenu) {
                    positionMenu(activeItem, activeMenu);
                }
            }
        }, { passive: false });

        window.addEventListener('resize', () => {
            updateScrollButtons();
            if (activeItem && activeMenu) {
                positionMenu(activeItem, activeMenu);
            }
        });

        // Expose helper globally for pin toggles
        window._modulonUpdateNavScroll = updateScrollButtons;
        setTimeout(updateScrollButtons, 50);
    }

    initHeaderFloatingDropdowns();

    // Subview Navigation
    launcher.querySelectorAll('.js-open-subview').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const targetId = this.dataset.subviewTarget;
            const target = document.getElementById(targetId);
            if (target && mainPanel) {
                mainPanel.classList.add('d-none');
                subviews.forEach(s => s.classList.add('d-none'));
                target.classList.remove('d-none');
            }
        });
    });

    launcher.querySelectorAll('.js-close-subview').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            subviews.forEach(s => s.classList.add('d-none'));
            if (mainPanel) {
                mainPanel.classList.remove('d-none');
            }
        });
    });

    // Helper: Sync Header DOM Order from Grid Cards
    function syncHeaderOrder() {
        if (!grid || !headerNav) return;
        const cardKeys = Array.from(grid.querySelectorAll('.app-launcher-card')).map(c => c.dataset.moduleKey);
        cardKeys.forEach(key => {
            const headerItems = headerNav.querySelectorAll(`.js-header-item[data-module-key="${key}"]`);
            headerItems.forEach(item => {
                headerNav.appendChild(item);
            });
        });
    }

    // Toggle Sort Mode
    if (sortToggle) {
        sortToggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            isSortMode = !isSortMode;

            if (isSortMode) {
                sortToggle.classList.add('btn-primary', 'text-white');
                sortToggle.classList.remove('btn-outline-secondary');
                if (sortLabel) sortLabel.textContent = 'Fertig';
                if (sortBadge) sortBadge.classList.remove('d-none');
                dragHandles.forEach(h => h.classList.remove('d-none'));
                grid.querySelectorAll('.app-launcher-card').forEach(card => {
                    card.setAttribute('draggable', 'true');
                    card.classList.add('is-draggable');
                });
            } else {
                sortToggle.classList.remove('btn-primary', 'text-white');
                sortToggle.classList.add('btn-outline-secondary');
                if (sortLabel) sortLabel.textContent = 'Sortieren';
                if (sortBadge) sortBadge.classList.add('d-none');
                dragHandles.forEach(h => h.classList.add('d-none'));
                grid.querySelectorAll('.app-launcher-card').forEach(card => {
                    card.setAttribute('draggable', 'false');
                    card.classList.remove('is-draggable');
                });
            }
        });
    }

    // Drag & Drop Reordering
    let draggedCard = null;

    if (grid) {
        grid.addEventListener('dragstart', function (e) {
            if (!isSortMode) return;
            const card = e.target.closest('.app-launcher-card');
            if (card) {
                draggedCard = card;
                card.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
            }
        });

        grid.addEventListener('dragover', function (e) {
            if (!isSortMode || !draggedCard) return;
            e.preventDefault();
            const overCard = e.target.closest('.app-launcher-card');
            if (overCard && overCard !== draggedCard) {
                const rect = overCard.getBoundingClientRect();
                const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
                grid.insertBefore(draggedCard, next && overCard.nextSibling ? overCard.nextSibling : overCard);
                syncHeaderOrder();
                if (window._modulonUpdateNavScroll) window._modulonUpdateNavScroll();
            }
        });

        grid.addEventListener('dragend', function () {
            if (draggedCard) {
                draggedCard.classList.remove('is-dragging');
                draggedCard = null;

                // Save custom reordered keys
                const orderedKeys = Array.from(grid.querySelectorAll('.app-launcher-card')).map(c => c.dataset.moduleKey);
                const pinnedOrderedKeys = orderedKeys.filter(key => {
                    const card = grid.querySelector(`.app-launcher-card[data-module-key="${key}"]`);
                    return card && card.querySelector('.app-launcher-pin-btn.is-pinned');
                });

                const formData = new FormData();
                formData.append('_csrf', csrfToken);
                pinnedOrderedKeys.forEach(k => formData.append('keys[]', k));

                if (resetBtn) resetBtn.classList.remove('d-none');
            if (window._modulonUpdateNavScroll) window._modulonUpdateNavScroll();

                fetch('/profil/header-modules/reorder', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                }).catch(err => console.error('Reorder save failed:', err));
            }
        });
    }

    // Seamless AJAX Pinning Handler (No full page reload!)
    launcher.querySelectorAll('.app-launcher-pin-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const moduleKey = this.dataset.moduleKey;
            const isCurrentlyPinned = this.classList.contains('is-pinned');
            const newPinnedState = !isCurrentlyPinned;

            // Instant UI update
            if (newPinnedState) {
                this.classList.add('is-pinned');
                this.innerHTML = '<i class="bi bi-pin-angle-fill"></i>';
                this.title = 'Aus Header-Leiste lösen';
            } else {
                this.classList.remove('is-pinned');
                this.innerHTML = '<i class="bi bi-pin-angle"></i>';
                this.title = 'An Header-Leiste anheften';
            }

            // Instant Header DOM update
            const headerItems = headerNav ? headerNav.querySelectorAll(`.js-header-item[data-module-key="${moduleKey}"]`) : [];
            headerItems.forEach(item => {
                item.style.setProperty('display', newPinnedState ? '' : 'none', newPinnedState ? '' : 'important');
            });

            if (resetBtn) resetBtn.classList.remove('d-none');
            if (window._modulonUpdateNavScroll) window._modulonUpdateNavScroll();

            // Background server persist
            const formData = new FormData();
            formData.append('_csrf', csrfToken);
            formData.append('module_key', moduleKey);
            defaultPinned.forEach(k => formData.append('default_pinned_keys[]', k));
            canonicalKeys.forEach(k => formData.append('canonical_keys[]', k));

            fetch('/profil/header-modules/toggle', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    console.error('Pin toggle failed on server:', data.error);
                }
            })
            .catch(err => console.error('Pin toggle network error:', err));
        });
    });

    // Seamless Reset Handler (No full page reload!)
    if (resetBtn) {
        resetBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            // Reset pin buttons
            launcher.querySelectorAll('.app-launcher-pin-btn').forEach(btn => {
                const key = btn.dataset.moduleKey;
                const isDefPinned = defaultPinned.includes(key);
                if (isDefPinned) {
                    btn.classList.add('is-pinned');
                    btn.innerHTML = '<i class="bi bi-pin-angle-fill"></i>';
                    btn.title = 'Aus Header-Leiste lösen';
                } else {
                    btn.classList.remove('is-pinned');
                    btn.innerHTML = '<i class="bi bi-pin-angle"></i>';
                    btn.title = 'An Header-Leiste anheften';
                }
            });

            // Reset Header DOM visibility
            if (headerNav) {
                headerNav.querySelectorAll('.js-header-item').forEach(item => {
                    const key = item.dataset.moduleKey;
                    const isDefPinned = defaultPinned.includes(key);
                    item.style.setProperty('display', isDefPinned ? '' : 'none', isDefPinned ? '' : 'important');
                });
            }

            resetBtn.classList.add('d-none');

            const formData = new FormData();
            formData.append('_csrf', csrfToken);

            fetch('/profil/header-modules/reset', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData
            }).catch(err => console.error('Reset failed:', err));
        });
    }
});
</script>
