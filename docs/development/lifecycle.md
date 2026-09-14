# Historischer Modul-v1-Lebenszyklus

Dieses Dokument beschreibt den Legacy-Lebenszyklus von ModulNest 1.x. Der
aktuelle katalogverwaltete Modul-v2-Lifecycle steht in
[`module-v2-authoring.md`](module-v2-authoring.md#10-lifecycle). Die folgenden
Aussagen sind nur für verbliebene v1-/Legacy-Kompatibilität bestimmt und dürfen
nicht als Anleitung für neue Module verwendet werden.

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

## Historische Grenzen von v1

Im hier beschriebenen v1-System gab es ausdrücklich **nicht**:

- physische Installation einzelner Module aus einem Marketplace,
- Einzelmodul-Updates,
- deklarierte Modulabhängigkeiten,
- automatische Uninstall-/Datenrollback-Logik.

Das später implementierte Modul-v2 verwendet dafür das in der kanonischen
Authoring-Anleitung dokumentierte Manifest, den signierten Katalog und
`ModuleLifecycleService`. Der frühe Vorschlagsname `requires_modulnest` ist keine
aktuelle API; implementiert ist `requires.core`.

## Historische v1-Versionierung

In v1 wurde ModulNest als Gesamtpaket versioniert. Änderte sich ein mitgeliefertes
Produktmodul und soll diese Änderung öffentlich ausgeliefert werden, benötigt
das gesamte ModulNest-Paket eine neue Version.

Ein Modul-v2, das einzeln installiert oder aktualisiert wird, besitzt dagegen
eine eigene SemVer-Version und die heute implementierten Manifest-/Katalogdaten.

Core- und Modulversion sind seit ModulNest 2 unabhängig. Die exakten heute
implementierten Metadaten stehen ausschließlich in der Authoring-Anleitung.
