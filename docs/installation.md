# ModulNest Installation

## Empfohlene Installation mit `install.php`

Für eine normale Installation wird nur [`../install.php`](../install.php) benötigt.

1. `install.php` in den Ziel-Webspace laden.
2. Datei im Browser öffnen.
3. Systemcheck prüfen.
4. Datenbankdaten eintragen und testen.
5. Erstes Admin-Konto anlegen.
6. Installation starten.

Der Installer lädt ein geprüftes Release-Paket, prüft SHA256, entpackt sicher, schreibt `.env`, führt das Datenbankschema aus, aktiviert die ausgewählten Module und erstellt das erste Admin-Konto.

Nach erfolgreicher Installation versucht der Installer, die laufende `install.php` automatisch zu löschen. Wenn das nicht klappt, zeigt er einen deutlichen manuellen Löschhinweis.

## Webroot

Der Webserver muss auf das `public/`-Verzeichnis zeigen.

Beispiel:

```text
Installation: /pfad/zu/modulnest
DocumentRoot: /pfad/zu/modulnest/public
```

Der Installer darf entweder im Projektroot oder direkt im späteren `public/`-Webroot liegen:

```text
/pfad/zu/modulnest/install.php
/pfad/zu/modulnest/public/install.php
```

Unterverzeichnis-Installationen wie `/modulnest/public/` werden aktuell nicht unterstützt.

## Pakettypen

- Bundled-Paket:
  - enthält `vendor/`
  - benötigt keinen Composer auf dem Zielserver
  - empfohlen für die meisten Installationen
- Source-Paket:
  - enthält kein `vendor/`
  - benötigt Composer auf dem Zielsystem
  - gedacht für VPS, Entwicklungs- und Expertenumgebungen

## Manuelle Installation

Für Entwicklung oder eigene Server-Setups:

```bash
composer install
cp .env.example .env
```

Danach `.env` anpassen und das Schema einspielen:

```bash
mariadb -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < app/Database/schema.sql
```

Lokaler Testserver:

```bash
php -S 127.0.0.1:8080 -t public
```

Für produktiven Betrieb wird ein richtiger Webserver mit DocumentRoot auf `public/` empfohlen.
