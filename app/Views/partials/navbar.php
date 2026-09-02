<?php
declare(strict_types=1);

$auth = is_array($auth ?? null) ? $auth : [];
$isAuthenticated = (bool) ($auth['is_authenticated'] ?? false);
$isAdmin = (bool) ($auth['is_admin'] ?? false);
$userName = (string) ($auth['user_name'] ?? '');
$currentPath = (string) ($current_path ?? '/');
$navModules = is_array($nav_modules ?? null) ? $nav_modules : [];
$publicRegistrationEnabled = (bool) ($public_registration_enabled ?? true);
$adminItems = is_array($admin_nav_items ?? null) ? $admin_nav_items : [];
$userItems = is_array($user_nav_items ?? null) ? $user_nav_items : [];
$pagesModuleActive = (bool) ($pages_module_active ?? false);
$pagesHeaderUngrouped = is_array($pages_header_ungrouped ?? null) ? $pages_header_ungrouped : [];
$pagesHeaderGroups = is_array($pages_header_groups ?? null) ? $pages_header_groups : [];
$productMeta = is_array($product_meta ?? null) ? $product_meta : [];
$productName = (string) ($productMeta['product_name'] ?? 'Modulon');
$csrfToken = (string) ($csrf_token ?? '');

$isActive = static function (string $path) use ($currentPath): string {
    $normalizedPath = rtrim($path, '/');
    $normalizedCurrent = rtrim($currentPath, '/');

    if ($normalizedPath === '') {
        return $normalizedCurrent === '' ? 'active' : '';
    }

    return $normalizedCurrent === $normalizedPath || str_starts_with($normalizedCurrent, $normalizedPath . '/') ? 'active' : '';
};
?>
<nav class="navbar navbar-expand-lg app-navbar border-bottom py-2">
    <div class="container app-container app-header-grid">
        <a class="navbar-brand fw-semibold app-brand" href="/">
            <img src="/assets/img/modulon-icon.svg" alt="" class="app-brand-icon">
            <span class="app-brand-text"><?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNav" aria-controls="appNav" aria-expanded="false" aria-label="Navigation umschalten">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse app-header-shell" id="appNav">
            <div class="app-header-middle">
                <ul class="navbar-nav app-main-nav mb-2 mb-lg-0">
                    <?php foreach ($navModules as $module): ?>
                        <?php
                        $moduleUrl = (string) ($module['url'] ?? '/');
                        $moduleName = (string) ($module['name'] ?? 'Modul');
                        $children = is_array($module['children'] ?? null) ? $module['children'] : [];
                        $hasChildren = $children !== [];
                        $activeClass = $isActive($moduleUrl);
                        ?>
                        <?php if ($hasChildren): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link app-nav-link dropdown-toggle <?= $activeClass ?>" href="<?= htmlspecialchars($moduleUrl, ENT_QUOTES, 'UTF-8') ?>" id="module-nav-<?= htmlspecialchars((string) ($module['prefix'] ?? $moduleName), ENT_QUOTES, 'UTF-8') ?>" role="button" data-bs-toggle="dropdown" data-app-nav-dropdown-link aria-expanded="false">
                                    <?= htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <ul class="dropdown-menu app-module-dropdown" aria-labelledby="module-nav-<?= htmlspecialchars((string) ($module['prefix'] ?? $moduleName), ENT_QUOTES, 'UTF-8') ?>">
                                    <?php foreach ($children as $child): ?>
                                        <?php
                                        $childUrl = (string) ($child['url'] ?? '#');
                                        $childLabel = (string) ($child['label'] ?? '');
                                        $isChildActive = (bool) ($child['is_active'] ?? false);
                                        ?>
                                        <li>
                                            <a class="dropdown-item<?= $isChildActive ? ' active' : '' ?>" href="<?= htmlspecialchars($childUrl, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($childLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php else: ?>
                            <li class="nav-item">
                                <a class="nav-link app-nav-link <?= $activeClass ?>" href="<?= htmlspecialchars($moduleUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($pagesModuleActive): ?>
                        <?php foreach ($pagesHeaderUngrouped as $page): ?>
                            <?php
                            $pageUrl = (string) ($page['url'] ?? '/pages');
                            $pageTitle = (string) ($page['title'] ?? 'Seite');
                            ?>
                            <li class="nav-item">
                                <a class="nav-link app-nav-link <?= $isActive($pageUrl) ?>" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
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
                                <li class="nav-item">
                                    <a class="nav-link app-nav-link <?= $isActive($singleUrl) ?>" href="<?= htmlspecialchars($singleUrl, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($singleTitle, ENT_QUOTES, 'UTF-8') ?>
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
                            <li class="nav-item dropdown">
                                <a class="nav-link app-nav-link dropdown-toggle <?= $groupActive ?>" href="/pages" id="pages-group-<?= htmlspecialchars((string) md5((string) $groupName), ENT_QUOTES, 'UTF-8') ?>" role="button" data-bs-toggle="dropdown" data-app-nav-dropdown-link aria-expanded="false">
                                    <?= htmlspecialchars((string) $groupName, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <ul class="dropdown-menu app-module-dropdown" aria-labelledby="pages-group-<?= htmlspecialchars((string) md5((string) $groupName), ENT_QUOTES, 'UTF-8') ?>">
                                    <?php foreach ($groupItems as $groupItem): ?>
                                        <?php
                                        $groupItemUrl = (string) ($groupItem['url'] ?? '/pages');
                                        $groupItemLabel = (string) ($groupItem['title'] ?? 'Seite');
                                        ?>
                                        <li>
                                            <a class="dropdown-item<?= $isActive($groupItemUrl) !== '' ? ' active' : '' ?>" href="<?= htmlspecialchars($groupItemUrl, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($groupItemLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="app-header-right">
                <?php if ($isAdmin): ?>
                    <div class="nav-item dropdown app-admin-nav">
                        <a class="nav-link app-nav-link app-admin-link dropdown-toggle <?= $isActive('/admin') ?>" href="/admin/modules" id="admin-nav-dropdown" role="button" data-bs-toggle="dropdown" data-app-nav-dropdown-link aria-expanded="false">
                            Admin
                        </a>
                        <ul class="dropdown-menu app-module-dropdown" aria-labelledby="admin-nav-dropdown">
                            <?php foreach ($adminItems as $item): ?>
                                <?php
                                $adminUrl = (string) ($item['url'] ?? '#');
                                $adminLabel = (string) ($item['label'] ?? '');
                                $adminActive = (bool) ($item['is_active'] ?? false);
                                ?>
                                <li>
                                    <a class="dropdown-item<?= $adminActive ? ' active' : '' ?>" href="<?= htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($adminLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="app-user-zone">
                    <?php if ($isAuthenticated): ?>
                        <?php if ($userItems !== []): ?>
                            <div class="nav-item dropdown">
                                <button type="button" class="app-user-chip dropdown-toggle" id="user-nav-dropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="app-user-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($isAdmin): ?>
                                        <span class="app-role-badge">Admin</span>
                                    <?php endif; ?>
                                </button>
                                <ul class="dropdown-menu app-module-dropdown" aria-labelledby="user-nav-dropdown">
                                    <?php foreach ($userItems as $item): ?>
                                        <?php
                                        $userUrl = (string) ($item['url'] ?? '#');
                                        $userLabel = (string) ($item['label'] ?? '');
                                        $userActive = (bool) ($item['is_active'] ?? false);
                                        ?>
                                        <li>
                                            <a class="dropdown-item<?= $userActive ? ' active' : '' ?>" href="<?= htmlspecialchars($userUrl, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <span class="app-user-chip">
                                <span class="app-user-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($isAdmin): ?>
                                    <span class="app-role-badge">Admin</span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <form method="post" action="/logout" class="m-0">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Logout</button>
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
