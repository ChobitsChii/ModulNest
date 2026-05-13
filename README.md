# ModulNest

ModulNest ist eine modulare, selbst hostbare PHP-Webplattform. Ziel ist es, mehrere kleine Web-Apps, Tools und Module unter einer Oberfläche, einem Login und einer Benutzerverwaltung zu bündeln.

Der interne Core-/Arbeitsname lautet Modulon. Für öffentliche Releases, Dokumentation und Installation ist der Produktname **ModulNest**.

Status: **Alpha**. Aktuelle Versionen und Downloads findest du unter [GitHub Releases](https://github.com/ChobitsChii/ModulNest/releases/latest).

## Warum ModulNest?

- Ein Login für mehrere Module und Tools.
- Benutzer-, Rollen- und Rechteverwaltung.
- Adminbereich für Module, Benutzer und Systemeinstellungen.
- Native Module mit eigenen Routen, Menüs und Adminbereichen.
- Legacy-App-Anbindung für bestehende PHP-Anwendungen.
- Bootstrap-Installer als einzelne `install.php`.
- Source- und Bundled-Releases: mit oder ohne Composer auf dem Zielsystem.

ModulNest nutzt bewusst etablierte Komponenten wie Composer/PSR-4, PDO, Bootstrap, DataTables sowie externe PHP-Libraries für 2FA, WebAuthn, QR-Codes und Mail. Details stehen in [Technologien & Abhängigkeiten](docs/dependencies.md).

## Installation

Für die normale Installation brauchst du nur die Datei [`install.php`](install.php).

1. Lade `install.php` auf deinen Webspace.
2. Öffne die Datei im Browser.
3. Folge dem Installer.

Der Installer lädt das passende Release-Paket, prüft die Voraussetzungen, richtet Datenbank und erstes Admin-Konto ein und versucht sich nach erfolgreicher Installation selbst zu löschen.

Wichtig:

- Der Webroot/DocumentRoot deiner Domain oder Subdomain muss auf das `public/`-Verzeichnis der Installation zeigen.
- Unterverzeichnis-Installationen wie `/modulnest/public/` werden aktuell nicht unterstützt.
- Für die meisten Nutzer ist das Bundled-Paket empfohlen, weil es kein Composer auf dem Zielserver benötigt.

Manuelle Installation und Details stehen in der [Installationsdokumentation](docs/installation.md).

## Dokumentation

- [Installation](docs/installation.md): Bootstrap-Installer, Webroot, Source/Bundled und manuelle Installation.
- [Konfiguration](docs/configuration.md): wichtige `.env`-Werte, Datenbank, Sessions, 2FA und Mail-Schlüssel.
- [Release & Export](docs/release.md): privater Entwicklungsstand, öffentlicher Export, Paketbau und Update-Metadaten.
- [Export & Import](docs/export-import.md): Moduldaten zwischen ModulNest-Instanzen übertragen.
- [Anforderungen](docs/requirements.md): PHP-Version, Extensions und Composer-Abhängigkeiten.
- [Technologien & Abhängigkeiten](docs/dependencies.md): verwendete Libraries, Frameworks und externe Bausteine.
- [Module](docs/modules.md): native Module, optionale Module, Autodiscovery und Zugriffsebenen.
- [Routen](docs/routes.md): wichtige öffentliche, Benutzer- und Admin-Routen.
- [Datenbank](docs/database.md): Tabellenüberblick und Verweis auf das Schema.
- [Tests](docs/testing.md): lokale Browser-E2E-Tests mit pytest und Playwright.
- [Entwicklung](docs/development.md): Projektpflege, KI-Unterstützung und technische Leitlinien.
- [Technische Architektur](docs/technical/tech-architecture.md): Core, Routing, Module und technische Details.
- [Export-Zusammenfassung](docs/export-summary.md): letzter Public-Export und Review-Hinweise.

## Sicherheit

- Produktiv nur über HTTPS betreiben.
- `.env` niemals committen oder veröffentlichen.
- Vor Updates Backups von Dateien und Datenbank erstellen.
- `install.php` nach der Installation entfernen. Der Installer versucht das automatisch.
- Secrets wie `MAIL_CREDENTIAL_KEY`, Datenbankpasswörter und API-Schlüssel dürfen nicht in Git landen.

## Entwicklung

ModulNest ist ein persönliches Open-Source-Projekt von Jennifer Graßl. Bei Planung, Code-Reviews, Refactoring, Dokumentation und einzelnen Implementierungsschritten kamen KI-Werkzeuge wie ChatGPT und Codex unterstützend zum Einsatz.

Architekturentscheidungen, Funktionsumfang, Tests, Review und Veröffentlichung liegen beim Projektmaintainer. Weitere Details stehen in der [Entwicklungsdokumentation](docs/development.md).

## Lizenz

ModulNest steht unter der [MIT License](LICENSE).

Nutzung, Forks und Änderungen sind erlaubt. Eine sichtbare Nennung des Projekts ist willkommen, aber nicht erforderlich. Copyright- und Lizenzhinweise müssen gemäß Lizenz erhalten bleiben.
