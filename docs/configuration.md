# ModulNest Konfiguration

Die lokale Konfiguration liegt in `.env`. Diese Datei darf nicht veröffentlicht oder committed werden.

## Umgebung

```env
APP_ENV=production
APP_DEBUG=false
APP_PRODUCT_NAME=ModulNest
APP_CORE_NAME=Modulon
APP_CORE_LABEL="Modulon Core"
APP_VERSION=0.5.0
APP_CHANNEL=alpha
```

`APP_ENV=development` oder `APP_DEBUG=true` aktiviert sichtbarere Diagnosehinweise. In Produktion sollte `APP_DEBUG=false` gesetzt sein.

## Datenbank

```env
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=modulnest
DB_CHARSET=utf8mb4
DB_USER=modulnest
DB_PASS=
```

Passwörter gehören nur in die lokale `.env`.

## Registrierung

```env
PUBLIC_REGISTRATION_ENABLED=false
```

Steuert, ob öffentliche Registrierung angeboten wird.

## Sessions und Remember-Me

```env
SESSION_IDLE_TIMEOUT=1800
SESSION_ABSOLUTE_TIMEOUT=28800

REMEMBER_COOKIE_NAME=modulnest_remember
REMEMBER_TOKEN_LIFETIME=1209600
REMEMBER_COOKIE_SECURE=true
REMEMBER_COOKIE_SAMESITE=Lax
```

Für HTTPS-Installationen sollte `REMEMBER_COOKIE_SECURE=true` aktiv bleiben.

## TOTP und WebAuthn

```env
TOTP_ISSUER=ModulNest
WEBAUTHN_RP_NAME=ModulNest
WEBAUTHN_RP_ID=
```

`WEBAUTHN_RP_ID` ist normalerweise die Domain ohne Protokoll.

## Mail-Zugangsdaten

```env
MAIL_CREDENTIAL_KEY=
```

`MAIL_CREDENTIAL_KEY` wird für die Verschlüsselung gespeicherter Mail-Zugangsdaten verwendet.

- Pro Installation eindeutig generieren.
- Niemals veröffentlichen.
- Nicht nachträglich ändern, wenn bereits Mail-Zugangsdaten gespeichert wurden.

Wenn dieser Key geändert wird, können bestehende gespeicherte Mail-Zugangsdaten ggf. nicht mehr entschlüsselt werden.
