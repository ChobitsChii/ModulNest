<?php

declare(strict_types=1);

/**
 * ModulNest Bootstrap-Installer.
 *
 * Diese Datei ist absichtlich frameworkfrei. Sie darf auf einen leeren Webspace
 * gelegt und direkt im Browser ausgeführt werden. Sicherheitsrelevante Schritte
 * wie Download, Hashprüfung, Entpacken und Selbstlöschung sind bewusst explizit
 * implementiert statt an die eigentliche Anwendung zu delegieren.
 */

const MODULNEST_INSTALLER_VERSION = '0.5.1';
const MODULNEST_METADATA_URL = 'https://raw.githubusercontent.com/ChobitsChii/ModulNest/main/build/update/stable.json';
const MODULNEST_MIN_PHP = '8.3.0';
const MODULNEST_REQUIRED_EXTENSIONS = [
    'pdo',
    'pdo_mysql',
    'mbstring',
    'openssl',
    'json',
    'curl',
    'zip',
    'fileinfo',
    'session',
];
const MODULNEST_CORE_MODULE_DIRECTORIES = ['Admin', 'Auth', 'Modules'];

session_start();

$errors = [];
$warnings = [];
$messages = [];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @return array{project_root:string,public_dir:string,installer_dir:string,installer_in_public:bool}
 */
function detectInstallPaths(): array
{
    $installerDir = rtrim(str_replace('\\', '/', __DIR__), '/');
    $installerInPublic = basename($installerDir) === 'public';
    $projectRoot = $installerInPublic ? dirname($installerDir) : $installerDir;
    $publicDir = $installerInPublic ? $installerDir : $projectRoot . '/public';

    return [
        'project_root' => $projectRoot,
        'public_dir' => $publicDir,
        'installer_dir' => $installerDir,
        'installer_in_public' => $installerInPublic,
    ];
}

/**
 * @return array{project_root:string,public_dir:string,installer_dir:string,installer_in_public:bool}
 */
function installPaths(): array
{
    static $paths = null;
    if ($paths === null) {
        $paths = detectInstallPaths();
    }

    return $paths;
}

function projectPath(string $relative): string
{
    return installPaths()['project_root'] . '/' . ltrim($relative, '/');
}

function publicPath(string $relative = ''): string
{
    $relative = ltrim($relative, '/');

    return installPaths()['public_dir'] . ($relative !== '' ? '/' . $relative : '');
}

function isInstalled(): bool
{
    return is_file(projectPath('var/install.lock'))
        || is_file(projectPath('.env'))
        || is_file(projectPath('app/Config/database.php'));
}

