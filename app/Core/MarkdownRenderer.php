<?php

declare(strict_types=1);

namespace Modulon\Core;

use League\CommonMark\CommonMarkConverter;
use Throwable;

final class MarkdownRenderer
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    /**
     * $allowImages is deliberately opt-in for controlled consumers such as Wiki.
     * Callers must still rewrite and allow-list every image URL before output.
     */
    public function render(string $markdown, bool $allowImages = false): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        try {
            $html = $this->decorateCodeBlocks((string) $this->converter->convert($markdown));

            return $allowImages ? $html : (preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html);
        } catch (Throwable $exception) {
            error_log('Markdown rendering failed: ' . $exception->getMessage());

            return nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
    }

    private function decorateCodeBlocks(string $html): string
    {
        return preg_replace_callback('/<pre><code(?: class="language-([A-Za-z0-9_+-]+)")?>(.*?)<\\/code><\\/pre>/s', static function (array $match): string {
            $language = strtolower((string) ($match[1] ?? ''));
            $label = match ($language) {
                'js', 'javascript' => 'JavaScript', 'php' => 'PHP', 'json' => 'JSON', 'sh', 'shell', 'bash' => 'Shell',
                'html' => 'HTML', 'css' => 'CSS', 'sql' => 'SQL', 'yml', 'yaml' => 'YAML', 'md', 'markdown' => 'Markdown',
                '' => 'Code', default => strtoupper($language),
            };
            $class = $language === '' ? '' : ' class="language-' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"';
            return '<div class="mn-code-block"><div class="mn-code-toolbar"><span class="mn-code-language">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><button class="mn-code-copy" type="button" aria-live="polite">Kopieren</button></div><pre><code' . $class . '>' . $match[2] . '</code></pre></div>';
        }, $html) ?? $html;
    }
}
