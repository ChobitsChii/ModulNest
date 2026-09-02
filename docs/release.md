# ModulNest Release-Prozess

ModulNest ist der öffentliche, bereinigte Export für Source-Reviews, GitHub und installierbare Release-Pakete. Das private Arbeitsprojekt und der interne Core-/Arbeitsname heißen Modulon.

## Namen und Metadaten

Die zentrale Datei `app/Config/version.php` trennt die Namen:

- Öffentlicher Produktname: `ModulNest`
- Interner Core-/Arbeitsname: `Modulon`
- UI-Laufzeitname über `APP_PRODUCT_NAME`
- Core-Hinweis über `APP_CORE_LABEL`
- Version über `app/Config/version.php`
- Release-Channel über `app/Config/version.php`

Der private Entwicklungsstand kann dadurch weiter als Modulon laufen, während der öffentliche Export in `.env.example` ModulNest als Produktname setzt. Die lokale `.env` überschreibt die Code-/Release-Version nicht mehr, damit Updates die installierte Version zuverlässig aktualisieren.

## Versionierung

ModulNest-App-Version und Bootstrap-Installer-Version sind getrennt:

- Die App-Version beschreibt das installierte ModulNest-Release und wird über `app/Config/version.php` bzw. Release-Metadaten geführt.
- Die Bootstrap-Installer-Version beschreibt nur die einzelne `install.php` und deren Installationslogik.

Für die Installer-Version gilt:

- Patch-Versionen für kleine Bugfixes und UI-Korrekturen.
- Minor-Versionen für neue Installer-Funktionen wie Pakettypen, Modulauswahl, AJAX-Prüfungen oder verbesserte Systemchecks.
- Major-Versionen später für stabile oder inkompatible Änderungen am Installer-Verhalten.

Vor einem Release werden App-Version und Bootstrap-Installer-Version bewusst geprüft und bei öffentlichen ModulNest-Releases synchron auf die Zielversion gesetzt.

ModulNest 1.0.1 verwendet den Channel `stable`.

Jeder öffentliche Release benötigt eine passende Datei
`docs/release-notes-VERSION.md`. Diese Notes sind die verbindliche Grundlage
für die GitHub-Release-Beschreibung.

## Recovery-Modus

Kann ein Update oder eine notwendige Migration nach einer Mutation nicht sicher
abgeschlossen werden, bleibt ModulNest im Wartungsmodus. Administratoren melden
sich über `/recovery` an. Der Bereich zeigt nur redigierte Diagnoseinformationen
und bietet ausschließlich sichere Prüfungen an. Eine automatische Änderung von
Migrationsmetadaten wird nur nach einer Schema-Prüfung und einem verifizierten
Datenbank-Backup angeboten. Der interne Recovery-Log ist
`storage/logs/recovery-YYYY-MM-DD.log`; der Wartungsmodus endet nur nach einer
erfolgreichen erneuten Konsistenzprüfung.

## Zwei Modul-Auswahl-Ebenen

### Maintainer-/Release-Build-Auswahl

Das private Repo kann unfertige, experimentelle oder private Module enthalten. Diese dürfen nie automatisch in ein öffentliches Release gelangen.

Deshalb bleibt die Modul-Auswahl im Export-Script erhalten:

```bash
tools/release/export-modulnest.sh --target /srv/http/modulnest
tools/release/export-modulnest.sh --target /srv/http/modulnest --modules Dashboard,News,Systeminfo,User --no-ui --yes
```

Core-/Pflichtordner werden immer exportiert:

- `Admin`
- `Auth`
- `Modules`

Optionale Module werden per `dialog`/`whiptail`, CLI-Fallback oder `--modules` ausgewählt. Nichtinteraktiv sind die aktuellen Public-Default-Module vorausgewählt:

- `Banking`
- `Dashboard`
- `DataPortability`
- `Homepage`
- `Logs`
- `News`
- `SneakPreview`
- `Systeminfo`
- `Tools`
- `Updates`
- `User`

Der Export schreibt `modulnest-package.json`. Diese Datei beschreibt, welche Module wirklich im Public-Export enthalten sind und welche davon erforderlich oder optional sind.

### Endnutzer-/Installer-Auswahl

