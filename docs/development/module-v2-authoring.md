# Modul-v2-Module entwickeln

Diese Seite ist die **kanonische Authoring-Dokumentation** für ModulNest 2.0.1.
Bei Abweichungen sind die Validatoren und Laufzeitverträge im aktuellen Core die
technische Wahrheit. Das historische Design-Dokument
[module-system-v2.md](module-system-v2.md) ist keine API-Spezifikation.

## 1. Was ist ein Modul v2?

Ein Modul v2 ist ein unabhängig vom Core versioniertes, signiertes Paket. Der
Modul-Katalog und `ModuleLifecycleService` installieren es in ein unveränderliches
Releaseverzeichnis, verwalten seinen Aktivstatus und können Code und Daten getrennt
behandeln.

- **Core** stellt Authentifizierung, Administration, Registry, Lifecycle und neutrale
  Verträge bereit.
- **Modul v1** ist alter, vom Core-Quellbaum entdeckter Code.
- **Modul v2** ist package-/catalog-managed und hat eine namespaced ID sowie eine
  eigene SemVer.
- Modulcode verändert keine Core-Dateien und wird für ein neues Katalogmodul niemals
  unter `app/Modules` angelegt.
- PHP-Module sind nicht sandboxed. Installation setzt Vertrauen in Katalogsignatur,
  Paketsignatur und Hash voraus.

## 2. Workspace und Paket

Im Core-Entwicklungsrepository liegen Release-Workspaces derzeit versionsbezogen:

```text
modules-src/example-notes/
└── 1.0.0/
    ├── module.json
    ├── CHANGELOG.md
    ├── LICENSE
    ├── src/
    ├── views/          # optional
    ├── assets/         # optional
    ├── migrations/     # optional
    ├── tests/          # optional, nur Modultests
    └── resources/      # optionaler normaler Paketinhalt, ohne Core-Automatik
```

`module.json`, `LICENSE`, die Entrypoint-Datei und alle PSR-4-Wurzeln müssen im
ZIP vorhanden sein. `CHANGELOG.md` ist für offizielle Katalog-Builds verpflichtend.
`views`, `assets` und `migrations` haben nur dann besondere Bedeutung, wenn der
jeweilige Manifestpfad deklariert ist. Andere sichere Dateien werden zwar paketiert,
aber nicht automatisch geladen oder verwaltet. Symlinks und Special Files sind im
Paket verboten.

Das ZIP hat keinen zusätzlichen Top-Level-Ordner: `module.json` liegt direkt an
seiner Wurzel. Releasecode ist unveränderlich. Persistente Daten gehören niemals in
den Workspace bzw. installierten Releasepfad.

## 3. `module.json`

### Vollständiges Beispiel

Das folgende Beispiel wird durch den Doku-Gate gegen den aktuellen
`ModuleManifest`-Validator geprüft.

<!-- module-v2-manifest-example:start -->
```json
{
  "manifest_version": 2,
  "id": "acme.example-notes",
  "name": "Example Notes",
  "description": "Verwaltet persönliche Notizen.",
  "version": "1.0.0",
  "license": "MIT",
  "authors": [
    {"name": "Acme Team", "url": "https://example.invalid"}
  ],
  "homepage": "https://example.invalid/example-notes",
  "repository": "https://example.invalid/example-notes/source",
  "support": "https://example.invalid/example-notes/issues",
  "funding": "https://example.invalid/sponsor",
  "requires": {
    "core": ">=2.0.0 <3.0.0",
    "php": ">=8.3.0",
    "extensions": ["pdo"]
  },
  "entrypoint": {
    "class": "Acme\\ExampleNotes\\ExampleNotesModule",
    "file": "src/ExampleNotesModule.php"
  },
  "autoload": {
    "psr4": {"Acme\\ExampleNotes\\": "src/"}
  },
  "dependencies": {},
  "optional_dependencies": {},
  "conflicts": {},
  "migrations": {"path": "migrations"},
  "views": {"path": "views"},
  "assets": {"path": "assets"},
  "capabilities": {},
  "data": {
    "schema_version": 1,
    "compatible_schema": ">=1 <=1",
    "ownership": {
      "tables": ["example_notes"],
      "settings": ["example_notes.display_mode"],
      "storage": ["notes"],
      "uploads": [],
      "jobs": []
    }
  },
  "route_prefix": "example-notes",
  "access_level": "user"
}
```
<!-- module-v2-manifest-example:end -->

