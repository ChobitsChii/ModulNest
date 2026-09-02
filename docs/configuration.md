# ModulNest Konfiguration

Die lokale Konfiguration liegt in `.env`. Diese Datei darf nicht veröffentlicht oder committed werden.

## Umgebung

```env
APP_ENV=production
APP_DEBUG=false
APP_PRODUCT_NAME=ModulNest
APP_CORE_NAME=Modulon
APP_CORE_LABEL="Modulon Core"
```

`APP_ENV=development` oder `APP_DEBUG=true` aktiviert sichtbarere Diagnosehinweise. In Produktion sollte `APP_DEBUG=false` gesetzt sein.

Die installierte App-/Release-Version kommt aus `app/Config/version.php`. `APP_VERSION` und `APP_CHANNEL` werden für neue Installationen nicht mehr in `.env` geschrieben, damit Datei-Updates die angezeigte Version nicht durch alte lokale Werte blockieren. Falls diese Werte in bestehenden `.env`-Dateien noch vorhanden sind, werden sie für die UI-Version ignoriert.

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
SESSION_COOKIE_SECURE=auto
SESSION_COOKIE_SAMESITE=Lax

REMEMBER_COOKIE_NAME=modulnest_remember
REMEMBER_TOKEN_LIFETIME=1209600
REMEMBER_COOKIE_SECURE=auto
REMEMBER_COOKIE_SAMESITE=Lax

AUTH_RATE_LIMIT_MAX_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=900
```

Die Session verwendet ausschließlich Cookies, Strict-Mode und HttpOnly. Mit
`*_COOKIE_SECURE=auto` werden Cookies bei HTTPS sowie immer in Produktion als
`Secure` gesetzt; lokale HTTP-Entwicklung bleibt in einer Development-Umgebung
funktionsfähig. `SameSite=Lax` ist der kompatible Standard für die bestehenden
Login- und 2FA-Flows.

Der Auth-Limiter erlaubt standardmäßig fünf Passwort-, TOTP-, Recovery- oder
WebAuthn-Versuche pro Aktion, IP und gehashtem Benutzerbezug in 15 Minuten.
Er speichert keine Passwörter, Codes oder Tokens. Erfolgreiche Schritte setzen
den jeweiligen Bucket zurück.

## TOTP und WebAuthn

```env
TOTP_ISSUER=ModulNest
WEBAUTHN_RP_NAME=ModulNest
WEBAUTHN_RP_ID=modulnest.example
```

`WEBAUTHN_RP_ID` ist die Domain ohne Protokoll. In Produktion ist sie für
Passkeys verpflichtend, damit keine RP-ID aus einem Host-Header abgeleitet
wird. Für lokale Development-Umgebungen ist weiterhin `localhost` als
Fallback möglich. WebAuthn benötigt außerdem HTTPS (außer localhost).

## Mail-Zugangsdaten

```env
MAIL_CREDENTIAL_KEY=
```

`MAIL_CREDENTIAL_KEY` wird für die Verschlüsselung gespeicherter Mail-Zugangsdaten verwendet.

- Pro Installation eindeutig generieren.
- Niemals veröffentlichen.
- Nicht nachträglich ändern, wenn bereits Mail-Zugangsdaten gespeichert wurden.

Wenn dieser Key geändert wird, können bestehende gespeicherte Mail-Zugangsdaten ggf. nicht mehr entschlüsselt werden.
