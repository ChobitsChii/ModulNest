# Modul-v2-Pakete und Lifecycle

Diese Seite ergänzt die kanonische
[Authoring-Anleitung](module-v2-authoring.md) um die implementierte Laufzeit- und
Katalogsicht. Manifestfelder, Capabilities und Beispiele werden ausschließlich dort
normativ dokumentiert.

## Paketprüfung und Installation

Ein deterministisches ZIP enthält `module.json` direkt an der Wurzel.
`ModulePackageInspector` prüft erwarteten SHA-256, Archivlimits, sichere Pfade und
Dateitypen, Manifest, `LICENSE`, Entrypoint sowie PSR-4-Wurzeln. Testmaterial unter
`tests/Fixtures/` und Testsignaturschlüssel sind niemals Production-Trust.

```text
php bin/modules.php list [--json]
php bin/modules.php inspect MODULE_ID [--json]
php bin/modules.php install package.zip [--sha256 HASH] [--json]
php bin/modules.php update package.zip [--sha256 HASH] [--json]
php bin/modules.php activate|deactivate|uninstall|purge MODULE_ID [--json]
```

Install und Update legen unveränderliche Releases unter
`modules/<id>/releases/<version>-<hash>` ab, prüfen den Entrypoint in einem separaten
PHP-Prozess, führen checksummed Modulmigrationen aus, veröffentlichen versionierte
Assets und schalten die Registry zuletzt um. Jeder Request arbeitet mit einem
`ModuleRuntimeSnapshot`.

Uninstall behält Daten und Migrationshistorie. Purge sichert deklarierte Tabellen,
löscht nur im Core registrierte Ownership und entfernt die kanonischen Storage-/
Cache-Wurzeln des Moduls. Reinstall akzeptiert retained Daten nur, wenn
`data.compatible_schema` passt. Clean Install und Katalogaktionen benutzen denselben
`ModuleLifecycleService`; es gibt keinen zweiten Installer-Lifecycle.

## Katalog und Adoption

Der Modul-Katalog ist die Oberfläche für Install, Update, Retain/Reinstall/Purge,
Changelogs und Abhängigkeiten. Seine Read Models und die technische Modulverwaltung
verwenden dieselbe Registry und Aktivierungswahrheit.

v1-Adoption ist eine optionale Brücke für tatsächlich veröffentlichte Altmodule.
Die bekannten Dateihashes und Baseline-Migrationen kommen aus signierten
Katalogmetadaten. Unbekannter oder geänderter v1-Code wird fail-closed nicht
übernommen. Neue Module ohne v1-Vorgänger benötigen und erfinden keine
Adoption-Metadaten.

Der vollständige Trust-, LKG- und Operatorvertrag steht in
[module-catalog-v2.md](module-catalog-v2.md). Die aktuellen offiziellen Module und
ihre individuellen Versionen stehen in `modules-src/VERSIONING.md` und ihren
paketierten `CHANGELOG.md`-Dateien.

## Betriebsverzeichnisse

Der Webprozess benötigt passend begrenzte Schreibrechte für `modules/`,
`public/assets/modules/`, `storage/modules/` und `storage/cache/modules/`.
Persistente Laufzeitdaten liegen nur unter der Modul-Storagewurzel; immutable
Paketassets nur unter dem versionierten öffentlichen Releasepfad. Große Lifecycle-
Operationen verwenden die Core-Background-/Journal-Infrastruktur.
