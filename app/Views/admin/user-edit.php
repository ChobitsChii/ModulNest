<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$editUser = is_array($edit_user ?? null) ? $edit_user : [];
$currentUserId = (int) ($current_user_id ?? 0);

$userId = (int) ($editUser['id'] ?? 0);
$isCurrent = $userId > 0 && $userId === $currentUserId;
$role = (string) ($editUser['role_name'] ?? 'user');
$isBlocked = (int) ($editUser['is_blocked'] ?? 0) === 1;
?>
<?php require __DIR__ . '/partials/nav.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Benutzer bearbeiten</h1>
    <a class="btn btn-outline-secondary btn-sm" href="/admin/users">Zurück zur Übersicht</a>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body p-4">
        <form method="post" action="/admin/users/update" class="row g-3">
            <input type="hidden" name="user_id" value="<?= $userId ?>">

            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="edit_user_name">Anzeigename / Name</label>
                <input id="edit_user_name" class="form-control" type="text" name="name" required value="<?= htmlspecialchars((string) ($editUser['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="edit_user_username">Benutzername</label>
                <input id="edit_user_username" class="form-control" type="text" name="username" value="<?= htmlspecialchars((string) ($editUser['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="z. B. max.mustermann">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="edit_user_email">E-Mail-Adresse</label>
                <input id="edit_user_email" class="form-control" type="email" name="email" required value="<?= htmlspecialchars((string) ($editUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="edit_user_role">Rolle</label>
                <select id="edit_user_role" class="form-select" name="role"<?= $isCurrent ? ' disabled' : '' ?>>
                    <option value="user"<?= $role === 'user' ? ' selected' : '' ?>>user</option>
                    <option value="admin"<?= $role === 'admin' ? ' selected' : '' ?>>admin</option>
                </select>
                <?php if ($isCurrent): ?>
                    <input type="hidden" name="role" value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="edit_user_blocked">Status</label>
                <select id="edit_user_blocked" class="form-select" name="is_blocked"<?= $isCurrent ? ' disabled' : '' ?>>
                    <option value="0"<?= !$isBlocked ? ' selected' : '' ?>>Aktiv</option>
                    <option value="1"<?= $isBlocked ? ' selected' : '' ?>>Gesperrt</option>
                </select>
                <?php if ($isCurrent): ?>
                    <input type="hidden" name="is_blocked" value="0">
                <?php endif; ?>
            </div>

            <div class="col-12"><hr class="my-2"></div>

            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="edit_user_new_password">Neues Passwort (optional)</label>
                <input id="edit_user_new_password" class="form-control" type="password" name="new_password" minlength="8" autocomplete="new-password">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label mb-1" for="edit_user_new_password_confirmation">Neues Passwort wiederholen</label>
                <input id="edit_user_new_password_confirmation" class="form-control" type="password" name="new_password_confirmation" minlength="8" autocomplete="new-password">
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm">Benutzer speichern</button>
            </div>
        </form>
    </div>
</div>
