<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\ModuleManifest;
use Modulon\Core\Modules\ModulePackageInspector;
use Modulon\Core\Modules\SemVer;
use RuntimeException;

final class CatalogPackageInstaller
{
    private ?CatalogSourceResolver $resolver = null;

    public function __construct(
        private readonly CatalogLoader $loader,
        private readonly CatalogSourceInterface $source,
        private readonly CatalogSnapshot $snapshot,
        private readonly CatalogService $catalog,
        private readonly ModuleLifecycleService $lifecycle,
    ) {}

    public static function fromAggregate(
        CatalogAggregateResult $aggregate,
        CatalogSourceResolver $resolver,
        CatalogService $catalog,
        ModuleLifecycleService $lifecycle,
    ): self {
        $loader = array_values($aggregate->loaders)[0] ?? null;
        $source = array_values($aggregate->sources)[0] ?? null;
        $snapshot = array_values($aggregate->snapshots)[0] ?? null;
        if (!$loader instanceof CatalogLoader || !$source instanceof CatalogSourceInterface || !$snapshot instanceof CatalogSnapshot) {
            throw new RuntimeException('Kein installierbarer Katalog-Snapshot verfügbar.');
        }
        $installer = new self($loader, $source, $snapshot, $catalog, $lifecycle);
        $installer->resolver = $resolver;
        return $installer;
    }

    /** @return array<string,mixed> */
    public function install(string $moduleId, bool $activate = false, ?string $version = null): array
    {
        [$release, $context] = $this->releaseContext($moduleId, null, $version);
        $path = $this->verifiedTemp($moduleId, $release, $context['loader'], $context['source']);
        try {
            return $this->lifecycle->install(
                $path, $release['package']['sha256'], $activate, 'catalog-managed',
                $context['source']->id(), (int) $context['snapshot']->root['sequence'],
            );
        } finally { @unlink($path); }
    }

    /** @return array<string,mixed> */
    public function prepareAdoptionRelease(string $moduleId): array
    {
        [$release, $context] = $this->releaseContext($moduleId);
        $path = $this->verifiedTemp($moduleId, $release, $context['loader'], $context['source']);
        try { return $this->lifecycle->prepareAdoptionRelease($path, $release['package']['sha256']); }
        finally { @unlink($path); }
    }

    public function sourceId(?string $moduleId = null): string
    {
        return $moduleId !== null ? $this->context($moduleId)['source']->id() : $this->source->id();
    }

    public function sequence(?string $moduleId = null): int
    {
        return (int) ($moduleId !== null ? $this->context($moduleId)['snapshot']->root['sequence'] : $this->snapshot->root['sequence']);
    }

    public function adoptionMetadata(string $moduleId): CatalogAdoptionMetadata
    {
        return CatalogAdoptionMetadata::fromModuleIndex($this->context($moduleId)['module']);
    }

    public function manifest(string $moduleId): ModuleManifest
    {
        [$release, $context] = $this->releaseContext($moduleId);
        $path = $this->verifiedTemp($moduleId, $release, $context['loader'], $context['source']);
        try { return (new ModulePackageInspector())->inspect($path, $release['package']['sha256'])['manifest']; }
        finally { @unlink($path); }
    }

    /** @return array<string,mixed> */
    public function update(string $moduleId, ?string $version = null): array
    {
        [$release, $context] = $this->releaseContext($moduleId, null, $version);
        $current = $this->lifecycle->inspect($moduleId);
        $installedVersion = $current['installed_version'] ?? null;
        if ($installedVersion !== null) {
            $comparison = SemVer::parse((string) $release['version'])->compare(SemVer::parse((string) $installedVersion));
            if ($comparison <= 0) {
                throw new RuntimeException('Ein Downgrade oder erneutes Installieren derselben Version per Update ist nicht erlaubt.');
            }
        }
        $path = $this->verifiedTemp($moduleId, $release, $context['loader'], $context['source']);
        try {
            return $this->lifecycle->update(
                $path, $release['package']['sha256'], (int) $context['snapshot']->root['sequence'], $context['source']->id(), false,
            );
        } finally { @unlink($path); }
    }