### Feldreferenz

| Feld | Pflicht | Aktuelles Format und Bedeutung |
|---|---:|---|
| `manifest_version` | ja | Integer `2`; Vertragsversion, nicht Modulversion |
| `id` | ja | unveränderliche namespaced ID, siehe unten |
| `name` | ja | Text, maximal 120 Zeichen |
| `description` | ja | Text, maximal 500 Zeichen, keine Steuerzeichen |
| `version` | ja | vollständige SemVer ohne führendes `v` |
| `license` | ja | SPDX-artiger Bezeichner oder `LicenseRef-*`; `LICENSE` beilegen |
| `authors` | ja | nichtleere Liste von Objekten; jedes benötigt `name` |
| `homepage`, `repository`, `support`, `funding` | nein | jeweils valide absolute URL |
| `requires.core` | ja | unterstützte Core-Versionen als Constraint |
| `requires.php` | ja | unterstützte PHP-Versionen als Constraint |
| `requires.extensions` | nein | Liste kleingeschriebener PHP-Extensionnamen |
| `entrypoint.class` | ja | vollqualifizierter Klassenname; implementiert `NativeModuleInterface` |
| `entrypoint.file` | ja | sicherer relativer Pfad zur Klasse |
| `autoload.psr4` | ja | nichtleeres Objekt; Namespace endet `\\`, Pfad ist relativ |
| `dependencies` | nein | Modul-ID → Versionsconstraint |
| `optional_dependencies` | nein | Modul-ID → Versionsconstraint; Installation wird nicht erzwungen |
| `conflicts` | nein | Modul-ID → inkompatibler Versionsbereich |
| `migrations.path` | nein | relativer Ordner mit PHP-Migrationen |
| `views.path` | nein | relativer View-Ordner |
| `assets.path` | nein | relativer Ordner, den der Lifecycle versioniert veröffentlicht |
| `capabilities` | nein | Capability-Key → Providerklasse; nur Core-Verträge aus Abschnitt 9 |
| `data.schema_version` | ja | monotone, nichtnegative Ganzzahl; `0` bei datenlosen Modulen |
| `data.compatible_schema` | nein | Integer-Constraint für vorhandene/retained Daten |
| `data.ownership` | ja | Objekt mit den fünf unten beschriebenen Listen |
| `route_prefix` | ja | kleingeschriebener Route-Schlüssel, z. B. `example-notes` |
| `access_level` | ja | `public`, `user` oder `admin` |

`requires_features` ist reserviert; ein nichtleerer Wert wird von 2.0.1 bewusst
abgelehnt. Unbekannte Felder werden für Diagnose erhalten, erzeugen aber keinerlei
Funktion. Verlasse dich nie auf ein nicht dokumentiertes Feld.

Häufige Ablehnungsgründe sind ein listenförmiges Objekt statt `{}`, ein fehlender
abschließender Backslash im PSR-4-Namespace, absolute oder `..` enthaltende Pfade,
eine ID mit Unterstrich/Großbuchstaben, eine nicht unterstützte Range-Syntax, ein
fehlendes `LICENSE` oder ein Manifestpfad, der im ZIP nicht existiert. Das Manifest
ist UTF-8-JSON und auf 256 KiB begrenzt.

### ID-Regel

Eine ID besteht aus **genau zwei** durch einen Punkt getrennten Segmenten:

```text
publisher.module-name
```

