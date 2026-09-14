# Modul-v2: AI-Quickstart

Kompakte Arbeitsgrundlage für eine Coding-KI, die ein neues ModulNest-v2-Modul
erstellt. Die vollständige und kanonische Referenz ist
[module-v2-authoring.md](module-v2-authoring.md).

## 1. Zuerst lesen

1. `docs/development/module-v2-authoring.md`
2. `app/Core/Modules/ModuleManifest.php` und `ModuleId.php`
3. `app/Core/NativeModuleInterface.php`, `ModuleContext.php`, `Router.php`
4. Nur die benötigten Capability-Interfaces aus `app/Core/Modules/`
5. Ein passendes reales Modul im öffentlichen Repository
   `ChobitsChii/ModulNest-Modules`
6. `tools/test-v2-module.sh`, `tools/test-v2-fast.sh` und
   `ModulePackageBuilder.php`
7. Vor einer offiziellen Veröffentlichung:
   `docs/development/module-v2-publishing.md`

`module-system-v2.md` ist ein historischer Entwurf, keine Authoring-API.
`examples/modules/ExampleNotes` ist ein 1.x-Beispiel. v2-Testfixtures sind nützlich
für Lifecycle-Randfälle, aber kein vollständiges Produktmodul.

## 2. Unverhandelbare Regeln

- Verwende eine globale ID `publisher.module-name`; nie nur `module-name`.
- Lege neuen Modulcode in einem v2-Workspace an, nie in `app/Modules` oder anderen
  Core-Verzeichnissen.
- Ändere keine Core-Dateien, um eine Modulfunktion zu verdrahten.
- Nutze ausschließlich existierende Core-Verträge und Capabilities.
- Modulversion und Core-Version sind unabhängig.
- Veröffentliche dieselbe Version niemals mit anderen Bytes erneut.
- Lege Runtime-Daten nie in Releasecode oder veröffentlichte Assets.
- Deklariere ausschließlich Daten, die allein dem Modul gehören.
- Ändere veröffentlichte Migrationen nie.
- Erzeuge, kopiere oder committe keine privaten Signing-Keys.

## 3. Standardlayout

```text
modules-src/<slug>/<version>/
├── module.json
├── CHANGELOG.md
├── LICENSE
├── src/
├── views/       # falls deklariert
├── assets/      # falls deklariert
├── migrations/  # falls deklariert
├── tests/       # optional
└── resources/   # optional, keine automatische Semantik
```

Das ZIP enthält diese Dateien direkt an seiner Wurzel. Persistente Dateien liegen
in `storage/modules/<module-id>/`, Cache in `storage/cache/modules/<module-id>/`.

## 4. Manifest-Checkliste

- [ ] `manifest_version` ist Integer `2`.
- [ ] `id` hat exakt zwei lowercase Segmente; je max. 63, gesamt max. 127 Bytes.
- [ ] `name`, `description`, SemVer-`version`, `license`, nichtleere `authors`.
- [ ] `requires.core` und `requires.php` verwenden nur Vergleicher/`||`, keine
      Caret-/Tilde-/Wildcard-Syntax.
- [ ] Prerelease-Core ist nur erlaubt, wenn die Range einen Prerelease-Komparator
      enthält.
- [ ] `entrypoint.class` implementiert `NativeModuleInterface`; Datei existiert.
- [ ] `autoload.psr4` ist paketlokal; Namespace endet `\\`.
- [ ] `dependencies`, `optional_dependencies`, `conflicts` sind ID→Range-Objekte.
- [ ] Deklarierte `migrations/views/assets.path` existieren und sind relative,
      sichere Pfade.
- [ ] `capabilities` enthält nur `root_page`, `page_links` oder
      `data_portability` und reale Providerklassen.
- [ ] `data.schema_version`, optional `compatible_schema`, vollständige Ownership.
- [ ] `route_prefix` und `access_level` (`public|user|admin`) stimmen mit Code.
- [ ] Keine geheimen Daten, lokalen absoluten Pfade oder erfundenen Felder.

## 5. Ownership-Checkliste

- [ ] `tables`: jede vollständig modul-eigene Tabelle, keine Core-/Fremdtabelle.
- [ ] `settings`: jeder modul-eigene **exakte** Schlüssel; keine Prefix-Wildcards.
- [ ] `storage`, `uploads`, `jobs`: alle eigenen Ressourcen beschrieben.
- [ ] Alle Dateien bleiben unter den kanonischen Storage-/Cache-Wurzeln.
- [ ] Datenloses Modul nutzt Schema `0` und fünf leere Listen.
- [ ] Purge kann anhand des Core-Ledgers ohne geladenen Modulcode sicher arbeiten.
- [ ] Ein gelesener Core-Bestand wird nicht als Ownership beansprucht.

