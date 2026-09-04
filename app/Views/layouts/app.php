<?php
declare(strict_types=1);

$assetVersion = rawurlencode((string) ($app_version ?? ''));
$layoutAuth = is_array($auth ?? null) ? $auth : [];
$layoutAuthenticated = (bool) ($layoutAuth['is_authenticated'] ?? false);
$layoutThemeCandidate = (string) ($theme_mode ?? 'system');
$layoutThemeMode = in_array($layoutThemeCandidate, ['system', 'light', 'dark'], true) ? $layoutThemeCandidate : 'system';
?>
<!doctype html>
<html lang="de" data-theme-mode="<?= htmlspecialchars($layoutThemeMode, ENT_QUOTES, 'UTF-8') ?>" data-theme-authenticated="<?= $layoutAuthenticated ? 'true' : 'false' ?>"<?= $layoutThemeMode !== 'system' ? ' data-theme="' . htmlspecialchars($layoutThemeMode, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string) ($title ?? 'Modulon'), ENT_QUOTES, 'UTF-8') ?></title>
    <script src="/assets/js/theme-init.js<?= $assetVersion !== '' ? '?v=' . $assetVersion : '' ?>"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/app.css<?= $assetVersion !== '' ? '?v=' . $assetVersion : '' ?>" rel="stylesheet">
</head>
<body class="app-body">
<?php require dirname(__DIR__) . '/partials/navbar.php'; ?>

<main class="py-4 py-md-5">
    <div class="container app-container">
        <?php
        $layoutCurrentPath = '/' . trim((string) ($current_path ?? ''), '/');
        $layoutAdminNavItems = is_array($admin_nav_items ?? null) ? $admin_nav_items : [];
        if (($layoutCurrentPath === '/admin' || str_starts_with($layoutCurrentPath, '/admin/')) && $layoutAdminNavItems !== []) {
            require dirname(__DIR__) . '/admin/partials/nav.php';
        }
        ?>
        <?= $content ?? '' ?>
    </div>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js<?= $assetVersion !== '' ? '?v=' . $assetVersion : '' ?>"></script>
<script src="/assets/js/markdown-highlight.js<?= $assetVersion !== '' ? '?v=' . $assetVersion : '' ?>" defer></script>
</body>
</html>
