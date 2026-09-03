# Entwicklung nativer Module

Diese Dokumentation beschreibt den aktuellen, öffentlichen ModulNest-Vertrag
für native Module. Sie ist so geschrieben, dass Menschen und Coding-KIs ein
Modul allein anhand des Repositorys und dieser Dateien umsetzen können.

Ein **natives Modul** ist PHP-Code, der über eine Modulklasse Routen,
Navigation und Bindungsmetadaten am Core anmeldet. Native Module unterscheiden
sich von **Legacy-Anwendungen**: Legacy-Code wird dateibasiert unter
`app/Legacy/` eingebunden und verwendet bei Formularen die
[`LegacyCsrf`](../modules.md#legacy-csrf-vertrag-ab-modulon-10)-Bridge.

## Wichtigster Einstieg

- [Modulvertrag](module-spec.md) – verbindliche MUST/MUST-NOT-Regeln.
- [Ein Modul erstellen](create-module.md) – typische Umsetzung Schritt für Schritt.
- [Security](security.md) – Zugriff, CSRF, Daten, Uploads und Logging.
- [Tests](testing.md) – vorhandene Prüfungen und erwartete Negativtests.
- [Lebenszyklus](lifecycle.md) – Discovery, Aktivierung, Release und heutige Grenzen.
- [Vollständiges Example-Modul](example-module.md) – ausführbare Referenz mit
  Service, Repository, Migration, Assets und CSRF.

Bestehende Systemdokumentation ergänzt diese Referenz:

- [Module](../modules.md)
- [Technische Architektur](../technical/tech-architecture.md)
- [Datenbank und Migrationen](../database.md)
- [Release und Public Export](../release.md)
- [Projektweite E2E-Tests](../testing.md)

## Schnellübersicht

Ein normales Produktmodul liegt unter `app/Modules/<Modul>/`. Der Loader sucht
dort genau nach `Modulon\Modules\<Modul>\<Modul>Module`. Views gehören zentral
unter `app/Views/`, öffentliche Assets unter `public/assets/`.

```text
app/Modules/MyModule/MyModuleModule.php
app/Modules/MyModule/MyModuleController.php       # nur bei Bedarf
app/Modules/MyModule/Database/Migrations/*.php    # bei Schemaänderungen
app/Views/my-module/*.php                          # nur bei HTML-Ausgabe
public/assets/js/my-module.js                      # nur bei Browser-JS
```

Das ausführbare Lehrbeispiel liegt bewusst außerhalb dieses Pfads unter
 [`examples/modules/ExampleNotes/`](https://github.com/ChobitsChii/ModulNest/tree/main/examples/modules/ExampleNotes/). Es
wird deshalb weder auto-discovered noch als deaktiviertes Produktmodul angelegt.

Für ein kleines Grundgerüst steht optional `php tools/create-module.php` bereit.
Der manuelle Weg bleibt vollständig unterstützt; Optionen, Dry Run und JSON für
Automatisierung sind in [Ein Modul erstellen](create-module.md) beschrieben.
Für Navigation unterscheidet der Generator bewusst Hauptnavigation
(`--main-navigation`) und persönliches Account-Menü (`--account-navigation`).
