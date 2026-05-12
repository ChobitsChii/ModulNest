<?php

declare(strict_types=1);

namespace Modulon\Modules\DataPortability;

use RuntimeException;
use ZipArchive;

final class DataPortabilityFileCollector
{
    /**
     * @var array<int, array{source:string,zip_path:string,size:int}>
     */
    private array $files = [];

    public function __construct(private readonly string $moduleKey)
    {
    }

    public function addImage(string $sourcePath, string $relativeName): ?string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($relativeName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }

        $size = filesize($sourcePath);
        if ($size === false || $size <= 0 || $size > 10 * 1024 * 1024) {
            return null;
        }

        $safeName = $this->safeRelativePath($relativeName);
        $zipPath = 'modules/' . $this->moduleKey . '/files/' . $safeName;
        $this->files[] = ['source' => $sourcePath, 'zip_path' => $zipPath, 'size' => $size];

        return $safeName;
    }

    public function addToZip(ZipArchive $zip): void
    {
        foreach ($this->files as $file) {
            if (!$zip->addFile($file['source'], $file['zip_path'])) {
                throw new RuntimeException('Datei konnte nicht zum Export hinzugefügt werden: ' . basename($file['source']));
            }
        }
    }

    /**
     * @return array<int, array{path:string,size:int}>
     */
    public function manifestFiles(): array
    {
        return array_map(
            static fn (array $file): array => ['path' => $file['zip_path'], 'size' => $file['size']],
            $this->files
        );
    }

    public function count(): int
    {
        return count($this->files);
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', $path) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $parts[] = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $part) ?: 'file';
        }

        return implode('/', $parts) ?: ('file-' . bin2hex(random_bytes(4)));
    }
}
