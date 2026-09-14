<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$flash = (string) ($flash ?? '');
$user = is_array($user ?? null) ? $user : null;
$availableModules = is_array($available_modules ?? null) ? $available_modules : [];
$favoriteModules = is_array($favorite_modules ?? null) ? $favorite_modules : [];
$publicRegistrationEnabled = (bool) ($public_registration_enabled ?? true);
$healthSummary = is_array($health_summary ?? null) ? $health_summary : [];
$healthText = (string) ($healthSummary['text'] ?? '');
$healthStatus = strtolower((string) ($healthSummary['status'] ?? 'ok'));
$healthAlertClass = $healthStatus === 'error' ? 'alert-danger' : ($healthStatus === 'warning' ? 'alert-warning' : 'alert-success');
$healthIcon = $healthStatus === 'error' ? 'bi-x-circle-fill' : ($healthStatus === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
$authData = is_array($auth ?? null) ? $auth : [];
$isAdminUser = (bool) ($authData['is_admin'] ?? false);
$productMeta = is_array($product_meta ?? null) ? $product_meta : [];
$productName = (string) ($productMeta['product_name'] ?? $productMeta['public_product_name'] ?? 'ModulNest');
$csrfToken = (string) ($csrf_token ?? '');

$renderModuleCard = static function (array $module, bool $isFavorite, string $csrfToken, bool $canFavorite = true): string {
    $name = (string) ($module['name'] ?? 'Modul');
    $description = trim((string) ($module['description'] ?? ''));
    $url = (string) ($module['url'] ?? '/');
    $key = (string) ($module['prefix'] ?? $module['key'] ?? strtolower($name));
    $presentation = \Modulon\Core\ModulePresentationRegistry::presentation($key);
    $icon = $presentation['icon'];
    $category = $presentation['category'];
    $color = $presentation['color'];
    $bg = $presentation['bg'];
    $textColor = $presentation['text'];

    $favButtonHtml = '';
    if ($canFavorite) {
        $starClass = $isFavorite ? 'bi-star-fill is-active' : 'bi-star';
        $favButtonHtml = '<button type="button" class="app-favorite-btn ' . ($isFavorite ? 'is-active' : '') . '" data-module-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" title="' . ($isFavorite ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen') . '" aria-label="' . ($isFavorite ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen') . '">
            <i class="bi ' . $starClass . '"></i>
        </button>';
    }

    return '<div class="col-12 col-md-6 col-xl-4">
        <div class="card app-module-tile h-100">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                    <div class="app-module-icon-badge" style="background:' . htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') . ';color:' . htmlspecialchars($textColor, ENT_QUOTES, 'UTF-8') . ';">
                        <i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>
                    </div>
                    ' . $favButtonHtml . '
                </div>
                <div class="mb-1">
                    <span class="badge bg-body-secondary text-body-secondary small fw-normal mb-1">' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '</span>
                    <h3 class="h6 fw-semibold mb-1">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</h3>
                </div>
                <p class="text-body-secondary small mb-3 flex-grow-1">
                    ' . htmlspecialchars($description !== '' ? $description : 'Keine Beschreibung hinterlegt.', ENT_QUOTES, 'UTF-8') . '
                </p>
                <div class="mt-auto pt-2 d-flex align-items-center justify-content-between border-top border-opacity-10">
                    <span class="small fw-medium text-primary d-inline-flex align-items-center gap-1 app-tile-link">
                        Modul öffnen <i class="bi bi-arrow-right app-tile-arrow"></i>
                    </span>
                    <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="stretched-link" aria-label="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' öffnen"></a>
                </div>
            </div>
        </div>
    </div>';
};
?>
<?php if ($user === null): ?>
<div class="row g-4 align-items-stretch">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4 p-md-5">
                <h1 class="h3 mb-3"><?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?> verbindet deine Apps in einem Login.</h1>
                <p class="text-body-secondary mb-4">Verwalte Module zentral, steuere Berechtigungen pro Bereich und binde bestehende Legacy-Apps ohne Umbau ein.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="/login" class="btn btn-primary">Login</a>
                    <?php if ($publicRegistrationEnabled): ?>
                        <a href="/internal/register" class="btn btn-outline-secondary">Registrieren</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body p-4">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Warum <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="d-grid gap-2">
                    <div class="border rounded-2 p-2">
                        <strong class="d-block small">Ein Login</strong>
                        <span class="text-body-secondary small">Zentraler Zugriff auf alle freigegebenen Module.</span>
                    </div>
                    <div class="border rounded-2 p-2">
                        <strong class="d-block small">Rechtesteuerung</strong>
                        <span class="text-body-secondary small">public, user und admin pro Modul konfigurierbar.</span>
                    </div>
                    <div class="border rounded-2 p-2">
                        <strong class="d-block small">Legacy-Kompatibel</strong>
                        <span class="text-body-secondary small">Bestehende PHP-Apps weiterhin nutzbar.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($healthText !== ''): ?>
<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="alert <?= htmlspecialchars($healthAlertClass, ENT_QUOTES, 'UTF-8') ?> d-flex align-items-center gap-2 mb-0" role="status">
            <i class="bi <?= htmlspecialchars($healthIcon, ENT_QUOTES, 'UTF-8') ?> fs-5"></i>
            <div>
                <strong>Systemcheck:</strong> <?= htmlspecialchars($healthText, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($isAdminUser): ?>
                    <span class="ms-2 small"><a href="/systeminfo" class="alert-link app-health-details-link">Details in Systeminfo</a></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-lg">
                        <h2 class="h6 mb-2">Öffentliche Nutzung</h2>
                        <p class="text-body-secondary mb-0">
                            Diese <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>-Instanz darf als normaler Benutzer frei genutzt werden. Eine vollständige Demo für Admin-Funktionen ist noch in Arbeit. Wenn du <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?> selbst betreiben möchtest, reicht die <code>install.php</code> aus dem GitHub-Repo.
                        </p>
                    </div>
                    <div class="col-12 col-lg-auto">
                        <div class="d-flex flex-wrap gap-2">
                            <a href="https://github.com/ChobitsChii/ModulNest" class="btn btn-outline-secondary btn-sm" rel="noopener noreferrer">GitHub</a>
                            <a href="https://github.com/ChobitsChii/ModulNest/blob/main/install.php" class="btn btn-primary btn-sm" rel="noopener noreferrer">install.php</a>
                            <a href="https://github.com/ChobitsChii/ModulNest/releases/latest" class="btn btn-outline-secondary btn-sm" rel="noopener noreferrer">Neuester Release</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12">
        <h2 class="h6 text-uppercase text-body-secondary mb-1">Öffentlich nutzbar</h2>
    </div>
    <?php if ($availableModules === []): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 app-card">
                <div class="card-body p-4 text-body-secondary">Aktuell sind keine öffentlichen Module aktiv.</div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($availableModules as $module): ?>
            <?= $renderModuleCard($module, false, $csrfToken, false) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php else: ?>

<?php
$favModulesList = [];
$otherModulesList = [];
foreach ($availableModules as $mod) {
    $mKey = (string) ($mod['prefix'] ?? $mod['key'] ?? strtolower((string) ($mod['name'] ?? '')));
    if (in_array($mKey, $favoriteModules, true)) {
        $favModulesList[] = $mod;
    } else {
        $otherModulesList[] = $mod;
    }
}
?>

<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <?= \Modulon\Core\UserAvatarHelper::render($user, 48) ?>
                        <div>
                            <h1 class="h4 mb-1">Willkommen zurück, <?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> 👋</h1>
                            <p class="text-body-secondary small mb-0 d-flex align-items-center gap-2">
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Aktiv</span>
                                <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
                            </p>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <a href="/profil" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
                            <i class="bi bi-person"></i> Profil
                        </a>
                        <a href="/profil/security" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
                            <i class="bi bi-shield-lock"></i> Sicherheit
                        </a>
                        <?php if ($isAdminUser): ?>
                            <a href="/admin/modules" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1">
                                <i class="bi bi-grid"></i> Modulverwaltung
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($flash !== ''): ?>
                    <div class="alert alert-success mt-3 mb-0" role="alert"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($healthText !== ''): ?>
<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="alert <?= htmlspecialchars($healthAlertClass, ENT_QUOTES, 'UTF-8') ?> d-flex align-items-center gap-2 mb-0 shadow-sm" role="status">
            <i class="bi <?= htmlspecialchars($healthIcon, ENT_QUOTES, 'UTF-8') ?> fs-5"></i>
            <div>
                <strong>Systemcheck:</strong> <?= htmlspecialchars($healthText, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($isAdminUser): ?>
                    <span class="ms-2 small"><a href="/systeminfo" class="alert-link app-health-details-link">Details in Systeminfo</a></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($availableModules === []): ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card shadow-sm border-0 app-card">
                <div class="card-body p-4 text-body-secondary">Aktuell sind keine Module für deinen Zugriff aktiv.</div>
            </div>
        </div>
    </div>
<?php else: ?>

    <?php if ($favModulesList !== []): ?>
        <div class="row g-3 mt-2" id="favorites-section">
            <div class="col-12 d-flex align-items-center gap-2">
                <i class="bi bi-star-fill text-warning"></i>
                <h2 class="h6 text-uppercase text-body-secondary mb-0">Meine Favoriten</h2>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1"><?= count($favModulesList) ?></span>
            </div>
            <?php foreach ($favModulesList as $module): ?>
                <?= $renderModuleCard($module, true, $csrfToken, true) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mt-2" id="all-modules-section">
        <div class="col-12 d-flex align-items-center gap-2">
            <i class="bi bi-grid-fill text-primary"></i>
            <h2 class="h6 text-uppercase text-body-secondary mb-0"><?= $favModulesList !== [] ? 'Weitere Module' : 'Alle verfügbaren Module' ?></h2>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1"><?= count($otherModulesList) ?></span>
        </div>
        <?php foreach ($otherModulesList as $module): ?>
            <?= $renderModuleCard($module, false, $csrfToken, true) ?>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';
    
    document.querySelectorAll('.app-favorite-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const moduleKey = btn.getAttribute('data-module-key');
            if (!moduleKey) return;

            const icon = btn.querySelector('i');
            const wasActive = btn.classList.contains('is-active');

            // Optimistic UI toggle
            btn.classList.toggle('is-active');
            if (icon) {
                icon.className = wasActive ? 'bi bi-star' : 'bi bi-star-fill is-active';
            }

            const formData = new FormData();
            formData.append('_csrf', csrfToken);
            formData.append('csrf_token', csrfToken);
            formData.append('module_key', moduleKey);

            fetch('/profil/favorites/toggle', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    // Smooth reload so sections re-group naturally
                    window.location.reload();
                } else {
                    // Revert on error
                    btn.classList.toggle('is-active', wasActive);
                    if (icon) {
                        icon.className = wasActive ? 'bi bi-star-fill is-active' : 'bi bi-star';
                    }
                }
            })
            .catch(function () {
                btn.classList.toggle('is-active', wasActive);
                if (icon) {
                    icon.className = wasActive ? 'bi bi-star-fill is-active' : 'bi bi-star';
                }
            });
        });
    });
});
</script>

<?php endif; ?>
