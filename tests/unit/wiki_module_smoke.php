<?php

declare(strict_types=1);

use Modulon\Modules\Wiki\WikiMarkdownRenderer;
use Modulon\Modules\Wiki\WikiModule;
use Modulon\Modules\Wiki\WikiNavigationBuilder;
use Modulon\Core\NativeModuleLoader;
use Modulon\Core\View;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function wiki_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

$root = dirname(__DIR__, 2);
wiki_assert(is_file($root . '/app/Modules/Wiki/WikiModule.php'), 'Wiki module must exist below app/Modules.');
wiki_assert(WikiModule::metadata()['key'] === 'wiki', 'Wiki key must be stable.');
wiki_assert(WikiModule::metadata()['access_level'] === 'user', 'Wiki v1 must be a user module.');
wiki_assert(WikiModule::metadata()['show_in_header'] === true, 'Wiki must be visible as a normal module in main navigation.');
wiki_assert((NativeModuleLoader::discover($root)['wiki'] ?? null) === WikiModule::class, 'Wiki must be auto-discoverable as a native module.');
foreach (['GitHubWikiClient.php', 'WikiMarkdownRenderer.php', 'WikiRepository.php', 'WikiService.php'] as $file) wiki_assert(is_file($root . '/app/Modules/Wiki/' . $file), "$file missing.");
wiki_assert(!is_file($root . '/app/Modules/Wiki/WikiUserNavigationProvider.php'), 'Wiki must not be added to the personal account dropdown.');

$renderer = new WikiMarkdownRenderer();
$html = $renderer->render("# Start\n\n[Module](development/module-spec.md)\n\n![Logo](images/logo.png)\n\n[Bad](javascript:alert(1))", 'index');
wiki_assert(str_contains($html, 'href="/wiki/development/module-spec"'), 'Relative Markdown links must become Wiki routes.');
wiki_assert(str_contains($html, 'src="/wiki/assets/images/logo.png"'), 'Approved local image links must use the controlled asset route.');
wiki_assert(!str_contains($html, 'javascript:'), 'Unsafe link schemes must not survive rendering.');
$nested = $renderer->render('[Sibling](module-spec.md)', 'development/README.md');
wiki_assert(str_contains($nested, 'href="/wiki/development/module-spec"'), 'Directory README links must resolve relative to their source directory.');
$sameTitle = $renderer->renderPage("# ModulNest Konfiguration\n\n## Datenbank\n\n## Datenbank\n\n### Details", 'configuration.md', 'ModulNest Konfiguration');
wiki_assert(!str_contains($sameTitle['html'], '<h1'), 'An identical Markdown H1 must not duplicate the layout page title.');
wiki_assert(str_contains($sameTitle['html'], 'id="datenbank"') && str_contains($sameTitle['html'], 'id="datenbank-2"'), 'Duplicate headings must receive stable, unique anchors.');
wiki_assert(count($sameTitle['toc']) === 3 && $sameTitle['toc'][2]['level'] === 3, 'The page TOC must contain H2 and H3 entries only.');
$differentTitle = $renderer->renderPage("# Zusätzliche Einführung\n\n## Abschnitt", 'configuration.md', 'ModulNest Konfiguration');
wiki_assert(!str_contains($differentTitle['html'], '<h1') && str_contains($differentTitle['html'], '<h2 id="zusatzliche-einfuhrung"'), 'A different Markdown H1 must remain as demoted content instead of creating a second page H1.');
$fileTitle = $renderer->renderPage('Nur Inhalt ohne Überschrift.', 'file-name.md', 'File Name');
wiki_assert(!str_contains($fileTitle['html'], '<h1') && $fileTitle['toc'] === [], 'A filename-derived title without headings must not invent a Markdown H1 or TOC.');
$anchor = $renderer->render("[Sprung](#Überblick-und-Ziele)", 'README.md');
wiki_assert(str_contains($anchor, 'href="#uberblick-und-ziele"'), 'Same-page Markdown links must use the generated safe heading anchor.');

$migration = require $root . '/app/Modules/Wiki/Database/Migrations/20260902_164244_wiki.php';
wiki_assert($migration->scope() === 'module' && $migration->moduleKey() === 'wiki', 'Wiki migration must be versioned and scoped to wiki.');
$admin = View::render('wiki/admin', ['csrf_token' => 'test-token', 'source' => null, 'page_count' => 0, 'asset_count' => 0, 'message' => '', 'error' => '']);
wiki_assert(str_contains($admin, 'class="form-control"') && str_contains($admin, 'class="btn btn-primary"'), 'Wiki admin must use the existing ModulNest form and button classes.');
wiki_assert(str_contains($admin, 'GitHub-Benutzer oder Organisation') && str_contains($admin, 'Branch oder Tag') && str_contains($admin, 'GitHub-URL'), 'Wiki admin labels must be user-facing.');
wiki_assert(str_contains($admin, 'Dokumentationsordner') && str_contains($admin, 'Synchronisationsstatus'), 'Wiki admin must expose complete source status.');
$emptyUser = View::render('wiki/empty', ['is_admin' => false]);
$emptyAdmin = View::render('wiki/empty', ['is_admin' => true]);
wiki_assert(str_contains($emptyUser, 'Für dieses Wiki wurden noch keine Inhalte synchronisiert.') && !str_contains($emptyUser, 'href="/admin/wiki"'), 'Non-admin users must receive a helpful setup state without an admin link.');
wiki_assert(str_contains($emptyAdmin, 'href="/admin/wiki"'), 'Admins must receive the setup link in the empty wiki state.');

