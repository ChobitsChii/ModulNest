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
            <span class="app-divider-dot">&middot;</span>
            <a class="app-footer-link" href="https://github.com/ChobitsChii/ModulNest" rel="noopener noreferrer">
                <svg class="app-footer-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.22 2.2.82A7.65 7.65 0 0 1 8 3.86c.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/>
                </svg>
                <span>GitHub</span>
            </a>
        </span>
    </div>
</footer>
