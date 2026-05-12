<?php

declare(strict_types=1);

use Modulon\Core\MarkdownRenderer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function markdown_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$renderer = new MarkdownRenderer();

$normal = $renderer->render("**fett**\n\n*kursiv*\n\n- Punkt\n\n`app/Config/version.php`\n\n[ModulNest](https://github.com/ChobitsChii/ModulNest)");
markdown_assert(str_contains($normal, '<strong>fett</strong>'), 'Bold Markdown wurde nicht gerendert.');
markdown_assert(str_contains($normal, '<em>kursiv</em>'), 'Italic Markdown wurde nicht gerendert.');
markdown_assert(str_contains($normal, '<li>Punkt</li>'), 'Listen wurden nicht gerendert.');
markdown_assert(str_contains($normal, '<code>app/Config/version.php</code>'), 'Inline-Code wurde nicht gerendert.');
markdown_assert(str_contains($normal, 'href="https://github.com/ChobitsChii/ModulNest"'), 'Sicherer https-Link wurde nicht gerendert.');

$script = $renderer->render('<script>alert(1)</script>');
markdown_assert(!str_contains($script, '<script'), 'Raw script HTML wurde nicht entfernt.');

$unsafeLink = $renderer->render('[x](javascript:alert(1))');
markdown_assert(!str_contains($unsafeLink, 'javascript:'), 'Unsicherer javascript-Link wurde nicht entfernt.');
markdown_assert(!preg_match('/<a\s/i', $unsafeLink), 'Unsicherer Link wurde klickbar gerendert.');

$html = $renderer->render('<div onclick="alert(1)">Text</div>');
markdown_assert(!str_contains($html, '<div'), 'Raw HTML wurde nicht entfernt.');
markdown_assert(!str_contains($html, 'onclick'), 'HTML-Attribut wurde nicht entfernt.');

$image = $renderer->render('![x](https://example.com/tracker.png)');
markdown_assert(!preg_match('/<img\s/i', $image), 'Markdown-Bilder wurden nicht entfernt.');

$deep = str_repeat('> ', 30) . 'tief';
$deepHtml = $renderer->render($deep);
markdown_assert($deepHtml !== '', 'Tiefe Verschachtelung crasht oder rendert leer.');

fwrite(STDOUT, "Markdown renderer smoke test passed.\n");
