<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\ModuleManifest;
use Modulon\Core\Modules\ModulePackageInspector;
use RuntimeException;

final readonly class CatalogPackageInstaller
{
    public function __construct(
        private CatalogLoader $loader,
        private CatalogSourceInterface $source,
        private CatalogSnapshot $snapshot,
        private CatalogService $catalog,
        private ModuleLifecycleService $lifecycle,
    ) {
    }

    /** @return array<string,mixed> */
    public function install(string $moduleId, bool $activate = false): array
    {
        $release = $this->release($moduleId);
        $path = $this->verifiedTemp($moduleId, $release);
        try {
            return $this->lifecycle->install(
                $path,
                $release['package']['sha256'],
                $activate,
                'catalog-managed',
                $this->source->id(),
                (int) $this->snapshot->root['sequence'],
            );
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    public function prepareAdoptionRelease(string $moduleId): array
    {
        $release = $this->release($moduleId);
        $path = $this->verifiedTemp($moduleId, $release);
        try {
            return $this->lifecycle->prepareAdoptionRelease($path, $release['package']['sha256']);
        } finally {
            @unlink($path);
        }
    }

    public function sourceId(): string
    {
        return $this->source->id();
    }

    public function sequence(): int
    {
        return (int) $this->snapshot->root['sequence'];
    }

    public function manifest(string $moduleId): ModuleManifest
    {
        $release = $this->release($moduleId);
        $path = $this->verifiedTemp($moduleId, $release);
        try {
            return (new ModulePackageInspector())->inspect($path, $release['package']['sha256'])['manifest'];
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    public function update(string $moduleId): array
    {
        $release = $this->release($moduleId);
        $path = $this->verifiedTemp($moduleId, $release);
        try {
            return $this->lifecycle->update($path, $release['package']['sha256'], (int) $this->snapshot->root['sequence']);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    private function release(string $id): array
    {
        $item = $this->catalog->module($id);
        if ($item === null || $item['catalog'] === null) {
            throw new RuntimeException('Modul ist nicht im verifizierten Katalog.');
        }
        if (!$item['compatible'] || !is_array($item['release'])) {
            throw new RuntimeException((string) ($item['incompatibility_reason'] ?? 'Modul ist nicht kompatibel.'));
        }
        return $item['release'];
    }

    /** @param array<string,mixed> $release */
    private function verifiedTemp(string $moduleId, array $release): string
    {
        $bytes = $this->loader->verifyPackage($this->source, $release);
        $path = tempnam(sys_get_temp_dir(), 'modulnest-catalog-');
        if ($path === false || file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Verifiziertes Katalogpaket kann nicht bereitgestellt werden.');
        }
        try {
            $manifest = (new ModulePackageInspector())->inspect($path, $release['package']['sha256'])['manifest'];
            if ($manifest->id !== $moduleId || $manifest->version->value !== $release['version']) {
                throw new RuntimeException('Katalogrelease und Paketmanifest stimmen nicht überein.');
            }
            return $path;
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }
}
