<?php

declare(strict_types=1);

use Modulon\Modules\Wiki\WikiMarkdownRenderer;
use Modulon\Modules\Wiki\WikiController;
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
foreach (['GitHubWikiClient.php', 'WikiMarkdownRenderer.php', 'WikiRepository.php', 'WikiService.php', 'WikiSearchText.php', 'WikiSearchHighlighter.php', 'WikiSearchIndexer.php', 'WikiSearchService.php'] as $file) wiki_assert(is_file($root . '/app/Modules/Wiki/' . $file), "$file missing.");
wiki_assert(!is_file($root . '/app/Modules/Wiki/WikiUserNavigationProvider.php'), 'Wiki must not be added to the personal account dropdown.');
$sourceForView = new ReflectionMethod(WikiController::class, 'sourceForView');
wiki_assert($sourceForView->getReturnType()?->allowsNull() === true, 'A fresh installation without a configured Wiki source must remain an explicit null view state.');

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
wiki_assert(str_contains($admin, 'Dokumentationsordner'), 'Wiki admin must expose the documentation directory configuration.');
wiki_assert(str_contains($admin, 'value="github" selected'), 'A new Wiki configuration must default to GitHub.');
wiki_assert(str_contains($admin, 'data-wiki-directory-picker hidden'), 'The local directory picker must be hidden in the initial GitHub state.');
$localAdmin = View::render('wiki/admin', ['csrf_token' => 'test-token', 'source' => ['source_type'=>'local','docs_root'=>'docs','enabled'=>1,'active_source_type'=>'github','active_repository_owner'=>'ChobitsChii','active_repository_name'=>'ModulNest','active_ref_name'=>'main','last_commit_sha'=>'abcdef','last_sync_status'=>'success'], 'page_count' => 1, 'asset_count' => 0, 'message' => '', 'error' => '']);
wiki_assert(str_contains($localAdmin, 'value="local" selected') && str_contains($localAdmin, 'Die konfigurierte Quelle wurde noch nicht synchronisiert.') && str_contains($localAdmin, 'Konfigurierte Quelle') && str_contains($localAdmin, 'Aktiver Wiki-Stand') && str_contains($localAdmin, 'data-wiki-configured-source') && str_contains($localAdmin, 'data-wiki-active-source'), 'A saved local source must remain visibly configured while a prior GitHub cache remains active.');
wiki_assert(str_contains($localAdmin, 'data-wiki-directory-picker>Ordner auswählen</button>') && !str_contains($localAdmin, 'data-wiki-directory-picker hidden'), 'The local directory picker must be visible in the initial Local state.');
$wikiJavaScript = (string) file_get_contents($root . '/public/assets/js/wiki.js');
$wikiCss = (string) file_get_contents($root . '/public/assets/css/wiki.css');
wiki_assert(str_contains($wikiJavaScript, 'pickerButton.hidden = !local'), 'Client-side source switching must show the picker for Local and hide it for GitHub immediately.');
wiki_assert(str_contains($wikiJavaScript, 'window.setTimeout(()=>run(sequence),delay)') && str_contains($wikiJavaScript, 'controller?.abort()') && str_contains($wikiJavaScript, 'sequence===requestSequence') && str_contains($wikiJavaScript, "Accept:'application/json'") && str_contains($wikiJavaScript, 'document.createTextNode'), 'Live Wiki search must debounce, abort stale requests, reject stale responses and render untrusted snippets through text nodes.');
wiki_assert(str_contains($wikiJavaScript, 'document.body.append(output)') && str_contains($wikiCss, 'position: fixed') && str_contains($wikiCss, 'overflow-wrap: anywhere'), 'Live search results must escape the scrollable sidebar and wrap safely inside a responsive floating panel.');
$wikiModuleSource=(string)file_get_contents($root.'/app/Modules/Wiki/WikiModule.php');
wiki_assert(str_contains($wikiModuleSource, "'/admin/wiki/search/rebuild',[\$this->controller,'rebuildSearch'],'admin'") && str_contains($wikiModuleSource, "'/wiki/search',[\$this->controller,'search'],'user'"), 'Wiki search must use user access while its centrally CSRF-protected rebuild route must be admin-only.');
$searchView=View::render('wiki/search',['search'=>['query'=>'<script>alert(1)</script>','available'=>true,'too_short'=>false,'results'=>[['title'=>'<img onerror=alert(1)> Banking','route_path'=>'safe','context'=>'Wiki Banking','snippet'=>'<svg onload=alert(1)> Banking banking','matched_terms'=>['banking']]]]]);
wiki_assert(!str_contains($searchView,'<script>alert(1)</script>')&&!str_contains($searchView,'<img onerror')&&!str_contains($searchView,'<svg onload'),'Search queries, titles and snippets must be HTML-escaped in the non-JavaScript result view.');
wiki_assert(substr_count($searchView,'<mark>')===4,'Full-page results must safely highlight title, context and every snippet occurrence.');
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
$landingNavigation = (new WikiNavigationBuilder())->build([
    ['relative_path' => 'README.md', 'route_path' => 'index', 'title' => 'Start', 'sort_order' => 10],
    ['relative_path' => 'releases/README.md', 'route_path' => 'releases', 'title' => 'ModulNest Releases', 'sort_order' => 10],
    ['relative_path' => 'releases/1.1.0.md', 'route_path' => 'releases/1.1.0', 'title' => 'ModulNest 1.1.0', 'sort_order' => 20],
], 'releases/1.1.0', 'index');
wiki_assert(($landingNavigation['groups'][0]['landing']['route_path'] ?? '') === 'releases' && array_column($landingNavigation['groups'][0]['pages'], 'route_path') === ['releases/1.1.0'], 'A folder README must become its landing page instead of a duplicate child entry.');
$allLandingNavigation = (new WikiNavigationBuilder())->build([
    ['relative_path' => 'README.md', 'route_path' => 'index', 'title' => 'Start', 'sort_order' => 1],
    ['relative_path' => 'development/README.md', 'route_path' => 'development', 'title' => 'Entwicklung', 'sort_order' => 1],
    ['relative_path' => 'modules/README.md', 'route_path' => 'modules', 'title' => 'Module', 'sort_order' => 1],
    ['relative_path' => 'releases/README.md', 'route_path' => 'releases', 'title' => 'Releases', 'sort_order' => 1],
    ['relative_path' => 'technical/README.md', 'route_path' => 'technical', 'title' => 'Technik', 'sort_order' => 1],
], 'modules', 'index');
foreach ($allLandingNavigation['groups'] as $group) wiki_assert(($group['landing']['route_path'] ?? '') === $group['path'], 'Every documentation folder README must become its own landing page.');
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
wiki_assert(str_contains($navigationView, '<button class="wiki-nav-group-toggle-row"') && str_contains($navigationView, 'data-wiki-nav-toggle') && str_contains($navigationView, 'aria-expanded="true"'), 'Folders without a landing page must remain one accessible toggle button with a no-JavaScript open fallback.');
wiki_assert(str_contains($navigationView, '<h1 id="dashboard-foundation">'), 'The layout page title must retain the stable H1 anchor when the matching Markdown H1 is removed.');
wiki_assert(str_contains($navigationView, 'wiki-toc-level-1') && str_contains($navigationView, 'href="#dashboard-foundation"'), 'Desktop and mobile TOCs must start with the linked page H1 before H2/H3 entries.');
wiki_assert(str_contains($navigationView, '<div class="wiki-toc-title">Auf dieser Seite</div>'), 'The desktop TOC label must remain a non-interactive section heading.');
wiki_assert(str_contains($navigationView, 'Stand abcdef012345') && str_contains($navigationView, 'Synchronisiert am 02.09.2026 21:35:00'), 'Wiki footer metadata must show the commit and sync time without claiming a per-file modification date.');
wiki_assert(\Modulon\Core\DateTimeFormatter::formatUserDateTime('2026-09-02T19:35:00+00:00', 'Europe/Berlin') === '02.09.2026 21:35:00', 'Wiki sync time must use the existing configured timezone formatter.');
$docsReadme = (string) file_get_contents($root . '/docs/README.md');
$docsHtml = $renderer->render($docsReadme, 'README.md');
wiki_assert(str_contains($docsHtml, 'href="/wiki/installation"') && str_contains($docsHtml, 'href="/wiki/development/create-module"'), 'The documentation start page must use working relative Wiki links.');
fwrite(STDOUT, "Wiki module smoke passed.\n");