function csrfToken(): string
{
    if (!isset($_SESSION['modulnest_install_csrf']) || !is_string($_SESSION['modulnest_install_csrf'])) {
        $_SESSION['modulnest_install_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['modulnest_install_csrf'];
}

function verifyCsrf(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    if ($submitted === '' || !hash_equals(csrfToken(), $submitted)) {
        throw new RuntimeException('Ungültiger Sicherheits-Token. Bitte Formular neu laden.');
    }
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Verzeichnis konnte nicht erstellt werden: ' . $path);
    }
}

function logInstall(string $message, array $context = []): void
{
    $logDir = projectPath('storage/logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $redacted = [];
    foreach ($context as $key => $value) {
        $lower = strtolower((string) $key);
        if (str_contains($lower, 'pass') || str_contains($lower, 'token') || str_contains($lower, 'secret') || str_contains($lower, 'key')) {
            $redacted[$key] = '***';
        } else {
            $redacted[$key] = $value;
        }
    }

    $line = json_encode([
        'timestamp' => gmdate('c'),
        'message' => $message,
        'context' => $redacted,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (is_string($line)) {
        @file_put_contents($logDir . '/install.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function systemChecks(): array
{
    $checks = [];
    $checks[] = [
        'group' => 'PHP',
        'label' => 'PHP-Version',
        'ok' => version_compare(PHP_VERSION, MODULNEST_MIN_PHP, '>='),
        'value' => PHP_VERSION,
        'required' => '>= ' . MODULNEST_MIN_PHP,
        'required_for_install' => true,
        'severity' => 'critical',
    ];

    foreach (MODULNEST_REQUIRED_EXTENSIONS as $extension) {
        $checks[] = [
            'group' => 'PHP-Erweiterungen',
            'label' => 'PHP-Extension ' . $extension,
            'ok' => extension_loaded($extension),
            'value' => extension_loaded($extension) ? 'vorhanden' : 'fehlt',
            'required' => 'vorhanden',
            'required_for_install' => true,
            'severity' => 'critical',
        ];
    }

    $checks[] = [
        'group' => 'PHP-Erweiterungen',
        'label' => 'ZipArchive',
        'ok' => class_exists(ZipArchive::class),
        'value' => class_exists(ZipArchive::class) ? 'verfügbar' : 'fehlt',
        'required' => 'verfügbar',
        'required_for_install' => true,
        'severity' => 'critical',
    ];

    $paths = installPaths();
    $writableTargets = [
        ['label' => 'Installationsziel', 'path' => $paths['project_root'], 'description' => 'Root-Verzeichnis, in das ModulNest entpackt wird.'],
        ['label' => 'Storage-Verzeichnis', 'path' => $paths['project_root'] . '/storage', 'description' => 'Laufzeitdaten und Upload-Ziele.'],
        ['label' => 'Log-Verzeichnis', 'path' => $paths['project_root'] . '/storage/logs', 'description' => 'Installationslog und spätere App-Logs.'],
        ['label' => 'Public-Verzeichnis', 'path' => $paths['public_dir'], 'description' => 'Öffentlicher Webroot nach der Installation.'],
    ];
    foreach ($writableTargets as $target) {
        $path = (string) $target['path'];
        $writable = canWriteOrCreateDirectory($path);
        $checks[] = [
            'group' => 'Schreibrechte',
            'label' => $target['label'],
            'ok' => $writable,
            'value' => $writable ? 'schreibbar oder erstellbar' : 'nicht schreibbar',
            'required' => 'schreibbar/erstellbar',
            'required_for_install' => true,
            'severity' => 'critical',
            'detail' => $path,
            'description' => $target['description'],
        ];
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $checks[] = [
        'group' => 'Installation/Umgebung',
        'label' => 'HTTPS',
        'ok' => $https,
        'value' => $https ? 'aktiv' : 'nicht erkannt',
        'required' => 'empfohlen',
        'required_for_install' => false,
        'severity' => 'warning',
        'description' => 'Für produktive Installationen sollte der Installer über HTTPS aufgerufen werden.',
    ];

    $publicHintOk = true;
    $checks[] = [
        'group' => 'Installation/Umgebung',
        'label' => 'Webroot-Hinweis',
        'ok' => $publicHintOk,
        'value' => $paths['installer_in_public'] ? 'Installer liegt im public-Webroot' : 'Installer liegt im Projektroot',
        'required' => $paths['installer_in_public'] ? 'Webroot bereits public/' : 'später Webroot auf public/ setzen',
        'required_for_install' => false,
        'severity' => 'warning',
        'description' => $paths['installer_in_public']
            ? 'Der Installer wurde direkt im späteren public-Webroot ausgeführt.'
            : 'Nach der Installation sollte der Webserver auf das public/-Verzeichnis zeigen.',
    ];

    $capabilities = runtimeCapabilities();
    $checks[] = [
        'group' => 'Composer/Expertenmodus',
        'label' => 'PHP-CLI',
        'ok' => $capabilities['php_cli'] !== '',
        'value' => $capabilities['php_cli'] !== '' ? $capabilities['php_cli'] : 'nicht erkannt',
        'required' => 'optional für Source-Installation',
        'required_for_install' => false,
        'severity' => 'warning',
        'description' => 'Nur nötig, wenn das Source-Paket mit Composer installiert werden soll.',
    ];
    $checks[] = [
        'group' => 'Composer/Expertenmodus',
        'label' => 'Composer',
        'ok' => $capabilities['composer'] !== '',
        'value' => $capabilities['composer'] !== '' ? $capabilities['composer'] : 'nicht erkannt',
        'required' => 'optional für Source-Installation',
        'required_for_install' => false,
        'severity' => 'warning',
        'description' => 'Bundled-Installationen benötigen keinen Composer auf dem Zielserver.',
    ];

    return $checks;
}

function canWriteOrCreateDirectory(string $path): bool
{
    if (is_dir($path)) {
        return is_writable($path);
    }

    $parent = dirname($path);
    while ($parent !== '' && $parent !== '/' && !is_dir($parent)) {
        $parent = dirname($parent);
    }

    return is_dir($parent) && is_writable($parent);
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @return array{total:int, passed:int, warnings:int, critical:int}
 */
function systemCheckSummary(array $checks): array
{
    $summary = ['total' => count($checks), 'passed' => 0, 'warnings' => 0, 'critical' => 0];
    foreach ($checks as $check) {
        if (!empty($check['ok'])) {
            $summary['passed']++;
            continue;
        }
        if (($check['severity'] ?? '') === 'critical' && !empty($check['required_for_install'])) {
            $summary['critical']++;
        } else {
            $summary['warnings']++;
        }
    }

    return $summary;
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @return array<string, array<int, array<string, mixed>>>
 */
function groupedSystemChecks(array $checks): array
{
    $groups = [];
    foreach ($checks as $check) {
        $group = (string) ($check['group'] ?? 'Weitere Checks');
        $groups[$group][] = $check;
    }

    return $groups;
}

/**
 * @return array{php_cli: string, composer: string, proc_open: bool, exec: bool, shell_exec: bool}
 */
function runtimeCapabilities(): array
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $has = static fn (string $function): bool => function_exists($function) && !in_array($function, $disabled, true);

    return [
        'php_cli' => findExecutable(['PHP_BINARY' => PHP_BINARY, 'php' => 'php', '/usr/bin/php' => '/usr/bin/php', '/usr/local/bin/php' => '/usr/local/bin/php']),
        'composer' => findExecutable(['composer' => 'composer', '/usr/bin/composer' => '/usr/bin/composer', '/usr/local/bin/composer' => '/usr/local/bin/composer']),
        'proc_open' => $has('proc_open'),
        'exec' => $has('exec'),
        'shell_exec' => $has('shell_exec'),
    ];
}

/**
 * @param array<string, string> $candidates
 */
function findExecutable(array $candidates): string
{
    foreach ($candidates as $candidate) {
        if ($candidate === 'PHP_BINARY') {
            continue;
        }
        if ($candidate !== '' && str_contains($candidate, '/') && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        foreach ($candidates as $candidate) {
            if ($candidate === '' || str_contains($candidate, '/')) {
                continue;
            }
            $path = trim((string) @shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }
    }

    return '';
}

function requiredChecksPass(): bool
{
    foreach (systemChecks() as $check) {
        if (($check['required_for_install'] ?? false) && !($check['ok'] ?? false)) {
            return false;
        }
    }

    return true;
}

function postString(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

/**
 * @return array{host:string,port:int,name:string,user:string,password:string,prefix:string}
 */
function validateDatabaseInput(): array
{
    $db = [
        'host' => postString('db_host', '127.0.0.1'),
        'port' => (int) postString('db_port', '3306'),
        'name' => postString('db_name'),
        'user' => postString('db_user'),
        'password' => (string) ($_POST['db_password'] ?? ''),
        'prefix' => postString('db_prefix'),
    ];

    if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
        throw new RuntimeException('Datenbank-Host, Name und Benutzer sind erforderlich.');
    }
    if ($db['port'] < 1 || $db['port'] > 65535) {
        throw new RuntimeException('Ungültiger Datenbank-Port.');
    }
    if ($db['prefix'] !== '') {
        throw new RuntimeException('Tabellenpräfixe werden vom aktuellen Modulon-Core noch nicht unterstützt. Bitte leer lassen.');
    }

    return $db;
}

function testDatabaseConnection(array $db): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'],
        (int) $db['port'],
        $db['name']
    );

    return new PDO($dsn, (string) $db['user'], (string) $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function fetchUrl(string $url, int $timeout = 30): string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Ungültige Download-URL.');
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Download konnte nicht vorbereitet werden.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'ModulNest-Installer/' . MODULNEST_INSTALLER_VERSION,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $body === '' || $status >= 400) {
            throw new RuntimeException('Download fehlgeschlagen: HTTP ' . $status . ($error !== '' ? ' (' . $error . ')' : ''));
        }

        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'follow_location' => 1,
            'max_redirects' => 3,
            'user_agent' => 'ModulNest-Installer/' . MODULNEST_INSTALLER_VERSION,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Download fehlgeschlagen.');
    }

    return $body;
}

/**
 * Lädt Release-Metadaten. Die URL ist bewusst eine Konstante am Dateianfang,
 * damit keine beliebige Remote-Quelle über Formularwerte eingeschleust wird.
 *
 * @return array<string, mixed>
 */
function loadReleaseMetadata(): array
{
    $json = fetchUrl(MODULNEST_METADATA_URL, 20);
    $metadata = json_decode($json, true);
    if (!is_array($metadata)) {
        throw new RuntimeException('Release-Metadaten sind kein gültiges JSON.');
    }

    return $metadata;
}

/**
 * @param array<string, mixed> $metadata
 * @return array{url: string, sha256: string}
 */
function packageFromMetadata(array $metadata, string $packageType): array
{
    if (!in_array($packageType, ['source', 'bundled'], true)) {
        throw new RuntimeException('Ungültiger Pakettyp.');
    }

    $package = $metadata['packages'][$packageType] ?? null;
    if (!is_array($package)) {
        throw new RuntimeException('Pakettyp ist in den Release-Metadaten nicht vorhanden.');
    }

    $url = (string) ($package['url'] ?? '');
    $sha256 = strtolower((string) ($package['sha256'] ?? ''));
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
        throw new RuntimeException('Paket-Metadaten sind unvollständig oder ungültig.');
    }

    return ['url' => $url, 'sha256' => $sha256];
}

/**
 * @return array<string, mixed>|null
 */
function loadReleaseMetadataForUi(): ?array
{
    try {
        return loadReleaseMetadata();
    } catch (Throwable) {
        return null;
    }
}

function downloadPackage(string $url, string $expectedSha256, string $tmpDir): string
{
    ensureDirectory($tmpDir);
    $packagePath = $tmpDir . '/modulnest-package.zip';

    // Sicherheit: Download wird anschließend zwingend per SHA256 gegen die
    // signalisierte Release-Metadatei geprüft. Ohne Hash-Match wird nichts entpackt.
    logInstall('Paketdownload gestartet', ['url' => $url]);
    $body = fetchUrl($url, 300);
    file_put_contents($packagePath, $body, LOCK_EX);

    $actualSha256 = hash_file('sha256', $packagePath);
    if (!is_string($actualSha256) || !hash_equals($expectedSha256, strtolower($actualSha256))) {
        @unlink($packagePath);
        throw new RuntimeException('SHA256-Prüfung fehlgeschlagen. Paket wird nicht installiert.');
    }

    logInstall('Paketdownload abgeschlossen und SHA256 geprüft', ['sha256' => $actualSha256]);
    return $packagePath;
}

function isUnsafeZipPath(string $name): bool
{
    $normalized = str_replace('\\', '/', $name);
    return $normalized === ''
        || str_starts_with($normalized, '/')
        || preg_match('~(^|/)\.\.(/|$)~', $normalized) === 1
        || preg_match('~^[A-Za-z]:/~', $normalized) === 1;
}

function extractPackageSafe(string $zipPath, string $extractDir): void
{
    ensureDirectory($extractDir);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('ZIP-Paket konnte nicht geöffnet werden.');
    }

    // Sicherheit: kein extractTo() ohne Prüfung. Jeder Eintrag wird gegen
    // Zip-Slip geprüft, damit keine Dateien außerhalb des Zielbaums landen.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (isUnsafeZipPath($name)) {
            $zip->close();
            throw new RuntimeException('Unsicherer ZIP-Pfad erkannt: ' . $name);
        }

        $targetPath = $extractDir . '/' . str_replace('\\', '/', $name);
        if (str_ends_with($name, '/')) {
            ensureDirectory($targetPath);
            continue;
        }

        ensureDirectory(dirname($targetPath));
        $source = $zip->getStream($name);
        if (!is_resource($source)) {
            $zip->close();
            throw new RuntimeException('ZIP-Eintrag konnte nicht gelesen werden: ' . $name);
        }
        $destination = fopen($targetPath, 'wb');
        if (!is_resource($destination)) {
            fclose($source);
            $zip->close();
            throw new RuntimeException('ZIP-Eintrag konnte nicht geschrieben werden: ' . $name);
        }
        stream_copy_to_stream($source, $destination);
        fclose($source);
        fclose($destination);
    }
    $zip->close();

    logInstall('Paket sicher entpackt', ['extract_dir' => $extractDir]);
}

function recursiveCopy(string $source, string $destination, array $excludeFiles = []): void
{
    $excluded = array_fill_keys(array_map(
        static fn (string $path): string => ltrim(str_replace('\\', '/', $path), '/'),
        $excludeFiles
    ), true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', $iterator->getSubPathName());
        if (!$item->isDir() && isset($excluded[$relative])) {
            logInstall('Paketdatei beim Kopieren übersprungen', ['path' => $relative]);
            continue;
        }

        $target = $destination . '/' . $relative;
        if ($item->isDir()) {
            ensureDirectory($target);
            continue;
        }
        ensureDirectory(dirname($target));
        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('Datei konnte nicht kopiert werden: ' . $relative);
        }
    }
}

function runProcess(array $command, string $cwd, int $timeoutSeconds = 300): string
{
    $capabilities = runtimeCapabilities();
    if (!$capabilities['proc_open']) {
        throw new RuntimeException('proc_open ist deaktiviert; Composer kann hier nicht ausgeführt werden.');
    }

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Prozess konnte nicht gestartet werden.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $started = time();
    while (true) {
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
            break;
        }
        if (time() - $started > $timeoutSeconds) {
            proc_terminate($process);
            throw new RuntimeException('Prozess-Timeout erreicht.');
        }
        usleep(100000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('Prozess fehlgeschlagen: ' . trim($output));
    }

    return $output;
}

function runComposerInstall(string $path): void
{
    $capabilities = runtimeCapabilities();
    if ($capabilities['composer'] === '') {
        throw new RuntimeException('Composer ist nicht verfügbar. Bitte bundled Paket verwenden.');
    }
    if ($capabilities['php_cli'] === '') {
        throw new RuntimeException('PHP-CLI ist nicht verfügbar. Bitte bundled Paket verwenden.');
    }

    logInstall('composer install gestartet');
    $output = runProcess([
        $capabilities['composer'],
        'install',
        '--no-dev',
        '--optimize-autoloader',
        '--no-interaction',
        '--prefer-dist',
    ], $path, 900);
    logInstall('composer install abgeschlossen', ['output_tail' => substr($output, -1000)]);
}

/**
 * @return array<string, mixed>
 */
function readPackageMetadata(string $root): array
{
    $path = rtrim($root, '/') . '/modulnest-package.json';
    if (!is_file($path)) {
        throw new RuntimeException('Paket-Metadaten fehlen: modulnest-package.json');
    }
    $metadata = json_decode((string) file_get_contents($path), true);
    if (!is_array($metadata)) {
        throw new RuntimeException('Paket-Metadaten sind ungültig.');
    }

    return $metadata;
}

/**
 * @return array<int, array<string, mixed>>
 */
function packageModules(array $packageMetadata): array
{
    $modules = $packageMetadata['modules'] ?? [];
    return is_array($modules) ? array_values(array_filter($modules, 'is_array')) : [];
}

/**
 * @return array<int, string>
 */
function selectedModuleDirectories(array $packageMetadata, array $submitted): array
{
    $selected = [];
    $submittedLookup = array_flip(array_map('strval', $submitted));
    $useDefaults = $submitted === [];
    foreach (packageModules($packageMetadata) as $module) {
        $dir = (string) ($module['directory'] ?? '');
        if ($dir === '') {
            continue;
        }
        if (!empty($module['required']) || isset($submittedLookup[$dir]) || ($useDefaults && !empty($module['default_enabled']))) {
            $selected[] = $dir;
        }
    }

    return array_values(array_unique($selected));
}

function removeUnselectedModules(string $root, array $packageMetadata, array $selectedDirectories): void
{
    $selected = array_flip($selectedDirectories);
    foreach (packageModules($packageMetadata) as $module) {
        $dir = (string) ($module['directory'] ?? '');
        if ($dir === '' || !empty($module['required']) || isset($selected[$dir])) {
            continue;
        }
        removeDirectory(rtrim($root, '/') . '/app/Modules/' . $dir);
        $viewDir = (string) ($module['view_dir'] ?? '');
        if ($viewDir !== '') {
            removeDirectory(rtrim($root, '/') . '/app/Views/' . $viewDir);
        }
        logInstall('Optionales Modul vor Installation entfernt', ['module' => $dir]);
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function writeEnvFile(array $db): void
{
    $env = [
        'APP_ENV=production',
        'APP_DEBUG=false',
        'APP_PRODUCT_NAME=ModulNest',
        'APP_CORE_NAME=Modulon',
        'APP_CORE_LABEL="Modulon Core"',
        'APP_VERSION=0.5.1',
        'APP_CHANNEL=alpha',
        'PUBLIC_REGISTRATION_ENABLED=false',
        '',
        'DB_DRIVER=mysql',
        'DB_HOST=' . envValue((string) $db['host']),
        'DB_PORT=' . envValue((string) $db['port']),
        'DB_NAME=' . envValue((string) $db['name']),
        'DB_CHARSET=utf8mb4',
        'DB_USER=' . envValue((string) $db['user']),
        'DB_PASS=' . envValue((string) $db['password']),
        '',
        'SESSION_IDLE_TIMEOUT=1800',
        'SESSION_ABSOLUTE_TIMEOUT=28800',
        '',
        'REMEMBER_COOKIE_NAME=modulnest_remember',
        'REMEMBER_TOKEN_LIFETIME=1209600',
        'REMEMBER_COOKIE_SECURE=true',
        'REMEMBER_COOKIE_SAMESITE=Lax',
        '',
        'TOTP_ISSUER=ModulNest',
        'WEBAUTHN_RP_NAME=ModulNest',
        'WEBAUTHN_RP_ID=',
        '',
        'MAIL_CREDENTIAL_KEY=' . base64_encode(random_bytes(32)),
        '',
    ];

    if (file_put_contents(projectPath('.env'), implode(PHP_EOL, $env), LOCK_EX) === false) {
        throw new RuntimeException('.env konnte nicht geschrieben werden.');
    }
    @chmod(projectPath('.env'), 0640);
    logInstall('.env geschrieben', ['db_host' => $db['host'], 'db_name' => $db['name'], 'db_user' => $db['user']]);
}

function envValue(string $value): string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_.:@\/-]+$/', $value) === 1) {
        return $value;
    }

    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function runSchema(PDO $pdo): void
{
    $schemaPath = projectPath('app/Database/schema.sql');
    if (!is_file($schemaPath)) {
        throw new RuntimeException('Datenbankschema nicht gefunden: app/Database/schema.sql');
    }

    $sql = (string) file_get_contents($schemaPath);
    $pdo->exec($sql);
    logInstall('Datenbankschema ausgeführt');
}

function createAdminUser(PDO $pdo, array $admin): void
{
    $passwordHash = password_hash((string) $admin['password'], PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        throw new RuntimeException('Admin-Passwort konnte nicht gehasht werden.');
    }

    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT id FROM users WHERE email = :email OR username = :username LIMIT 1');
        $existing->execute([
            'email' => $admin['email'],
            'username' => $admin['username'],
        ]);
        if ($existing->fetch() !== false) {
            throw new RuntimeException('Admin-E-Mail oder Benutzername ist bereits vorhanden.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO users (name, username, email, password_hash, timezone)
             VALUES (:name, :username, :email, :password_hash, :timezone)'
        );
        $statement->execute([
            'name' => $admin['name'],
            'username' => $admin['username'],
            'email' => $admin['email'],
            'password_hash' => $passwordHash,
            'timezone' => 'Europe/Berlin',
        ]);
        $userId = (int) $pdo->lastInsertId();

        foreach (['user', 'admin'] as $roleName) {
            $roleStatement = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
            $roleStatement->execute(['name' => $roleName]);
            $role = $roleStatement->fetch();
            if (!is_array($role) || !isset($role['id'])) {
                throw new RuntimeException('Rolle fehlt: ' . $roleName);
            }

            $link = $pdo->prepare('INSERT IGNORE INTO user_role (user_id, role_id) VALUES (:user_id, :role_id)');
            $link->execute(['user_id' => $userId, 'role_id' => (int) $role['id']]);
        }

        $pdo->commit();
        logInstall('Erster Admin erstellt', ['user_id' => $userId, 'email' => $admin['email'], 'username' => $admin['username']]);
    } catch (Throwable $throwable) {
        $pdo->rollBack();
        throw $throwable;
    }
}

function seedSelectedModules(PDO $pdo, array $packageMetadata, array $selectedDirectories): void
{
    $selected = array_flip($selectedDirectories);
    $sortOrder = 10;
    $statement = $pdo->prepare(
        'INSERT INTO modules
            (name, description, route_prefix, access_level, handler, legacy_entry, admin_entry, enable_overlay, is_active, sort_order, show_in_header, show_on_home)
         VALUES
            (:name, :description, :route_prefix, :access_level, :handler, NULL, NULL, 0, 1, :sort_order, :show_in_header, :show_on_home)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            access_level = VALUES(access_level),
            handler = VALUES(handler),
            is_active = 1,
            sort_order = VALUES(sort_order),
            show_in_header = VALUES(show_in_header),
            show_on_home = VALUES(show_on_home),
            updated_at = CURRENT_TIMESTAMP'
    );

    foreach (packageModules($packageMetadata) as $module) {
        $dir = (string) ($module['directory'] ?? '');
        if ($dir === '' || empty($module['native']) || !isset($selected[$dir])) {
            continue;
        }
        $prefix = trim((string) ($module['route_prefix'] ?? ''), '/');
        if ($prefix === '') {
            continue;
        }
        $statement->execute([
            'name' => (string) ($module['name'] ?? $dir),
            'description' => (string) ($module['description'] ?? ''),
            'route_prefix' => $prefix,
            'access_level' => (string) ($module['access_level'] ?? 'user'),
            'handler' => 'native',
            'sort_order' => $sortOrder,
            'show_in_header' => !empty($module['show_in_header']) ? 1 : 0,
            'show_on_home' => !empty($module['show_on_home']) ? 1 : 0,
        ]);
        $sortOrder += 10;
    }
    logInstall('Module initial aktiviert', ['modules' => $selectedDirectories]);
}

function writeInstallLock(array $metadata, string $packageType, array $selectedModules): void
{
    ensureDirectory(projectPath('var'));
    $payload = json_encode([
        'installed_at' => gmdate('c'),
        'installer_version' => MODULNEST_INSTALLER_VERSION,
        'version' => (string) ($metadata['latest'] ?? ''),
        'channel' => (string) ($metadata['channel'] ?? ''),
        'package_type' => $packageType,
        'selected_modules' => $selectedModules,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || file_put_contents(projectPath('var/install.lock'), $payload . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('install.lock konnte nicht geschrieben werden.');
    }
}

function validateInstallInput(): array
{
    $packageType = postString('package_type', 'bundled');
    $db = validateDatabaseInput();
    $admin = [
        'username' => postString('admin_username'),
        'name' => postString('admin_name'),
        'email' => postString('admin_email'),
        'password' => (string) ($_POST['admin_password'] ?? ''),
        'password_confirm' => (string) ($_POST['admin_password_confirm'] ?? ''),
    ];

    if (!in_array($packageType, ['source', 'bundled'], true)) {
        throw new RuntimeException('Bitte einen gültigen Pakettyp wählen.');
    }
    if ($packageType === 'source') {
        $capabilities = runtimeCapabilities();
        if ($capabilities['composer'] === '' || $capabilities['php_cli'] === '' || !$capabilities['proc_open']) {
            throw new RuntimeException('Source-Installation benötigt PHP-CLI, Composer und proc_open. Bitte bundled Paket verwenden.');
        }
    }
    if (!preg_match('/^[a-z0-9._-]{3,40}$/i', $admin['username'])) {
        throw new RuntimeException('Benutzername muss 3-40 Zeichen lang sein und darf a-z, 0-9, Punkt, Unterstrich und Bindestrich enthalten.');
    }
    if ($admin['name'] === '') {
        throw new RuntimeException('Anzeigename ist erforderlich.');
    }
    if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Bitte eine gültige Admin-E-Mail eingeben.');
    }
    if (strlen($admin['password']) < 10) {
        throw new RuntimeException('Admin-Passwort muss mindestens 10 Zeichen lang sein.');
    }
    if (!hash_equals($admin['password'], $admin['password_confirm'])) {
        throw new RuntimeException('Admin-Passwörter stimmen nicht überein.');
    }

    unset($admin['password_confirm']);
    $enabledModules = $_POST['enabled_modules'] ?? [];
    if (!is_array($enabledModules)) {
        $enabledModules = [];
    }
    $enabledModules = array_values(array_filter(array_map('strval', $enabledModules), static fn (string $value): bool => preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value) === 1));

    return ['package_type' => $packageType, 'db' => $db, 'admin' => $admin, 'enabled_modules' => $enabledModules];
}

function sendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function detectedRootUrl(): ?string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($scriptDir !== '' && $scriptDir !== '.') {
        return null;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return null;
    }

    $scheme = 'http';
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        $scheme = 'https';
    } elseif ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $scheme = 'https';
    }

    return $scheme . '://' . $host . '/';
}

