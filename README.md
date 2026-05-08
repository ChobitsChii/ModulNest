# Modulon

Modulon ist ein modulares PHP-8.3+-System mit eigenem Core, Rollen-/Rechtemodell, erweitertem Auth-Stack (Session, Remember-Me, 2FA), Admin-Werkzeugen und nativen sowie Legacy-Modulen.

Aktueller Stand: `v0.5.0`

Der öffentliche Produktname für Releases ist **ModulNest**. Modulon bleibt der interne Core-/Arbeitsname und das private Entwicklungsrepo.

Release-/Export-Hinweise für den öffentlichen ModulNest-Stand stehen in [`docs/release.md`](docs/release.md). Anforderungen stehen in [`docs/requirements.md`](docs/requirements.md).

Für frische Zielsysteme ist ein erster Bootstrap-Installer als einzelne Datei vorbereitet: `install.php`.

## Highlights

- PHP 8.3+, Composer, PSR-4 (`Modulon\\`)
- Front Controller (`public/index.php`) + Apache Rewrite
- Core-Komponenten: `Request`, `Response`, `Router`, `Application`, `Session`, `Env`, `Database`, `View`
- Auth:
  - Login/Logout, interne Registrierung (global schaltbar)
  - Session-Idle + absolute Laufzeit
  - Remember-Me mit gehashten, rotierenden Tokens
  - TOTP, WebAuthn/Passkeys, Recovery Codes
- Admin:
  - `/admin/modules` (Modulverwaltung)
  - `/admin/users` (Benutzerverwaltung + Registrierungsschalter)
  - `/admin/news` (News/Changelog-CRUD)
- Module:
  - DB-gesteuerte Modulfreischaltung + Zugriff (`public|user|admin`)
  - native Modultyp (`native`) plus `legacy` und `placeholder` (Übergang)
  - native Module registrieren Routen, Untermenüs und Adminbereiche selbst
  - Auto-Discovery neuer nativer Modulordner in der Modulverwaltung
  - Legacy-Module inkl. zentraler Overlay-Injection
- native Module: `Dashboard`, `Mail`, `News`, `Profil`, `Systeminfo`, `Banking`
- Mail-Modul (modernisiert): IMAP via `webklex/php-imap`, SMTP via `symfony/mailer`
- Banking-Modul: native, usergebundene Umsätze, Monatsübersicht, wiederkehrende Regeln, CSV-Import und Legacy-Fallback unter `/banking-old`

## Technische Doku

- [Technische Architektur](docs/technical/TECH_ARCHITECTURE.md)
- [Dashboard Foundation](docs/technical/DASHBOARD_FOUNDATION.md)

## Projektstruktur (vereinfacht)

```text
app/
  Config/
  Core/
  Database/
    schema.sql
  Modules/
    Admin/
      AdminController.php
      AppSettingRepository.php
    Auth/
      AuthController.php
      AuthService.php
      UserRepository.php
      ...
    Modules/
      ModuleRepository.php
    News/
      NewsController.php
      NewsRepository.php
    Banking/
      BankingModule.php
      BankingController.php
      BankingTransactionRepository.php
    Systeminfo/
      SysteminfoController.php
    User/
      UserController.php
  Views/
    admin/
    auth/
    news/
    systeminfo/
    user/
    partials/
  Legacy/
  bootstrap.php
public/
  .htaccess
  index.php
  assets/
storage/
```

## Installation

Für ModulNest-Releases gibt es zwei Paketarten:

- `modulnest-source-VERSION.zip`: ohne `vendor/`, benötigt Composer
- `modulnest-bundled-VERSION.zip`: mit `vendor/`, benötigt keinen Composer

Der Bootstrap-Installer `install.php` erkennt die Möglichkeiten des Zielsystems und empfiehlt standardmäßig das Bundled-Paket. Im erweiterten Modus können Endnutzer einzelne im Release enthaltene optionale Module abwählen.

Für den ersten öffentlichen Release wird Betrieb im Domain-/Subdomain-Root erwartet. Der Webserver muss mit seinem Webroot/DocumentRoot direkt auf das `public/`-Verzeichnis der Installation zeigen. Unterverzeichnis-Installationen wie `/modulnest/public/` werden aktuell nicht unterstützt.

Manuelle Modulon-Entwicklungsinstallation:

1. `composer install`
2. `.env` konfigurieren (siehe `.env.example`)
3. Schema einspielen:
   ```bash
   mariadb -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < app/Database/schema.sql
   ```
4. Lokal starten:
   ```bash
   php -S 127.0.0.1:8080 -t public
   ```

## Browser-E2E-Tests (lokal)

Versionierte E2E-Tests liegen unter:

- `tests/e2e`

Lokale (nicht versionierte) E2E-Artefakte liegen unter:

- `.local/e2e`

Setup:

```bash
python -m venv .local/e2e/.venv
.local/e2e/.venv/bin/python -m pip install -r tests/e2e/requirements.txt
.local/e2e/.venv/bin/python -m playwright install chromium
```

Konfiguration (optional, nicht versioniert) in `.local/e2e/local.env`:

```env
MODULON_E2E_BASE_URL=http://127.0.0.1:8080
MODULON_E2E_LOGIN=dein-testuser
MODULON_E2E_PASSWORD=dein-testpasswort
```