$navigation = (new WikiNavigationBuilder())->build([
    ['relative_path' => 'README.md', 'route_path' => 'index', 'title' => 'Dokumentation', 'sort_order' => 10],
    ['relative_path' => 'configuration.md', 'route_path' => 'configuration', 'title' => 'Konfiguration', 'sort_order' => 20],
    ['relative_path' => 'technical/architecture.md', 'route_path' => 'technical/architecture', 'title' => 'Technische Architektur', 'sort_order' => 20],
    ['relative_path' => 'technical/dashboard.md', 'route_path' => 'technical/dashboard', 'title' => 'Dashboard Foundation', 'sort_order' => 10],
    ['relative_path' => 'development/create-module.md', 'route_path' => 'development/create-module', 'title' => 'Modul erstellen', 'sort_order' => 10],
], 'technical/dashboard', 'index');
wiki_assert($navigation['root_route'] === 'index', 'The navigation must retain the detected root route for the fixed Start link.');
wiki_assert(array_column($navigation['groups'], 'label') === ['Development', 'Technical'], 'Wiki folders must form deterministic, separate navigation groups.');
wiki_assert(array_column($navigation['groups'][1]['pages'], 'title') === ['Dashboard Foundation', 'Technische Architektur'], 'Frontmatter order must sort pages within a folder before source order.');
wiki_assert($navigation['groups'][1]['is_active'] === true && $navigation['groups'][1]['pages'][0]['is_active'] === true, 'The active page and its folder group must be marked.');
wiki_assert(array_column($navigation['root_pages'], 'title') === ['Konfiguration'], 'Root files must remain outside folder groups and the root README must be represented only by Start.');
$navigationView = View::render('wiki/index', [
    'page' => ['route_path' => 'technical/dashboard', 'title' => 'Dashboard Foundation'],
    'navigation' => $navigation,
    'content_html' => '<p>Inhalt</p>',
    'toc' => [
        ['id' => 'dashboard-foundation', 'title' => 'Dashboard Foundation', 'level' => 1],
        ['id' => 'grundlagen', 'title' => 'Grundlagen', 'level' => 2],
        ['id' => 'details', 'title' => 'Details', 'level' => 3],
    ],
    'source' => ['last_commit_sha' => 'abcdef0123456789', 'last_sync_at_local' => '02.09.2026 21:35:00'],
    'breadcrumbs' => [],
]);
wiki_assert(str_contains($navigationView, '>Start</a>') && str_contains($navigationView, 'bi-folder2-open'), 'Wiki navigation must render a fixed Start entry and visible folder groups.');
wiki_assert(str_contains($navigationView, 'aria-current="page"') && !str_contains($navigationView, '.md</a>'), 'Wiki navigation must mark the active page and never show Markdown extensions.');
wiki_assert(str_contains($navigationView, '<button class="wiki-nav-group-title"') && str_contains($navigationView, 'data-wiki-nav-toggle') && str_contains($navigationView, 'aria-expanded="true"'), 'The complete folder row must be one accessible toggle button with a no-JavaScript open fallback.');
wiki_assert(str_contains($navigationView, '<h1 id="dashboard-foundation">'), 'The layout page title must retain the stable H1 anchor when the matching Markdown H1 is removed.');
wiki_assert(str_contains($navigationView, 'wiki-toc-level-1') && str_contains($navigationView, 'href="#dashboard-foundation"'), 'Desktop and mobile TOCs must start with the linked page H1 before H2/H3 entries.');
wiki_assert(str_contains($navigationView, '<div class="wiki-toc-title">Auf dieser Seite</div>'), 'The desktop TOC label must remain a non-interactive section heading.');
wiki_assert(str_contains($navigationView, 'Stand abcdef012345') && str_contains($navigationView, 'Synchronisiert am 02.09.2026 21:35:00'), 'Wiki footer metadata must show the commit and sync time without claiming a per-file modification date.');
wiki_assert(\Modulon\Core\DateTimeFormatter::formatUserDateTime('2026-09-02T19:35:00+00:00', 'Europe/Berlin') === '02.09.2026 21:35:00', 'Wiki sync time must use the existing configured timezone formatter.');
$docsReadme = (string) file_get_contents($root . '/docs/README.md');
$docsHtml = $renderer->render($docsReadme, 'README.md');
wiki_assert(str_contains($docsHtml, 'href="/wiki/installation"') && str_contains($docsHtml, 'href="/wiki/development/create-module"'), 'The documentation start page must use working relative Wiki links.');
fwrite(STDOUT, "Wiki module smoke passed.\n");
