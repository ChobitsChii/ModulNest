<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use InvalidArgumentException;

final readonly class CatalogAdoptionMetadata
{
    /**
     * @param array<string,list<string>> $fileHashes
     * @param list<array{key:string,checksum:string}> $baselineMigrations
     */
    private function __construct(
        public string $legacyVersion,
        public array $fileHashes,
        public array $baselineMigrations,
    ) {
    }

    public static function fromModuleIndex(array $module): self
    {
        $raw = $module['adoption'] ?? null;
        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException('Signierte Adoptionsmetadaten fehlen.');
        }
        $keys = array_keys($raw);
        sort($keys);
        if ($keys !== ['baseline_migrations', 'file_hashes', 'legacy_version']) {
            throw new InvalidArgumentException('Signierte Adoptionsmetadaten haben ein ungültiges Schema.');
        }
        $legacyVersion = $raw['legacy_version'] ?? null;
        if (!is_string($legacyVersion) || preg_match('/^\d+\.\d+\.\d+$/D', $legacyVersion) !== 1) {
            throw new InvalidArgumentException('Ungültige Legacy-Version in den Adoptionsmetadaten.');
        }
        $rawHashes = $raw['file_hashes'] ?? null;
        if (!is_array($rawHashes) || $rawHashes === [] || array_is_list($rawHashes)) {
            throw new InvalidArgumentException('Signiertes Adoptionsinventar fehlt.');
        }
        $fileHashes = [];
        foreach ($rawHashes as $path => $hashes) {
            if (!is_string($path)
                || preg_match('#^(?:app/(?:Modules|Views)|bin|public/assets)/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\.(?:php|sql|js|css)$#D', $path) !== 1
            ) {
                throw new InvalidArgumentException('Unsicherer Pfad im signierten Adoptionsinventar.');
            }
            $values = is_string($hashes) ? [$hashes] : $hashes;
            if (!is_array($values) || $values === [] || !array_is_list($values)) {
                throw new InvalidArgumentException('Ungültige Hashliste im signierten Adoptionsinventar.');
            }
            $validated = [];
            foreach ($values as $hash) {
                if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new InvalidArgumentException('Ungültiger SHA-256 im signierten Adoptionsinventar.');
                }
                $validated[] = $hash;
            }
            $fileHashes[$path] = $validated;
        }
        $rawMigrations = $raw['baseline_migrations'] ?? null;
        if (!is_array($rawMigrations) || !array_is_list($rawMigrations)) {
            throw new InvalidArgumentException('Ungültige Baseline-Migrationen in den Adoptionsmetadaten.');
        }
        $baselineMigrations = [];
        $seen = [];
        foreach ($rawMigrations as $migration) {
            if (!is_array($migration) || array_is_list($migration)) {
                throw new InvalidArgumentException('Ungültiger Baseline-Migrationseintrag.');
            }
            $migrationKeys = array_keys($migration);
            sort($migrationKeys);
            if ($migrationKeys !== ['checksum', 'key']) {
                throw new InvalidArgumentException('Ungültiges Baseline-Migrationsschema.');
            }
            $key = $migration['key'] ?? null;
            $checksum = $migration['checksum'] ?? null;
            if (!is_string($key) || preg_match('/^[a-z0-9._-]+$/D', $key) !== 1
                || !is_string($checksum) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1
                || isset($seen[$key])
            ) {
                throw new InvalidArgumentException('Ungültige Baseline-Migrationsmetadaten.');
            }
            $seen[$key] = true;
            $baselineMigrations[] = ['key' => $key, 'checksum' => $checksum];
        }

        return new self($legacyVersion, $fileHashes, $baselineMigrations);
    }
}