function maskLogValueForUi(mixed $value, string $key = ''): mixed
{
    $lower = strtolower($key);
    if ($lower !== '' && (str_contains($lower, 'pass') || str_contains($lower, 'token') || str_contains($lower, 'secret') || str_contains($lower, 'key'))) {
        return '***';
    }

    if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
        [$local, $domain] = explode('@', $value, 2);
        $first = $local !== '' ? $local[0] : '*';

        return $first . '***@' . $domain;
    }

    if (is_array($value)) {
        $masked = [];
        foreach ($value as $childKey => $childValue) {
            $masked[$childKey] = maskLogValueForUi($childValue, (string) $childKey);
        }

        return $masked;
    }

    return $value;
}

function compactJsonForUi(mixed $value): string
{
    if ($value === [] || $value === null || $value === '') {
        return '—';
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) && $json !== '' ? $json : '—';
}

/**
 * @return array<int, array{timestamp:string,message:string,details:string}>
 */
function installLogEntriesForUi(): array
{
    $path = projectPath('storage/logs/install.log');
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $content = file_get_contents($path);
    if (!is_string($content) || $content === '') {
        return [];
    }

    $entries = [];
    foreach (preg_split('/\R/', trim($content)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $context = maskLogValueForUi($decoded['context'] ?? []);
            $entries[] = [
                'timestamp' => (string) ($decoded['timestamp'] ?? ''),
                'message' => (string) ($decoded['message'] ?? ''),
                'details' => compactJsonForUi($context),
            ];
            continue;
        }

        $maskedLine = preg_replace('/("?(?:pass|password|token|secret|key)"?\\s*[:=]\\s*)("[^"]*"|[^\\s,}]+)/i', '$1"***"', $line);
        $entries[] = [
            'timestamp' => '',
            'message' => 'Logzeile',
            'details' => is_string($maskedLine) ? $maskedLine : $line,
        ];
    }

    return $entries;
}

