<?php
declare(strict_types=1);

$userTab = (string) ($user_tab ?? 'profile');
$profileUser = is_array($profile_user ?? null) ? $profile_user : [];
$profileMessage = (string) ($profile_message ?? '');
$profileError = (string) ($profile_error ?? '');

$securityMessage = (string) ($security_message ?? '');
$securityError = (string) ($security_error ?? '');
$settingsMessage = (string) ($settings_message ?? '');
$settingsError = (string) ($settings_error ?? '');
$timezoneOptions = is_array($timezone_options ?? null) ? $timezone_options : [];
$settingsTimezone = (string) ($settings_timezone ?? 'UTC');
$settingsDashboardAutoRefreshEnabled = (bool) ($settings_dashboard_auto_refresh_enabled ?? true);
$settingsDashboardAutoRefreshIntervalMinutes = (int) ($settings_dashboard_auto_refresh_interval_minutes ?? 30);
$fantasyCardsProfileAvailable = (bool) ($fantasy_cards_profile_available ?? false);
$fantasyCardsProfile = is_array($fantasy_cards_profile ?? null) ? $fantasy_cards_profile : [];
$profileCardsMessage = (string) ($profile_cards_message ?? '');
$profileCardsError = (string) ($profile_cards_error ?? '');
$totpEnabled = (bool) ($totp_enabled ?? false);
$webauthnEnabled = (bool) ($webauthn_enabled ?? false);
$pendingTotp = is_array($pending_totp ?? null) ? $pending_totp : null;
$recoveryCodes = is_array($recovery_codes ?? null) ? $recovery_codes : [];
$recoveryCount = (int) ($recovery_count ?? 0);
$credentials = is_array($credentials ?? null) ? $credentials : [];
?>
<?php require __DIR__ . '/partials/nav.php'; ?>

