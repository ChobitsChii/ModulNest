<?php

declare(strict_types=1);

namespace Modulon\Core;

use Closure;
use RuntimeException;

final class Response
{
    public function __construct(
        private readonly string $output,
        private readonly int $status = 200,
        private readonly array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
        private readonly ?Closure $streamer = null,
    ) {
    }

    /**
     * Sendet HTTP-Status, Header und Body an den Client.
     */
    public function send(bool $withBody = true): void
    {
        // An explicit reason phrase keeps non-standard but intentional statuses
        // such as CSRF's 419 intact with Apache's PHP SAPI. Emitting every status
        // this way also keeps repeated Response::send() calls deterministic in tests.
        $protocolCandidate = (string) ($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
        $protocol = preg_match('/^HTTP\/[0-9.]+$/D', $protocolCandidate) === 1
            ? $protocolCandidate
            : 'HTTP/1.1';
        header($protocol . ' ' . $this->status . ' ' . self::reasonPhrase($this->status), true, $this->status);
        SecurityHeaders::apply();

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if (!$withBody) {
            return;
        }

        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }

        echo $this->output;
    }

    /**
     * Baut eine Redirect-Response mit Location-Header.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /**
     * Streamt eine Datei ohne sie komplett in den PHP-Speicher zu laden.
     */
    public static function downloadFile(string $path, string $filename, string $contentType = 'application/octet-stream', bool $deleteAfterSend = false): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Download-Datei ist nicht lesbar.');
        }

        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Download-Dateigröße konnte nicht gelesen werden.');
        }

        $safeFilename = self::safeDownloadFilename($filename);

        return new self('', 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
            'Content-Length' => (string) $size,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], static function () use ($path, $deleteAfterSend): void {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Download-Datei konnte nicht geöffnet werden.');
            }

            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 1048576);
                    if ($chunk === false) {
                        throw new RuntimeException('Download-Datei konnte nicht gelesen werden.');
                    }
                    echo $chunk;
                    flush();
                }
            } finally {
                fclose($handle);
                if ($deleteAfterSend && is_file($path)) {
                    @unlink($path);
                }
            }
        });
    }

    private static function safeDownloadFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? '';
        $filename = trim($filename, '.-');

        return $filename !== '' ? $filename : 'download.bin';
    }

    private static function reasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            410 => 'Gone',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown Status',
        };
    }
}
