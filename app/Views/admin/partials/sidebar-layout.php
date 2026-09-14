<?php
declare(strict_types=1);
?>
<div class="row g-4 admin-sidebar-layout">
    <aside class="col-12 col-lg-3 col-xl-2 admin-sidebar-col mb-4 mb-lg-0">
        <?php require __DIR__ . '/sidebar.php'; ?>
    </aside>
    <section class="col-12 col-lg-9 col-xl-10 admin-content-col">
        <?= $content ?? '' ?>
    </section>
</div>