Start:

```bash
./scripts/e2e.sh
```

Hinweis:
- Wenn `MODULON_E2E_BASE_URL` auf `http://127.0.0.1:8080` oder `http://localhost:8080` steht und dort noch kein Server läuft,
  startet `scripts/e2e.sh` automatisch einen temporären lokalen PHP-Server (`php -S ... -t public`) für den Testlauf.

## Wichtige ENV-Werte

```env
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=modulon
DB_USER=root
DB_PASS=

PUBLIC_REGISTRATION_ENABLED=true
APP_VERSION=0.5.0

SESSION_IDLE_TIMEOUT=1800
SESSION_ABSOLUTE_TIMEOUT=28800

REMEMBER_COOKIE_NAME=modulon_remember
REMEMBER_TOKEN_LIFETIME=1209600
REMEMBER_COOKIE_SECURE=false
REMEMBER_COOKIE_SAMESITE=Lax

TOTP_ISSUER=Modulon
WEBAUTHN_RP_NAME=Modulon
WEBAUTHN_RP_ID=127.0.0.1
```

## Datenbanktabellen (Auszug)

- `users`, `roles`, `permissions`, `user_role`, `role_permission`
- `remember_tokens`
- `modules`
- `app_settings`
- `webauthn_credentials`
- `recovery_codes`
- `news_entries`
- `mail_accounts`, `mail_favorite_folders`, `mail_sender_whitelist`
- `banking_accounts`, `banking_transactions`, `banking_recurring_rules`, `banking_import_batches`

## Mail-Modul Voraussetzungen

- `webklex/php-imap`
- `symfony/mailer` + `symfony/mime`
- PHP-Extension `iconv` erforderlich (u. a. für IMAP-Transportbibliothek)
- PHP-Extension `imap` **nicht** erforderlich

Hinweis:
- fehlende Voraussetzungen werden im Systemcheck angezeigt
- bei fehlendem `iconv` degradiert das Mail-Modul kontrolliert (Kontoverwaltung/SMTP bleiben nutzbar, IMAP-Funktionen sind deaktiviert)

## Zugriffsebenen

- `public`
- `user`
- `admin`

Die Zugriffskontrolle läuft zentral im Access-Guard in `app/bootstrap.php`.

## Wichtige Routen

### Kern/Auth
- `GET /`
- `GET|POST /login`
- `GET /login/2fa`
- `POST /login/2fa/totp`
- `POST /login/2fa/recovery`
- `POST /webauthn/login/options`
- `POST /webauthn/login/verify`
- `GET|POST /internal/register`
- `POST /logout`
- `GET /profil`
- `GET /profil/security`
- `GET /profil/settings`
- `POST /profil/update`
- `POST /profil/password`
- `POST /profil/settings`
- kompatible Altpfade für Security bleiben unter `/account/security/*` verfügbar (UI-primär: `/profil/security`)

### Dashboard (nativ)
- `GET /dashboard`
- `POST /dashboard/links/*`
- `POST /dashboard/tasks/*`
- `POST /dashboard/notes/*`

### Mail (nativ)
- `GET /mail`
- `GET /mail/*`
- `POST /mail/accounts`
- `POST /mail/accounts/*`
- `POST /mail/messages/*`

### Banking (nativ)
- `GET /banking`
- `GET /banking/transactions`
- `GET /banking/overview`
- `GET|POST /banking/recurring`
- `GET /banking/recurring/overview`
- `GET|POST /banking/import`
- `POST /banking/transactions/duplicates/delete`
- Legacy-Fallback: `GET /banking-old/*`

### Admin
- `GET /admin` (Redirect)
- `GET /admin/modules`
- `GET /admin/modules/{id}/edit`
- `GET /admin/users`
- `GET /admin/users/{id}/edit`
- `POST /admin/settings/registration/toggle`
- `GET /admin/news`
- `GET /admin/news/create`
- `GET /admin/news/{id}/edit`
- `GET /systeminfo` (nur Admin)

### News-Modul (nativ)
- `GET /news`
- `GET /news/{slug}`

## News-/Changelog-Modul

- Public:
  - kompakte oder erweiterte Übersicht:
    - `/news?view=compact`
    - `/news?view=expanded`
  - Detailseite pro Eintrag: `/news/{slug}`
- Admin:
  - CRUD-nahe Verwaltung unter `/admin/news`
- Statuslogik:
  - `draft` => Entwurf
  - `published` + `published_at` in Zukunft => Geplant
  - `published` + `published_at <= CURRENT_TIMESTAMP` => Veröffentlicht

## Legacy-Module und Overlay

- Legacy-Module können auf `app/Legacy/...` gemappt werden.
- Native Module werden über interne Controller gebunden (`handler = native`), Legacy-Module über den Legacy-Dispatcher.
- Overlay wird serverseitig injiziert, nur wenn:
  - Modul `enable_overlay=1`
  - Modulon-User eingeloggt
  - valide HTML-Antwort vorhanden
- Overlay-Status wird pro Request aus der DB gelesen (kein veralteter Route-Capture-Wert).

## Sicherheitshinweise

- Produktiv nur mit HTTPS betreiben
- `REMEMBER_COOKIE_SECURE=true` in Produktion setzen
- Zugangsdaten nicht committen