Der Installer lädt immer ein vorbereitetes vollständiges Public-Release-Paket. Standardmäßig werden alle im Paket enthaltenen öffentlichen Module installiert und aktiviert.

Im erweiterten Installer-Bereich können Endnutzer optionale Module abwählen. Pflichtmodule sind sichtbar, aber nicht abwählbar.

Die Liste kommt aus den Release-/Paketmetadaten, nicht aus dem privaten Arbeitsrepo. Wenn optionale Module abgewählt werden, entfernt `install.php` deren `app/Modules/<Modul>`-Verzeichnis aus dem entpackten Paket, bevor die Dateien in den Zielordner kopiert werden. Dadurch kann die Modul-Autodiscovery sie später nicht versehentlich aktivieren.

## Ablauf

1. Entwicklung erfolgt im privaten Arbeitsrepo `/srv/http/modulon`.
2. Der öffentliche Export wird nach `/srv/http/modulnest` erzeugt:

   ```bash
   tools/release/export-modulnest.sh --target /srv/http/modulnest --no-ui --yes
   ```

3. Der Export wird manuell geprüft. Dabei besonders auf `.env`, `.local`, Logs, Backups, Storage-Daten, Legacy-Daten und echte Nutzdaten achten.
4. Erst nach Review wird im Zielrepo committed und nach `ChobitsChii/ModulNest` gepusht.
5. Release-ZIPs werden aus dem bereinigten Public-Export gebaut:

   ```bash
   tools/release/build-packages.sh --public-target /srv/http/modulnest --yes
   ```

6. `install.php` liest später die Release-Metadaten, lädt das passende ZIP, prüft SHA256 und installiert es.

`requires_migrations` ist releasebezogen: Der Export schreibt den Wert in `modulnest-package.json`; der Paketbau übernimmt ihn oder erhält ihn bewusst über `--requires-migrations true|false`. Für 1.0.0 und 1.0.1 ist der Wert `false`, weil der Migrationsstand mit Public v0.9.0 identisch ist. Releases mit neuen Migrationen müssen explizit `true` setzen.

## Pakettypen

Es gibt keine getrennten Core-/Full-Pakete mehr.

- `modulnest-source-VERSION.zip`
  - enthält `composer.json` und `composer.lock`
  - enthält kein `vendor/`
  - benötigt Composer auf dem Zielsystem oder im Installer
- `modulnest-bundled-VERSION.zip`
  - enthält `composer.json`, `composer.lock` und `vendor/`
  - benötigt keinen Composer auf dem Zielsystem

`vendor/` wird nicht dauerhaft in den Public-Source-Export geschrieben. Für das Bundled-Paket erzeugt `tools/release/build-packages.sh` ein temporäres Staging und führt dort aus:

```bash
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
```

Es wird ausdrücklich kein `composer update` ausgeführt. Security-Updates für Composer-Abhängigkeiten werden über neue ModulNest-Releases mit aktualisiertem `composer.lock` ausgeliefert.

## Bootstrap-Installer

`install.php` ist eine einzelne, frameworkfreie Bootstrap-Datei. Sie ist für leere Zielsysteme gedacht und führt bewusst keine unbeaufsichtigte Installation aus.

Der Installer:

- bricht ab, wenn bereits `var/install.lock`, `.env` oder eine bestehende App-Konfiguration erkannt wird
- zeigt Systemchecks für PHP, Extensions, Schreibrechte, HTTPS und Webroot-Hinweise
- erkennt PHP-CLI, Composer und `proc_open`
- empfiehlt standardmäßig das Bundled-Paket
- bietet Source + `composer install --no-dev --optimize-autoloader` als Experten-/VPS-Option an, falls Composer-Ausführung möglich ist
- fragt Datenbankdaten und erstes Admin-Konto ab
- testet die Datenbankverbindung
- lädt Release-Metadaten von einer oben in `install.php` konfigurierten URL
- lädt das passende ZIP und prüft SHA256 vor dem Entpacken
- entpackt mit Zip-Slip-Schutz in einen temporären Ordner
- entfernt abgewählte optionale Module vor dem Kopieren
- schreibt `.env`
- führt zuerst `app/Database/schema/core.sql`, dann die Schemas der ausgewählten Module, danach Core-Seeds und ausgewählte Modul-Seeds aus
- aktiviert die ausgewählten nativen Module in der Tabelle `modules`
- erstellt den ersten Admin-User
- schreibt `var/install.lock`
- versucht sich nach erfolgreicher Installation selbst zu löschen

