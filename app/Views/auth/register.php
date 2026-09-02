<?php
declare(strict_types=1);

$error = (string) ($error ?? '');
$info = (string) ($info ?? '');
$csrfToken = (string) ($csrf_token ?? '');
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4 p-md-5">
                <h1 class="h4 mb-4">Interne Registrierung</h1>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($info !== ''): ?>
                    <div class="alert alert-success" role="alert"><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="post" action="/internal/register" class="vstack gap-3">
                    <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                    <div>
                        <label for="name" class="form-label">Name</label>
                        <input id="name" class="form-control" type="text" name="name" required>
                    </div>
                    <div>
                        <label for="email" class="form-label">E-Mail</label>
                        <input id="email" class="form-control" type="email" name="email" required>
                    </div>
                    <div>
                        <label for="password" class="form-label">Passwort (mind. 8 Zeichen)</label>
                        <input id="password" class="form-control" type="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Benutzer anlegen</button>
                </form>
            </div>
        </div>
    </div>
</div>