    /** Explicit, verified source transition for an already catalog-managed module. @return array<string,mixed> */
    public function switchSource(string $moduleId, string $targetSourceId): array
    {
        if ($this->resolver === null) throw new RuntimeException('Quellenwechsel benötigt den Multi-Katalog-Resolver.');
        [$release, $context] = $this->releaseContext($moduleId, $targetSourceId);
        $path = $this->verifiedTemp($moduleId, $release, $context['loader'], $context['source']);
        try {
            $current = $this->lifecycle->inspect($moduleId);
            $comparison = SemVer::parse((string) $release['version'])->compare(SemVer::parse((string) $current['installed_version']));
            if ($comparison === 0) {
                return $this->lifecycle->rebindCatalogSource(
                    $moduleId, $context['source']->id(), (int) $context['snapshot']->root['sequence'],
                    (string) $release['version'], (string) $release['package']['sha256'],
                );
            }
            if ($comparison < 0) throw new RuntimeException('Quellenwechsel auf eine ältere Modulversion ist nicht erlaubt.');
            return $this->lifecycle->update(
                $path, $release['package']['sha256'], (int) $context['snapshot']->root['sequence'], $context['source']->id(), true,
            );
        } finally { @unlink($path); }
    }

    /** @return array{0:array<string,mixed>,1:array{record:array<string,mixed>,source:CatalogSourceInterface,loader:CatalogLoader,snapshot:CatalogSnapshot,module:array<string,mixed>}} */
    private function releaseContext(string $id, ?string $sourceId = null, ?string $targetVersion = null): array
    {
        $context = $this->context($id, $sourceId);
        $module = $context['module'];
        if ($targetVersion !== null && $targetVersion !== '') {
            $candidate = null;
            foreach ($module['releases'] ?? [] as $rel) {
                if ((string) ($rel['version'] ?? '') === $targetVersion) {
                    $candidate = $rel;
                    break;
                }
            }
            if (!is_array($candidate)) {
                throw new RuntimeException("Version {$targetVersion} ist für Modul {$id} im Katalog nicht vorhanden.");
            }
            if (!$this->catalog->isReleaseCompatible($candidate)) {
                throw new RuntimeException($this->catalog->incompatibility($candidate));
            }
            $release = $candidate;
        } elseif ($sourceId === null) {
            $item = $this->catalog->module($id);
            $release = is_array($item) ? ($item['release'] ?? null) : null;
            $compatible = is_array($item) && !empty($item['compatible']);
            $reason = is_array($item) ? ($item['incompatibility_reason'] ?? null) : null;
            if (!$compatible || !is_array($release)) throw new RuntimeException((string) ($reason ?? 'Modul ist nicht kompatibel.'));
        } else {
            $release = $this->catalog->compatibleRelease($context['module']);
            if (!is_array($release)) throw new RuntimeException('Modul ist in der Zielquelle nicht kompatibel.');
        }
        return [$release, $context];
    }

    /** @return array{record:array<string,mixed>,source:CatalogSourceInterface,loader:CatalogLoader,snapshot:CatalogSnapshot,module:array<string,mixed>} */
    private function context(string $moduleId, ?string $sourceId = null): array
    {
        if ($this->resolver !== null) return $this->resolver->context($moduleId, $sourceId);
        $module = $this->snapshot->modules[$moduleId] ?? null;
        if (!is_array($module) || ($sourceId !== null && $sourceId !== $this->source->id())) {
            throw new RuntimeException('Modul ist nicht im verifizierten Katalog.');
        }
        return [
            'record'=>['id'=>$this->source->id(), 'enabled'=>true, 'priority'=>0, 'is_official'=>false],
            'source'=>$this->source, 'loader'=>$this->loader, 'snapshot'=>$this->snapshot, 'module'=>$module,
        ];
    }

    /** @param array<string,mixed> $release */
    private function verifiedTemp(string $moduleId, array $release, CatalogLoader $loader, CatalogSourceInterface $source): string
    {
        $bytes = $loader->verifyPackage($source, $release);
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
