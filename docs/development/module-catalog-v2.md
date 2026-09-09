# Signierter Modulkatalog v2

Status: **ModulNest 2.0.0-rc.1**. Der Production-Katalog enthält die elf
öffentlichen Produktmodule; der Development-Katalog bleibt separat.

## Statisches Format

Eine Quelle stellt unveränderliche Dateien unter `catalog/v1/` bereit:

```text
catalog/v1/root.json
catalog/v1/root.json.sig
catalog/v1/modules/<module-id>.json
packages/<module-id>-<version>.zip
```

`root.json` enthält Schema-Version, Katalog-ID, monoton steigende Sequence,
Erstell-/Ablaufzeit, Ed25519-Key-Fingerprints und SHA-256-verknüpfte
Modulindizes. Jeder Modulindex enthält exakt Metadaten und Releases; ein Release
deklariert Version/Channel, Kompatibilitätsbereiche, Dependencies,
Migrationsanzahl, Paketpfad/-größe/-Hash, Veröffentlichungszeit sowie eine
detached Ed25519-Paketsignatur. Schema v1 weist unbekannte und fehlende Felder
zurück. IDs, SemVer, Constraints, URLs, Zeitstempel und ausschließlich relative
Pfade werden strikt validiert.

Der Core liest lokale Quellen über `LocalCatalogSource` und entfernte Quellen
über `HttpCatalogSource`. HTTP ist verboten; HTTPS nutzt TLS-Prüfung, keine
Redirects sowie harte Downloadgrößen. Vor jeder Installation wird das Paket
erneut auf Größe, SHA-256, Key-ID/Fingerprint und Signatur geprüft und danach
noch einmal vom sicheren Paketinspektor validiert.

## Trust Store und Schlüssel

Production vertraut den im Core eingebetteten offiziellen öffentlichen Root-
und Release-Keys. `MODULE_CATALOG_TRUSTED_KEYS_JSON` kann für kontrollierte
Installationen einen expliziten Trust Store konfigurieren. Die Root-Signatur
muss zum Root-Key passen; jeder Release-Key muss zusätzlich im signierten Root
autorisiert und lokal vertraut sein. Rotation und Recovery sind in
[`module-catalog-signing.md`](module-catalog-signing.md) beschrieben.

Unter `tests/Fixtures/catalog-v1/keys/` liegt absichtlich ein als **TEST ONLY**
markiertes Ed25519-Schlüsselpaar. Der private Testkey wird ausschließlich vom
deterministischen Fixture-Builder verwendet. Development/Test darf den
öffentlichen Testkey automatisch vertrauen; Production akzeptiert ihn nie und
verwendet standardmäßig ausschließlich die offizielle HTTPS-Katalogquelle mit
den eingebetteten Production-Public-Keys.

## Sequence und Last Known Good

Der Loader akzeptiert keine niedrigere Sequence als den bereits verifizierten
Stand. Nach vollständiger Root-, Index- und Ablaufprüfung speichert er atomar
einen checksummierten Last-Known-Good-Snapshot unter `storage/catalog/`.
Netzwerkfehler, Manipulation, unbekannte Keys, falsche Signaturen, abgelaufene
Metadaten oder Replay liefern im UI eine Warnung und verwenden nur diesen
vorher verifizierten Snapshot. Ohne LKG bleibt der Katalog sicher deaktiviert;
bereits installierte Module laufen weiter.

Eine identische Sequence bezeichnet immer exakt denselben Root-Inhalt. Auch in
Development ist „gleiche Sequence, anderer Root“ deshalb ein echter Konflikt
und wird nicht toleriert. Der lokale Entwicklungsworkflow verwendet eine
eigene Quellen-ID `modulnest.dev`, eine ignorierte Runtime-Quelle unter
`storage/catalog-dev/source` und einen persistenten Sequence-Zähler. Jeder
Build wählt `max(Sequence-State, vorhandener Root, LKG) + 1`; damit bleibt der
Replay-Schutz unverändert wirksam.

Der lokale Produktkatalog wird mit folgendem Befehl erzeugt:

```bash
php tools/build-module-catalog.php --development
```

Er enthält ausschließlich `modulnest.wiki`, `modulnest.logs`,
`modulnest.systeminfo` und `modulnest.news`. ExampleNotes bleibt ein
versioniertes Test-/Referenzmodul in den Test-Fixtures und erscheint nicht als
reguläres Produktmodul. Der Runtime-Katalog liegt unter dem bereits ignorierten
`storage/` und wird nicht committed.

`tools/build-module-catalog.php` ist zugleich der gemeinsame Publisherpfad für
einen späteren GitHub-gehosteten offiziellen Katalog. Dafür werden Ziel,
Katalog-ID, Key-ID, externer Keypfad, Sequence-State und Ablaufdatum explizit
übergeben. Der Builder verweigert TEST-ONLY-Keys außerhalb des Development-
Modus; Production-Private-Keys dürfen niemals im Repository liegen.

