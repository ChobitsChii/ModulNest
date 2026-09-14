<?php

declare(strict_types=1);

use Modulon\Core\CsrfGuard;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\Database\MigrationRunner;
use Modulon\Core\ModuleContext;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Router;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Core\Modules\Catalog\CatalogSourceRegistry;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\ModulePackageInspector;
use ModulNest\RepositoryManager\RepositoryManagerController;
use ModulNest\RepositoryManager\RepositoryManagerModule;
use ModulNest\RepositoryManager\RepositoryMirrorService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
require_once $root . '/modules-src/repository-manager/0.1.0-beta.4/src/RepositoryManagerModule.php';
require_once $root . '/modules-src/repository-manager/0.1.0-beta.4/src/RepositoryManagerController.php';
require_once $root . '/modules-src/repository-manager/0.1.0-beta.4/src/RepositoryManagerAdminNavigationProvider.php';
require_once $root . '/modules-src/repository-manager/0.1.0-beta.4/src/RepositoryMirrorService.php';

function rm_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function rm_throws(callable $callable, string $message, string $contains = ''): void
{
    try {
        $callable();
    } catch (Throwable $error) {
        rm_assert($contains === '' || str_contains($error->getMessage(), $contains), $message . ' Falscher Fehler: ' . $error->getMessage());
        return;
    }
    rm_assert(false, $message);
}

/** @return array<string,string> */
function rm_env(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value, " \t\"'");
    }
    return $values;
}

View::registerModuleRoot('modulnest.repository-manager', $root . '/modules-src/repository-manager/0.1.0-beta.4/views');

