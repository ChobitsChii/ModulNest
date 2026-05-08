<?php
declare(strict_types=1);

$adminSection = (string) ($admin_section ?? 'modules');
$adminNavItems = is_array($admin_nav_items ?? null) ? $admin_nav_items : [];
if ($adminNavItems === []) {
    $adminNavItems = [
        ['key' => 'modules', 'label' => 'Modulverwaltung', 'url' => '/admin/modules', 'is_active' => $adminSection === 'modules'],
        ['key' => 'users', 'label' => 'Benutzerverwaltung', 'url' => '/admin/users', 'is_active' => $adminSection === 'users'],
    ];
}
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($adminNavItems as $item): ?>
        <?php
        $label = (string) ($item['label'] ?? '');
        $url = (string) ($item['url'] ?? '#');
        $isActive = (bool) ($item['is_active'] ?? false);
        ?>
        <li class="nav-item">
            <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
