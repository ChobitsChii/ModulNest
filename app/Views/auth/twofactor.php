<?php
declare(strict_types=1);

$error = (string) ($error ?? '');
$info = (string) ($info ?? '');
$pendingEmail = (string) ($pending_email ?? '');
$showTotp = (bool) ($show_totp ?? false);
$showWebAuthn = (bool) ($show_webauthn ?? false);
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4 p-md-5">
                <h1 class="h4 mb-2">2FA Verifizierung</h1>
                <p class="text-body-secondary mb-4"><?= htmlspecialchars($pendingEmail, ENT_QUOTES, 'UTF-8') ?></p>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($info !== ''): ?>
                    <div class="alert alert-success" role="alert"><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($showTotp): ?>
                    <form method="post" action="/login/2fa/totp" class="mb-4">
                        <label for="totp_code" class="form-label">TOTP-Code</label>
                        <div class="input-group">
                            <input id="totp_code" class="form-control" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                            <button type="submit" class="btn btn-primary">Prüfen</button>
                        </div>
                    </form>
                <?php endif; ?>

                <form method="post" action="/login/2fa/recovery" class="mb-4">
                    <label for="recovery_code" class="form-label">Recovery Code</label>
                    <div class="input-group">
                        <input id="recovery_code" class="form-control" type="text" name="code" required>
                        <button type="submit" class="btn btn-outline-secondary">Verwenden</button>
                    </div>
                </form>

                <?php if ($showWebAuthn): ?>
                    <hr>
                    <button type="button" class="btn btn-outline-secondary" onclick="verifyPasskey2FA()">Mit Passkey bestätigen</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($showWebAuthn): ?>
<script>
function b64ToAb(v){const p='='.repeat((4-v.length%4)%4);const b=atob((v+p).replace(/-/g,'+').replace(/_/g,'/'));const a=new Uint8Array(b.length);for(let i=0;i<b.length;i++){a[i]=b.charCodeAt(i);}return a.buffer;}
function fromBase64(pk){if(pk.challenge){pk.challenge=b64ToAb(pk.challenge);}if(pk.user&&pk.user.id){pk.user.id=b64ToAb(pk.user.id);}if(Array.isArray(pk.excludeCredentials)){pk.excludeCredentials=pk.excludeCredentials.map(c=>({...c,id:b64ToAb(c.id)}));}if(Array.isArray(pk.allowCredentials)){pk.allowCredentials=pk.allowCredentials.map(c=>({...c,id:b64ToAb(c.id)}));}return pk;}
function ab2b64(ab){const bytes=new Uint8Array(ab);let str='';for(const b of bytes){str+=String.fromCharCode(b);}return btoa(str);}
async function verifyPasskey2FA(){
    try{
        const optionsRes=await fetch('/webauthn/login/options',{method:'POST'});
        const options=await optionsRes.json();
        if(!options.success){throw new Error(options.message||'Passkey-Optionen fehlgeschlagen.');}
        const publicKey=fromBase64(options.publicKey);
        const cred=await navigator.credentials.get({publicKey});
        const payload={id:ab2b64(cred.rawId),clientDataJSON:ab2b64(cred.response.clientDataJSON),authenticatorData:ab2b64(cred.response.authenticatorData),signature:ab2b64(cred.response.signature),userHandle:cred.response.userHandle?ab2b64(cred.response.userHandle):''};
        const verifyRes=await fetch('/webauthn/login/verify',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const verify=await verifyRes.json();
        if(!verify.success){throw new Error(verify.message||'Passkey-Verifizierung fehlgeschlagen.');}
        window.location=verify.redirect||'/';
    }catch(e){alert(e.message||'Passkey-Verifizierung fehlgeschlagen.');}
}
</script>
<?php endif; ?>