Jedes Segment beginnt mit `a-z`, enthält danach nur `a-z`, `0-9` und einzelne
Bindestrichgruppen, ist höchstens 63 Bytes lang; die gesamte ID höchstens 127 Bytes.
Gültig: `acme.example-notes`, `modulnest.wiki`. Ungültig: `wiki`,
`Acme.notes`, `acme.example_notes`, `acme.-notes`, `a.b.c`. Fremde Publisher dürfen
den offiziellen Namespace `modulnest.*` nicht verwenden, auch wenn dies eine
Publisher-/Katalogrichtlinie und keine syntaktische Validatorregel ist.

### Versionsconstraints

Unterstützt werden whitespace-verknüpfte Vergleicher `>`, `>=`, `<`, `<=`, `=`
und Alternativen mit `||`, zum Beispiel `>=2.0.0 <3.0.0`. Caret, Tilde und
Wildcards werden nicht unterstützt. Eine Range ohne Prerelease-Komparator schließt
Prereleases aus. Wer eine 2.x-Alpha ausdrücklich unterstützt, nutzt beispielsweise
`>=2.0.0-alpha.1 <3.0.0`.

## 4. Versionierung und Changelog

Core- und Modulversion sind unabhängig. Nutze SemVer:

- Major: inkompatibler öffentlicher Vertrag oder Daten-/Verhaltensbruch.
- Minor: abwärtskompatible Funktion.
- Patch: abwärtskompatible Korrektur.
- Prereleases heißen etwa `2.0.0-beta.1` oder `2.0.0-rc.1`; der Core-Constraint
  muss sie ausdrücklich zulassen, falls benötigt.

Jedes offizielle Paket enthält `CHANGELOG.md`. Der aktuelle Katalog-Builder erwartet
Überschriften `## <SemVer> - YYYY-MM-DD` und darunter mindestens einen `- Eintrag`.
Eine einmal veröffentlichte Version, Migration und Paketdatei ist unveränderlich.
Geänderte Bytes benötigen immer eine neue SemVer und einen neuen Changelog-Eintrag.

## 5. Entrypoint, Routes, Zugriff und CSRF

Der Entrypoint implementiert `Modulon\Core\NativeModuleInterface`. Seine statische
Factory `create(ModuleContext $context)` erzeugt die Instanz; sie registriert Routen
und Navigation über die übergebenen Core-Registries. `metadata()['key']`, `key()` und
Manifest-ID müssen dieselbe stabile ID meinen; `routePrefix()` entspricht dem
konfigurierten Route-Prefix, ist aber **nicht** die Modulidentität.

`ModuleContext` stellt direkt `basePath`, die nullable `PDO`-Verbindung und
`Session` bereit. In 2.0.1 enthält sein Service-Bag `authService`,
`moduleRepository`, `userRepository`, `appSettingRepository`, `healthCheck`,
`healthCheckRegistry` und `capabilityRegistry`; Konfigurationswerte sind
`authConfig`, `app_version`, `app_channel` und `product_name`. Hole nur wirklich
benötigte Werte über `service()`/`config()`, prüfe konkrete Typen und behandle
nullable Infrastruktur ohne Fatal Error. Diese Namen sind Core-Runtime-APIs, keine
verdeckten Modulabhängigkeiten; Fachmodule dürfen sich dort nicht gegenseitig
bereitstellen.

Routen werden mit `Router::get/post/put/patch/delete($path, $handler, $access)`
registriert. Zugriff ist `public`, `user` oder `admin`. Verwende den effektiven
lokalen Zugriff über `ModuleContext::moduleAccess()` dort, wo der Modulvertrag ihn
konfigurierbar macht. Erfinde aus `route_prefix` keine nicht vorhandene Route.

Alle `POST`, `PUT`, `PATCH` und `DELETE`-Routen sind standardmäßig zentral
CSRF-geschützt. HTML-Formulare erhalten:

```php
<?= \Modulon\Core\View::csrfField((string) $csrf_token) ?>
```

