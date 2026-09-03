# Security-Regeln für Module

Diese Regeln ergänzen den [Modulvertrag](module-spec.md). Sie gelten für jede
native Route und jedes Modul-Asset.

## Zugriff und CSRF

- Jede Route erhält bewusst `public`, `user` oder `admin`. Clientseitiges
  Ausblenden ist keine Zugriffskontrolle.
- `POST`, `PUT`, `PATCH` und `DELETE` sind zentral geschützt. Formulare nutzen
  `<?= \Modulon\Core\View::csrfField($csrf_token) ?>`.
- `fetch`/XHR sendet den aktuellen Token in `X-CSRF-Token`; JSON-Aufrufer
  senden zusätzlich sinnvollerweise `Accept: application/json`.
- Ein Modul darf keinen eigenen allgemeinen CSRF-Mechanismus, Session-Key oder
  Controller-Check bauen. Nur explizit begründete, nicht cookie-authentifizierte
  Integrationen können eine Router-Ausnahme benötigen.
- Import-, Freigabe-, Einmal- und Workflow-Tokens sind Fachdaten. Sie bleiben
  zusätzlich zu `_csrf` erforderlich und dürfen CSRF nicht ersetzen.

## Daten und Ausgabe

- Eingaben vor Verwendung nach Typ, Länge, Format und Berechtigung validieren.
- Dynamische HTML-Ausgabe mit `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
  escapen, sofern kein vorhandener sicherer Renderer zuständig ist.
- PDO-Statements vorbereiten; keine Benutzerdaten in SQL-Strings konkatenieren.
- Fehlerantworten dürfen keine Stacktraces, SQL, Dateipfade oder Secrets zeigen.
- JSON-Antworten mit Zustandsänderung sollen klaren Status und Inhaltstyp
  liefern; der zentrale CSRF-Fehler ist JSON mit HTTP 419.

## Dateien und Storage

- Uploads nur nach Berechtigung, Größe, Typ und sicherem Speicherziel
  akzeptieren. Nutzer-Dateinamen sind keine vertrauenswürdigen Pfade.
- Laufzeitdaten gehören nach `storage/`; keine Uploads, Backups oder temporären
  Daten direkt unter `public/` ablegen.
- Tokens, Passwörter, Session-IDs und Dateipfade niemals in URLs oder
  Query-Strings übertragen.

## Secrets und Logging

- Secrets kommen aus Konfiguration/Umgebung, niemals aus Quellcode oder
  Client-JavaScript.
- Für Ereignisse `RotatingFileLogger` und sichere Kontextfelder verwenden.
- Niemals Passwörter, Cookies, Authorization-Header, Session-IDs,
  CSRF-/Remember-Me-Tokens, Recovery-Codes oder TOTP-Secrets loggen.
- Logdaten sind kein Ersatz für Berechtigungsprüfungen und dürfen keine
  vollständigen Request-Bodies speichern.
