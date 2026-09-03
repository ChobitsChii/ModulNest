# Nativer Modulvertrag

Dieses Dokument ist normativ. Die Schlüsselwörter **MUST**, **MUST NOT**,
**SHOULD**, **SHOULD NOT** und **MAY** sind verbindlich zu verstehen.

## Discovery und Identität

- Ein auto-discoverbares natives Modul **MUST** unter
  `app/Modules/<ModuleName>/` liegen.
- Es **MUST** die Klasse
  `Modulon\Modules\<ModuleName>\<ModuleName>Module` geben.
- Diese Klasse **MUST** `Modulon\Core\NativeModuleInterface` implementieren.
- `<ModuleName>` **MUST** zugleich Ordnername und Klassenpräfix sein. Beispiel:
  `app/Modules/ExampleNotes/ExampleNotesModule.php` und
  `namespace Modulon\Modules\ExampleNotes;`.
- `metadata()['key']` und `metadata()['route_prefix']` **MUST** eindeutig sein.
  `route_prefix` wird ohne führenden oder abschließenden Slash angegeben, etwa
  `example-notes`.
- Code, der nur als Beispiel dienen soll, **MUST NOT** unter `app/Modules/`
  liegen. Beispiele gehören unter `examples/`.

Die tatsächlich vorhandene Schnittstelle lautet:

```php
final class MyModuleModule implements \Modulon\Core\NativeModuleInterface
{
    public static function metadata(): array;
    public static function create(\Modulon\Core\ModuleContext $context): ?\Modulon\Core\NativeModuleInterface;
    public function key(): string;
    public function routePrefix(): string;
    public function registerNavigation(
        \Modulon\Core\ModuleSubnavigationRegistry $moduleNavigation,
        \Modulon\Core\AdminNavigationRegistry $adminNavigation,
        \Modulon\Core\UserNavigationRegistry $userNavigation,
    ): void;
    public function registerRoutes(\Modulon\Core\Router $router): void;
    public function registerAdminRoutes(\Modulon\Core\Router $router): void;
    public function nativeBinding(): array;
}
```

`metadata()` **MUST** mindestens `key`, `name`, `route_prefix` und
`access_level` liefern. `access_level` **MUST** `public`, `user` oder `admin`
sein. `description`, `show_in_header`, `show_on_home` und `core` sind optional.

`create(ModuleContext $context)` **MUST** die benötigten Abhängigkeiten aus
dem Kontext erzeugen und ein Modul zurückgeben; falls eine unverzichtbare
Abhängigkeit wie PDO nicht verfügbar ist, **MAY** es `null` zurückgeben.
`$context->pdo`, `$context->session`, `$context->basePath`,
`$context->service('…')`, `$context->moduleRow($prefix)` und
`$context->moduleAccess($prefix, $default)` sind die vorhandenen APIs.

## Routing und Zugriff

- Ein Modul **MUST** jede eigene Route über `Router` registrieren.
- Es **MUST** für jede Route bewusst `public`, `user` oder `admin` angeben.
- Es **MUST NOT** Zugriffskontrollen durch versteckte Views oder clientseitige
  Navigation ersetzen.
- `GET`, `POST`, `PUT`, `PATCH` und `DELETE` stehen als
  `$router->get()`, `post()`, `put()`, `patch()` und `delete()` bereit.
- Schreibende Routen sind ohne CSRF-Argument automatisch `protect`.
  `GET`-Routen sind automatisch `exempt`.
- Ein Modul **MUST NOT** eine schreibende Route aus Bequemlichkeit explizit
  `exempt` machen. Eine Ausnahme benötigt eine dokumentierte, nicht
  cookie-authentifizierte Sicherheitsalternative.

Routen, Controller, Views, Services und Repositories sind nicht pauschal
Pflicht. Ein Modul **MUST** nur die Bausteine verwenden, die seine Funktion
benötigt. Ein HTTP-Modul **SHOULD** Controller dünn halten und Fach-/DB-Logik
in passende Services oder Repositories auslagern.

## CSRF und Browser-APIs

- Neue Module **MUST NOT** eigene allgemeine CSRF-Session-Keys, Tokenhelfer
  oder manuelle Controller-CSRF-Prüfungen implementieren.
- Ein HTML-Formular einer schreibenden Route **MUST** den zentralen Token
  senden, normalerweise mit `<?= \Modulon\Core\View::csrfField($csrf_token) ?>`.