function install(array $input): array
{
    if (!requiredChecksPass()) {
        throw new RuntimeException('Systemcheck nicht bestanden. Bitte Voraussetzungen korrigieren.');
    }

    logInstall('Installation gestartet', ['package_type' => $input['package_type']]);
    $pdo = testDatabaseConnection($input['db']);
    $metadata = loadReleaseMetadata();
    $package = packageFromMetadata($metadata, (string) $input['package_type']);

    $paths = installPaths();
    $tmpBase = projectPath('var/install-tmp-' . bin2hex(random_bytes(6)));
    $extractDir = $tmpBase . '/extract';
    try {
        $packagePath = downloadPackage($package['url'], $package['sha256'], $tmpBase);
        extractPackageSafe($packagePath, $extractDir);
        $packageMetadata = readPackageMetadata($extractDir);
        $selectedModules = selectedModuleDirectories($packageMetadata, $input['enabled_modules']);
        removeUnselectedModules($extractDir, $packageMetadata, $selectedModules);

        // Sicherheit: erst nach erfolgreichem Download, Hashcheck und sicherem
        // Entpacken werden Dateien in das Zielverzeichnis kopiert.
        recursiveCopy($extractDir, $paths['project_root'], ['install.php']);
        if ($input['package_type'] === 'source') {
            runComposerInstall($paths['project_root']);
        }
        writeEnvFile($input['db']);
        runSchema($pdo);
        seedSelectedModules($pdo, $packageMetadata, $selectedModules);
        createAdminUser($pdo, $input['admin']);
        writeInstallLock($metadata, (string) $input['package_type'], $selectedModules);
    } finally {
        removeDirectory($tmpBase);
    }

    logInstall('Installation abgeschlossen');
    $selfDeleted = false;
    $selfPath = __FILE__;

    // Sicherheit: Nach erfolgreicher Installation soll der Bootstrapper nicht
    // weiter öffentlich erreichbar bleiben. Falls Löschen scheitert, zeigt die
    // UI einen deutlichen manuellen Löschhinweis.
    if (is_file($selfPath)) {
        $selfDeleted = @unlink($selfPath);
    }

    return [
        'metadata' => $metadata,
        'self_deleted' => $selfDeleted,
        'self_path' => $selfPath,
        'root_url' => detectedRootUrl(),
        'install_path' => $paths['project_root'],
        'public_path' => $paths['public_dir'],
        'installer_in_public' => $paths['installer_in_public'],
        'install_log_path' => projectPath('storage/logs/install.log'),
        'install_log_entries' => installLogEntriesForUi(),
    ];
}

