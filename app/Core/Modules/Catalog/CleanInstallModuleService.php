<?php

declare(strict_types=1);

namespace Modulon\Core\Modules\Catalog;

use Modulon\Core\Modules\ModuleLifecycleService;
use RuntimeException;
use Throwable;

final readonly class CleanInstallModuleService
{
    /** @var list<string> */
    public const DEFAULT_MODULE_IDS = [
        'modulnest.banking',
        'modulnest.dashboard',
        'modulnest.data-portability',
        'modulnest.homepage',
        'modulnest.logs',
        'modulnest.news',
        'modulnest.pages',
        'modulnest.sneak-preview',
        'modulnest.systeminfo',
        'modulnest.tools',
        'modulnest.wiki',
    ];

    public function __construct(
        private CatalogService $catalog,
        private CatalogPackageInstaller $installer,
        private ModuleLifecycleService $lifecycle,
    ) {
    }

    /** @return list<array{id:string,name:string,description:string,version:string,default_selected:bool}> */
    public function availableModules(): array
    {
        $available = [];
        foreach ($this->catalog->modules() as $module) {
            $id = (string) ($module['id'] ?? '');
            if ($id === '' || !is_array($module['release'] ?? null) || empty($module['compatible'])) {
                continue;
            }
            $available[] = [
                'id' => $id,
                'name' => (string) ($module['name'] ?? $id),
                'description' => (string) ($module['description'] ?? ''),
                'version' => (string) ($module['available_version'] ?? ''),
                'default_selected' => in_array($id, self::DEFAULT_MODULE_IDS, true),
            ];
        }

        usort($available, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        return $available;
    }

    /** @param list<string> $moduleIds @return list<array<string,mixed>> */
    public function installSelected(array $moduleIds): array
    {
        $selected = array_values(array_unique(array_map('strval', $moduleIds)));
        $available = array_column($this->availableModules(), null, 'id');
        foreach ($selected as $id) {
            if (!isset($available[$id])) {
                throw new RuntimeException('Ausgewähltes Modul ist nicht im verifizierten kompatiblen Katalog: ' . $id);
            }
        }

        $installed = [];
        try {
            foreach ($selected as $id) {
                $installed[] = $this->installer->install($id, true);
            }
            return $installed;
        } catch (Throwable $error) {
            foreach (array_reverse($installed) as $module) {
                $id = (string) ($module['module_id'] ?? '');
                if ($id === '') continue;
                try {
                    $this->lifecycle->uninstall($id, true);
                } catch (Throwable) {
                    // The original install error remains authoritative. Any failed
                    // cleanup is retained in the lifecycle journal for recovery.
                }
            }
            throw $error;
        }
    }
}