- `fetch`/XHR für schreibende Routen **MUST** `X-CSRF-Token` senden und
  **SHOULD** `Accept: application/json` setzen, wenn JSON erwartet wird.
- Fachliche Einmal-, Import-, Bestätigungs- oder Workflow-Tokens **MUST** von
  CSRF getrennt bleiben; sie ersetzen `_csrf` nicht.

## Views, Navigation und Assets

- HTML-Views **MUST** unter `app/Views/<template>.php` liegen und via
  `View::render('<template>', $data)` gerendert werden.
- Ausgaben aus Benutzerdaten **MUST** HTML-escaped werden, sofern nicht ein
  vorhandener sicherer Renderer ausdrücklich zuständig ist.
- Ein Modul in der globalen Hauptnavigation **MUST** `show_in_header => true`
  in seinen Metadaten setzen. Es **MUST NOT** dafür einen
  `UserNavigationProviderInterface` verwenden.
- Persönliche Konto-/Profil-Einträge **MAY** über
  `UserNavigationProviderInterface` registriert werden.
- Admin-Einträge **MAY** über `AdminNavigationProviderInterface` registriert
  werden. `ModuleSubnavigationProviderInterface` **MAY** Unterseiten eines
  bereits sichtbaren Hauptmoduls liefern.
- Öffentliche, direkt auslieferbare Assets **MUST** unter `public/assets/`
  liegen. Modulcode **MUST NOT** Laufzeitdateien in öffentliche Pfade schreiben.
- JS **SHOULD** je Datei einen kleinen lokalen CSRF-Header-Helper verwenden,
  statt Tokenlogik in jeder Aktion zu kopieren.

## Daten, Migrationen und Storage

- Neue Datenbankschemaänderungen **SHOULD** als versionierte PHP-Migration unter
  `app/Modules/<Module>/Database/Migrations/` implementiert werden.
- Drittanbieter- bzw. künftig paketierbare Module **MUST** Schemaänderungen
  über versionierte Migrationen liefern.
- Neue Drittanbieter-Module **MUST NOT** `ensureSchema()` als Schema- oder
  Updateweg verwenden.
- Eine Modulmigration implementiert die vorhandene Schnittstelle
  `Modulon\Core\Database\Migration`: `key(): string`, `scope(): string`,
  `moduleKey(): ?string`, `description(): string` und
  `up(PDO $pdo, SchemaHelper $schema): void`. Für eine Modulmigration liefert
  `scope()` den Wert `module` und `moduleKey()` den Modul-Key.
- SQL **MUST** vorbereitete PDO-Statements verwenden; Eingaben **MUST** vor
  ihrer Verwendung validiert werden.
- Laufzeitdaten, Uploads, temporäre Dateien und Logs **MUST** unter
  `storage/` liegen und **MUST NOT** versioniert werden.
- Ein Modul **MUST NOT** Secrets oder private Zustände im öffentlichen Export,
  in Assets, URLs oder Beispieldateien ablegen.

## Logging, Fehler und Tests

- Sicherheits- und Betriebsereignisse **SHOULD** über
  `RotatingFileLogger` als sichere Kontextdaten unter `storage/logs/` schreiben.
- Logs **MUST NOT** Passwörter, Cookies, Session-IDs, CSRF-Tokens,
  Recovery-Codes, TOTP-Secrets oder Remember-Me-Tokens enthalten.
- Ein Modul **MUST NOT** interne Exceptions oder Secrets an Browserantworten
  ausgeben. Es **SHOULD** die zentralen Fehlerantworten und Statuscodes nutzen.
- Ein Modul mit Routen **MUST** mindestens Routing-/Autorisierungs- und
  CSRF-Negativtests für jede schreibende Route haben.
- Ein Modul mit Migrationen **MUST** deren frische und Upgrade-Ausführung
  testen. Browser-JS **SHOULD** durch einen passenden Smoke- oder E2E-Test
  abgedeckt werden.

## Zukünftige Paket-/Marketplace-Metadaten

Heute gibt es keine Einzelmodul-Installation, keine Modulupdates, keine
Abhängigkeiten und keine automatische Deinstallation. Eine spätere
Marketplace-Spezifikation kann Metadaten für Modulversion,
`requires_modulnest`, Abhängigkeiten, Paket-Hash/Signatur sowie Upgrade- und
Uninstall-Policy ergänzen. Bis dahin **MUST NOT** ein Modul solche Felder als
funktionierende Plattform-API voraussetzen.
