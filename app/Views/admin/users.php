<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$users = is_array($users ?? null) ? $users : [];
$registrationEnabled = (bool) ($public_registration_enabled ?? true);
$adminSection = (string) ($admin_section ?? 'users');
$currentUserId = (int) ($current_user_id ?? 0);
?>
<?php require __DIR__ . '/partials/nav.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Admin / Benutzer</h1>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Neuen Benutzer anlegen</h2>
                <form method="post" action="/admin/users/create" class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1" for="new_user_name">Name</label>
                        <input id="new_user_name" class="form-control" type="text" name="name" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1" for="new_user_username">Benutzername</label>
                        <input id="new_user_username" class="form-control" type="text" name="username" placeholder="z. B. max.mustermann">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1" for="new_user_email">E-Mail</label>
                        <input id="new_user_email" class="form-control" type="email" name="email" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1" for="new_user_password">Passwort</label>
                        <input id="new_user_password" class="form-control" type="password" name="password" minlength="8" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1" for="new_user_role">Rolle</label>
                        <select id="new_user_role" class="form-select" name="role">
                            <option value="user">user</option>
                            <option value="admin">admin</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm">Benutzer anlegen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card shadow-sm border-0 app-card h-100">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Öffentliche Registrierung</h2>
                <div class="vstack gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <label class="module-flag-switch m-0" title="Registrierung für Gäste erlauben">
                            <input
                                id="public_registration_enabled"
                                class="module-flag-switch-input js-registration-toggle"
                                type="checkbox"
                                <?= $registrationEnabled ? 'checked' : '' ?>
                            >
                            <span class="module-flag-switch-track" aria-hidden="true"></span>
                        </label>
                        <label for="public_registration_enabled" class="form-label mb-0">Registrierung für Gäste erlauben</label>
                    </div>
                    <div id="registration-toggle-feedback" class="module-toggle-feedback small" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 app-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 app-table">
                <thead>
                <tr>
                    <th class="ps-4">Name</th>
                    <th>Benutzername</th>
                    <th>E-Mail</th>
                    <th>Rolle</th>
                    <th>Status</th>
                    <th>Erstellt</th>
                    <th class="pe-4 text-end">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="7" class="ps-4 text-body-secondary">Keine Benutzer vorhanden.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $row): ?>
                        <?php
                        $userId = (int) ($row['id'] ?? 0);
                        $name = (string) ($row['name'] ?? '');
                        $username = trim((string) ($row['username'] ?? ''));
                        $email = (string) ($row['email'] ?? '');
                        $role = (string) ($row['role_name'] ?? 'user');
                        $isBlocked = (int) ($row['is_blocked'] ?? 0) === 1;
                        $createdAt = (string) ($row['created_at'] ?? '');
                        $isCurrent = $currentUserId > 0 && $currentUserId === $userId;
                        ?>
                        <tr>
                            <td class="ps-4 fw-medium"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($username !== ''): ?>
                                    <code><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></code>
                                <?php else: ?>
                                    <span class="text-body-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <?php if ($isBlocked): ?>
                                    <span class="badge text-bg-warning">Gesperrt</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Aktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-body-secondary small"><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="pe-4 text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="/admin/users/<?= $userId ?>/edit">Bearbeiten</a>
                                <form method="post" action="/admin/users/toggle-block" class="d-inline">
                                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"<?= $isCurrent ? ' disabled' : '' ?>>
                                        <?= $isBlocked ? 'Entsperren' : 'Sperren' ?>
                                    </button>
                                </form>
                                <form method="post" action="/admin/users/delete" class="d-inline ms-1" onsubmit="return confirm('Benutzer wirklich löschen?');">
                                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"<?= $isCurrent ? ' disabled' : '' ?>>Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(() => {
    const toggle = document.querySelector('.js-registration-toggle');
    const feedback = document.getElementById('registration-toggle-feedback');
    if (!toggle) return;

    const setFeedback = (text, isError = false) => {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.classList.toggle('is-error', isError);
        if (text !== '') {
            window.setTimeout(() => {
                if (feedback.textContent === text) {
                    feedback.textContent = '';
                    feedback.classList.remove('is-error');
                }
            }, 2600);
        }
    };

    toggle.addEventListener('change', async () => {
        const previous = !toggle.checked;
        toggle.disabled = true;

        try {
            const response = await fetch('/admin/settings/registration/toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ enabled: toggle.checked ? 1 : 0 }),
            });

            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Speichern fehlgeschlagen.');
            }

            setFeedback(payload.message || 'Status gespeichert.');
        } catch (error) {
            toggle.checked = previous;
            setFeedback(error instanceof Error ? error.message : 'Speichern fehlgeschlagen.', true);
        } finally {
            toggle.disabled = false;
        }
    });
})();
</script>