## Betrieb und Veröffentlichung

1. Modulpackage reproduzierbar bauen und unabhängig inspizieren.
2. SHA-256/Größe ermitteln und die Paketbytes detached signieren.
3. Release in den Modulindex aufnehmen; Indexhash im Root aktualisieren.
4. Root-Sequence erhöhen, kurze sinnvolle Ablaufzeit setzen und Root signieren.
5. Pakete und Indizes zuerst veröffentlichen, `root.json.sig` und `root.json`
   zuletzt atomar austauschen.
6. In einer isolierten Installation Discover, Plan, Install/Update und Health
   prüfen; niemals private Signing-Keys auf dem Zielsystem ablegen.

`tools/build-test-catalog.php` baut ausschließlich die versionierten Fixtures:

- `source-sequence-1`: ExampleNotes 0.1.0,
- `source-sequence-2`: ExampleNotes 0.1.0/0.2.0 und Wiki 1.0.0,
- `source`: zusätzlich Wiki 1.0.1.

Die Multi-Modul-Sequenzen enthalten außerdem Wiki, Logs, Systeminfo und News:

- `source-multi-sequence-1`: initiale installierbare Produktpakete,
- `source-multi-sequence-2`: gleichzeitige Updates für Logs, Systeminfo und News,
- `source-multi-incompatible`: Core-inkompatibles Modul,
- `source-multi-dependency-disabled` / `source-multi-dependency-missing`:
  deaktivierte beziehungsweise fehlende Required Dependency,
- `source-multi-conflict` und `source-multi-health-fail`: konfliktbehaftetes
  beziehungsweise im Healthcheck scheiterndes Update.

Die Fixtures beweisen, dass Planung und Rollback pro Modul isoliert bleiben:
Ein fehlerhaftes Update verändert weder andere installierte Module noch deren
Daten oder den Core.

Weitere signierte `source-*`-Fixtures bilden Ablauf, Core-Inkompatibilität,
fehlende Dependencies, ungültige Paketsignatur, Konflikt, fehlgeschlagenen
Health-Check und fehlgeschlagene Migration ab. Die abschließende installierbare
Sequenz beweist, dass der Katalog nach den Fehlerfällen weiter verwendbar ist.

Paket-ZIPs bleiben ausschließlich unter `tests/Fixtures/` und werden vom
aktuellen Public-1.2-Export ausgeschlossen.

## Admin- und Lifecycle-Vertrag

Der primäre Adminpunkt **Modul-Katalog** bietet Entdecken, Installiert, Updates
und Details. Karten trennen die Klassifikation **Core**, **Modul v1**,
**Modul v2** und **Legacy** von der technischen Modulversion und einer
zusätzlichen Herkunft wie Katalog, Lokal oder Manuell. Vor einem Update zeigt die UI
Versionen, Migrationsanzahl, Backup, Dependencies und Release Notes. Install
bleibt zunächst deaktiviert; Doppelsubmit wird im Browser gesperrt, serverseitig
serialisiert der per-Modul Operation Lock jede Mutation. Alle Aktionen sind
admin-only und CSRF-geschützt.

Deinstallation behält Daten. Purge ist separat, verlangt die exakte technische
ID, erstellt/verifiziert zuerst ein logisches DB-Backup und löscht nur das
persistierte Ownership-Ledger. Auditoperationen bleiben erhalten. Retained-only
Module bieten keinen ausführbaren Data-Portability-Button. Bei aktivem Provider
und aktiver Zentrale verlinkt die Detailseite deren Export/Import-Oberfläche.

Core-, Modul-v1- und echte Legacy-Zeilen bleiben gemeinsam unter Installiert
sichtbar. Während der ModulNest-2-Alpha bleibt die bestehende
„Modulverwaltung“ als eigener Admin-Tab direkt erreichbar. Katalog und
Modulverwaltung verwenden dieselbe Lifecycle-/Status-Quelle; einen
zusätzlichen Kopfbutton oder eine zweite Aktivierungswahrheit gibt es nicht.
Ein vorhandenes v1-Modul mit passendem v2-Paket erscheint genau einmal
unter Installiert und zeigt „Modul-v2-Version … verfügbar“ sowie „Auf Modul v2
umstellen“. Diese Adoption erscheint weder unter Entdecken noch unter Updates.
Für verwaltete v2-Zeilen delegiert auch diese Aktiv/Deaktiv an
denselben Lifecycle-Service und blockiert direkte Identitäts-/Route-/Lösch-
Mutationen. Ein Request lädt zuerst genau einen aktiven v2-Release-Snapshot und
schließt dessen Route aus der 1.x-Discovery aus; dadurch entstehen keine
doppelten Routen oder Navigationseinträge.
