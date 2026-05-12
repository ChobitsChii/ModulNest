# ModulNest Technical Architecture

Dieses Dokument beschreibt den **aktuellen technischen Stand** von ModulNest und ergänzt die README um interne Architekturdetails. Der interne Core-/Arbeitsname lautet Modulon.

## 1) Gesamtarchitektur

ModulNest ist ein serverseitig gerendertes PHP-System mit:

- einem zentralen **Front Controller**
- einem schlanken **Core** (HTTP, Routing, Rendering, Session, ENV, DB)
- einem datenbankgestützten **Modulsystem** (`modules`-Tabelle)
- drei Modultypen:
  - `native` (interne Controller)
  - `legacy` (eingebundene Altanwendungen unter `app/Legacy`)
  - `placeholder` (Übergangstyp ohne eigene native Implementierung)

Architekturprinzip:

- **Core bleibt stabil**
- alles Fachliche hängt als Modul am Router
- Access-Control (`public`/`user`/`admin`) wird zentral im Router-Guard erzwungen

---

## 2) Ordnerstruktur und Zuständigkeiten

- `public/`
  - Webroot
  - enthält `index.php` (Front Controller), `.htaccess`, statische Assets
- `public/index.php`
  - lädt Composer-Autoload
  - lädt `app/bootstrap.php`
  - startet `Application::run()`
- `public/.htaccess`
  - liefert echte Dateien/Ordner direkt aus
  - leitet alles andere auf `index.php` um
- `app/bootstrap.php`
  - Composition Root / Wiring
  - lädt ENV + Config
  - initialisiert Session, PDO, Services, Repositories, Controller
  - registriert Core-Routen und lädt aktive native Module
  - setzt Access-Guard und globalen View-Composer
  - registriert Legacy-/Placeholder-Modulrouten
- `app/Core/`
  - Framework-nahe Basisklassen (`Application`, `Router`, `Request`, `Response`, `Session`, `Env`, `Database`, `View`)
- `app/Config/`
  - Konfigurationsdateien (`auth.php`, `database.php`) auf Basis von ENV
- `app/Database/`
  - Core-Schema und Core-Seeds (`schema/core.sql`, `seeds/core.sql`)
  - `schema.sql` bleibt als Kompatibilitäts-/Gesamtschema erhalten
- `app/Modules/`
  - fachliche Module/Controller/Repositories
  - optionale Modul-Schemas liegen im jeweiligen Modul unter `Database/schema.sql`
  - z. B. `Auth`, `Admin`, `Dashboard`, `Mail`, `News`, `User`, `Systeminfo`, `Banking`, `Modules`
- `app/Views/`
  - Templates inkl. `layouts/` und `partials/`
- `app/Legacy/`
  - Legacy-Apps, die über den Legacy-Dispatcher eingebunden werden
- `storage/`
  - reserviert für Laufzeitdaten/Artefakte

---

## 3) Wichtige Core-Klassen

- `Application`
  - erzeugt `Request` aus Globals
  - optionaler Request-Bootstrap (Session-Lifetime/Remember-Me)
  - ruft Router auf und sendet `Response`
  - zentraler Catch-All für 500-Fallback

- `Router`
  - speichert statische und Wildcard-Routen (`/foo/*`)
  - wählt bei Wildcards den **längsten passenden Prefix**
  - führt optionalen Access-Guard vor Handler-Ausführung aus

- `Request`
  - kapselt Methode, Pfad, Query, Body, Cookies
  - unterstützt JSON-Body bei `Content-Type: application/json`

- `Response`
  - trägt Output, Status, Header
  - sendet HTTP-Antwort
  - `Response::redirect(...)` für Redirect-Responses

- `Session`
  - Start, Get/Set, Flash-Messages, Invalidate
  - Idle- und Absolute-Timeout-Prüfung (`enforceLifetime`)

- `Env`
  - lädt `.env` in `$_ENV`/`putenv`
  - liefert ENV-Werte mit Defaults

- `Database`
  - baut PDO-Verbindung auf
  - setzt `ERRMODE_EXCEPTION` + `FETCH_ASSOC`

- `View`
  - rendert Template in zentrales Layout `app/Views/layouts/app.php`
  - unterstützt globalen Composer (`View::setComposer(...)`) für zentrale Layout-Daten