## 6. Security-, Routes- und CSRF-Checkliste

- [ ] Jede Route hat bewusst `public`, `user` oder `admin`.
- [ ] GET/HEAD ändern niemals Zustand.
- [ ] POST/PUT/PATCH/DELETE bleiben zentral CSRF-geschützt.
- [ ] Formulare nutzen `View::csrfField($csrf_token)`.
- [ ] Fetch/XHR sendet `X-CSRF-Token`; Fehler 419 wird verständlich behandelt.
- [ ] HTML wird ausgabekontextgerecht escaped; kein ungeprüftes Raw-Markup.
- [ ] SQL nutzt Prepared Statements; Eingaben haben Typ-/Längenlimits.
- [ ] Dateipfade blockieren absolute Pfade, `..`, Symlinks und Traversal.
- [ ] Uploads prüfen Größe, Typ/Inhalt und landen außerhalb des Webroots.
- [ ] Externe Requests begrenzen Protokoll/Ziele und verhindern SSRF.
- [ ] Keine Tokens, Passwörter, personenbezogenen Inhalte oder Schlüssel in Logs.

## 7. Capability- und Kopplungscheck

- Navigation wird über `registerNavigation()` und vorhandene Providerinterfaces
  registriert, nicht als erfundene Manifest-Capability.
- `root_page`: `RootPageProviderInterface` wie Homepage.
- `page_links`: `PageLinkProviderInterface` wie Pages.
- `data_portability`: `DataPortabilityProviderInterface` wie News/Wiki/Banking.
- Providerklasse im Manifest deklarieren und dieselbe Instanz über
  `CapabilityRegistry::registerInstance()` anmelden.
- Gibt es keinen passenden neutralen Core-Vertrag, die Capability nicht erfinden.
  Separaten Core-Vertragsvorschlag melden.

## 8. Migration-, Test- und Build-Checkliste

- [ ] Jede Migration implementiert `Migration`, Scope `module`, korrekte Modul-ID.
- [ ] Keys sind stabil/eindeutig; Dateien werden nur hinzugefügt, nie umgeschrieben.
- [ ] Kein Runtime-`ensureSchema()` statt versionierter Migration.
- [ ] Clean Install und Update von der unmittelbar vorherigen Version getestet.
- [ ] Activate/Deactivate, Retain/Reinstall und Purge getestet.
- [ ] Fehlerpfad bewahrt funktionierenden alten Stand und Daten.
- [ ] Route-/Access-/CSRF-, XSS-, Daten- und Storage-Tests vorhanden.
- [ ] Für registrierte offizielle Module:
      `tools/test-v2-module.sh <module-id>`.
- [ ] Core-Regression: `tools/test-v2-fast.sh`.
- [ ] Format: `git diff --check`.
- [ ] ZIP zweimal mit `ModulePackageBuilder` bauen und identischen SHA-256 prüfen.
- [ ] `ModulePackageInspector` akzeptiert ZIP und erwarteten Hash.
- [ ] `CHANGELOG.md` enthält `## <SemVer> - YYYY-MM-DD` plus Bulletpoints.
- [ ] Offizieller Erst-Release: `--workspace-root`, expliziter `--publisher` und
      letzter öffentlicher Stand als `--published-root`; keine Dummy-Adoption.
- [ ] Publisher hat Manifest, reproduzierbares ZIP, SHA-256 und Production-
      Signaturen geprüft; private Keys bleiben außerhalb aller Repositories.

## 9. Eine KI darf niemals

- Modulcode direkt in den Core oder für ein neues v2-Modul nach `app/Modules`
  integrieren;
- Core-Tabellen oder fremde Daten als Modulownership beanspruchen;
- eine veröffentlichte Migration, Paketversion, Tag oder Paketdatei überschreiben;
- private Signing-Keys erzeugen, anzeigen, kopieren oder committen;
- eine neue Capability oder einen Manifest-Key als existent behaupten;
- ein Fachmodul direkt koppeln, wenn eine neutrale Capability vorgesehen ist;
- Runtime-Daten in Release-, View-, Asset- oder andere Codepfade schreiben;
- CSRF abschalten, mutierende GET-Routen bauen oder eigene schwächere Auth erfinden;
- v1-ExampleNotes oder historische Architekturbeispiele als v2-API kopieren;
- Adoption-Metadaten für ein neues Modul erfinden, das nie eine v1-Version hatte.

Vor Abschluss: Manifest validieren, vollständigen Lifecycle testen, Paket inspizieren,
Hash dokumentieren und alle Abweichungen vom aktuellen Core-Vertrag ausdrücklich
melden statt sie zu erraten.
