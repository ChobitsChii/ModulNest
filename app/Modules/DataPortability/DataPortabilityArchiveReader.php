<?php

declare(strict_types=1);

namespace Modulon\Modules\DataPortability;

use RuntimeException;
use ZipArchive;

final class DataPortabilityArchiveReader
{
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'jar', 'js', 'mjs', 'html', 'htm',
    ];

    private ZipArchive $zip;

    public function __construct(private readonly string $path)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('ZIP-Datei konnte nicht geöffnet werden.');
        }
        $this->validateEntries();
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    /**
     * @return array<string,mixed>
     */
    public function readJson(string $path): array
    {
        $this->assertSafePath($path);
        $data = $this->zip->getFromName($path);
        if (!is_string($data) || $data === '') {
            throw new RuntimeException('JSON-Datei fehlt im Archiv: ' . $path);
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON-Datei ist ungültig: ' . $path);
        }

        return $decoded;
    }

    /**
     * @return array<int,string>
     */
    public function listFiles(string $prefix): array
    {
        $this->assertSafePath($prefix);
        $files = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (is_string($name) && str_starts_with($name, $prefix) && !str_ends_with($name, '/')) {
                $files[] = $name;
            }
        }

        return $files;
    }

    public function extractImage(string $zipPath, string $targetDirectory, string $preferredName): ?string
    {
        $this->assertSafePath($zipPath);
        $extension = strtolower(pathinfo($zipPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }

        $stat = $this->zip->statName($zipPath);
        if (!is_array($stat) || (int) ($stat['size'] ?? 0) <= 0 || (int) ($stat['size'] ?? 0) > 10 * 1024 * 1024) {
            return null;
        }

        $data = $this->zip->getFromName($zipPath);
        if (!is_string($data) || $data === '') {
            return null;
        }

        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Zielverzeichnis für Dateien konnte nicht erstellt werden.');
        }
        if (!is_writable($targetDirectory)) {
            throw new RuntimeException('Zielverzeichnis für Dateien ist nicht beschreibbar.');
        }

        $base = pathinfo($preferredName, PATHINFO_FILENAME);
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base ?: 'imported') ?: 'imported';
        $filename = $base . '.' . $extension;
        $target = rtrim($targetDirectory, '/') . '/' . $filename;
        $counter = 1;
        while (is_file($target)) {
            $filename = $base . '-' . $counter . '.' . $extension;
            $target = rtrim($targetDirectory, '/') . '/' . $filename;
            $counter++;
        }

        $temporary = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $data, LOCK_EX) === false) {
            throw new RuntimeException('Import-Datei konnte nicht geschrieben werden.');
        }
        @chmod($temporary, 0664);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Import-Datei konnte nicht finalisiert werden.');
        }

        return $filename;
    }

    private function validateEntries(): void
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (!is_string($name) || $name === '') {
                throw new RuntimeException('ZIP enthält einen ungültigen Eintrag.');
            }
            $this->assertSafePath($name);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
                throw new RuntimeException('ZIP enthält nicht erlaubte Dateitypen.');
            }
        }
    }

    private function assertSafePath(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('~(^|/)\.\.(/|$)~', $normalized) === 1) {
            throw new RuntimeException('ZIP enthält unsichere Pfade.');
        }
    }
}