Fetch/XHR sendet denselben Wert im Header `X-CSRF-Token`. Eine Ausnahme über den
fünften Routerparameter `'exempt'` ist nur für einen ausdrücklich geprüften,
authentifizierten Protokoll-Endpunkt zulässig, nicht als Bequemlichkeitslösung.
`GET` und `HEAD` dürfen nie Zustand ändern.

## 6. Views und Assets

Mit `views.path` registriert der Loader einen paketlokalen View-Root. Rendern:

```php
View::render('@acme.example-notes/index', $data);
```

Escaping erfolgt am Ausgabepunkt mit `htmlspecialchars(..., ENT_QUOTES |
ENT_SUBSTITUTE, 'UTF-8')`. Nach Installation kopiert der Lifecycle `assets.path`
nach `/assets/modules/<module-id>/<release-id>/`. Verwende die versionierte URL des
aktiven Releases; keine Pfade unter `app/Modules`, `app/Views` oder globalen
`public/assets/js|css` voraussetzen.

Releasecode und veröffentlichte Assets sind immutable. Uploads, erzeugte Dateien,
Modelle, Indizes und andere Laufzeitdaten liegen unter
`storage/modules/<module-id>/`; Cache unter `storage/cache/modules/<module-id>/`.

## 7. Daten und Ownership

Ownership ist die vom Core persistierte Löschgrenze. Sie muss vollständig und eng
sein, damit Purge auch ohne Modulcode funktioniert. Unterstützt sind exakt:

- `tables`: vollständig modul-eigene Tabellennamen.
- `settings`: vollständig modul-eigene **exakte** Setting-Schlüssel; keine Prefix-
  Wildcards.
- `storage`: relative Deskriptoren modul-eigener Inhalte unter der Modulwurzel.
- `uploads`: relative Deskriptoren modul-eigener Uploadbereiche.
- `jobs`: relative Deskriptoren modul-eigener Jobbereiche.

In 2.0.1 löscht der Core deklarierte Tabellen und exakte Settings anhand des
Ownership-Ledgers und entfernt beim Purge außerdem die kanonischen Modulwurzeln
`storage/modules/<id>` und `storage/cache/modules/<id>`. Deshalb müssen alle
persistenten Dateien unter diesen Wurzeln liegen. Deklariere niemals Core-Tabellen,
globale Logs, fremde Settings oder Daten eines anderen Moduls.

Datenlos:

```json
{"schema_version": 0, "ownership": {"tables": [], "settings": [], "storage": [], "uploads": [], "jobs": []}}
```

DB-Modul: `schema_version: 1`, alle eigenen Tabellen in `tables`, alle eigenen
Settings exakt in `settings`, plus versionierte Migrationen. Storage-Modul:
kanonische Root in `storage` dokumentieren und ausschließlich darunter schreiben.
Ein Modul, das Core-Daten nur liest (wie Logs), besitzt sie nicht.

```json
{"schema_version": 2, "compatible_schema": ">=1 <=2", "ownership": {"tables": ["acme_notes", "acme_note_tags"], "settings": ["acme_notes.sort"], "storage": [], "uploads": [], "jobs": []}}
```

```json
{"schema_version": 0, "compatible_schema": "=0", "ownership": {"tables": [], "settings": [], "storage": ["documents"], "uploads": ["documents/incoming"], "jobs": ["documents/index-jobs"]}}
```

## 8. Migrationen

Jede Datei in `migrations.path` endet auf `.php`, wird lexikografisch sortiert und
liefert ein Objekt zurück, das `Modulon\Core\Database\Migration` implementiert.
Für Paketmigrationen gilt:

```php
public function scope(): string { return 'module'; }
public function moduleKey(): ?string { return 'acme.example-notes'; }
```

Der Runner speichert Key, Modul-ID und SHA-256 in `schema_migrations`. Eine bereits
registrierte Datei mit anderem Hash wird abgelehnt. Veröffentlichte Migrationen
werden daher niemals bearbeitet oder umbenannt; jede Schemaänderung erhält eine neue
Migration mit neuem, global stabilem Key. Runtime-`ensureSchema()` ist kein Ersatz
für eine Migration.

