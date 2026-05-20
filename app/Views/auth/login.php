<?php
declare(strict_types=1);

$error = (string) ($error ?? '');
$info = (string) ($info ?? '');
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <div class="card shadow-sm border-0 app-card">
            <div class="card-body p-4 p-md-5">
                <h1 class="h4 mb-4">Login</h1>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($info !== ''): ?>
                    <div class="alert alert-success" role="alert"><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="post" action="/login" class="vstack gap-3">
                    <div>
                        <label for="email" class="form-label">E-Mail oder Benutzername</label>
                        <input id="email" class="form-control" type="text" name="email" autocomplete="username" required>
                    </div>
                    <div>
                        <label for="password" class="form-label">Passwort</label>
                        <input id="password" class="form-control" type="password" name="password" required>
                    </div>
                    <div class="form-check">
                        <input id="remember_me" class="form-check-input" type="checkbox" name="remember_me" value="1">
                        <label class="form-check-label" for="remember_me">Eingeloggt bleiben</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Einloggen</button>
                </form>

                <hr class="my-4">
                <button type="button" class="btn btn-outline-secondary w-100" onclick="loginWithPasskey()">Mit Passkey einloggen</button>
            </div>
        </div>
    </div>
</div>

<script>
function b64ToAb(v){const p='='.repeat((4-v.length%4)%4);const b=atob((v+p).replace(/-/g,'+').replace(/_/g,'/'));const a=new Uint8Array(b.length);for(let i=0;i<b.length;i++){a[i]=b.charCodeAt(i);}return a.buffer;}
function fromBase64(pk){if(pk.challenge){pk.challenge=b64ToAb(pk.challenge);}if(pk.user&&pk.user.id){pk.user.id=b64ToAb(pk.user.id);}if(Array.isArray(pk.excludeCredentials)){pk.excludeCredentials=pk.excludeCredentials.map(c=>({...c,id:b64ToAb(c.id)}));}if(Array.isArray(pk.allowCredentials)){pk.allowCredentials=pk.allowCredentials.map(c=>({...c,id:b64ToAb(c.id)}));}return pk;}
function ab2b64(ab){const bytes=new Uint8Array(ab);let str='';for(const b of bytes){str+=String.fromCharCode(b);}return btoa(str);}
async function loginWithPasskey(){
    try{
        const optionsRes=await fetch('/webauthn/login/options',{method:'POST'});
        const options=await optionsRes.json();
        if(!options.success){throw new Error(options.message||'Passkey-Optionen fehlgeschlagen.');}
        const publicKey=fromBase64(options.publicKey);
        const cred=await navigator.credentials.get({publicKey});
        const payload={
            id:ab2b64(cred.rawId),
            clientDataJSON:ab2b64(cred.response.clientDataJSON),
            authenticatorData:ab2b64(cred.response.authenticatorData),
            signature:ab2b64(cred.response.signature),
            userHandle:cred.response.userHandle?ab2b64(cred.response.userHandle):'',
            remember_me:document.getElementById('remember_me')?.checked?'1':'0'
        };
        const verifyRes=await fetch('/webauthn/login/verify',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const verify=await verifyRes.json();
        if(!verify.success){throw new Error(verify.message||'Passkey-Login fehlgeschlagen.');}
        window.location=verify.redirect||'/';
    }catch(e){alert(e.message||'Passkey-Login fehlgeschlagen.');}
}
</script>
