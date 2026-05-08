<?php
declare(strict_types=1);

$message = (string) ($message ?? '');
$error = (string) ($error ?? '');
$totpEnabled = (bool) ($totp_enabled ?? false);
$webauthnEnabled = (bool) ($webauthn_enabled ?? false);
$pendingTotp = is_array($pending_totp ?? null) ? $pending_totp : null;
$recoveryCodes = is_array($recovery_codes ?? null) ? $recovery_codes : [];
$recoveryCount = (int) ($recovery_count ?? 0);
$credentials = is_array($credentials ?? null) ? $credentials : [];
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Sicherheits-Einstellungen</h1>
    <a href="/" class="btn btn-outline-secondary btn-sm">Zur Startseite</a>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-xl-6">
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

    <div class="col-12 col-xl-6">
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

    <div class="col-12">
        <div class="card shadow-sm border-0 app-card">
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
        window.location='/account/security';
    }catch(e){alert(e.message||'Passkey-Registrierung fehlgeschlagen.');}
}
</script>