`data.schema_version` steigt mit dem Datenschema. `compatible_schema` muss angeben,
welche vorhandenen retained Schemas die neue Codeversion lesen/aktualisieren kann.
Reinstall führt bekannte Migrationen wegen ihrer Keys/Checksummen nicht erneut aus.
Migrationen sind vorwärtsgerichtet; es gibt kein automatisches Schema-Downgrade.

## 9. Aktuelle Core-Erweiterungspunkte

### Navigation (Entrypoint-Hook, keine Manifest-Capability)

`registerNavigation()` kann Provider registrieren:

- `AdminNavigationProviderInterface` für Adminnavigation,
- `UserNavigationProviderInterface` für globale Benutzer-/App-Navigation,
- `ModuleSubnavigationProviderInterface` für modulinterne Navigation.

Health-Checks werden durch einen Entrypoint mit
`HealthCheckProviderInterface::registerHealthChecks()` bereitgestellt. Diese vier
Hooks gehören nicht in `capabilities`.

### Manifest-Capabilities

In 2.0.1 existieren genau diese neutralen, konsumierten Capability-Keys:

| Key | Interface | Zweck | Referenz |
|---|---|---|---|
| `root_page` | `Modulon\Core\Modules\RootPageProviderInterface` | Inhalt für `/` liefern | Homepage |
| `page_links` | `Modulon\Core\Modules\PageLinkProviderInterface` | veröffentlichte Header-/Footer-Seiten | Pages |
| `data_portability` | `Modulon\Core\Modules\DataPortability\DataPortabilityProviderInterface` | Export/Preview/Import | News, Wiki, Banking |

`root_page` deklariert eine Klasse mit `view(): string` und
`build(?array $user, bool $isAdmin, array $availableModules): ?array`:

```json
{"capabilities": {"root_page": "Acme\\Landing\\LandingRenderer"}}
```

`page_links` deklariert eine Klasse mit `listPublicHeaderPages(): array` und
`listPublicFooterPages(): array`:

```json
{"capabilities": {"page_links": "Acme\\ExampleNotes\\ExamplePageLinkProvider"}}
```

`data_portability` implementiert Identität, Label, Route-Prefix, Schema-/Scope-
Informationen sowie `export()`, `previewImport()` und `import()` exakt gemäß
`DataPortabilityProviderInterface`:

```json
{"capabilities": {"data_portability": "Acme\\ExampleNotes\\NotesPortabilityProvider"}}
```

Die jeweilige Factory holt `capabilityRegistry` aus `ModuleContext`, konstruiert
den Provider und registriert dieselbe Instanz. Minimal für `page_links`:

```php
$registry = $context->service('capabilityRegistry');
if ($registry instanceof CapabilityRegistry) {
    $registry->registerInstance('acme.example-notes', 'page_links', $provider);
}
```

Der Provider implementiert die im Manifest genannte Klasse und den jeweiligen
Core-Vertrag. Für Methodensignaturen ist immer das Interface im aktuellen Core
maßgeblich. Es gibt aktuell keine `profile_extension`-Capability. Neue Keys dürfen
nicht von einem Modul erfunden werden; dafür ist zuerst ein neutraler Core-Vertrag
in einem separaten Core-Release nötig.

## 10. Lifecycle

- **Install:** Hash, Archiv, Manifest, Umgebung und Dependencies prüfen; Release
  isoliert entpacken/prüfen; Migrationen ausführen; Assets veröffentlichen; Registry
  zuletzt umschalten. Eine Kataloginstallation startet deaktiviert.
- **Activate:** erforderliche Dependencies müssen installiert und aktiv sein.
- **Deactivate:** wird bei aktiven abhängigen Modulen abgelehnt.
- **Update:** nur auf höhere SemVer; deklarierte DB-Daten werden vorher gesichert,
  neues Release geprüft und dann umgeschaltet.
