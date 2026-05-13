# Export und Import von Moduldaten

ModulNest stellt eine zentrale Admin-Funktion für den Export und Import von Moduldaten bereit. Sie ist für den gezielten Umzug einzelner Modulbereiche zwischen ModulNest-Instanzen gedacht.

Diese Funktion ersetzt kein vollständiges System-Backup. Für Updates, Migrationen oder Notfälle bleiben Datenbank-Backups, Datei-Backups und Server-Snapshots weiterhin notwendig.

## Format

Ein Export ist ein ZIP-Archiv mit versioniertem Manifest:

```text
modulnest-export-YYYY-MM-DD-HHMMSS.zip
├── manifest.json
└── modules/
    ├── dashboard/data.json
    ├── banking/accounts.json
    ├── banking/categories.json
    ├── banking/transactions.json
    ├── banking/recurring.json
    ├── news/entries.json
    └── sneak/
        ├── entries.json
        └── files/posters/...
```

`manifest.json` enthält unter anderem `format_version`, Produkt, App-Version, Erstellzeitpunkt und die enthaltenen Modulbereiche. Das aktuelle Format ist `format_version = 1`.

## Sicherheit

Importe sind nur im Adminbereich möglich und CSRF-geschützt. ZIP-Dateien werden nicht blind entpackt:

- `manifest.json` ist Pflicht.
- Unsichere Pfade wie `../` oder absolute Pfade werden abgelehnt.
- Ausführbare Dateitypen werden blockiert.
- Dateien werden temporär unter `storage/data-portability` verarbeitet, nicht im öffentlichen Webroot.
- Posterdateien werden nur als `jpg`, `jpeg`, `png` oder `webp` importiert.
- Vor dem Schreiben zeigt ModulNest immer eine Import-Vorschau.
- Standardmäßig werden Daten hinzugefügt oder zusammengeführt. Optional kann beim Import der Modus `Bestehende Moduldaten ersetzen` gewählt werden.
- Der Ersetzen-Modus löscht nur Daten der ausgewählten Import-Module und erst nach ausdrücklicher Bestätigung in der Vorschau.

Banking-Exporte enthalten persönliche Finanzdaten und müssen entsprechend geschützt aufbewahrt werden.

## Aktuelle Provider

### Dashboard

Exportiert die Dashboard-Daten des aktuellen Benutzers, darunter Widgets, Link-Ordner, Links, Aufgaben und Notizen.

Beim Import werden Daten dem aktuell angemeldeten Zieluser zugeordnet. Im Standardmodus bleiben bestehende Dashboard-Daten erhalten. Im Ersetzen-Modus werden nur Dashboard-Daten dieses Zielusers gelöscht und danach aus dem Import neu angelegt.

### Banking

Exportiert Konten, Kategorien, Import-Batches, Transaktionen und wiederkehrende Regeln des aktuellen Benutzers.

Beim Import werden Daten dem aktuell angemeldeten Zieluser zugeordnet. Im Standardmodus bleiben bestehende Daten erhalten. Transaktionen werden user-scoped über vorhandene Hashes, Legacy-IDs oder einen abgeleiteten Kernfeld-Hash dedupliziert. Wiederkehrende Regeln und Bedingungen werden nicht anhand gleicher Namen dedupliziert, weil gleiche Namen fachlich gültig sein können.

Im Ersetzen-Modus werden nur Banking-Daten des Zielusers gelöscht und danach aus dem Import neu aufgebaut. Daten anderer Benutzer bleiben unangetastet.

### Sneak Preview

Exportiert Sneak-Preview-Einträge, Einstellungen und lokale Posterdateien. Sneak Preview ist adminweit und nicht benutzerbezogen.

Beim Import werden Einträge über TMDB-ID oder die Kombination aus Datum, Titel und Ort erkannt. Posterdateien werden sicher normalisiert und bei Namenskollisionen mit Suffix gespeichert. Im Ersetzen-Modus werden Sneak-Preview-Einträge, Einstellungen und eindeutig zugeordnete Posterdateien des Moduls vor dem Import gelöscht.

### News

Exportiert News- und Changelog-Einträge als Markdown-Quelldaten. Gerendertes HTML wird nicht exportiert.

Beim Import werden Einträge über den Slug erkannt. Existierende Slugs werden aktualisiert, neue Slugs werden angelegt. Ungültige oder leere Slugs werden übersprungen. Im Ersetzen-Modus werden bestehende News-Einträge vor dem Import gelöscht.

## Provider für neue Module

Ein Modul kann einen Export-/Import-Provider bereitstellen, indem es die Schnittstelle `Modulon\Modules\DataPortability\DataPortabilityProviderInterface` implementiert.

Ein Provider definiert:

- Export-Key
- Anzeigename
- Route-Prefix für die Admin-Anzeige
- Beschreibung
- Format-Version
- ob Dateien enthalten sind
- Export-Daten
- Import-Vorschau
- Import-Logik

Die zentrale Service-Schicht schreibt die Daten unter `modules/<module-key>/` in das ZIP-Archiv. Dateien liegen unter `modules/<module-key>/files/`.

Neue Provider sollten keine rohen Auto-Increment-IDs als externe Identität verwenden. Stattdessen sollten stabile UUIDs, Slugs, fachliche Schlüssel oder explizite Hashes genutzt werden.