---

## 4) Native vs. Legacy Module

Die `modules`-Tabelle steuert Modul-Metadaten:

- `route_prefix`
- `access_level` (`public|user|admin`)
- `handler` (`native|legacy|placeholder`)
- `legacy_entry`, `admin_entry`, `enable_overlay`, `is_active`

### `handler = native`

- Modul stellt optional eine Klasse `Modulon\Modules\<Ordner>\<Ordner>Module` bereit
- diese Modulklasse registriert eigene Frontend-Routen, Admin-Routen, Untermenüs und Binding-Metadaten
- keine Legacy-Dateipfade nötig
- Aktivierung/Deaktivierung erfolgt über `is_active` in `modules`
- neue Modulordner werden beim Öffnen der Modulverwaltung per Auto-Discovery als deaktivierte native Module angelegt

### `handler = legacy`

- Modul wird über den Legacy-Dispatcher in `bootstrap.php` ausgeliefert
- Einstieg über `legacy_entry` (PHP-Datei unter `app/Legacy/...`)
- kann statische Assets und Unterpfade aus dem Legacy-Modul ausliefern
- optional mit serverseitiger Overlay-Injection (`enable_overlay`)

### `handler = placeholder`

- Übergangstyp
- falls nicht `native`/`legacy`, wird eine generische Modul-Seite (`app/Views/modules/show.php`) unter `/<prefix>` gerendert

---

## 5) Routing in ModulNest

Routen werden im Composition Root und in aktiven Modulen registriert:

1. Core/Auth-Routen (`/login`, `/logout`, 2FA, Register)
2. Aktive native Modulklassen registrieren eigene Routen (z. B. `/dashboard`, `/mail`, `/news`, `/profil`, `/systeminfo`, `/banking`)
3. Admin-Routen (`/admin/*`)
4. Dynamische Modullogik aus `modules`:
   - `native`: wird nur über geladene Modulklassen geroutet
   - `legacy`: Wildcard-Dispatcher (`/<prefix>/*`)
   - `placeholder`: einfache GET-Route `/<prefix>`

Router-Entscheidung:

- exakter Match zuerst
- sonst Wildcard mit längstem Prefix
- wenn nichts passt: 404-View

---

## 6) Access Guard / Auth

Der Access-Guard ist im Router gesetzt (`$router->setAccessGuard(...)` in `bootstrap.php`):

- `public`: immer erlaubt
- `user`: erfordert authentifizierten Benutzer
- `admin`: erfordert zusätzlich Admin-Rolle

Bei Verstoß:

- nicht eingeloggt: Redirect auf `/login` + Flash-Fehler
- kein Admin: 403-View

Rollenprüfung:

- über `AuthService::isAdmin()` (intern via `UserRepository` + Rollenrelationen)

Session/Remember-Me:

- erfolgt im Request-Bootstrap (`$requestBootstrap` in `bootstrap.php`)
- Ablauf:
  1. Session-Lifetime erzwingen
  2. falls nicht eingeloggt: Remember-Me-Cookie prüfen und ggf. Login wiederherstellen

---

## 7) Views und Rendering

Render-Pipeline:

1. Controller erzeugt `View::render('template', $data)`
2. `View` rendert erst Template, dann `layouts/app.php`
3. Layout bindet Partials (`partials/navbar.php`, `partials/footer.php`) ein

Globaler View-Composer (in `bootstrap.php`) injiziert zentrale Layout-Daten:

- `auth` (eingeloggt/admin/username)
- `current_path`
- `nav_modules` (zugriffsgefilterte aktive Module)
- `admin_nav_items` (Core- und Modul-Adminbereiche)
- `user_nav_items` (z. B. Profil-Unterpunkte am Benutzer-Dropdown)
- `module_features` (modulübergreifende Feature-Verfügbarkeit, z. B. Profil-Settings)
- `public_registration_enabled`
- `app_version`

Ergebnis: konsistente Navbar/Footer-Logik auf allen Seiten.

---

## 8) Datenfluss: konkrete Request-Abläufe

### Beispiel A: `GET /news`

