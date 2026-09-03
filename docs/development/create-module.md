# Ein typisches natives Modul erstellen

Diese Anleitung zeigt einen üblichen Aufbau. Nur die Modulklasse und ihre
gültigen Metadaten sind für die Auto-Discovery zwingend; Controller, Views,
Assets, Datenbank und Navigation sind jeweils **nur bei Bedarf** nötig.

## Optional: Generator

Der Generator erzeugt ein kleines Scaffold, ersetzt aber weder diese Anleitung
noch [ExampleNotes](https://github.com/ChobitsChii/ModulNest/tree/main/examples/modules/ExampleNotes/):

```bash
php tools/create-module.php WeatherAlerts
php tools/create-module.php "Weather Alerts" --admin --service --migration --tests
php tools/create-module.php Weather --admin --migration --dry-run
php tools/create-module.php Weather --non-interactive --format=json
```

Verfügbar sind `--access=public|user|admin`, `--admin`, `--service`,
`--repository`, `--migration`, `--main-navigation`,
`--account-navigation`, `--js`, `--css`, `--tests`, `--e2e`, `--dry-run`,
`--non-interactive` und `--format=text|json`. Der bisherige Schalter
`--navigation` bleibt vorübergehend als Alias für `--main-navigation`
erhalten und gibt eine Deprecation-Warnung aus. Der Generator plant und
validiert alle Pfade vor dem ersten Schreibvorgang und überschreibt niemals
bestehende Dateien.

## 1. Modulklasse anlegen

Lege `app/Modules/MyModule/MyModuleModule.php` mit dem Namespace
`Modulon\Modules\MyModule` an. Sie implementiert `NativeModuleInterface`.

```php
<?php
declare(strict_types=1);

namespace Modulon\Modules\MyModule;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\ModuleContext;
use Modulon\Core\ModuleSubnavigationRegistry;
use Modulon\Core\NativeModuleInterface;
use Modulon\Core\Router;
use Modulon\Core\UserNavigationRegistry;

final class MyModuleModule implements NativeModuleInterface
{
    public static function metadata(): array
    {
        return [
            'key' => 'my-module', 'name' => 'My module',
            'route_prefix' => 'my-module', 'access_level' => 'user',
            'description' => 'Kurzbeschreibung.',
            'show_in_header' => false, 'show_on_home' => false,
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        if ($context->pdo === null) { return null; }
        return new self(new MyModuleController($context->pdo), $context->moduleRow('my-module'));
    }

    public function __construct(private MyModuleController $controller, private ?array $moduleRow) {}
    public function key(): string { return 'my-module'; }
    public function routePrefix(): string { return 'my-module'; }
    public function registerNavigation(ModuleSubnavigationRegistry $moduleNavigation, AdminNavigationRegistry $adminNavigation, UserNavigationRegistry $userNavigation): void {}
    public function registerRoutes(Router $router): void {
        if (!$this->active()) { return; }
        $router->get('/my-module', [$this->controller, 'index'], 'user');
        $router->post('/my-module/save', [$this->controller, 'save'], 'user');
    }
    public function registerAdminRoutes(Router $router): void {}
    public function nativeBinding(): array { return [
        'module_key' => 'my-module', 'internal_name' => 'MyModule',
        'controller' => MyModuleController::class,
        'implementation_path' => 'app/Modules/MyModule/MyModuleController.php',
        'route_binding' => 'GET /my-module, POST /my-module/save',
    ]; }
    private function active(): bool { return is_array($this->moduleRow) && strtolower((string) ($this->moduleRow['handler'] ?? 'native')) === 'native'; }
}
```

Die tatsächliche Aktivierung wird durch den Moduldatensatz gesteuert. Öffne
nach dem Ausliefern die Modulverwaltung, damit sie die Metadaten entdeckt und
den Eintrag zunächst deaktiviert anlegt; anschließend wird er bewusst aktiviert.

## 2. Controller und View – nur bei HTTP/HTML

Ein Controller erhält einen `Request` und gibt `Response` zurück. Eine GET-View
nutzt den zentral zusammengesetzten `$csrf_token` für spätere Formulare:

```php
return new \Modulon\Core\Response(\Modulon\Core\View::render('my-module/index', [
    'title' => 'My module',
    'current_path' => '/my-module',
]));
```

`app/Views/my-module/index.php` enthält für einen POST:

```php
<form method="post" action="/my-module/save">
    <?= \Modulon\Core\View::csrfField($csrf_token) ?>
    <input name="label" required>
    <button type="submit">Speichern</button>
</form>
```

Die Route ist automatisch CSRF-geschützt. Keinen eigenen `csrf_token`, keinen
eigenen Session-Key und keine manuelle Tokenvalidierung ergänzen.

## 3. Navigation – optional

Es gibt drei klar getrennte Ziele:

- **Hauptnavigation:** `--main-navigation` setzt `show_in_header` in den
  Modulmetadaten. Das ist die normale globale Modulnavigation wie beim Wiki;
  dafür wird kein `UserNavigationProviderInterface` erzeugt.
- **Persönliches Account-Menü:** `--account-navigation` erzeugt einen
  `UserNavigationProviderInterface` und registriert ihn bei
  `UserNavigationRegistry`. Dies ist nur für persönliche Konto-/Profilfunktionen
  gedacht.
- **Admin-Navigation:** `--admin` erzeugt den Admin-Provider und die
  Admin-Routen.

`ModuleSubnavigationProviderInterface` bleibt für Unterseiten eines bereits
sichtbaren Hauptmoduls verfügbar. Provider liefern `moduleKey(): string` sowie
`items(string $currentPath): array`. Admin- und Account-Einträge können
`sort_order` enthalten; Modul-Untermenüs benötigen ihn nicht.

## 4. JSON, fetch und Assets – optional

Lege Browser-JS bei Bedarf unter `public/assets/js/` ab. Ein POST per fetch
liest einen in der gerenderten Seite bereitgestellten aktuellen Token und sendet
ihn ausschließlich im Header:

```js
await fetch('/my-module/save', {
  method: 'POST',
  headers: {
    'X-CSRF-Token': csrfToken,
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({label})
});
```

Token gehören niemals in URL, Query-String oder Logs. Für `FormData` bleibt
der Header derselbe; Datei- und Fachfelder werden nicht umgebaut.

## 5. Service, Repository und Migration – nur bei Bedarf

Fachlogik gehört normalerweise in einen Service, PDO-Zugriffe in ein
Repository. Neue Schemaänderungen gehören als PHP-Migration nach
`app/Modules/MyModule/Database/Migrations/`; orientiere dich an vorhandenen
Modulmigrationen und an [Datenbank und Migrationen](../database.md).

Nutze keine neue `ensureSchema()`-Logik für ein neues modulartiges Paket.
Runtime-Dateien gehören unter `storage/`, nicht nach `app/`, `public/` oder ins
Git-Repository.

## 6. Tests

Füge mindestens einen Unit-/Smoke-Test unter `tests/unit/` hinzu. Prüfe GET,
richtige Zugriffsstufe, gültigen CSRF-Token und fehlenden/falschen Token bei
jeder schreibenden Route. Ergänze Migration- und E2E-Tests, wenn das Modul
solche Pfade hat. Konkrete Befehle stehen in [Tests](testing.md).