- **Uninstall (retain):** deaktiviert und entfernt Code/Assets, behält Daten,
  Schema-Version und Migrationshistorie.
- **Reinstall:** installiert kompatiblen Code auf retained Daten.
- **Purge:** sichert und löscht ausschließlich registrierte Ownership sowie
  kanonische Modul-Storage-/Cache-Wurzeln; entfernt die Installation endgültig.

Lifecycle-Zustand kommt ausschließlich aus Registry und Service. Modulcode baut
keine zweite Aktivierungswahrheit, verschiebt beim Laden keine Daten und führt keine
eigenen Install-/Uninstall-Skripte aus. Lang laufende Adoptionen/Updates werden vom
Core-Background-Lifecycle orchestriert, nicht durch einen eigenen HTTP-Dauerrequest.

## 11. Dependencies und Konflikte

`dependencies` sind zwingend; Installation und Aktivierung werden gegen installierte
Versionen geprüft. `optional_dependencies` beschreiben kompatible Zusatzintegration,
erzwingen aber keine Installation. `conflicts` blockieren passende installierte
Versionen. Die Range-Syntax entspricht Abschnitt 3.

Kopple Fachmodule nicht direkt, wenn eine neutrale Capability existiert. Der
Data-Portability-Provider gehört beispielsweise ins Fachmodul; das optionale
DataPortability-Modul entdeckt ihn dynamisch.

## 12. Sicherheit

- Modulcode läuft mit den Rechten des PHP-Prozesses und ist nicht sandboxed.
- Nutze den zentralen Access Guard und CSRF-Guard; keine eigene schwächere Parallel-
  Authentifizierung.
- Escape HTML kontextgerecht; behandle Markdown/HTML als nicht vertrauenswürdig.
- Nutze Prepared Statements und validiere IDs, Sortierung und Grenzwerte.
- Normalisiere Dateipfade, verbiete `..`, absolute Pfade und Symlink-Ausbrüche.
- Prüfe Uploadtyp, Größe, tatsächlichen Inhalt und Ziel; speichere außerhalb des
  Webroots unter dem Modul-Storage.
- Lege Secrets nicht in Code, Manifest, ZIP, Changelog oder Logs ab.
- Begrenze externe Ziele gegen SSRF; erlaube nur nötige HTTPS-Hosts und blockiere
  interne/Link-Local-Ziele nach Auflösung.
- Private Production-Signing-Keys werden niemals erzeugt oder gespeichert, nur weil
  ein Modul gebaut wird. Sie gehören ausschließlich in die Release-Umgebung.

## 13. Tests

Für bereits in die Core-Testmatrix aufgenommenen offiziellen Module:

```bash
tools/test-v2-module.sh modulnest.wiki
tools/test-v2-fast.sh
```

Der Modulrunner prüft Build/Validierung, Install, Activate, Update, Disable, Retain,
Reinstall, Purge und Adoption. Er kennt derzeit die im offiziellen Test-Fixture
registrierten Modul-IDs; ein neues Drittmodul ergänzt eigene äquivalente Tests, bis
es in diese Matrix aufgenommen wird. Das 1.x-ExampleNotes unter `examples/modules/`
ist **keine** v2-Authoring-Referenz. Die v2-Fixtures unter
`tests/Fixtures/module-packages-v2/` zeigen Lifecycle-Randfälle, nicht den kompletten
Produktionsstandard.

Vor Übergabe mindestens:

1. `module.json` durch `ModuleManifestReader`/Package Inspector prüfen.
2. modulspezifische Unit-, Route-, Access-, CSRF- und Datenmigrationstests.
3. kompletten Lifecycle inklusive Fehler/Rollback und retained Daten.
4. deterministischen Build zweimal vergleichen.
5. `tools/test-v2-fast.sh` für Core-Regressionen und `git diff --check`.

## 14. Build

