#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Creates a small native ModulNest module scaffold. It deliberately has no
 * runtime dependency on the application bootstrap or a database.
 */
final class ModuleGenerator
{
    private const RESERVED = ['admin', 'auth', 'modules'];
    /** @var array<string,bool> */ private array $options = [];
    /** @var array<string,string> */ private array $names = [];
    /** @var array<string,string> */ private array $files = [];
    /** @var array<int,string> */ private array $directories = [];
    /** @var array<int,string> */ private array $warnings = [];
    private bool $json = false;

    public function __construct(private readonly string $root, private readonly array $argv) {}

    public function run(): int
    {
        try {
            $input = $this->parse();
            if ($input === null) { return 0; }
            $this->derive($input);
            $this->plan();
            $this->validate();
            if ($this->options['dry-run']) { $this->success(false); return 0; }
            $this->write();
            $this->success(true);
            return 0;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function parse(): ?string
    {
        $args = array_slice($this->argv, 1);
        $this->options = array_fill_keys(['admin','service','repository','migration','main-navigation','account-navigation','js','css','tests','e2e','dry-run','non-interactive'], false);
        $this->options['access'] = 'user';
        $name = null;
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') { $this->usage(); return null; }
            if (str_starts_with($arg, '--access=')) { $this->options['access'] = substr($arg, 9); continue; }
            if (str_starts_with($arg, '--format=')) { $this->json = substr($arg, 9) === 'json'; if (!in_array(substr($arg, 9), ['text','json'], true)) throw new RuntimeException('Ungültiges Format.'); continue; }
            if ($arg === '--navigation') {
                $this->options['main-navigation'] = true;
                $this->warnings[] = '--navigation is deprecated; use --main-navigation.';
                continue;
            }
            $flag = ltrim($arg, '-');
            if (array_key_exists($flag, $this->options)) { $this->options[$flag] = true; continue; }
            if (str_starts_with($arg, '--')) throw new RuntimeException("Unbekannte Option: {$arg}");
            if ($name !== null) throw new RuntimeException('Genau ein Modulname ist erlaubt.');
            $name = $arg;
        }
        if ($this->json) { $this->options['non-interactive'] = true; }
        if ($this->options['e2e']) { $this->options['tests'] = true; }
        if ($name === null) {
            if ($this->options['non-interactive'] || !defined('STDIN') || !(function_exists('stream_isatty') && stream_isatty(STDIN))) throw new RuntimeException('Modulname fehlt. Nutze: php tools/create-module.php <Name>');
            $name = $this->ask('Modulname');
            $this->options['access'] = $this->askChoice('Zugriff', ['public','user','admin'], 'user');
            foreach (['admin','service','repository','migration'] as $option) $this->options[$option] = $this->askYes($option . ' erzeugen?', false);
            $this->options['main-navigation'] = $this->askYes('Hauptnavigation hinzufügen?', false);
            $this->options['account-navigation'] = $this->askYes('Persönliches Account-Menü hinzufügen?', false);
            foreach (['js','css','tests','e2e'] as $option) $this->options[$option] = $this->askYes($option . ' erzeugen?', false);
        }
        if (!in_array($this->options['access'], ['public','user','admin'], true)) throw new RuntimeException('Zugriff muss public, user oder admin sein.');
        return $name;
    }

    private function derive(string $input): void
    {
        $input = trim($input);
        if ($input === '' || preg_match('/^[\pL\pN _-]+$/u', $input) !== 1) throw new RuntimeException('Ungültiger Modulname. Erlaubt sind Buchstaben, Ziffern, Leerzeichen, - und _.');
        $parts = preg_split('/[\s_-]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) throw new RuntimeException('Ungültiger Modulname.');
        $pascal = implode('', array_map(static fn(string $p): string => ucfirst(strtolower($p)), $parts));
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $pascal) !== 1) throw new RuntimeException('Der abgeleitete PHP-Name ist ungültig.');
        $slug = strtolower(implode('-', $parts));
        $snake = str_replace('-', '_', $slug);
        if (in_array(strtolower($pascal), self::RESERVED, true)) throw new RuntimeException("Reservierter Modulname: {$pascal}");
        $this->names = compact('pascal','slug','snake') + ['display' => implode(' ', array_map(static fn(string $p): string => ucfirst($p), $parts))];
    }

