# Tests für Module

Jedes Modul testet nur die Pfade, die es tatsächlich bereitstellt. Schreibende
Routen benötigen immer Positiv- und Negativtests für Zugriff und CSRF.

## Mindestprüfungen

- PHP-Syntax für neue/geänderte PHP-Dateien: `php -l <datei>`.
- Unit-/Smoke-Test für Routing, Berechtigung und Fachlogik.
- Für jeden POST/PUT/PATCH/DELETE: gültiger Token akzeptiert, fehlender und
  falscher Token liefern 419 und führen keine Zustandsänderung aus.
- Migrationen: frische Ausführung und Upgradepfad, wenn das Modul Migrationen
  enthält.
- Browser-JS: `node --check public/assets/js/<datei>.js`, falls Node vorhanden.
- E2E für wesentliche Browser-Flows, Uploads oder fetch/XHR.

## Vorhandene Projektbefehle

Die komplette projektweite Smoke-Suite besteht aus den versionierten PHP-Tests:

```bash
for test in tests/unit/*.php; do php "$test"; done
```

Die Browserumgebung wird projektlokal geführt:

```bash
./scripts/e2e.sh
```

Details zu Installation und lokaler E2E-Konfiguration stehen in
[`docs/testing.md`](../testing.md). Vor einem Commit zusätzlich ausführen:

```bash
git diff --check
```

## Sinnvolle zusätzliche Tests

- Ein `admin`-Endpunkt: Gast wird zum Login geführt, normaler User erhält keinen
  Zugriff, Admin erreicht die Fachlogik.
- Ein `user`-Endpunkt: Gast wird abgewiesen, anderer User kann fremde Daten
  nicht verändern.
- JSON/fetch: zentraler 419-JSON-Body bei fehlendem Token.
- Upload: Token wird vor Datei-Verarbeitung geprüft; ungültiger Request legt
  keine Datei an.
- Migration: erneute Ausführung ist sicher und die erwartete Version/Checksum
  wird registriert.