Der aktuelle reproduzierbare Builder ist `ModulePackageBuilder`: Dateien werden
lexikografisch sortiert, Symlinks abgelehnt und ZIP-Zeitstempel normalisiert. Ein
einzelnes Workspace-Paket lässt sich im Core-Checkout so bauen:

```bash
mkdir -p build/modules
php -r 'require "vendor/autoload.php"; $b=new Modulon\Core\Modules\ModulePackageBuilder(); echo $b->build($argv[1], $argv[2]), PHP_EOL;' \
  modules-src/example-notes/1.0.0 \
  build/modules/acme.example-notes-1.0.0.zip
sha256sum build/modules/acme.example-notes-1.0.0.zip
```

Danach prüft `ModulePackageInspector` das ZIP; lokal kann derselbe Lifecycle wie im
Katalog verwendet werden:

```bash
php bin/modules.php install build/modules/acme.example-notes-1.0.0.zip --sha256 HASH
php bin/modules.php activate acme.example-notes
```

`tools/build-module-catalog.php --development --module <id>` baut die bereits
registrierten Development-Definitionen. Neue offizielle Module werden ohne
Allowlist über `--workspace-root` entdeckt und mit einem explizit autorisierten
`--publisher` gebaut. Production-Builds benötigen zusätzlich externe Root- und
Release-Signing-Keys, Sequence-State und den bisherigen unveränderlichen
`--published-root`; Testkeys dürfen nie dafür dienen. Der genaue Maintainer-Ablauf
steht in [module-v2-publishing.md](module-v2-publishing.md). Eine KI darf weder
Dummy-Adoption-Hashes erfinden noch einen v1-Ausgangsstand vortäuschen.

## 15. Veröffentlichung

Offizielle Produktmodule leben öffentlich in
[ChobitsChii/ModulNest-Modules](https://github.com/ChobitsChii/ModulNest-Modules),
getrennt vom Core. Paketpfade lauten
`packages/<module-id>/<version>/<module-id>-<version>.zip`; Tags folgen
`<module-id>-v<version>`. Ein Modulrelease benötigt keinen Core-Release.

Der Maintainer baut das unveränderliche Paket, erzeugt SHA-256, Release Notes und
Katalogeintrag, signiert Paket und neuen monotonen Katalogstand mit den externen
Production-Keys und veröffentlicht atomar. Das aktuelle Katalogschema kennt die
Channels `stable`, `beta` und `nightly`; ein RC trägt eine SemVer wie
`2.0.0-rc.1` und wird als `beta`-Channel ausgeliefert. Der Publisher leitet für
generische Workspaces `stable` oder `beta` aus der SemVer ab; die elf historischen
Definitionen bleiben Stable. Signierte Katalogdateien dürfen nie nachträglich
editiert werden.

Bereits veröffentlichte Bytes, Tags und Katalogsequenzen werden nicht ersetzt.
Adoption-Metadaten sind nur für eine reale v1→v2-Brücke nötig und Bestandteil
signierter Katalogmetadaten, nicht des Core und nicht normaler neuer Module.
Die erstmalige Aufnahme und unabhängige Folgereleases sind Schritt für Schritt in
[module-v2-publishing.md](module-v2-publishing.md) beschrieben.

## 16. Reale Referenzmodule

Im Repository `ChobitsChii/ModulNest-Modules`:

- klein/datenlos: **Systeminfo**; **Logs** zeigt zusätzlich korrektes Lesen von
  Core-Daten ohne Ownership.
- Datenbank und Migrationen: **News**.
- Storage und versionierte Assets: **Dashboard** bzw. **SneakPreview**.
- Root-/Link-Capability: **Homepage** (`root_page`) und **Pages** (`page_links`).
- Data Portability: **News**, **Wiki** oder **Banking**.
- komplexe Worker-/Dateidaten: **Tools**; komplexes relationales Modell: **Banking**.

Kopiere nie blind Übergangsbrücken wie ein historisches Runtime-`ensureSchema()`.
Für neue Module gelten diese Anleitung, der aktuelle Validator und versionierte
Migrationen.
