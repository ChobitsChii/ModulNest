<?php

declare(strict_types=1);

function link_design_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$appCss = (string) file_get_contents($root . '/public/assets/css/app.css');
$wikiCss = (string) file_get_contents($root . '/public/assets/css/wiki.css');

link_design_assert(substr_count($appCss, '--app-link:') >= 2, 'Light and dark themes must each define a text-link token.');
link_design_assert(substr_count($appCss, '--app-link-hover:') >= 2, 'Light and dark themes must each define a text-link hover token.');
link_design_assert(substr_count($appCss, '--app-link-rgb:') >= 2 && substr_count($appCss, '--app-link-hover-rgb:') >= 2, 'Bootstrap-compatible RGB link tokens must exist in both themes.');
link_design_assert(str_contains($appCss, ':root[data-theme="dark"]') && str_contains($appCss, ':root:not([data-theme])'), 'Dark link tokens must support explicit and system theme modes.');
link_design_assert(str_contains($appCss, '--bs-link-color-rgb: var(--app-link-rgb);') && str_contains($appCss, '--bs-link-hover-color-rgb: var(--app-link-hover-rgb);'), 'Bootstrap links must inherit the calmer application link palette.');
link_design_assert(str_contains($appCss, '.app-body :where(main a:not([class]), .mn-markdown a, .markdown-body a, .app-text-link)'), 'The shared text-link selector must cover plain content and Markdown links.');
link_design_assert(str_contains($appCss, 'text-decoration: none;') && str_contains($appCss, 'text-decoration: underline;'), 'Text links must be quiet at rest and underlined on interaction.');
link_design_assert(str_contains($appCss, ':visited') && str_contains($appCss, 'color: var(--app-link);'), 'Visited text links must retain the application link color.');
link_design_assert(str_contains($appCss, ':focus-visible') && str_contains($appCss, 'outline: 2px solid var(--app-link-hover);'), 'Keyboard focus must remain clearly visible without relying on color alone.');
link_design_assert(str_contains($appCss, '.app-navbar .app-nav-link') && str_contains($appCss, '.modulon-module-nav .nav-link'), 'Existing global and module navigation component styles must remain present.');
link_design_assert(str_contains($wikiCss, '.wiki-nav-link') && str_contains($wikiCss, '.wiki-toc a'), 'Wiki navigation and table-of-contents links must retain their component styling.');

fwrite(STDOUT, "Link design smoke test passed.\n");
