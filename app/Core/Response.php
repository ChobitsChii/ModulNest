<?php

declare(strict_types=1);

namespace Modulon\Core;

final class Response
{
    public function __construct(
        private readonly string $output,
        private readonly int $status = 200,
        private readonly array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
    }

    /**
     * Sendet HTTP-Status, Header und Body an den Client.
     */
    public function send(bool $withBody = true): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($withBody) {
            echo $this->output;
        }
    }

    /**
     * Baut eine Redirect-Response mit Location-Header.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }
}