Die erste Version unterstützt noch keine Tabellenpräfixe, weil der aktuelle Core SQL-Abfragen ohne Prefix ausführt. Das Feld ist im Installer sichtbar, muss aber leer bleiben.

Für den ersten öffentlichen Release wird ModulNest im Domain-/Subdomain-Root betrieben. Der Webserver muss auf das `public/`-Verzeichnis der Installation zeigen, z. B. Installation unter `/pfad/zu/modulnest` und DocumentRoot `/pfad/zu/modulnest/public`. Unterverzeichnis-Installationen werden aktuell noch nicht unterstützt.

Installationslogs landen unter `storage/logs/install-YYYY-MM-DD.log`. Passwörter, Tokens und Secrets werden dort nicht im Klartext geschrieben. Auf der Installer-Abschlussseite wird das JSONL-Installationslog tabellarisch mit Zeitpunkt, Meldung und Details angezeigt, sofern die Logdatei lesbar ist.

Composer.phar-Download ist bewusst noch nicht vollständig umgesetzt. Wenn Composer lokal nicht ausführbar ist, verweist der Installer auf das Bundled-Paket.

## Update-Metadaten

`tools/release/build-packages.sh` schreibt standardmäßig:

```text
/srv/http/modulnest/build/update/stable.json
```

Die JSON enthält mindestens:

- `latest`
- `channel`
- `php_requirement`
- `packages.source.url`
- `packages.source.sha256`
- `packages.source.needs_composer`
- `packages.bundled.url`
- `packages.bundled.sha256`
- `packages.bundled.needs_composer`
- `changelog_url`
- `requires_migrations`

## Datenbankmigrationen Bei Updates

- Seit der 0.7.0-Vorbereitung gibt es `schema_migrations` und `Modulon\Core\Database\MigrationRunner`.
- Migrationen liegen als PHP-Dateien unter `app/Database/migrations/` und `app/Modules/<Modul>/Database/Migrations/`.
- Der Updater führt nach erfolgreicher Datei-Kopie während Maintenance den MigrationRunner aus, sofern eine Datenbankverbindung verfügbar ist.
- Der App-Start führt Migrationen zusätzlich best-effort einmal pro Code-Version aus (`storage/migrations/<version>.done`), damit Updates von älteren Updater-Versionen auf 0.7.0 nicht ohne Migrationsprüfung bleiben.
- Migrationen dürfen keine Daten löschen und müssen idempotent sein.
- V1 versucht keinen automatischen DB-Rollback. Schlägt ein Update nach Beginn der Dateikopie oder während einer Migration fehl, bleibt der Wartungsmodus aktiv, das Update wird nicht als erfolgreich markiert und der gespeicherte Backup-Pfad muss für die Betreiber-Recovery geprüft werden.
- `package_metadata`
- `modules`
- `generated_at`

Das ist noch kein Auto-Updater. Es ist nur die stabile Grundlage, damit ein späterer Installer/Updater Releases nachvollziehbar prüfen kann.

## Sicherheitsregeln

Export und Paketbau dürfen keine privaten Daten enthalten:

- keine `.env`
- keine `.local`
- keine Logs oder Backups
- keine Storage-Nutzdaten
- keine echten Mail-/Banking-/Sneak-/FantasyCards-Daten
- keine Legacy-App-Inhalte außer `.gitkeep`
- kein `vendor/` im Public-Source-Export
- `vendor/` nur im temporären Bundled-Staging
- keine Datenbank-Dumps
- keine Tokens, Passwörter oder API-Keys
- keine privaten Testdaten

Wenn der Sicherheitscheck anschlägt, wird der Vorgang abgebrochen. In diesem Fall nicht pushen und nicht veröffentlichen, sondern zuerst die Ursache prüfen.

## Spätere Ziele

- echte Modul-Dependency-Trennung
- Modul-Katalog
- Modul-Nachinstallation
- vollständiger Auto-Updater
- optionaler CLI-Installer
