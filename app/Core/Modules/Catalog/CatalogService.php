<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use Modulon\Core\Modules\SemVer;
use Modulon\Core\Modules\VersionConstraint;
use PDO;

final readonly class CatalogService
{
    /** @var array<string,string> */
    private const ADOPTION_ROUTES = [
        'modulnest.wiki' => 'wiki',
        'modulnest.logs' => 'logs',
        'modulnest.systeminfo' => 'systeminfo',
        'modulnest.news' => 'news',
        'modulnest.pages' => 'pages',
        'modulnest.homepage' => 'homepage',
        'modulnest.data-portability' => 'data-portability',
        'modulnest.dashboard' => 'dashboard',
        'modulnest.sneak-preview' => 'sneak-preview',
        'modulnest.tools' => 'tools',
        'modulnest.banking' => 'banking',
        'modulnest.fantasy-cards' => 'fantasy-cards',
        'modulnest.mail' => 'mail',
    ];

    /** @var array<string,array{name:string,description:string}> */
    private const CORE_COMPONENTS = [
        'admin' => ['name' => 'Administration', 'description' => 'Geschützter Administrationsrahmen und Systemverwaltung.'],
        'auth' => ['name' => 'Authentifizierung', 'description' => 'Login, Rollen, Sitzungen und Zugriffsschutz.'],
        'modules' => ['name' => 'Modulverwaltung', 'description' => 'Core-Registry, Discovery und sicherer Modul-Lifecycle.'],
        'profil' => ['name' => 'Profil', 'description' => 'Benutzerkonto, Sicherheit und Core-Benutzereinstellungen.'],
        'updates' => ['name' => 'Updates', 'description' => 'Core-Updates, Wiederherstellung und gemeinsame Paketprimitive.'],
    ];

    public function __construct(private PDO $pdo, private string $coreVersion, private CatalogSnapshot $catalog)
    {
    }

    /** @return list<array<string,mixed>> */
    public function modules(): array
    {
        $installations = [];
        $statement = $this->pdo->query(
            'SELECT i.*, m.name AS registry_name, m.description AS registry_description,
                    m.route_prefix, m.access_level, m.is_active
             FROM module_installations i
             JOIN modules m ON m.id = i.module_row_id'
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $installations[(string) $row['module_id']] = $row;
        }

        $nativeRows = $this->nativeRows();
        $nativeByRoute = [];
        foreach ($nativeRows as $row) {
            $nativeByRoute[(string) $row['route_prefix']] = $row;
        }

        $consumedNativeIds = [];
        $result = [];
        foreach ($this->catalog->modules as $id => $module) {
            $current = $installations[$id] ?? null;
            $adoptionRow = null;
            $adoptionRoute = self::ADOPTION_ROUTES[$id] ?? null;
            if ($current === null && $adoptionRoute !== null) {
                $candidate = $nativeByRoute[$adoptionRoute] ?? null;
                if (is_array($candidate) && strtolower((string) $candidate['handler']) === 'native') {
                    $adoptionRow = $candidate;
                    $consumedNativeIds[(int) $candidate['id']] = true;
                }
            }

            $release = $this->latestCompatible($module);
            $latest = $this->latest($module);
            $reason = $release === null ? $this->incompatibility($latest) : null;
            $result[] = $this->catalogView($module, $current, $adoptionRow, $release, $latest, $reason);
            unset($installations[$id]);
        }

        foreach ($installations as $id => $row) {
            $classification = match ((string) $row['origin']) {
                'core' => 'core',
                'legacy' => 'legacy',
                default => 'v2',
            };
            $result[] = [
                'id' => $id,
                'name' => $row['registry_name'],
                'description' => $row['registry_description'],
                'classification' => $classification,
                'classification_label' => $this->classificationLabel($classification),
                'origin' => $row['origin'],
                'origin_label' => $this->originLabel((string) $row['origin']),
                'installed' => $row['installed_version'] !== null,
                'v2_installed' => $classification === 'v2' && $row['installed_version'] !== null,
                'retained' => (bool) $row['retained_data'],
                'active' => (bool) $row['is_active'],
                'installed_version' => $row['installed_version'],
                'available_version' => null,
                'latest_version' => null,
                'latest_update_compatible' => true,
                'update_incompatibility_reason' => null,
                'update_available' => false,
                'compatible' => true,
                'incompatibility_reason' => null,
                'release' => null,
                'catalog' => null,
                'catalog_source_id' => $row['catalog_source_id'] !== null ? (string) $row['catalog_source_id'] : null,
                'data_schema_version' => (int) $row['data_schema_version'],
                'adoption_candidate' => false,
                'adoptable' => false,
                'required' => $classification === 'core',
                'core_version' => $this->coreVersion,
            ];
        }

        foreach ($nativeRows as $row) {
            if (!isset($consumedNativeIds[(int) $row['id']])) {
                $result[] = $this->nativeView($row);
            }
        }
        $result = array_merge($result, $this->virtualCoreRows($nativeByRoute));

        foreach ($result as &$row) {
            $row['data_portability'] = $this->portability(
                (string) $row['id'],
                !empty($row['v2_installed']),
                !empty($row['active']),
            );
        }
        unset($row);

        usort($result, static function (array $left, array $right): int {
            $order = ['core' => 0, 'v1' => 1, 'v2' => 2, 'legacy' => 3, 'local' => 4];
            $classification = ($order[$left['classification']] ?? 9) <=> ($order[$right['classification']] ?? 9);
            return $classification !== 0 ? $classification : strcasecmp((string) $left['name'], (string) $right['name']);
        });
        return $result;
    }

    /** @return array{updates:int,entdecken:int,installiert:int} */
    public function counts(): array
    {
        return [
            'updates' => count($this->updateCandidates()),
            'entdecken' => count($this->discover()),
            'installiert' => count($this->installed()),
        ];
    }

    public function module(string $id): ?array
    {
        foreach ($this->modules() as $module) {
            if ($module['id'] === $id) {
                return $module;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function discover(): array
    {
        return array_values(array_filter(
            $this->modules(),
            static fn (array $module): bool => !$module['installed'] && !$module['retained'] && $module['catalog'] !== null,
        ));
    }

    /** @return list<array<string,mixed>> */
    public function installed(): array
    {
        return array_values(array_filter(
            $this->modules(),
            static fn (array $module): bool => $module['installed']
                || $module['retained']
                || in_array($module['classification'], ['core', 'v1', 'legacy', 'local'], true),
        ));
    }

    /** @return list<array<string,mixed>> */
    public function updates(): array
    {
        return array_values(array_filter(
            $this->modules(),
            static fn (array $module): bool => $module['classification'] === 'v2'
                && $module['v2_installed']
                && $module['update_available'],
        ));
    }

    /** @return list<array<string,mixed>> */
    public function updateCandidates(): array
    {
        return array_values(array_filter($this->modules(), static function (array $module): bool {
            $latest = $module['latest_version'] ?? null;
            $installed = $module['installed_version'] ?? null;
            if (($module['classification'] ?? '') !== 'v2' || empty($module['v2_installed']) || $installed === null || $latest === null) {
                return false;
            }
            return SemVer::parse((string) $latest)->compare(SemVer::parse((string) $installed)) > 0;
        }));
    }

    /** @param array<string,mixed> $module @return array<string,mixed>|null */
    public function compatibleRelease(array $module): ?array
    {
        return $this->latestCompatible($module);
    }

    private function catalogView(array $module, ?array $current, ?array $adoptionRow, ?array $release, ?array $latest, ?string $reason): array
    {
        $installedVersion = $current['installed_version'] ?? null;
        $available = $release['version'] ?? null;
        $update = $installedVersion !== null && $release !== null
            && SemVer::parse($available)->compare(SemVer::parse((string) $installedVersion)) > 0;
        $latestVersion = $latest['version'] ?? null;
        $newerLatestExists = $installedVersion !== null && $latestVersion !== null
            && SemVer::parse((string) $latestVersion)->compare(SemVer::parse((string) $installedVersion)) > 0;
        $latestUpdateCompatible = !$newerLatestExists || ($release !== null && $available === $latestVersion);
        $resources = $current ? $this->resources((string) $module['id']) : [];
        $isAdoption = $current === null && $adoptionRow !== null;
        $origin = $isAdoption ? 'v1' : (string) ($current['origin'] ?? 'catalog-managed');
        $classification = $isAdoption ? 'v1' : 'v2';

        $checkVer = (string) ($installedVersion ?? $available ?? $latestVersion ?? '');
        $channel = (string) ($release['channel'] ?? $latest['channel'] ?? '');
        $isBeta = ($channel === 'beta')
            || ($channel === 'alpha')
            || (bool) preg_match('/-(?:beta|alpha|rc|dev)\b/i', $checkVer);

        return [
            'is_beta' => $isBeta,
            'channel' => $channel !== '' ? $channel : ($isBeta ? 'beta' : 'stable'),
            'id' => $module['id'],
            'name' => $module['name'],
            'description' => $isAdoption ? $adoptionRow['description'] : $module['description'],
            'classification' => $classification,
            'classification_label' => $this->classificationLabel($classification),
            'origin' => $origin,
            'origin_label' => $this->originLabel($origin),
            'installed' => $isAdoption || $installedVersion !== null,
            'v2_installed' => $installedVersion !== null,
            'retained' => (bool) ($current['retained_data'] ?? false),
            'has_owned_data' => $resources !== [],
            'active' => $isAdoption ? (bool) $adoptionRow['is_active'] : (bool) ($current['is_active'] ?? false),
            'installed_version' => $installedVersion,
            'available_version' => $available,
            'latest_version' => $latestVersion,
            'latest_update_compatible' => $latestUpdateCompatible,
            'update_incompatibility_reason' => $newerLatestExists && !$latestUpdateCompatible ? $this->incompatibility($latest) : null,
            'update_available' => !$isAdoption && $update,
            'compatible' => $release !== null,
            'incompatibility_reason' => $reason,
            'release' => $release,
            'catalog' => $module,
            'catalog_source_id' => (string) ($module['_catalog_source_id'] ?? $this->catalog->sourceId),
            'data_schema_version' => (int) ($current['data_schema_version'] ?? 0),
            'last_operation' => $current ? $this->lastOperation((string) $module['id']) : null,
            'resources' => $resources,
            'adoption_candidate' => $isAdoption,
            'adoptable' => $isAdoption,
            'required' => false,
            'core_version' => $this->coreVersion,
        ];
    }

    private function nativeView(array $row): array
    {
        $route = (string) $row['route_prefix'];
        $handler = strtolower((string) $row['handler']);
        $classification = isset(self::CORE_COMPONENTS[$route])
            ? 'core'
            : ($handler === 'legacy' ? 'legacy' : ($handler === 'native' ? 'v1' : 'local'));

        return [
            'id' => ($classification === 'core' ? 'core:' : 'v1:') . $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'classification' => $classification,
            'classification_label' => $this->classificationLabel($classification),
            'origin' => $classification,
            'origin_label' => $this->originLabel($classification),
            'is_beta' => false,
            'channel' => 'stable',
            'installed' => true,
            'v2_installed' => false,
            'retained' => false,
            'has_owned_data' => false,
            'active' => (bool) $row['is_active'],
            'installed_version' => null,
            'available_version' => null,
            'latest_version' => null,
            'latest_update_compatible' => true,
            'update_incompatibility_reason' => null,
            'update_available' => false,
            'compatible' => true,
            'incompatibility_reason' => null,
            'release' => null,
            'catalog' => null,
            'data_schema_version' => 0,
            'adoption_candidate' => false,
            'adoptable' => false,
            'required' => $classification === 'core',
            'core_version' => $this->coreVersion,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function virtualCoreRows(array $nativeByRoute): array
    {
        $rows = [];
        foreach (self::CORE_COMPONENTS as $route => $metadata) {
            if (isset($nativeByRoute[$route])) {
                continue;
            }
            $rows[] = [
                'id' => 'core:modulnest.' . ($route === 'profil' ? 'user' : $route),
                'name' => $metadata['name'],
                'description' => $metadata['description'],
                'classification' => 'core',
                'classification_label' => 'Core',
                'origin' => 'core',
                'origin_label' => 'Core',
                'installed' => true,
                'v2_installed' => false,
                'retained' => false,
                'has_owned_data' => false,
                'active' => true,
                'installed_version' => null,
                'available_version' => null,
                'update_available' => false,
                'compatible' => true,
                'incompatibility_reason' => null,
                'release' => null,
                'catalog' => null,
                'data_schema_version' => 0,
                'adoption_candidate' => false,
                'adoptable' => false,
                'required' => true,
                'core_version' => $this->coreVersion,
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function nativeRows(): array
    {
        return $this->pdo->query(
            'SELECT id, module_key, name, description, route_prefix, handler, is_active
             FROM modules WHERE module_key IS NULL ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function classificationLabel(string $classification): string
    {
        return match ($classification) {
            'core' => 'Core',
            'v1' => 'Modul v1',
            'v2' => 'Modul v2',
            'legacy' => 'Legacy',
            default => 'Lokal',
        };
    }

    private function originLabel(string $origin): string
    {
        return match ($origin) {
            'catalog-managed' => 'Katalog',
            'manual-v2' => 'Manuell',
            'local' => 'Lokal',
            'core' => 'Core',
            'v1' => 'Modul v1',
            'legacy' => 'Legacy',
            default => 'Lokal',
        };
    }

    private function latestCompatible(array $module): ?array
    {
        $valid = array_values(array_filter(
            $module['releases'],
            fn (array $release): bool => VersionConstraint::parse($release['core'])->matches($this->coreVersion)
                && VersionConstraint::parse($release['php'])->matches(PHP_VERSION)
                && $this->depsSatisfied($release['dependencies']),
        ));
        usort($valid, static fn (array $a, array $b): int => SemVer::parse($b['version'])->compare(SemVer::parse($a['version'])));
        return $valid[0] ?? null;
    }

    private function latest(array $module): ?array
    {
        $all = $module['releases'];
        usort($all, static fn (array $a, array $b): int => SemVer::parse($b['version'])->compare(SemVer::parse($a['version'])));
        return $all[0] ?? null;
    }

    private function incompatibility(?array $release): string
    {
        if ($release === null) return 'Kein Release verfügbar.';
        if (!VersionConstraint::parse($release['core'])->matches($this->coreVersion)) return 'Benötigt ModulNest ' . $release['core'] . '.';
        if (!VersionConstraint::parse($release['php'])->matches(PHP_VERSION)) return 'Benötigt PHP ' . $release['php'] . '.';
        foreach ($release['dependencies'] as $id => $range) {
            $issue = $this->dependencyIssue($id, $range);
            if ($issue !== null) return $issue;
        }
        return 'Nicht installierbar.';
    }

    private function depsSatisfied(array $dependencies): bool
    {
        foreach ($dependencies as $id => $range) if ($this->dependencyIssue($id, $range) !== null) return false;
        return true;
    }

    private function dependencyIssue(string $id, string $range): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT i.installed_version, m.is_active FROM module_installations i
             JOIN modules m ON m.id = i.module_row_id
             WHERE i.module_id = ? AND i.installed_version IS NOT NULL'
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return 'Abhängigkeit fehlt: ' . $id . ' ' . $range . '.';
        if ((int) $row['is_active'] !== 1) return 'Abhängigkeit ist deaktiviert: ' . $id . '.';
        if (!VersionConstraint::parse($range)->matches((string) $row['installed_version'])) return 'Abhängigkeit ' . $id . ' erfüllt ' . $range . ' nicht.';
        return null;
    }

    private function lastOperation(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT operation_type, status, phase, error_message, created_at
             FROM module_operations WHERE module_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function resources(string $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT resource_type, resource_key FROM module_data_resources
             WHERE module_id = ? ORDER BY resource_type, resource_key'
        );
        $statement->execute([$id]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function portability(string $id, bool $installed, bool $active): array
    {
        $central = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM modules WHERE route_prefix = 'data-portability' AND is_active = 1"
        )->fetchColumn();
        $supported = false;
        if ($installed) {
            $statement = $this->pdo->prepare(
                'SELECT r.manifest_json FROM module_installations i
                 JOIN module_releases r ON r.module_id = i.module_id AND r.release_id = i.active_release_id
                 WHERE i.module_id = ? AND i.installed_version IS NOT NULL'
            );
            $statement->execute([$id]);
            $manifestJson = $statement->fetchColumn();
            if (is_string($manifestJson)) {
                try {
                    $manifest = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
                    $supported = is_string($manifest['capabilities']['data_portability'] ?? null);
                } catch (\Throwable) {
                    $supported = false;
                }
            }
        }
        return ['supported' => $supported, 'available' => $supported && $active && $central === 1, 'central_active' => $central === 1];
    }
}
