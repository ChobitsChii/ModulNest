# Example Notes

`ExampleNotes` ist ausführbarer Lehrcode für den nativen ModulNest-1.x-
Modulvertrag. Es liegt absichtlich unter `examples/` statt `app/Modules/`.
Der `NativeModuleLoader` betrachtet diesen Ort nicht; eine normale Installation
registriert oder aktiviert das Beispiel daher nicht.

## Was das Beispiel zeigt

- `ExampleNotesModule.php`: Metadaten, `NativeModuleInterface`, `ModuleContext`,
  User-/Admin-Routen, Navigation und `nativeBinding()`.
- `ExampleNotesController.php`: HTTP-Request/Response und Views.
- `ExampleNotesService.php`: kleine Fachlogik, `example_notes.hint` und sicheres
  Ereignislogging.
- `ExampleNotesRepository.php`: vorbereitete PDO-Abfragen.
- `Database/Migrations/`: versionierte, idempotente Migration.
- `app/Views/`: HTML-Formular mit zentralem CSRF-Feld und Admin-Einstellung.
- `public/assets/`: CSS sowie fetch mit `X-CSRF-Token` und JSON-Antwort.
- `tests/`: Smoke- und Browserablauf als Vorlage.

## Zum Testen kopieren

In einer **isolierten Entwicklungsinstallation** kopiere die Pfade wie folgt:

```text
examples/modules/ExampleNotes/app/Modules/ExampleNotes/
  -> app/Modules/ExampleNotes/
examples/modules/ExampleNotes/app/Views/example-notes/
  -> app/Views/example-notes/
examples/modules/ExampleNotes/public/assets/js/example-notes.js
  -> public/assets/js/example-notes.js
examples/modules/ExampleNotes/public/assets/css/example-notes.css
  -> public/assets/css/example-notes.css
```

Danach Modulverwaltung öffnen, damit Auto-Discovery `Example Notes` als
deaktiviertes natives Modul registriert. Dort aktivieren. Erwartet werden die
User-Seite `/example-notes`, die Admin-Seite `/admin/example-notes`, eine
User-Navigation, eine Admin-Navigation und die Tabelle `example_notes` nach
dem Migrationslauf.

Das Beispiel ist kein Marketplace-Paket. Künftige Paketmetadaten werden
separat definiert und gehören nicht in diesen Code.

Für ein kleineres Scaffold kann optional `tools/create-module.php` verwendet
werden. ExampleNotes bleibt die bewusst umfangreichere, vollständige Referenz.

## Verbindliche Referenz

- [Modul erstellen](../../../docs/development/create-module.md)
- [Modulvertrag](../../../docs/development/module-spec.md)
- [Security](../../../docs/development/security.md)
- [Tests](../../../docs/development/testing.md)
- [Lebenszyklus](../../../docs/development/lifecycle.md)