    private function plan(): void
    {
        $n = $this->names; $base = 'app/Modules/' . $n['pascal']; $view = 'app/Views/' . $n['slug'];
        $this->directories = [$base, $view];
        $this->add($base . '/' . $n['pascal'] . 'Module.php', $this->moduleTemplate());
        $this->add($base . '/' . $n['pascal'] . 'Controller.php', $this->controllerTemplate());
        $this->add($view . '/index.php', $this->indexView());
        if ($this->options['admin']) { $this->add($view . '/admin.php', $this->adminView()); $this->add($base . '/' . $n['pascal'] . 'AdminNavigationProvider.php', $this->adminNavigation()); }
        if ($this->options['account-navigation']) $this->add($base . '/' . $n['pascal'] . 'AccountNavigationProvider.php', $this->accountNavigation());
        if ($this->options['service']) $this->add($base . '/' . $n['pascal'] . 'Service.php', $this->serviceTemplate());
        if ($this->options['repository']) $this->add($base . '/' . $n['pascal'] . 'Repository.php', $this->repositoryTemplate());
        if ($this->options['migration']) { $dir = $base . '/Database/Migrations'; $this->directories[] = $dir; $this->add($dir . '/' . $this->migrationFilename() . '.php', $this->migrationTemplate()); }
        if ($this->options['js']) { $this->directories[] = 'public/assets/js'; $this->add('public/assets/js/' . $n['slug'] . '.js', $this->jsTemplate()); }
        if ($this->options['css']) { $this->directories[] = 'public/assets/css'; $this->add('public/assets/css/' . $n['slug'] . '.css', ".{$n['slug']} { max-width: 48rem; }\n"); }
        if ($this->options['tests']) { $this->directories[] = 'tests/unit'; $this->add('tests/unit/' . $n['snake'] . '_module_smoke.php', $this->testTemplate()); }
        if ($this->options['e2e']) { $this->directories[] = 'tests/e2e'; $this->add('tests/e2e/test_' . $n['snake'] . '_module.py', $this->e2eTemplate()); }
        $this->directories = array_values(array_unique($this->directories));
    }

    private function validate(): void
    {
        $moduleDir = $this->root . '/app/Modules/' . $this->names['pascal'];
        if (is_dir($moduleDir)) throw new RuntimeException("Modulordner existiert bereits: {$moduleDir}");
        foreach ($this->directories as $dir) if (is_file($this->root . '/' . $dir)) throw new RuntimeException("Pfad ist eine Datei: {$dir}");
        foreach ($this->files as $path => $_) if (file_exists($this->root . '/' . $path)) throw new RuntimeException("Datei existiert bereits: {$path}");
        foreach (glob($this->root . '/app/Modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (strcasecmp(basename($dir), $this->names['pascal']) === 0) throw new RuntimeException('Kollidierender Modulordner.');
        }
        foreach (glob($this->root . '/app/Modules/*/*Module.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match("/'(?:key|route_prefix)'\s*=>\s*'" . preg_quote($this->names['slug'], '/') . "'/", $source)) throw new RuntimeException('Kollidierender Modul-Key oder Route-Prefix.');
        }
    }

    private function write(): void
    {
        $created = [];
        try {
            foreach ($this->directories as $dir) { $target = $this->root . '/' . $dir; if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) throw new RuntimeException("Verzeichnis konnte nicht erstellt werden: {$dir}"); }
            foreach ($this->files as $path => $content) { $target = $this->root . '/' . $path; if (file_put_contents($target, $content, LOCK_EX) === false) throw new RuntimeException("Datei konnte nicht erstellt werden: {$path}"); $created[] = $target; }
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $file) if (is_file($file)) @unlink($file);
            throw $e;
        }
    }