1. Apache rewritet auf `public/index.php`
2. `index.php` lädt Autoload + `app/bootstrap.php`
3. `Application::run()` erzeugt `Request`
4. Request-Bootstrap prüft Session/Remember-Me
5. Router matched `/news` auf `NewsController::index`
6. `NewsController` liest veröffentlichte Einträge über `NewsRepository`
7. `View::render('news/index', ...)` rendert Template + Layout
8. `Response` sendet HTML

### Beispiel B: `GET /admin/modules`

1. gleicher Einstieg über Front Controller
2. Router matched `/admin/modules`
3. Access-Guard prüft `admin`
4. `AdminController::modules` lädt Modulliste via `ModuleRepository`
5. `admin/modules`-View wird gerendert
6. HTML-Response geht an Client

### Beispiel C: `GET /banking/assets/css/bootstrap.min.css` (Legacy)

1. Router matched dynamische Wildcard des Legacy-Moduls (`/banking/*`)
2. Legacy-Dispatcher validiert Pfad via `realpath` gegen Modulroot
3. Datei-Endung wird geprüft (Whitelist)
4. Dateiinhalt wird mit passendem MIME-Type als `Response` ausgeliefert
5. bei PHP-Datei: Datei wird ausgeführt; optional Overlay wird injiziert

---

## 9) Native Module: Profil, News, Dashboard, Mail, Banking, Systeminfo

### Profil (`/profil`)

- Controller: `app/Modules/User/UserController.php`
- Funktionen:
  - Profilfelder (Name/Username/E-Mail)
  - Security-Tab
  - eigene Passwortänderung

### News (`/news`, `/admin/news`)

- Controller: `app/Modules/News/NewsController.php`
- Public-Listing + Detail
- Admin-CRUD für Changelog/News
- Adminbereich wird durch das News-Modul selbst angemeldet

### Dashboard (`/dashboard`)

- Controller/Modulklasse unter `app/Modules/Dashboard/`
- usergebundene Links, Aufgaben, Notizen
- Uhr/Zeitzone, Auto-Refresh mit Pause bei Dialogen

### Mail (`/mail`)

- Controller/Services unter `app/Modules/Mail/`
- Multi-Account-IMAP/SMTP, Header-Index, sichere HTML-Mailanzeige, Split-View

### Banking (`/banking`)

- Controller/Services/Repositories unter `app/Modules/Banking/`
- native usergebundene Umsätze, Monatsübersicht, wiederkehrende Regeln, CSV-Import
- Legacy-Fallback read-only unter `/banking-old`

### Systeminfo (`/systeminfo`, admin)

- Controller: `app/Modules/Systeminfo/SysteminfoController.php`
- read-only System-/Runtime-Dashboard
- nutzt PHP/INI/`$_SERVER`, PDO, `/proc`, `/etc/os-release`

Unterschied zu Legacy:

- native Module haben festen internen Einstieg (Controller + definierte Routen)
- Legacy-Module werden dateibasiert unter `app/Legacy` ausgeführt und über Prefix gemountet

---

## 10) Wo ändere ich was? (für neue Module)

Wenn ein neues **natives** Modul gebaut wird:

1. Controller/Repository/Services in `app/Modules/<Modul>/` anlegen
2. Modulklasse `Modulon\Modules\<Modul>\<Modul>Module` implementieren
3. Views in `app/Views/<modul>/` anlegen
4. Modulverwaltung öffnen, damit Auto-Discovery den DB-Eintrag deaktiviert anlegt
5. Modul in `/admin/modules` aktivieren und Sichtbarkeit/Zugriff konfigurieren
6. optional Provider für Modul-Untermenüs, Admin-Navigation oder User-Navigation registrieren

Wenn ein **Legacy**-Modul eingebunden wird:

1. Dateien unter `app/Legacy/<app>/` ablegen
2. DB-Eintrag in `modules`:
   - `handler=legacy`
   - `route_prefix`
   - `legacy_entry` (und optional `admin_entry`)
3. optional `enable_overlay=1` setzen

---

## Hinweise / beobachtete Inkonsistenzen

- Es existieren weiterhin kompatible Security-Routen unter `/account/security/*`, damit bestehende/ältere Flows nicht brechen. Der bevorzugte UI-Einstieg ist jedoch `/profil/security`.
