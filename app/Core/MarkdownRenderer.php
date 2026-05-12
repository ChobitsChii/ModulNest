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

    public function render(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        try {
            $html = (string) $this->converter->convert($markdown);

            return preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
        } catch (Throwable $exception) {
            error_log('Markdown rendering failed: ' . $exception->getMessage());

            return nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
    }
}
