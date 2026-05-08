<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$flash = (string) ($flash ?? '');
$user = is_array($user ?? null) ? $user : null;
$availableModules = is_array($available_modules ?? null) ? $available_modules : [];
$publicRegistrationEnabled = (bool) ($public_registration_enabled ?? true);
$healthSummary = is_array($health_summary ?? null) ? $health_summary : [];
$healthText = (string) ($healthSummary['text'] ?? '');
$healthStatus = strtolower((string) ($healthSummary['status'] ?? 'ok'));
$healthAlertClass = $healthStatus === 'error' ? 'alert-danger' : ($healthStatus === 'warning' ? 'alert-warning' : 'alert-success');
$authData = is_array($auth ?? null) ? $auth : [];
$isAdminUser = (bool) ($authData['is_admin'] ?? false);
$productMeta = is_array($product_meta ?? null) ? $product_meta : [];
$productName = (string) ($productMeta['product_name'] ?? $productMeta['public_product_name'] ?? 'ModulNest');
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
        <div class="alert <?= htmlspecialchars($healthAlertClass, ENT_QUOTES, 'UTF-8') ?> mb-0" role="status">
            <strong>Systemcheck:</strong> <?= htmlspecialchars($healthText, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($isAdminUser): ?>
                <span class="ms-2 small"><a href="/systeminfo" class="alert-link app-health-details-link">Details in Systeminfo</a></span>
            <?php endif; ?>
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
            <?php
            $name = (string) ($module['name'] ?? 'Modul');
            $description = trim((string) ($module['description'] ?? ''));
            $url = (string) ($module['url'] ?? '/');
            ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card shadow-sm border-0 app-card h-100">
                    <div class="card-body p-4 d-flex flex-column">
                        <h3 class="h6 mb-2"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="text-body-secondary small mb-3">
                            <?= htmlspecialchars($description !== '' ? $description : 'Keine Beschreibung hinterlegt.', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="mt-auto">
                            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-sm">Modul öffnen</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h1 class="h4 mb-1">Willkommen zurück, <?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="text-body-secondary mb-0"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <a href="/profil/security" class="btn btn-outline-secondary btn-sm">Sicherheit</a>
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
        <div class="alert <?= htmlspecialchars($healthAlertClass, ENT_QUOTES, 'UTF-8') ?> mb-0" role="status">
            <strong>Systemcheck:</strong> <?= htmlspecialchars($healthText, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($isAdminUser): ?>
                <span class="ms-2 small"><a href="/systeminfo" class="alert-link app-health-details-link">Details in Systeminfo</a></span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mt-1">
    <?php if ($availableModules === []): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 app-card">
                <div class="card-body p-4 text-body-secondary">Aktuell sind keine Module für deinen Zugriff aktiv.</div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($availableModules as $module): ?>
            <?php
            $name = (string) ($module['name'] ?? 'Modul');
            $description = trim((string) ($module['description'] ?? ''));
            $url = (string) ($module['url'] ?? '/');
            ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card shadow-sm border-0 app-card h-100">
                    <div class="card-body p-4 d-flex flex-column">
                        <h2 class="h6 mb-2"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="text-body-secondary small mb-3">
                            <?= htmlspecialchars($description !== '' ? $description : 'Keine Beschreibung hinterlegt.', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="mt-auto">
                            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-sm">Modul öffnen</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
