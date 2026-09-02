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
$dataPortabilityAvailable = (bool) ($data_portability_available ?? false);
$dataPortabilityProviders = is_array($data_portability_providers ?? null) ? $data_portability_providers : [];
$dataPortabilityPreview = is_array($data_portability_preview ?? null) ? $data_portability_preview : null;
$dataPortabilityImportMode = (string) ($data_portability_import_mode ?? ($dataPortabilityPreview['import_mode'] ?? 'merge'));
$dataPortabilityImportMode = $dataPortabilityImportMode === 'replace' ? 'replace' : 'merge';
$dataPortabilityMessage = (string) ($data_portability_message ?? '');
$dataPortabilityError = (string) ($data_portability_error ?? '');
$dataPortabilityTargetUser = is_array($data_portability_target_user ?? null) ? $data_portability_target_user : $profileUser;
$dataPortabilityTargetLabel = (string) ($dataPortabilityTargetUser['display_name'] ?? $dataPortabilityTargetUser['name'] ?? $dataPortabilityTargetUser['email'] ?? 'aktueller Benutzer');
$dataPortabilityPreviewModules = is_array($dataPortabilityPreview['modules'] ?? null) ? $dataPortabilityPreview['modules'] : [];
$dataPortabilityCountLabel = static fn (string $name): string => [
    'accounts' => 'Konten',
    'categories' => 'Kategorien',
    'transactions' => 'Buchungen',
    'recurring_rules' => 'Regeln',
    'conditions' => 'Filter',
    'items' => 'Einträge',
][strtolower($name)] ?? $name;
$totpEnabled = (bool) ($totp_enabled ?? false);
$webauthnEnabled = (bool) ($webauthn_enabled ?? false);
$pendingTotp = is_array($pending_totp ?? null) ? $pending_totp : null;
$recoveryCodes = is_array($recovery_codes ?? null) ? $recovery_codes : [];
$recoveryCount = (int) ($recovery_count ?? 0);
$csrfToken = (string) ($csrf_token ?? '');
$securityDownloadUser = (string) ($profileUser['email'] ?? $profileUser['username'] ?? $profileUser['name'] ?? 'user');
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
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
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
    <?php if ($recoveryCodes !== []): ?>
        <section class="alert alert-warning mb-4" role="status" data-recovery-download-source data-recovery-user="<?= htmlspecialchars($securityDownloadUser, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <h2 class="h6 mb-2">Neue Recovery Codes</h2>
                <p class="mb-3">Speichere diese Codes jetzt. Sie werden später nicht erneut im Klartext angezeigt.</p>
                <div class="row g-2">
                    <?php foreach ($recoveryCodes as $code): ?>
                        <div class="col-6 col-md-3">
                            <code class="security-recovery-code js-recovery-code"><?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?></code>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3">
                    <button type="button" class="btn btn-primary btn-sm js-recovery-download">Als TXT herunterladen</button>
                    <div class="small mt-2">Speichere die Datei an einem sicheren Ort.</div>
                </div>
            </div>
        </section>
    <?php endif; ?>
    <div id="security-inline-feedback" class="module-toggle-feedback small mb-3" aria-live="polite"></div>

    <div class="row g-4 modulon-security-grid">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 app-card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 text-uppercase text-body-secondary mb-3">Passwort</h2>
                    <p class="small text-body-secondary mb-3">Zum Ändern wird dein aktuelles Passwort zur Bestätigung benötigt.</p>
                    <form method="post" action="/profil/password" class="row g-3">
                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
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

                    <?php if ($totpEnabled): ?>
                        <p class="small text-body-secondary mb-3">TOTP ist aktiv. QR-Code und Secret werden aus Sicherheitsgründen nicht dauerhaft angezeigt.</p>
                        <form method="post" action="/account/security/totp/disable" class="mt-3">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <button type="submit" class="btn btn-outline-danger">TOTP deaktivieren</button>
                        </form>
                    <?php elseif ($pendingTotp !== null): ?>
                        <p class="small text-body-secondary mb-2">Secret</p>
                        <p><code><?= htmlspecialchars((string) ($pendingTotp['secret'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></p>
                        <img class="security-totp-qr rounded border mb-3" src="<?= htmlspecialchars((string) ($pendingTotp['qr_data_uri'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="TOTP QR">
                        <form method="post" action="/account/security/totp/confirm" class="vstack gap-2">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <label class="form-label mb-0" for="totp_confirm">Bestätigungscode</label>
                            <input id="totp_confirm" class="form-control" type="text" name="code" inputmode="numeric" required>
                            <button type="submit" class="btn btn-primary">TOTP aktivieren</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/account/security/totp/start">
                            <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                            <button type="submit" class="btn btn-primary">TOTP einrichten</button>
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
                                                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
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
                    <p class="small text-body-secondary mb-3">Neu generieren ersetzt alle vorhandenen Recovery Codes.</p>
                    <form method="post" action="/account/security/recovery/regenerate" class="mb-3" onsubmit="return confirm('Vorhandene Recovery Codes werden ungültig. Neue Codes jetzt erzeugen?');">
                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                        <button type="submit" class="btn btn-outline-secondary">Neu generieren</button>
                    </form>

                    <?php if ($recoveryCodes !== []): ?>
                        <div class="alert alert-warning mb-0">
                            Die neuen Codes werden oben auf dieser Seite angezeigt und können dort als TXT gespeichert werden.
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
    async function securityJsonResponse(response){
        const type=response.headers.get('content-type')||'';
        if(!type.includes('application/json')){
            throw new Error(response.status===401||response.status===403||response.status===419?'Sitzung oder Sicherheits-Token ungültig. Bitte Seite neu laden.':'Unerwartete Serverantwort. Bitte Seite neu laden.');
        }
        const payload=await response.json();
        if(!response.ok||!payload.success){
            throw new Error(payload.message||'Aktion fehlgeschlagen.');
        }
        return payload;
    }
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
            const csrfHeaders={'X-CSRF-Token':<?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,'Accept':'application/json'};
            const optionsRes=await fetch('/account/security/webauthn/options',{method:'POST',headers:{...csrfHeaders,'Content-Type':'application/json'},body:'{}'});
            const options=await securityJsonResponse(optionsRes);
            const publicKey=fromBase64(options.publicKey);
            const cred=await navigator.credentials.create({publicKey});
            const payload={label:label,transports:cred.response.getTransports?cred.response.getTransports():[],clientDataJSON:ab2b64(cred.response.clientDataJSON),attestationObject:ab2b64(cred.response.attestationObject)};
            const verifyRes=await fetch('/account/security/webauthn/verify',{method:'POST',headers:{...csrfHeaders,'Content-Type':'application/json'},body:JSON.stringify(payload)});
            await securityJsonResponse(verifyRes);
            window.location='/profil/security';
        }catch(e){setSecurityInlineFeedback(e.message||'Passkey-Registrierung fehlgeschlagen.', true);}
    }
    function safeRecoveryFilenamePart(value){
        return String(value||'').replace(/[\\/:*?"<>|\u0000-\u001f]+/g,'-').replace(/\s+/g,' ').trim()||'user';
    }
    document.querySelectorAll('.js-recovery-download').forEach((button)=>{
        button.addEventListener('click',()=>{
            const source=button.closest('[data-recovery-download-source]');
            const codes=Array.from(document.querySelectorAll('.js-recovery-code')).map((node)=>node.textContent.trim()).filter(Boolean);
            if(!source||codes.length===0){
                setSecurityInlineFeedback('Keine neuen Recovery Codes zum Herunterladen gefunden.', true);
                return;
            }
            const host=window.location.hostname||'modulnest';
            const user=source.dataset.recoveryUser||'user';
            const created=new Intl.DateTimeFormat('de-DE',{dateStyle:'medium',timeStyle:'short'}).format(new Date());
            const content=[
                'ModulNest Recovery Codes',
                '',
                'Domain/Host: '+host,
                'Benutzer/Login: '+user,
                'Erstellt: '+created,
                '',
                'Codes:',
                ...codes.map((code)=>'- '+code),
                '',
                'Hinweis: Jeder Code kann nur einmal verwendet werden.',
                ''
            ].join('\n');
            const blob=new Blob([content],{type:'text/plain;charset=utf-8'});
            const url=URL.createObjectURL(blob);
            const link=document.createElement('a');
            link.href=url;
            link.download=safeRecoveryFilenamePart(host)+' recovery codes - '+safeRecoveryFilenamePart(user)+'.txt';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        });
    });
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
                <?= \Modulon\Core\View::csrfField($csrfToken) ?>
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
<?php elseif ($userTab === 'data-portability' && $dataPortabilityAvailable): ?>
    <section class="app-card p-4 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Profil</p>
                <h1 class="h4 mb-2">Meine Daten</h1>
                <p class="text-body-secondary mb-0">Exportiere oder importiere persönliche Moduldaten dieser ModulNest-Instanz.</p>
            </div>
            <span class="badge text-bg-secondary align-self-lg-start">aktuelles Benutzerkonto</span>
        </div>
    </section>

    <?php if ($dataPortabilityMessage !== '' || $dataPortabilityError !== ''): ?>
        <div class="modulon-feedback-stack mb-4">
            <?php if ($dataPortabilityMessage !== ''): ?>
                <div class="alert alert-success mb-0" role="status"><?= htmlspecialchars($dataPortabilityMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($dataPortabilityError !== ''): ?>
                <div class="alert alert-danger mb-0" role="alert"><?= htmlspecialchars($dataPortabilityError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info small" role="status">
        Importierte Daten werden deinem aktuellen Benutzerkonto zugeordnet:
        <strong><?= htmlspecialchars($dataPortabilityTargetLabel, ENT_QUOTES, 'UTF-8') ?></strong>.
        Standardmäßig werden Daten hinzugefügt; im Ersetzen-Modus werden nur deine eigenen Daten der ausgewählten Module vorher gelöscht.
    </div>

    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-lg-6">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body p-4">
                    <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Export</p>
                    <h2 class="h5 mb-2">Meine Daten exportieren</h2>
                    <p class="text-body-secondary small mb-4">Wähle aus, welche persönlichen Bereiche in ein ZIP-Archiv geschrieben werden.</p>

                    <form method="post" action="/profil/data-portability/export">
                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                        <?php if ($dataPortabilityProviders === []): ?>
                            <div class="alert alert-info mb-0">Aktuell sind keine exportfähigen persönlichen Bereiche aktiv.</div>
                        <?php else: ?>
                            <div class="vstack gap-3 data-portability-provider-list">
                                <?php foreach ($dataPortabilityProviders as $provider): ?>
                                    <label class="data-portability-provider">
                                        <span class="data-portability-provider-check">
                                            <input class="form-check-input" type="checkbox" name="providers[]" value="<?= htmlspecialchars((string) ($provider['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" checked>
                                        </span>
                                        <span class="data-portability-provider-body">
                                            <span class="data-portability-provider-header">
                                                <span>
                                                    <strong><?= htmlspecialchars((string) ($provider['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <span class="d-block small text-body-secondary"><?= htmlspecialchars((string) ($provider['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                                </span>
                                                <span class="data-portability-provider-badges">
                                                    <span class="badge text-bg-secondary">Format v<?= (int) ($provider['schema_version'] ?? 0) ?></span>
                                                </span>
                                            </span>
                                            <?php if (!empty($provider['sensitivity_note'])): ?>
                                                <span class="alert alert-warning data-portability-provider-note small mt-3 mb-0" role="note">
                                                    <?= htmlspecialchars((string) $provider['sensitivity_note'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button class="btn btn-primary mt-4" type="submit">Meine Daten exportieren</button>
                        <?php endif; ?>
                    </form>
                </div>
            </section>
        </div>

        <div class="col-12 col-lg-6">
            <section class="card shadow-sm border-0 app-card h-100">
                <div class="card-body p-4">
                    <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Import</p>
                    <h2 class="h5 mb-2">Meine Daten importieren</h2>
                    <p class="text-body-secondary small mb-4">Eine ZIP-Datei wird zuerst geprüft. Erst nach der Vorschau kannst du den Import bestätigen.</p>

                    <div class="data-portability-workflow mb-4">
                        <div class="data-portability-step">
                            <div class="data-portability-step-marker">1</div>
                            <div class="data-portability-step-body">
                                <h3 class="h6 mb-1">ZIP auswählen</h3>
                                <p class="text-body-secondary small mb-0">Wähle ein ModulNest-Exportarchiv.</p>
                            </div>
                        </div>
                        <div class="data-portability-step">
                            <div class="data-portability-step-marker">2</div>
                            <div class="data-portability-step-body">
                                <h3 class="h6 mb-1">Import prüfen</h3>
                                <p class="text-body-secondary small mb-0">Manifest und freigegebene persönliche Bereiche werden validiert.</p>
                            </div>
                        </div>
                        <div class="data-portability-step <?= $dataPortabilityPreview === null ? 'is-disabled' : 'is-ready' ?>">
                            <div class="data-portability-step-marker">3</div>
                            <div class="data-portability-step-body">
                                <h3 class="h6 mb-1">Vorschau bestätigen</h3>
                                <?php if ($dataPortabilityPreview === null): ?>
                                    <p class="text-body-secondary small mb-0">Dieser Schritt wird nach einer erfolgreichen Prüfung freigeschaltet.</p>
                                <?php else: ?>
                                    <p class="text-body-secondary small mb-3">
                                        Die Vorschau ist bereit. Der Import wird deinem aktuellen Benutzerkonto zugeordnet.
                                        <?= $dataPortabilityImportMode === 'replace' ? 'Der Ersetzen-Modus löscht vorher deine Daten der enthaltenen Module.' : 'Der Standardmodus fügt Daten hinzu oder führt sie zusammen.' ?>
                                    </p>
                                    <form method="post" action="/profil/data-portability/import/run" onsubmit="return confirm('Import jetzt ausführen? <?= $dataPortabilityImportMode === 'replace' ? 'Bestehende Moduldaten werden vorher gelöscht.' : 'Daten werden hinzugefügt oder zusammengeführt.' ?>');">
                                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                                        <input type="hidden" name="import_mode" value="<?= htmlspecialchars($dataPortabilityImportMode, ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">Import ausführen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <form method="post" action="/profil/data-portability/import/preview" enctype="multipart/form-data">
                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                        <div class="mb-3">
                            <label class="form-label" for="profile_import_zip">Export-ZIP</label>
                            <input class="form-control" type="file" id="profile_import_zip" name="import_zip" accept=".zip,application/zip" required>
                            <div class="form-text text-body-secondary">ZIPs werden temporär außerhalb des Webroots verarbeitet.</div>
                        </div>
                        <fieldset class="data-portability-import-mode mb-3">
                            <legend class="form-label mb-2">Importmodus</legend>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="import_mode" id="profile_import_mode_merge" value="merge"<?= $dataPortabilityImportMode !== 'replace' ? ' checked' : '' ?>>
                                <label class="form-check-label" for="profile_import_mode_merge">Hinzufügen / Zusammenführen</label>
                                <div class="form-text text-body-secondary">Bestehende Daten bleiben erhalten. Das ist der sichere Standard.</div>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="import_mode" id="profile_import_mode_replace" value="replace"<?= $dataPortabilityImportMode === 'replace' ? ' checked' : '' ?>>
                                <label class="form-check-label" for="profile_import_mode_replace">Bestehende Moduldaten ersetzen</label>
                                <div class="form-text text-warning">Nur deine eigenen Dashboard-/Banking-Daten werden ersetzt. Bitte vorher Backup erstellen.</div>
                            </div>
                        </fieldset>
                        <button class="btn btn-outline-primary" type="submit">Import prüfen</button>
                    </form>
                </div>
            </section>
        </div>
    </div>

    <?php if ($dataPortabilityPreview !== null): ?>
        <section class="card shadow-sm border-0 app-card mt-4">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
                    <div>
                        <p class="text-uppercase text-body-secondary small fw-semibold mb-1">Import-Vorschau</p>
                        <h2 class="h5 mb-1"><?= htmlspecialchars((string) ($dataPortabilityPreview['manifest']['product'] ?? 'Export'), ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="text-body-secondary mb-0">
                            Format <?= (int) ($dataPortabilityPreview['manifest']['format_version'] ?? 0) ?> · App-Version <?= htmlspecialchars((string) ($dataPortabilityPreview['manifest']['app_version'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <p class="mb-0 mt-2">
                            <span class="badge <?= $dataPortabilityImportMode === 'replace' ? 'text-bg-warning' : 'text-bg-secondary' ?>">
                                Importmodus: <?= $dataPortabilityImportMode === 'replace' ? 'Bestehende Moduldaten ersetzen' : 'Hinzufügen / Zusammenführen' ?>
                            </span>
                        </p>
                    </div>
                    <span class="badge text-bg-secondary"><?= count($dataPortabilityPreviewModules) ?> Module</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 app-table">
                        <thead>
                        <tr>
                            <th class="ps-4">Modul</th>
                            <th>Status</th>
                            <th>Datensätze</th>
                            <th>Dateien</th>
                            <th class="pe-4">Warnungen/Konflikte</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($dataPortabilityPreviewModules === []): ?>
                            <tr>
                                <td colspan="5" class="ps-4 text-body-secondary">Keine für deinen Benutzerbereich importierbaren Module im Archiv gefunden.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dataPortabilityPreviewModules as $module): ?>
                                <?php $counts = is_array($module['counts'] ?? null) ? $module['counts'] : []; ?>
                                <tr>
                                    <td class="ps-4">
                                        <strong><?= htmlspecialchars((string) ($module['label'] ?? $module['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="text-body-secondary small"><?= htmlspecialchars((string) ($module['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · Format v<?= (int) ($module['schema_version'] ?? 0) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($module['can_import'])): ?>
                                            <span class="badge text-bg-success">bereit</span>
                                        <?php elseif (!empty($module['available'])): ?>
                                            <span class="badge text-bg-warning">prüfen</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-danger">Modul fehlt</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($counts === []): ?>
                                            <span class="text-body-secondary small">keine Angaben</span>
                                        <?php else: ?>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($counts as $name => $count): ?>
                                                    <span class="badge text-bg-secondary"><?= htmlspecialchars($dataPortabilityCountLabel((string) $name), ENT_QUOTES, 'UTF-8') ?>: <?= (int) $count ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) ($module['file_count'] ?? 0) ?></td>
                                    <td class="small pe-4">
                                        <?php if (($module['warnings'] ?? []) === []): ?>
                                            <span class="text-body-secondary">-</span>
                                        <?php else: ?>
                                            <div class="vstack gap-1">
                                                <?php foreach (($module['warnings'] ?? []) as $warning): ?>
                                                    <div class="text-warning"><?= htmlspecialchars((string) $warning, ENT_QUOTES, 'UTF-8') ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($dataPortabilityImportMode === 'replace'): ?>
                    <div class="alert alert-warning mt-4 mb-0" role="alert">
                        Bestehende Daten der ausgewählten Module werden vor dem Import gelöscht. In deinem Profilbereich betrifft das nur dein aktuelles Benutzerkonto.
                    </div>
                <?php endif; ?>
                <div class="data-portability-preview-action mt-4">
                    <div>
                        <h3 class="h6 mb-1">Import bereit</h3>
                        <p class="text-body-secondary small mb-0">
                            Wenn die Vorschau korrekt aussieht, kannst du den Import jetzt ausführen.
                            <?= $dataPortabilityImportMode === 'replace' ? 'Der Ersetzen-Modus löscht vorher deine Daten der enthaltenen Module.' : 'Im Standardmodus werden Daten hinzugefügt oder zusammengeführt.' ?>
                        </p>
                    </div>
                    <form method="post" action="/profil/data-portability/import/run" onsubmit="return confirm('Import jetzt ausführen? <?= $dataPortabilityImportMode === 'replace' ? 'Bestehende Moduldaten werden vorher gelöscht.' : 'Daten werden hinzugefügt oder zusammengeführt.' ?>');">
                        <?= \Modulon\Core\View::csrfField($csrfToken) ?>
                        <input type="hidden" name="import_mode" value="<?= htmlspecialchars($dataPortabilityImportMode, ENT_QUOTES, 'UTF-8') ?>">
                        <button class="btn btn-danger" type="submit">Import ausführen</button>
                    </form>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
