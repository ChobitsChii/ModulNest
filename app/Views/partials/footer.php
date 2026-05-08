<?php
declare(strict_types=1);

$version = (string) ($app_version ?? '0.4.0');
$productMeta = is_array($product_meta ?? null) ? $product_meta : [];
$productName = (string) ($productMeta['product_name'] ?? 'Modulon');
$coreLabel = (string) ($productMeta['core_label'] ?? 'Modulon Core');
?>
<footer class="app-footer border-top py-3 mt-4">
    <div class="container text-body-secondary small">
        <span class="app-footer-meta">
            <span><?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="app-divider-dot">&middot;</span>
            <span>Version <?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="app-divider-dot">&middot;</span>
            <span>Powered by <?= htmlspecialchars($coreLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </span>
    </div>
</footer>
