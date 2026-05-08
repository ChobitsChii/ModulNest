<?php
declare(strict_types=1);

$moduleName = (string) ($module_name ?? 'Modul');
$moduleDescription = trim((string) ($module_description ?? ''));
$prefix = (string) ($prefix ?? '');
$access = (string) ($access ?? 'public');
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4 p-md-5">
                <h1 class="h4 mb-3"><?= htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($moduleDescription !== ''): ?>
                    <p class="text-body-secondary mb-3"><?= htmlspecialchars($moduleDescription, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-body-secondary">Route</dt>
                    <dd class="col-sm-8">/<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-body-secondary">Zugriff</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($access, ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