$env = rm_env($root . '/.env');
$server = new PDO(
    'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';charset=' . ($env['DB_CHARSET'] ?? 'utf8mb4'),
    $env['DB_USER'] ?? '',
    $env['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = 'modulnest_repository_manager_' . bin2hex(random_bytes(4));
$temporary = sys_get_temp_dir() . '/modulnest-repository-manager-' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$sampleKey = base64_encode(random_bytes(32));
$sampleKeyId = 'modulnest-test-key';
$otherKey = base64_encode(random_bytes(32));
$otherKeyId = 'modulnest-other-key';

try {
    $server->exec('USE `' . $database . '`');
    (new MigrationRunner($server, $root))->run([]);
    rm_assert((string) $server->query("SELECT COUNT(*) FROM catalog_sources WHERE id='modulnest.official'")->fetchColumn() === '1', 'Die offizielle Quelle fehlt nach der Migration.');

    $registry = new CatalogSourceRegistry($server);
    $session = new Session();
    $context = new ModuleContext($temporary, $server, $session, ['catalogSourceRegistry' => $registry], []);
    rm_assert($context->catalogSources() === $registry, 'ModuleContext::catalogSources() liefert die Registry nicht.');

    // 1. Modul registriert seine Admin-Routen korrekt
    $module = RepositoryManagerModule::create($context);
    rm_assert($module instanceof RepositoryManagerModule, 'Das Modul wird nicht erzeugt.');

    $expectedRoutes = [
        'GET|/admin/repository-manager',
        'POST|/admin/repository-manager/add',
        'POST|/admin/repository-manager/update',
        'POST|/admin/repository-manager/enable',
        'POST|/admin/repository-manager/disable',
        'POST|/admin/repository-manager/test',
        'POST|/admin/repository-manager/sync',
        'GET|/admin/repository-manager/mirror-status',
    ];
    $router = new Router();
    $module->registerAdminRoutes($router);
    foreach ($expectedRoutes as $entry) {
        [$method, $path] = explode('|', $entry, 2);
        rm_assert($router->hasRoute($method, $path), 'Route fehlt: ' . $method . ' ' . $path);
    }

    // 2. Alle Routen sind admin-geschützt
    $moduleSource = (string) file_get_contents($root . '/modules-src/repository-manager/0.1.0-beta.4/src/RepositoryManagerModule.php');
    foreach (['add', 'update', 'enable', 'disable', 'test', 'sync'] as $action) {
        rm_assert(
            preg_match("~post\\('/admin/repository-manager/" . $action . "'.*?'admin'\\)~", $moduleSource) === 1,
            'Route /admin/repository-manager/' . $action . ' ist nicht admin-geschützt registriert.',
        );
    }

    // 3. Controller Actions & Strukturierte Keys
    $mirrorService = new RepositoryMirrorService($root);
    $controller = new RepositoryManagerController($session, $registry, $mirrorService);

    // 3a. add() mit strukturierten Keys
    $addStructuredRequest = new Request('POST', '/admin/repository-manager/add', [
        'id' => 'test.structured',
        'name' => 'Structured Keys Test',
        'source_type' => 'https',
        'location' => 'https://structured.example.test',
        'enabled' => '1',
        'priority' => '40',
        'trust_key_id' => [$sampleKeyId, $otherKeyId],
        'trust_public_key' => [$sampleKey, $otherKey],
        'trust_is_root' => ['0'], // Erstes Element als Root
    ], [], [], []);
    $controller->add($addStructuredRequest);
    $addedStruct = $registry->get('test.structured');
    rm_assert($addedStruct !== null, 'add() mit strukturierten Keys legt Quelle nicht an.');
    rm_assert($addedStruct['trusted_keys'] === [$sampleKeyId => $sampleKey, $otherKeyId => $otherKey], 'Strukturierte Trust-Keys nicht korrekt gespeichert.');
    rm_assert($addedStruct['root_key_ids'] === [$sampleKeyId], 'Strukturierter Root-Key nicht korrekt gespeichert.');

    // 3b. update() mit strukturierten Keys und enabled
    $updateStructuredRequest = new Request('POST', '/admin/repository-manager/update', [
        'id' => 'test.structured',
        'name' => 'Structured Keys Updated',
        'source_type' => 'https',
        'location' => 'https://structured.example.test',
        'priority' => '45',
        'enabled' => '1',
        'enabled_submitted' => '1',
        'trust_key_id' => [$otherKeyId],
        'trust_public_key' => [$otherKey],
        'trust_is_root' => ['0'],
    ], [], [], []);
    $controller->update($updateStructuredRequest);
    $updatedStruct = $registry->get('test.structured');
    rm_assert($updatedStruct['name'] === 'Structured Keys Updated' && $updatedStruct['priority'] === 45, 'update() speichert Metadaten nicht.');
    rm_assert($updatedStruct['trusted_keys'] === [$otherKeyId => $otherKey], 'update() mit strukturierten Keys speichert Keys nicht.');
    rm_assert($updatedStruct['root_key_ids'] === [$otherKeyId], 'update() mit strukturierten Keys speichert Root-Keys nicht.');

    // 4. Testen-Funktion (POST /admin/repository-manager/test)
    // 4a. Ungültige/Nicht-existierende Quelle (AJAX)
    $testAjaxInvalid = new Request('POST', '/admin/repository-manager/test', ['id' => 'non.existent'], [], [], [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);
    $testResp = $controller->test($testAjaxInvalid);
    rm_assert($testResp instanceof Response, 'Test liefert keine Response.');
    ob_start();
    $testResp->send();
    $testJson = ob_get_clean();
    $testData = json_decode((string) $testJson, true);
    rm_assert(is_array($testData) && empty($testData['success']), 'Test für ungültige Quelle muss fehlschlagen.');

    // 4b. Lokale Test-Quelle mit gültigem signierten Test-Katalog
    $validSourceDir = $temporary . '/valid-catalog-repo';
    mkdir($validSourceDir . '/catalog/v1', 0775, true);
    $signingPair = sodium_crypto_sign_keypair();
    $signSecret = sodium_crypto_sign_secretkey($signingPair);
    $signPublic = sodium_crypto_sign_publickey($signingPair);
    $keyId = 'local-root-key';
    $pubBase64 = base64_encode($signPublic);
    $fingerprint = (new CatalogTrustStore([$keyId => $pubBase64], [$keyId]))->fingerprint($keyId);

    $rootData = [
        'schema_version' => 1,
        'catalog_id' => 'local.valid',
        'sequence' => 1,
        'generated_at' => gmdate(DATE_ATOM),
        'expires_at' => gmdate(DATE_ATOM, time() + 86400),
        'signing_keys' => [
            ['key_id' => $keyId, 'algorithm' => 'Ed25519', 'fingerprint' => $fingerprint],
        ],
        'modules' => [],
    ];
    $rootJson = json_encode($rootData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    file_put_contents($validSourceDir . '/catalog/v1/root.json', $rootJson);
    $sigBinary = sodium_crypto_sign_detached($rootJson, $signSecret);
    $sigJson = json_encode([
        'key_id' => $keyId,
        'signature' => base64_encode($sigBinary),
    ], JSON_THROW_ON_ERROR);
    file_put_contents($validSourceDir . '/catalog/v1/root.json.sig', $sigJson);

    $registry->add(
        'local.valid',
        'Local Valid Test Source',
        'local',
        $validSourceDir,
        true,
        500,
        [$keyId => $pubBase64],
        [$keyId],
        'test',
    );

    $testAjaxValid = new Request('POST', '/admin/repository-manager/test', ['id' => 'local.valid'], [], [], [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);
    $testValidResp = $controller->test($testAjaxValid);
    ob_start();
    $testValidResp->send();
    $testValidJson = ob_get_clean();
    $testValidData = json_decode((string) $testValidJson, true);
    rm_assert(is_array($testValidData) && !empty($testValidData['success']), 'Test für gültige lokale Quelle muss erfolgreich sein.');
    rm_assert(($testValidData['sequence'] ?? 0) === 1, 'Test liefert falsche Katalog-Sequenz.');

    // 5. enable() / disable() über den Controller
    $controller->disable(new Request('POST', '/admin/repository-manager/disable', ['id' => 'test.structured'], [], [], []));
    rm_assert($registry->get('test.structured')['enabled'] === false, 'disable() über den Controller deaktiviert nicht.');
    $controller->enable(new Request('POST', '/admin/repository-manager/enable', ['id' => 'test.structured'], [], [], []));
    rm_assert($registry->get('test.structured')['enabled'] === true, 'enable() über den Controller aktiviert nicht.');

    // 6. Konfigurierbarkeit der offiziellen Quelle (durch Admin anpassbar)
    $controller->update(new Request('POST', '/admin/repository-manager/update', [
        'id' => 'modulnest.official',
        'name' => 'Offizieller ModulNest-Katalog (Angepasst)',
        'source_type' => 'https',
        'location' => 'https://repo.modulnest.de',
        'priority' => '999',
    ], [], [], []));
    $officialAfter = $registry->get('modulnest.official');
    rm_assert($officialAfter['priority'] === 999 && $officialAfter['name'] === 'Offizieller ModulNest-Katalog (Angepasst)', 'Offizielle Quelle konnte nicht angepasst werden.');

    $controller->disable(new Request('POST', '/admin/repository-manager/disable', ['id' => 'modulnest.official'], [], [], []));
    rm_assert($registry->get('modulnest.official')['enabled'] === false, 'Die offizielle Quelle konnte über den Controller nicht deaktiviert werden.');
    $controller->enable(new Request('POST', '/admin/repository-manager/enable', ['id' => 'modulnest.official'], [], [], []));
    rm_assert($registry->get('modulnest.official')['enabled'] === true, 'Die offizielle Quelle konnte über den Controller nicht reaktiviert werden.');

    // 7. Security: Kein direkter SQL-Zugriff aus dem Modul-Source
    foreach (glob($root . '/modules-src/repository-manager/0.1.0-beta.4/src/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        rm_assert(!str_contains($source, 'catalog_sources'), basename($file) . ' greift direkt auf catalog_sources zu.');
        rm_assert(!preg_match('/->(?:exec|prepare)\s*\(/', $source), basename($file) . ' enthält direkte PDO-SQL-Aufrufe.');
    }

    fwrite(STDOUT, "Repository Manager beta.4 smoke test passed.\n");
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_dir($temporary)) ModulePackageInspector::removeTree($temporary);
}