<?php if ($userTab === 'profile'): ?>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h4 mb-0">Profil</h1>
    </div>

    <?php if ($profileMessage !== '' || $profileError !== ''): ?>
        <div class="modulon-feedback-stack mb-4">
            <?php if ($profileMessage !== ''): ?>
                <div class="alert alert-success mb-0" role="alert"><?= htmlspecialchars($profileMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($profileError !== ''): ?>
                <div class="alert alert-danger mb-0" role="alert"><?= htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 app-card">
        <div class="card-body p-4">
            <form method="post" action="/profil/update" class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label mb-1" for="profile_name">Anzeigename / Name</label>
                    <input id="profile_name" class="form-control" type="text" name="name" required value="<?= htmlspecialchars((string) ($profileUser['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label mb-1" for="profile_username">Benutzername</label>
                    <input id="profile_username" class="form-control" type="text" name="username" value="<?= htmlspecialchars((string) ($profileUser['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="z. B. max.mustermann">
                    <div class="form-text">Optional. Du kannst dich damit alternativ zur E-Mail-Adresse anmelden.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label mb-1" for="profile_email">E-Mail-Adresse</label>
                    <input id="profile_email" class="form-control" type="email" name="email" required value="<?= htmlspecialchars((string) ($profileUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Profil speichern</button>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($userTab === 'security'): ?>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h4 mb-0">Sicherheits-Einstellungen</h1>
    </div>

    <?php if ($securityMessage !== '' || $securityError !== ''): ?>
        <div class="modulon-feedback-stack mb-4">
            <?php if ($securityMessage !== ''): ?>
                <div class="alert alert-success mb-0" role="alert"><?= htmlspecialchars($securityMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($securityError !== ''): ?>
                <div class="alert alert-danger mb-0" role="alert"><?= htmlspecialchars($securityError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div id="security-inline-feedback" class="module-toggle-feedback small mb-3" aria-live="polite"></div>

    <div class="row g-4 modulon-security-grid">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 app-card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 text-uppercase text-body-secondary mb-3">Passwort</h2>
                    <p class="small text-body-secondary mb-3">Zum Ändern wird dein aktuelles Passwort zur Bestätigung benötigt.</p>
                    <form method="post" action="/profil/password" class="row g-3">
                        <div class="col-12">
                            <label class="form-label mb-1" for="current_password">Aktuelles Passwort</label>
                            <input id="current_password" class="form-control" type="password" name="current_password" required autocomplete="current-password">
                            <div class="form-text">Erforderlich zur Absicherung der Änderung.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label mb-1" for="new_password">Neues Passwort</label>
                            <input id="new_password" class="form-control" type="password" name="new_password" minlength="8" required autocomplete="new-password">
                            <div class="form-text">Mindestens 8 Zeichen verwenden.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label mb-1" for="new_password_confirmation">Neues Passwort wiederholen</label>
                            <input id="new_password_confirmation" class="form-control" type="password" name="new_password_confirmation" minlength="8" required autocomplete="new-password">
                            <div class="form-text">Muss mit dem neuen Passwort übereinstimmen.</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Passwort ändern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 app-card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 text-uppercase text-body-secondary mb-3">TOTP</h2>
                    <p>Status: <strong><?= $totpEnabled ? 'Aktiv' : 'Inaktiv' ?></strong></p>

                    <?php if ($pendingTotp !== null): ?>
                        <p class="small text-body-secondary mb-2">Secret</p>
                        <p><code><?= htmlspecialchars((string) ($pendingTotp['secret'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></p>
                        <img class="img-fluid rounded border mb-3" src="<?= htmlspecialchars((string) ($pendingTotp['qr_data_uri'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="TOTP QR">
                        <form method="post" action="/account/security/totp/confirm" class="vstack gap-2">
                            <label class="form-label mb-0" for="totp_confirm">Bestätigungscode</label>
                            <input id="totp_confirm" class="form-control" type="text" name="code" inputmode="numeric" required>
                            <button type="submit" class="btn btn-primary">TOTP aktivieren</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/account/security/totp/start">
                            <button type="submit" class="btn btn-primary">TOTP einrichten</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($totpEnabled): ?>
                        <form method="post" action="/account/security/totp/disable" class="mt-3">
                            <button type="submit" class="btn btn-outline-danger">TOTP deaktivieren</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 app-card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 text-uppercase text-body-secondary mb-3">Passkeys (WebAuthn)</h2>
                    <p>Status: <strong><?= $webauthnEnabled ? 'Aktiv' : 'Inaktiv' ?></strong></p>

                    <div class="mb-3">
                        <label for="credential_label" class="form-label">Name für neues Credential</label>
                        <input id="credential_label" class="form-control" type="text" value="Mein Passkey">
                    </div>
                    <button type="button" class="btn btn-outline-secondary mb-4" onclick="registerPasskey()">Passkey hinzufügen</button>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Name</th>
                                <th>Zuletzt genutzt</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($credentials === []): ?>
                                <tr><td colspan="3" class="text-body-secondary">Keine Passkeys vorhanden.</td></tr>
                            <?php else: ?>
                                <?php foreach ($credentials as $credential): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($credential['label'] ?? 'Passkey'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($credential['last_used_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-end">
                                            <form method="post" action="/account/security/webauthn/delete" class="d-inline">
                                                <input type="hidden" name="credential_id" value="<?= (int) ($credential['id'] ?? 0) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Entfernen</button>
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
        </div>

        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 app-card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 text-uppercase text-body-secondary mb-3">Recovery Codes</h2>
                    <p class="mb-3">Aktive Recovery Codes: <strong><?= $recoveryCount ?></strong></p>
                    <form method="post" action="/account/security/recovery/regenerate" class="mb-3">
                        <button type="submit" class="btn btn-outline-secondary">Neu generieren</button>
                    </form>

                    <?php if ($recoveryCodes !== []): ?>
                        <div class="alert alert-warning">
                            Neue Codes werden nur jetzt angezeigt. Bitte sicher speichern.
                        </div>
                        <div class="row g-2">
                            <?php foreach ($recoveryCodes as $code): ?>
                                <div class="col-6 col-md-3">
                                    <code><?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?></code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    function b64ToAb(v){const p='='.repeat((4-v.length%4)%4);const b=atob((v+p).replace(/-/g,'+').replace(/_/g,'/'));const a=new Uint8Array(b.length);for(let i=0;i<b.length;i++){a[i]=b.charCodeAt(i);}return a.buffer;}
    function fromBase64(pk){if(pk.challenge){pk.challenge=b64ToAb(pk.challenge);}if(pk.user&&pk.user.id){pk.user.id=b64ToAb(pk.user.id);}if(Array.isArray(pk.excludeCredentials)){pk.excludeCredentials=pk.excludeCredentials.map(c=>({...c,id:b64ToAb(c.id)}));}if(Array.isArray(pk.allowCredentials)){pk.allowCredentials=pk.allowCredentials.map(c=>({...c,id:b64ToAb(c.id)}));}return pk;}
    function ab2b64(ab){const bytes=new Uint8Array(ab);let str='';for(const b of bytes){str+=String.fromCharCode(b);}return btoa(str);}
    function setSecurityInlineFeedback(text, isError){
        const feedback = document.getElementById('security-inline-feedback');
        if(!feedback){return;}
        feedback.textContent = text || '';
        feedback.classList.toggle('is-error', !!isError);
        if (text) {
            window.setTimeout(() => {
                if (feedback.textContent === text) {
                    feedback.textContent = '';
                    feedback.classList.remove('is-error');
                }
            }, 3000);
        }
    }
    async function registerPasskey(){
        try{
            const label=document.getElementById('credential_label').value||'Passkey';
            const optionsRes=await fetch('/account/security/webauthn/options',{method:'POST'});
            const options=await optionsRes.json();
            if(!options.success){throw new Error(options.message||'Optionen fehlgeschlagen.');}
            const publicKey=fromBase64(options.publicKey);
            const cred=await navigator.credentials.create({publicKey});
            const payload={label:label,transports:cred.response.getTransports?cred.response.getTransports():[],clientDataJSON:ab2b64(cred.response.clientDataJSON),attestationObject:ab2b64(cred.response.attestationObject)};
            const verifyRes=await fetch('/account/security/webauthn/verify',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
            const verify=await verifyRes.json();
            if(!verify.success){throw new Error(verify.message||'Passkey-Registrierung fehlgeschlagen.');}
            window.location='/profil/security';
        }catch(e){setSecurityInlineFeedback(e.message||'Passkey-Registrierung fehlgeschlagen.', true);}
    }
    </script>
<?php elseif ($userTab === 'settings'): ?>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h4 mb-0">Einstellungen</h1>
    </div>

    <?php if ($settingsMessage !== '' || $settingsError !== ''): ?>
        <div class="modulon-feedback-stack mb-4">
            <?php if ($settingsMessage !== ''): ?>
                <div class="alert alert-success mb-0" role="alert"><?= htmlspecialchars($settingsMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($settingsError !== ''): ?>
                <div class="alert alert-danger mb-0" role="alert"><?= htmlspecialchars($settingsError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 app-card">
        <div class="card-body p-4">
            <form method="post" action="/profil/settings" class="row g-3">
                <div class="col-12 col-md-7 col-lg-6">
                    <label class="form-label mb-1" for="profile_timezone">Zeitzone</label>
                    <select id="profile_timezone" class="form-select" name="timezone" required>
                        <?php foreach ($timezoneOptions as $option): ?>
                            <?php
                            $tzValue = (string) ($option['value'] ?? '');
                            $tzLabel = (string) ($option['label'] ?? $tzValue);
                            if ($tzValue === '') {
                                continue;
                            }
                            ?>
                            <option value="<?= htmlspecialchars($tzValue, ENT_QUOTES, 'UTF-8') ?>"<?= $tzValue === $settingsTimezone ? ' selected' : '' ?>><?= htmlspecialchars($tzLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Diese Zeitzone wird als Grundlage für zeitbasierte Funktionen im Benutzerkontext genutzt.</div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input
                            id="settings_dashboard_auto_refresh_enabled"
                            class="form-check-input"
                            type="checkbox"
                            name="dashboard_auto_refresh_enabled"
                            value="1"
                            <?= $settingsDashboardAutoRefreshEnabled ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="settings_dashboard_auto_refresh_enabled">
                            Dashboard Auto-Aktualisieren aktivieren
                        </label>
                    </div>
                    <div class="form-text">Im Dashboard wird bei aktiver Option automatisch neu geladen.</div>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="form-label mb-1" for="settings_dashboard_auto_refresh_interval_minutes">Auto-Aktualisierungsintervall (Minuten)</label>
                    <input
                        id="settings_dashboard_auto_refresh_interval_minutes"
                        class="form-control"
                        type="number"
                        name="dashboard_auto_refresh_interval_minutes"
                        min="5"
                        max="240"
                        step="1"
                        required
                        value="<?= $settingsDashboardAutoRefreshIntervalMinutes ?>"
                    >
                    <div class="form-text">Standard: 30 Minuten. Erlaubt: 5 bis 240 Minuten.</div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Einstellungen speichern</button>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($userTab === 'fantasy-cards' && $fantasyCardsProfileAvailable): ?>
    <?php require __DIR__ . '/partials/fantasy-cards.php'; ?>
<?php endif; ?>
