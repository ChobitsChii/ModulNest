# Modul-Lebenszyklus heute

Dieses Dokument beschreibt bewusst nur den aktuellen Stand von ModulNest 1.1.1.

## Discovery und Aktivierung

`NativeModuleLoader` durchsucht `app/Modules/*`. Ausgenommen sind die
Core-Verzeichnisse `Admin`, `Auth` und `Modules`. Für jeden anderen Ordner
erwartet er `Modulon\Modules\<Ordner>\<Ordner>Module`, das
`NativeModuleInterface` implementiert und einen nichtleeren `route_prefix`
liefert.

Die Modulverwaltung kann so erkannte Module als native, zunächst deaktivierte
Einträge registrieren. Aktive native Module werden beim Bootstrap erzeugt und
melden Routen sowie Navigation an. Deaktivierung verhindert das Laden des
nativ gebundenen Moduls; sie entfernt weder Paketdateien noch Daten.

## Paketaufnahme und Public Export

Der Public Export wählt die im Paket enthaltenen Module. `modulnest-package.json`
beschreibt, welche davon erforderlich oder optional sind. Der Installer kann
optionale Module vor dem Kopieren aus dem Paket entfernen; dann kann die
Auto-Discovery sie nicht finden.

Core-Updates aktualisieren das gesamte ModulNest-Paket. Migrationen aus
`app/Database/migrations/` und `app/Modules/<Module>/Database/Migrations/`
werden über den zentralen MigrationRunner geprüft und ausgeführt, wenn ein
Release Migrationen verlangt. Details stehen in [Release](../release.md) und
[Datenbank](../database.md).

## Aktuelle Grenzen

Heute gibt es ausdrücklich **nicht**:

- physische Installation einzelner Module aus einem Marketplace,
- Einzelmodul-Updates,
- deklarierte Modulabhängigkeiten,
- automatische Uninstall-/Datenrollback-Logik.

Ein späterer Marketplace kann dafür Paketmetadaten für Modulversion,
`requires_modulnest`, Abhängigkeiten, Paket-Hash/Signatur sowie Upgrade- und
Uninstall-Policy definieren. Diese Felder sind heute keine implementierte API.

## Versionierung heute und mit zukünftigem Marketplace

Heute wird ModulNest als Gesamtpaket versioniert. Ändert sich ein mitgeliefertes
Produktmodul und soll diese Änderung öffentlich ausgeliefert werden, benötigt
das gesamte ModulNest-Paket eine neue Version.

Ein späteres Marketplace-Modul, das einzeln installiert oder aktualisiert
werden kann, benötigt dagegen eine eigene SemVer-Version. Seine künftigen
Paketmetadaten müssen mindestens eine Modulversion, `requires_modulnest`,
Abhängigkeiten beziehungsweise optionale Abhängigkeiten, eine Upgrade- und
Migrations-Policy sowie einen Paket-Hash oder eine Signatur beschreiben.

Core- und Modulversion wären dann unabhängig, etwa ModulNest Core `1.4.2`,
Wiki `1.7.0` und Banking `2.1.3`. Diese Metadaten und APIs sind heute bewusst
noch nicht implementiert.