$step = (string) ($_GET['step'] ?? 'system');
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'ajax_test_db') {
            $db = validateDatabaseInput();
            testDatabaseConnection($db);
            sendJson(['ok' => true, 'message' => 'Datenbankverbindung erfolgreich.']);
        } elseif ($action === 'test_db') {
            $db = validateDatabaseInput();
            testDatabaseConnection($db);
            $messages[] = 'Datenbankverbindung erfolgreich.';
            $step = 'config';
        } elseif ($action === 'install') {
            $input = validateInstallInput();
            $result = install($input);
            $step = 'done';
        }
    } catch (Throwable $throwable) {
        if ((string) ($_POST['action'] ?? '') === 'ajax_test_db') {
            sendJson(['ok' => false, 'message' => $throwable->getMessage()], 400);
        }
        $errors[] = $throwable->getMessage();
        logInstall('Fehler', ['error' => $throwable->getMessage()]);
        $step = 'config';
    }
}

$checks = systemChecks();
$checkSummary = systemCheckSummary($checks);
$groupedChecks = groupedSystemChecks($checks);
$capabilities = runtimeCapabilities();
$releaseMetadata = loadReleaseMetadataForUi();
$releaseModules = is_array($releaseMetadata) ? packageModules($releaseMetadata) : [];
$form = [
    'package_type' => postString('package_type', 'bundled'),
    'db_host' => postString('db_host', '127.0.0.1'),
    'db_port' => postString('db_port', '3306'),
    'db_name' => postString('db_name', 'modulnest'),
    'db_user' => postString('db_user', 'modulnest'),
    'db_prefix' => postString('db_prefix'),
    'admin_username' => postString('admin_username', 'admin'),
    'admin_name' => postString('admin_name', 'Administrator'),
    'admin_email' => postString('admin_email'),
];
$submittedEnabledModules = $_POST['enabled_modules'] ?? [];
if (!is_array($submittedEnabledModules)) {
    $submittedEnabledModules = [];
}
$submittedEnabledModules = array_map('strval', $submittedEnabledModules);

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ModulNest installieren</title>
    <style>
        :root { color-scheme: dark; --bg:#0b1220; --panel:#151f2e; --panel2:#101827; --line:#2b3a50; --text:#e5eefc; --muted:#9fb2ca; --ok:#41d392; --warn:#ffd166; --bad:#ff6b7a; --accent:#61a5ff; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); line-height:1.5; }
        main { width:min(1040px, calc(100vw - 32px)); margin:32px auto; }
        .header { margin-bottom:24px; }
        .eyebrow { color:var(--muted); text-transform:uppercase; letter-spacing:.08em; font-size:.78rem; font-weight:700; }
        h1 { margin:.2rem 0 .4rem; font-size:clamp(1.8rem, 4vw, 2.7rem); }
        h2 { margin:0 0 1rem; font-size:1.15rem; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:16px; box-shadow:0 18px 48px rgba(0,0,0,.18); }
        .grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px; }
        .checks { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px,1fr)); gap:10px; }
        .check { background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:12px; }
        .check-group { margin-top:14px; }
        .check-group-title { color:var(--muted); text-transform:uppercase; letter-spacing:.06em; font-size:.78rem; font-weight:800; margin:0 0 8px; }
        .check-summary { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:12px; margin-bottom:16px; }
        .summary-pill { border:1px solid var(--line); border-radius:999px; padding:5px 10px; background:var(--panel2); font-size:.92rem; }
        .critical-list { margin:14px 0 0; padding-left:1.1rem; color:#ffd9de; }
        .status { font-weight:700; }
        .ok { color:var(--ok); } .bad { color:var(--bad); } .warn { color:var(--warn); }
        label { display:block; font-weight:650; margin-bottom:6px; }
        input, select { width:100%; border:1px solid var(--line); background:#0d1624; color:var(--text); border-radius:8px; padding:10px 12px; font:inherit; }
        .field { margin-bottom:14px; }
        .form-section { border:1px solid var(--line); border-radius:10px; padding:16px; background:rgba(16,24,39,.58); margin:16px 0; }
        .form-section > legend { float:none; width:auto; padding:0 6px; margin-left:-6px; font-weight:800; font-size:1rem; }
        .form-section-intro { color:var(--muted); margin:.1rem 0 1rem; }
        .section-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px; }
        .db-test-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:4px; }
        .db-test-row .inline-result { flex:1 1 280px; margin:0; }
        .muted { color:var(--muted); }
        .actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:18px; }
        button, .button { border:1px solid #438cff; background:#2f7cff; color:white; border-radius:8px; padding:10px 14px; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; }
        button.secondary { background:transparent; border-color:var(--line); color:var(--text); }
        .alert { border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:12px; background:var(--panel2); }
        .alert.error { border-color:rgba(255,107,122,.5); color:#ffd9de; }
        .alert.success { border-color:rgba(65,211,146,.45); color:#dfffee; }
        .alert.warning { border-color:rgba(255,209,102,.45); color:#fff0c2; }
        .info-box { border:1px solid rgba(97,165,255,.35); border-radius:8px; padding:10px 12px; margin-top:10px; background:rgba(97,165,255,.08); color:#d7e8ff; }
        .inline-result { display:none; min-height:42px; border:1px solid var(--line); border-radius:8px; padding:9px 12px; background:var(--panel2); }
        .inline-result.is-visible { display:block; }
        .inline-result.is-success { border-color:rgba(65,211,146,.45); color:#dfffee; }
        .inline-result.is-error { border-color:rgba(255,107,122,.5); color:#ffd9de; }
        .metadata-url { overflow-wrap:anywhere; word-break:break-word; white-space:normal; }
        .password-meter { height:8px; border-radius:999px; background:#0d1624; border:1px solid var(--line); overflow:hidden; margin-top:8px; }
        .password-meter-bar { width:0; height:100%; background:var(--bad); transition:width .18s ease, background .18s ease; }
        .password-hint { margin-top:6px; font-size:.9rem; color:var(--muted); }
        .password-hint.is-ok { color:var(--ok); }
        .password-hint.is-error { color:var(--bad); }
        .install-log-wrap { max-height:340px; overflow:auto; border:1px solid var(--line); border-radius:8px; background:#0d1624; }
        .install-log-table { width:100%; border-collapse:collapse; font-size:.9rem; }
        .install-log-table th, .install-log-table td { padding:9px 10px; border-bottom:1px solid rgba(159,178,202,.16); vertical-align:top; }
        .install-log-table th { position:sticky; top:0; background:#101827; color:var(--muted); text-align:left; z-index:1; }
        .install-log-table tr:last-child td { border-bottom:0; }
        .install-log-details { max-width:520px; white-space:pre-wrap; overflow-wrap:anywhere; color:#d7e8ff; }
        details { border:1px solid var(--line); border-radius:8px; padding:12px; background:var(--panel2); }
        summary { cursor:pointer; font-weight:700; }
        .module-choice { display:flex; gap:10px; align-items:flex-start; padding:10px 0; border-top:1px solid rgba(159,178,202,.18); }
        .module-choice:first-of-type { border-top:0; }
        .module-choice input { width:auto; margin-top:.3rem; }
        .badge { display:inline-block; border:1px solid var(--line); border-radius:999px; padding:2px 8px; color:var(--muted); font-size:.78rem; }
        code { color:#b7d3ff; }
        @media (max-width: 760px) { .grid, .section-grid { grid-template-columns:1fr; } main { width:min(100vw - 20px, 1040px); margin:18px auto; } .db-test-row { align-items:stretch; } .db-test-row > * { width:100%; } }
    </style>
</head>
<body>
<main>
    <section class="header">
        <div class="eyebrow">ModulNest Bootstrap Installer <?= e(MODULNEST_INSTALLER_VERSION) ?></div>
        <h1>ModulNest installieren</h1>
        <p class="muted">Diese Datei lädt ein geprüftes Release-Paket, richtet die Datenbank ein und erstellt das erste Admin-Konto.</p>
    </section>

    <?php if (isInstalled() && $step !== 'done'): ?>
        <div class="card">
            <h2>Installation bereits vorhanden</h2>
            <p>Es wurde eine bestehende Installation erkannt. Bitte lösche <code>install.php</code> aus Sicherheitsgründen.</p>
            <p class="muted">Erkannte Hinweise: <code>var/install.lock</code>, <code>.env</code> oder bestehende App-Konfiguration.</p>
        </div>
    <?php else: ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php foreach ($messages as $message): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endforeach; ?>

        <?php if ($step === 'done' && is_array($result)): ?>
            <div class="card">
                <h2>Installation abgeschlossen</h2>
                <p>ModulNest wurde installiert. Version: <strong><?= e((string) ($result['metadata']['latest'] ?? '')) ?></strong></p>
                <?php if ((bool) ($result['self_deleted'] ?? false)): ?>
                    <p class="ok">install.php wurde automatisch gelöscht.</p>
                <?php else: ?>
                    <p class="bad"><strong>Wichtig:</strong> install.php konnte nicht automatisch gelöscht werden. Bitte lösche diese Datei manuell:</p>
                    <p><code><?= e((string) ($result['self_path'] ?? __FILE__)) ?></code></p>
                <?php endif; ?>
                <?php if (!empty($result['installer_in_public'])): ?>
                    <div class="alert success">
                        <strong>Bereit:</strong> Der Installer wurde bereits im public-Webroot ausgeführt.
                        Du kannst ModulNest jetzt öffnen.
                    </div>
                <?php else: ?>
                    <div class="alert warning">
                        <strong>Nächster Schritt:</strong> Setze den Webroot/DocumentRoot deiner Domain/Subdomain auf das
                        <code>public/</code>-Verzeichnis der Installation.
                    </div>
                <?php endif; ?>
                <p>Pfade:</p>
                <ul>
                    <li>Installationspfad: <code><?= e((string) ($result['install_path'] ?? '/pfad/zu/modulnest')) ?></code></li>
                    <li>
                        <?= !empty($result['installer_in_public']) ? 'Webroot/DocumentRoot:' : 'Webroot/DocumentRoot setzen auf:' ?>
                        <code><?= e((string) ($result['public_path'] ?? '/pfad/zu/modulnest/public')) ?></code>
                    </li>
                </ul>
                <?php if (empty($result['installer_in_public'])): ?>
                    <p class="muted">Unterverzeichnis-Installationen werden in dieser Version noch nicht unterstützt.</p>
                <?php endif; ?>
                <?php if (!empty($result['root_url'])): ?>
                    <p><a class="button" href="<?= e((string) $result['root_url']) ?>">ModulNest öffnen</a></p>
                <?php else: ?>
                    <p class="muted">
                        <?php if (!empty($result['installer_in_public'])): ?>
                            Rufe deine konfigurierte Domain/Subdomain auf.
                        <?php else: ?>
                            Rufe nach der Webroot-Umstellung deine konfigurierte Domain/Subdomain auf.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($result['install_log_entries']) && is_array($result['install_log_entries'])): ?>
                    <details>
                        <summary>Installationslog anzeigen</summary>
                        <p class="muted">Quelle: <code><?= e((string) ($result['install_log_path'] ?? 'storage/logs/install.log')) ?></code></p>
                        <div class="install-log-wrap">
                            <table class="install-log-table">
                                <thead>
                                    <tr>
                                        <th>Zeitpunkt</th>
                                        <th>Meldung</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($result['install_log_entries'] as $entry): ?>
                                        <?php if (is_array($entry)): ?>
                                            <tr>
                                                <td><?= e((string) ($entry['timestamp'] ?? '')) ?></td>
                                                <td><?= e((string) ($entry['message'] ?? '')) ?></td>
                                                <td><code class="install-log-details"><?= e((string) ($entry['details'] ?? '—')) ?></code></td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Systemcheck</h2>
                <p class="muted mb-0">Systemcheck: <?= (int) $checkSummary['passed'] ?>/<?= (int) $checkSummary['total'] ?> Voraussetzungen erfüllt.</p>
                <div class="check-summary">
                    <span class="summary-pill ok"><?= (int) $checkSummary['passed'] ?> OK</span>
                    <span class="summary-pill warn"><?= (int) $checkSummary['warnings'] ?> Warnungen</span>
                    <span class="summary-pill <?= $checkSummary['critical'] > 0 ? 'bad' : 'ok' ?>"><?= (int) $checkSummary['critical'] ?> kritische Fehler</span>
                </div>
                <?php if ($checkSummary['critical'] > 0): ?>
                    <ul class="critical-list">
                        <?php foreach ($checks as $check): ?>
                            <?php if (empty($check['ok']) && !empty($check['required_for_install'])): ?>
                                <li><strong><?= e((string) $check['label']) ?>:</strong> <?= e((string) $check['value']) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <details class="mt-3">
                    <summary>Alle Systemcheck-Details anzeigen</summary>
                    <?php foreach ($groupedChecks as $groupName => $groupChecks): ?>
                        <div class="check-group">
                            <div class="check-group-title"><?= e((string) $groupName) ?></div>
                            <div class="checks">
                                <?php foreach ($groupChecks as $check): ?>
                                    <div class="check">
                                        <div><strong><?= e((string) $check['label']) ?></strong></div>
                                        <div class="status <?= ($check['ok'] ?? false) ? 'ok' : (($check['required_for_install'] ?? false) ? 'bad' : 'warn') ?>">
                                            <?= e((string) $check['value']) ?>
                                        </div>
                                        <div class="muted">Soll: <?= e((string) $check['required']) ?></div>
                                        <?php if (!empty($check['detail'])): ?>
                                            <div class="muted"><code><?= e((string) $check['detail']) ?></code></div>
                                        <?php endif; ?>
                                        <?php if (!empty($check['description'])): ?>
                                            <div class="muted"><?= e((string) $check['description']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </details>
            </div>

            <form method="post" class="card" autocomplete="off" id="installer-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <h2>Installation konfigurieren</h2>
                <fieldset class="form-section">
                    <legend>Installationsmethode</legend>
                    <p class="form-section-intro">Wähle das Release-Paket und prüfe die Quelle, aus der der Installer die Metadaten lädt.</p>
                    <div class="field">
                        <label for="package_type">Pakettyp</label>
                        <select id="package_type" name="package_type">
                            <option value="bundled" <?= $form['package_type'] === 'bundled' ? 'selected' : '' ?>>Bundled Paket, empfohlen</option>
                            <option value="source" <?= $form['package_type'] === 'source' ? 'selected' : '' ?>>Source Paket + Composer, Experten/VPS</option>
                        </select>
                        <div class="muted">
                            Bundled enthält <code>vendor/</code> und benötigt keinen Composer. Empfohlen für die meisten Nutzer. Source/Composer ist für VPS und Entwickler gedacht.
                            <?php if ($capabilities['composer'] === '' || $capabilities['php_cli'] === '' || !$capabilities['proc_open']): ?>
                                Auf diesem System wird bundled empfohlen.
                            <?php else: ?>
                                Composer-Ausführung ist grundsätzlich möglich.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="alert">
                        Metadaten-URL: <code class="metadata-url"><?= e(MODULNEST_METADATA_URL) ?></code>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>Datenbank</legend>
                    <p class="form-section-intro">Diese Zugangsdaten werden nur für Verbindungstest und Installation verwendet. Das Passwort wird nicht ins Formular zurückgeschrieben.</p>
                    <div class="section-grid">
                        <div class="field">
                            <label for="db_host">Datenbank-Host</label>
                            <input id="db_host" name="db_host" value="<?= e($form['db_host']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="db_port">Datenbank-Port</label>
                            <input id="db_port" name="db_port" value="<?= e($form['db_port']) ?>" inputmode="numeric" required>
                        </div>
                        <div class="field">
                            <label for="db_name">Datenbankname</label>
                            <input id="db_name" name="db_name" value="<?= e($form['db_name']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="db_user">Datenbank-Benutzer</label>
                            <input id="db_user" name="db_user" value="<?= e($form['db_user']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="db_password">Datenbank-Passwort</label>
                            <input id="db_password" name="db_password" type="password">
                        </div>
                        <div class="field">
                            <label for="db_prefix">Tabellenpräfix optional</label>
                            <input id="db_prefix" name="db_prefix" value="<?= e($form['db_prefix']) ?>" placeholder="Aktuell leer lassen">
                            <div class="muted">Der aktuelle Modulon-Core unterstützt noch keine prefixed Queries. Für diese Version bitte leer lassen.</div>
                        </div>
                    </div>
                    <div class="db-test-row">
                        <button type="submit" name="action" value="test_db" class="secondary" id="db-test-button">Datenbank testen</button>
                        <div class="inline-result" id="db-test-result" role="status" aria-live="polite"></div>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>Erstes Admin-Konto</legend>
                    <p class="form-section-intro">Dieses Konto erhält initial Adminrechte. Das Passwort muss mindestens 10 Zeichen lang sein.</p>
                    <div class="section-grid">
                        <div class="field">
                            <label for="admin_username">Admin-Benutzername</label>
                            <input id="admin_username" name="admin_username" value="<?= e($form['admin_username']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="admin_name">Admin-Anzeigename</label>
                            <input id="admin_name" name="admin_name" value="<?= e($form['admin_name']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="admin_email">Admin-E-Mail</label>
                            <input id="admin_email" name="admin_email" type="email" value="<?= e($form['admin_email']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="admin_password">Admin-Passwort</label>
                            <input id="admin_password" name="admin_password" type="password" minlength="10" required>
                            <div class="password-meter" aria-hidden="true"><div class="password-meter-bar" id="password-meter-bar"></div></div>
                            <div class="password-hint" id="password-strength" aria-live="polite"></div>
                        </div>
                        <div class="field">
                            <label for="admin_password_confirm">Admin-Passwort bestätigen</label>
                            <input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="10" required>
                            <div class="password-hint" id="password-match" aria-live="polite"></div>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>Sicherheit und Logs</legend>
                    <div class="info-box">
                        Passwörter werden nicht im Installationslog gespeichert.<br>
                        Installationslog: <code><?= e(projectPath('storage/logs/install.log')) ?></code>
                    </div>
                </fieldset>

                <details class="form-section">
                    <summary>Erweiterte Modulauswahl</summary>
                    <p class="muted">Standardmäßig werden alle im Public-Release enthaltenen Module installiert. Pflichtmodule sind nicht abwählbar.</p>
                    <?php if ($releaseModules === []): ?>
                        <p class="warn">Release-Metadaten konnten gerade nicht geladen werden. Bei der Installation werden die Paket-Metadaten erneut geprüft.</p>
                    <?php else: ?>
                        <?php foreach ($releaseModules as $module): ?>
                            <?php
                            $directory = (string) ($module['directory'] ?? '');
                            $name = (string) ($module['name'] ?? $directory);
                            $description = (string) ($module['description'] ?? '');
                            $required = !empty($module['required']);
                            $checked = $required || $submittedEnabledModules === [] || in_array($directory, $submittedEnabledModules, true);
                            ?>
                            <label class="module-choice">
                                <input type="checkbox" name="enabled_modules[]" value="<?= e($directory) ?>" <?= $checked ? 'checked' : '' ?> <?= $required ? 'disabled' : '' ?>>
                                <?php if ($required): ?>
                                    <input type="hidden" name="enabled_modules[]" value="<?= e($directory) ?>">
                                <?php endif; ?>
                                <span>
                                    <strong><?= e($name) ?></strong>
                                    <?php if ($required): ?><span class="badge">erforderlich</span><?php endif; ?>
                                    <?php if ($description !== ''): ?><br><span class="muted"><?= e($description) ?></span><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>
                <div class="actions">
                    <button type="submit" name="action" value="install" id="install-button" <?= requiredChecksPass() ? '' : 'disabled' ?>>Installation starten</button>
                    <div class="password-hint" id="install-password-error" aria-live="polite"></div>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script>
(function () {
    var form = document.getElementById('installer-form');
    var button = document.getElementById('db-test-button');
    var result = document.getElementById('db-test-result');
    var password = document.getElementById('admin_password');
    var confirm = document.getElementById('admin_password_confirm');
    var strength = document.getElementById('password-strength');
    var match = document.getElementById('password-match');
    var meter = document.getElementById('password-meter-bar');
    var installError = document.getElementById('install-password-error');

    if (!form) {
        return;
    }

    function showResult(ok, message) {
        if (!result) {
            return;
        }
        result.textContent = message;
        result.classList.add('is-visible');
        result.classList.toggle('is-success', ok);
        result.classList.toggle('is-error', !ok);
    }

    function passwordScore(value) {
        var score = 0;
        if (value.length >= 10) score += 1;
        if (value.length >= 10) score += 1;
        if (/[a-z]/.test(value)) score += 1;
        if (/[A-Z]/.test(value)) score += 1;
        if (/[0-9]/.test(value)) score += 1;
        if (/[^A-Za-z0-9]/.test(value)) score += 1;
        return Math.min(score, 5);
    }

    function updatePasswordUi(touched) {
        if (!password || !confirm) {
            return true;
        }

        var value = password.value || '';
        var confirmation = confirm.value || '';
        var score = passwordScore(value);
        var labels = ['schwach', 'schwach', 'mittel', 'stark', 'stark', 'sehr stark'];
        var colors = ['var(--bad)', 'var(--bad)', 'var(--warn)', '#9bdc7c', 'var(--ok)', 'var(--ok)'];
        var width = value === '' ? 0 : Math.max(20, score * 20);

        if (meter) {
            meter.style.width = width + '%';
            meter.style.background = colors[score];
        }

        if (strength) {
            strength.classList.remove('is-ok', 'is-error');
            if (value === '') {
                strength.textContent = touched ? 'Mindestens 10 Zeichen erforderlich.' : '';
            } else if (value.length < 10) {
                strength.textContent = 'Mindestens 10 Zeichen erforderlich. Aktuell: ' + value.length + '.';
                strength.classList.add('is-error');
            } else {
                strength.textContent = 'Passwortstärke: ' + labels[score] + '.';
                strength.classList.add('is-ok');
            }
        }

        if (match) {
            match.classList.remove('is-ok', 'is-error');
            if (confirmation === '') {
                match.textContent = touched && value !== '' ? 'Bitte Passwort bestätigen.' : '';
            } else if (value === confirmation) {
                match.textContent = 'Passwörter stimmen überein.';
                match.classList.add('is-ok');
            } else {
                match.textContent = 'Passwörter stimmen nicht überein.';
                match.classList.add('is-error');
            }
        }

        var valid = value.length >= 10 && value === confirmation;
        if (installError) {
            installError.classList.remove('is-ok', 'is-error');
            if (touched && !valid) {
                installError.textContent = 'Bitte Admin-Passwort prüfen: mindestens 10 Zeichen und beide Felder müssen übereinstimmen.';
                installError.classList.add('is-error');
            } else {
                installError.textContent = '';
            }
        }

        return valid;
    }

    if (password && confirm) {
        password.addEventListener('input', function () { updatePasswordUi(false); });
        confirm.addEventListener('input', function () { updatePasswordUi(false); });
    }

    form.addEventListener('submit', function (event) {
        var submitter = event.submitter;
        if (submitter && submitter.id === 'db-test-button') {
            return;
        }
        if (!updatePasswordUi(true)) {
            event.preventDefault();
            if (password && (password.value || '').length < 10) {
                password.focus();
            } else if (confirm) {
                confirm.focus();
            }
        }
    });

    if (!button || !result || !window.fetch || !window.FormData) {
        return;
    }

    button.addEventListener('click', function (event) {
        event.preventDefault();

        var originalText = button.textContent;
        var data = new FormData(form);
        data.set('action', 'ajax_test_db');

        button.disabled = true;
        button.textContent = 'Teste...';
        result.classList.remove('is-visible', 'is-success', 'is-error');
        result.textContent = '';

        fetch(window.location.href, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, message: 'Der Server hat keine gültige JSON-Antwort geliefert.' };
                }).then(function (payload) {
                    if (!response.ok && payload && !payload.message) {
                        payload.message = 'Datenbanktest fehlgeschlagen.';
                    }
                    return payload;
                });
            })
            .then(function (payload) {
                showResult(Boolean(payload.ok), String(payload.message || (payload.ok ? 'Datenbankverbindung erfolgreich.' : 'Datenbanktest fehlgeschlagen.')));
            })
            .catch(function () {
                showResult(false, 'Datenbanktest konnte nicht ausgeführt werden.');
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = originalText;
            });
    });
})();
</script>
</body>
</html>
