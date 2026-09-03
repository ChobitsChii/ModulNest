# Das vollständige Example-Modul

[`ExampleNotes`](https://github.com/ChobitsChii/ModulNest/tree/main/examples/modules/ExampleNotes/) ist ein
ausführbares, bewusst etwas umfangreicheres Referenzmodul für ModulNest 1.x.
Es demonstriert einen normalen User-Bereich, eine Admin-Einstellung,
versionierte Migrationen, Service und Repository, Assets, Logging sowie
HTML- und fetch-CSRF.

## Warum liegt das Beispiel unter `examples/`?

Der Native Module Loader entdeckt nur Klassen unter `app/Modules/`.
ExampleNotes liegt deshalb bewusst außerhalb dieses Pfads. Eine gewöhnliche
ModulNest-Installation registriert oder aktiviert es nicht und zeigt es nicht
in der Modulverwaltung an.

## In einer Entwicklungsinstallation testen

Kopiere die im [ExampleNotes-README](https://github.com/ChobitsChii/ModulNest/tree/main/examples/modules/ExampleNotes/)
genannten Modul-, View- und Asset-Pfade in eine **isolierte** ModulNest-
Entwicklungsinstallation. Nach der Auto-Discovery erscheint ExampleNotes als
deaktiviertes Modul und kann über die Modulverwaltung aktiviert werden.

Das Beispiel ergänzt, ersetzt aber nicht die übrige Developer-Dokumentation:

- [Ein Modul erstellen](create-module.md) beschreibt den typischen Ablauf.
- [Modulspezifikation](module-spec.md) definiert die verbindlichen Regeln.
- [`tools/create-module.php`](create-module.md#generator-optional) erzeugt ein
  kleineres Grundgerüst für neue Module.

ExampleNotes ist kein Marketplace-Paket und enthält keine vorweggenommene
Marketplace-API.