    private function add(string $path, string $content): void { $this->files[$path] = $content; }
    private function migrationFilename(): string { $base = gmdate('Ymd_His') . '_' . $this->names['snake']; $dir = $this->root . '/app/Modules/' . $this->names['pascal'] . '/Database/Migrations'; $suffix = 1; $candidate = $base; while (is_file($dir . '/' . $candidate . '.php') || isset($this->files[$dir . '/' . $candidate . '.php'])) $candidate = $base . '_' . ++$suffix; return $candidate; }
    private function moduleTemplate(): string { $n=$this->names; $access=$this->options['access']; $admin=$this->options['admin'] ? "        \$router->get('/admin/{$n['slug']}', [\$this->controller, 'admin'], 'admin');\n        \$router->post('/admin/{$n['slug']}/save', [\$this->controller, 'adminSave'], 'admin');\n" : ''; $accountNav=$this->options['account-navigation'] ? "        \$userNavigation->registerProvider(new {$n['pascal']}AccountNavigationProvider());\n" : ''; $adminNav=$this->options['admin'] ? "        \$adminNavigation->registerProvider(new {$n['pascal']}AdminNavigationProvider());\n" : ''; $js=$this->options['js'] ? "        \$router->post('/{$n['slug']}/action', [\$this->controller, 'action'], '{$access}');\n" : ''; $showInHeader=$this->options['main-navigation'] ? 'true' : 'false'; return "<?php\ndeclare(strict_types=1);\nnamespace Modulon\\Modules\\{$n['pascal']};\nuse Modulon\\Core\\{AdminNavigationRegistry,ModuleContext,ModuleSubnavigationRegistry,NativeModuleInterface,Router,UserNavigationRegistry};\nfinal class {$n['pascal']}Module implements NativeModuleInterface {\n public static function metadata(): array { return ['key'=>'{$n['slug']}','name'=>'{$n['display']}','route_prefix'=>'{$n['slug']}','access_level'=>'{$access}','description'=>'{$n['display']} module.','show_in_header'=>{$showInHeader},'show_on_home'=>false]; }\n public static function create(ModuleContext \$context): ?NativeModuleInterface { return new self(new {$n['pascal']}Controller(\$context->session)); }\n public function __construct(private readonly {$n['pascal']}Controller \$controller) {}\n public function key(): string { return '{$n['slug']}'; } public function routePrefix(): string { return '{$n['slug']}'; }\n public function registerNavigation(ModuleSubnavigationRegistry \$moduleNavigation, AdminNavigationRegistry \$adminNavigation, UserNavigationRegistry \$userNavigation): void {\n{$accountNav}{$adminNav} }\n public function registerRoutes(Router \$router): void { \$router->get('/{$n['slug']}', [\$this->controller, 'index'], '{$access}');\n{$js} }\n public function registerAdminRoutes(Router \$router): void {\n{$admin} }\n public function nativeBinding(): array { return ['module_key'=>'{$n['slug']}','internal_name'=>'{$n['pascal']}','controller'=>{$n['pascal']}Controller::class,'implementation_path'=>'app/Modules/{$n['pascal']}/{$n['pascal']}Controller.php','route_binding'=>'GET /{$n['slug']}']; }\n}\n"; }
    private function controllerTemplate(): string { $n=$this->names; $js=$this->options['js'] ? " public function action(Request \$request): Response { return new Response('{\"ok\":true}', 200, ['Content-Type'=>'application/json; charset=UTF-8']); }\n" : ''; $admin=$this->options['admin'] ? " public function admin(Request \$request): Response { return new Response(View::render('{$n['slug']}/admin', ['title'=>'{$n['display']} administration','current_path'=>\$request->path(),'message'=>\$this->session->pullFlash('{$n['snake']}_info')])); }\n public function adminSave(Request \$request): Response { \$this->session->flash('{$n['snake']}_info', 'Saved.'); return Response::redirect('/admin/{$n['slug']}'); }\n" : ''; return "<?php\ndeclare(strict_types=1);\nnamespace Modulon\\Modules\\{$n['pascal']};\nuse Modulon\\Core\\{Request,Response,Session,View};\nfinal class {$n['pascal']}Controller { public function __construct(private readonly Session \$session) {}\n public function index(Request \$request): Response { return new Response(View::render('{$n['slug']}/index', ['title'=>'{$n['display']}','current_path'=>\$request->path()])); }\n{$js}{$admin}}\n"; }
    private function indexView(): string { $n=$this->names; $css=$this->options['css'] ? "<link rel=\"stylesheet\" href=\"/assets/css/{$n['slug']}.css\">\n" : ''; $js=$this->options['js'] ? "<form class=\"{$n['slug']}-csrf\"><?= \\Modulon\\Core\\View::csrfField(\$csrf_token) ?></form><button type=\"button\" class=\"js-{$n['slug']}-action\">JSON action</button>\n<script src=\"/assets/js/{$n['slug']}.js\" defer></script>\n" : ''; return "{$css}<section class=\"{$n['slug']}\"><h1><?= htmlspecialchars(\$title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>\n<p>Generated native module scaffold.</p>\n{$js}</section>\n"; }
    private function adminView(): string { $n=$this->names; return "<section><h1><?= htmlspecialchars(\$title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1><?php if (\$message !== ''): ?><p><?= htmlspecialchars(\$message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?><form method=\"post\" action=\"/admin/{$n['slug']}/save\"><?= \\Modulon\\Core\\View::csrfField(\$csrf_token) ?><button type=\"submit\">Save</button></form></section>\n"; }
    private function serviceTemplate(): string { $n=$this->names; return "<?php\ndeclare(strict_types=1);\nnamespace Modulon\\Modules\\{$n['pascal']};\nfinal class {$n['pascal']}Service { public function label(): string { return '{$n['display']}'; } }\n"; }
    private function repositoryTemplate(): string { $n=$this->names; return "<?php\ndeclare(strict_types=1);\nnamespace Modulon\\Modules\\{$n['pascal']};\nfinal class {$n['pascal']}Repository { public function __construct(private readonly \\PDO \$pdo) {} public function findById(int \$id): ?array { \$stmt=\$this->pdo->prepare('SELECT id FROM {$n['snake']}_items WHERE id = :id LIMIT 1'); \$stmt->execute(['id'=>\$id]); \$row=\$stmt->fetch(); return is_array(\$row) ? \$row : null; } }\n"; }
    private function migrationTemplate(): string { $n=$this->names; $key=pathinfo($this->migrationFilename(), PATHINFO_FILENAME); return "<?php\ndeclare(strict_types=1);\nuse Modulon\\Core\\Database\\{Migration,SchemaHelper};\nreturn new class implements Migration { public function key(): string { return '{$key}'; } public function scope(): string { return 'module'; } public function moduleKey(): ?string { return '{$n['slug']}'; } public function description(): string { return 'Creates {$n['display']} example table.'; } public function up(\\PDO \$pdo, SchemaHelper \$schema): void { if (!\$schema->tableExists('{$n['snake']}_items')) { \$pdo->exec('CREATE TABLE {$n['snake']}_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } } };\n"; }
    private function accountNavigation(): string { $n=$this->names; return "<?php\ndeclare(strict_types=1);\nnamespace Modulon\\Modules\\{$n['pascal']};\nuse Modulon\\Core\\UserNavigationProviderInterface;\nfinal class {$n['pascal']}AccountNavigationProvider implements UserNavigationProviderInterface { public function moduleKey(): string { return '{$n['slug']}'; } public function items(string \$currentPath): array { return [['key'=>'{$n['slug']}','label'=>'{$n['display']}','url'=>'/{$n['slug']}','is_active'=>rtrim(\$currentPath,'/')==='/{$n['slug']}','sort_order'=>900]]; } }\n"; }
    private function adminNavigation(): string { $n=$this->names; return "<?php\ndeclare(strict_types=1);\nnamespace Modulon\\Modules\\{$n['pascal']};\nuse Modulon\\Core\\AdminNavigationProviderInterface;\nfinal class {$n['pascal']}AdminNavigationProvider implements AdminNavigationProviderInterface { public function moduleKey(): string { return '{$n['slug']}'; } public function items(string \$currentPath): array { return [['key'=>'{$n['slug']}','label'=>'{$n['display']}','url'=>'/admin/{$n['slug']}','description'=>'{$n['display']} administration','is_active'=>rtrim(\$currentPath,'/')==='/admin/{$n['slug']}','sort_order'=>900]]; } }\n"; }
    private function jsTemplate(): string { $n=$this->names; return "(() => { const token=document.querySelector('input[name=\"_csrf\"]')?.value||''; document.querySelector('.js-{$n['slug']}-action')?.addEventListener('click', async () => { await fetch('/{$n['slug']}/action',{method:'POST',headers:{'X-CSRF-Token':token,'Accept':'application/json'}}); }); })();\n"; }
    private function testTemplate(): string { $n=$this->names; return "<?php\ndeclare(strict_types=1);\n// Generated smoke starting point for {$n['display']}.\nrequire dirname(__DIR__, 2) . '/vendor/autoload.php';\nif (!is_file(dirname(__DIR__, 2) . '/app/Modules/{$n['pascal']}/{$n['pascal']}Module.php')) { fwrite(STDERR, \"Module file missing\\n\"); exit(1); }\nfwrite(STDOUT, \"{$n['display']} module smoke placeholder passed.\\n\");\n"; }
    private function e2eTemplate(): string { $n=$this->names; return "from urllib.parse import urljoin\n\ndef test_{$n['snake']}_page_reachable(logged_in_page, base_url):\n    page = logged_in_page\n    page.goto(urljoin(base_url.rstrip('/') + '/', '{$n['slug']}'))\n    assert page.url.endswith('/{$n['slug']}')\n"; }
    private function success(bool $written): void { $data=['success'=>true,'written'=>$written,'module'=>$this->names['pascal'],'key'=>$this->names['slug'],'route_prefix'=>$this->names['slug'],'access'=>$this->options['access'],'navigation'=>['main'=>$this->options['main-navigation'],'account'=>$this->options['account-navigation']],'files'=>array_keys($this->files),'directories'=>$this->directories,'next_steps'=>['Open /admin/modules to discover and activate the module.','Run generated and project smoke tests.']]; if ($this->warnings !== []) $data['warnings']=$this->warnings; if ($this->json) { echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL; return; } foreach ($this->warnings as $warning) fwrite(STDERR, "Warning: {$warning}\n"); echo ($written?'Created':'Dry run').": {$data['module']}\n"; foreach ($data['files'] as $file) echo "  {$file}\n"; }
    private function error(string $message): void { if ($this->json) { echo json_encode(['success'=>false,'error'=>$message], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL; } else fwrite(STDERR, "Error: {$message}\n"); }
    private function usage(): void { echo "Usage: php tools/create-module.php <Name> [--access=public|user|admin] [--admin] [--service] [--repository] [--migration] [--main-navigation] [--account-navigation] [--navigation] [--js] [--css] [--tests] [--e2e] [--dry-run] [--non-interactive] [--format=text|json]\n"; }
    private function ask(string $question): string { fwrite(STDOUT, "{$question}: "); $value=fgets(STDIN); if (!is_string($value) || trim($value)==='') throw new RuntimeException('Eingabe fehlt.'); return trim($value); }
    private function askChoice(string $question, array $choices, string $default): string { fwrite(STDOUT, "{$question} [".implode('/', $choices)."] ({$default}): "); $value=trim((string) fgets(STDIN)); return $value===''?$default:$value; }
    private function askYes(string $question, bool $default): bool { fwrite(STDOUT, "{$question} [".($default?'Y/n':'y/N')."]: "); $value=trim((string) fgets(STDIN)); return $value===''?$default:in_array(strtolower($value), ['y','yes','j','ja'], true); }
}

$root = dirname(__DIR__);
exit((new ModuleGenerator($root, $argv))->run());
