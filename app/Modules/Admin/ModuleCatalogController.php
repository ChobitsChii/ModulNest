<?php

declare(strict_types=1);

namespace Modulon\Modules\Admin;

use Modulon\Core\Modules\Catalog\CatalogPackageInstaller;
use Modulon\Core\Modules\Catalog\CatalogService;
use Modulon\Core\Modules\Catalog\CleanInstallModuleService;
use Modulon\Core\Modules\LegacyModuleAdoptionService;
use Modulon\Core\Modules\ModuleAdoptionOperationService;
use Modulon\Core\Modules\ModuleBatchUpdateService;
use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\WikiAdoptionService;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Throwable;

final readonly class ModuleCatalogController
{
    public function __construct(
        private CatalogService $catalog,
        private ModuleLifecycleService $lifecycle,
        private ?CatalogPackageInstaller $installer,
        private Session $session,
        private ?string $catalogWarning = null,
        private bool $testKeyActive = false,
        private ?WikiAdoptionService $wikiAdoption = null,
        private ?LegacyModuleAdoptionService $legacyAdoption = null,
        private ?ModuleAdoptionOperationService $adoptionOperations = null,
        private ?CleanInstallModuleService $cleanInstall = null,
        private ?ModuleBatchUpdateService $batchUpdates = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tab = $request->query('bereich', 'entdecken');
        if (!in_array($tab, ['entdecken', 'installiert', 'updates'], true)) $tab = 'entdecken';
        $discover = $this->withAdoptionPreflight($this->catalog->discover());
        $installed = $this->withAdoptionPreflight($this->catalog->installed());
        $updates = $this->withAdoptionPreflight($this->catalog->updateCandidates());
        $items = match ($tab) {
            'installiert' => $installed,
            'updates' => $updates,
            default => $discover,
        };
        return new Response(View::render('admin/module-catalog/index', [
            'title' => 'Modul-Katalog',
            'admin_section' => 'module-catalog',
            'current_path' => $request->path(),
            'tab' => $tab,
            'modules' => $items,
            'counts' => ['entdecken' => count($discover), 'installiert' => count($installed), 'updates' => count($updates)],
            'message' => $this->session->pullFlash('catalog_info'),
            'error' => $this->session->pullFlash('catalog_error'),
            'catalog_warning' => $this->catalogWarning,
            'test_key_active' => $this->testKeyActive,
            'clean_install' => $request->query('einrichtung') === '1',
            'clean_install_modules' => $this->cleanInstall?->availableModules() ?? [],
            'batch_update_plan' => $this->batchUpdates?->plan() ?? [],
            'batch_update_operation' => $this->batchUpdates?->latest(),
        ]));
    }

    public function detail(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if (preg_match('#^admin/module-catalog/([a-z][a-z0-9-]*\.[a-z][a-z0-9-]*)$#D', $path, $matches) !== 1) {
            return new Response(View::render('errors/404', ['title' => 'Nicht gefunden', 'current_path' => $request->path()]), 404);
        }
        $module = $this->catalog->module($matches[1]);
        if ($module === null) {
            return new Response(View::render('errors/404', ['title' => 'Nicht gefunden', 'current_path' => $request->path()]), 404);
        }
        $module = $this->withAdoptionPreflight([$module])[0];
        $adoptionOperation = $this->adoptionOperations?->latest((string) $module['id']);
        return new Response(View::render('admin/module-catalog/detail', [
            'title' => $module['name'] . ' – Modul-Katalog',
            'admin_section' => 'module-catalog',
            'current_path' => $request->path(),
            'module' => $module,
            'message' => $this->session->pullFlash('catalog_info'),
            'error' => $this->session->pullFlash('catalog_error'),
            'catalog_warning' => $this->catalogWarning,
            'adoption_operation' => $adoptionOperation,
        ]));
    }

    public function operationStatus(Request $request): Response
    {
        $id = (string) $request->query('module_id', '');
        if ($id !== 'modulnest.tools' || $this->adoptionOperations === null) {
            return new Response('{"error":"not_found"}', 404, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $operation = $this->adoptionOperations->latest($id);
        if (!is_array($operation)) {
            return new Response('{"operation":null}', 200, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $payload = array_intersect_key($operation, array_flip([
            'operation_id', 'module_id', 'operation', 'status', 'phase', 'message', 'error_code',
            'completed_files', 'total_files', 'completed_bytes', 'total_bytes', 'started_at', 'updated_at', 'ended_at',
        ]));
        return new Response(json_encode(['operation' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store',
        ]);
    }

    public function batchUpdateStatus(Request $request): Response
    {
        if ($this->batchUpdates === null) return new Response('{"error":"not_found"}', 404, ['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);
        return new Response(json_encode(['operation'=>$this->batchUpdates->latest()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), 200, ['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);
    }

    public function action(Request $request): Response
    {
        $id = (string) $request->input('module_id', '');
        $action = (string) $request->input('action', '');
        if ($action === 'update-selected') {
            try {
                if ($this->batchUpdates === null) throw new \RuntimeException('Der Hintergrund-Lifecycle für Modulupdates ist nicht verfügbar.');
                $raw = $request->inputRaw('module_ids', []);
                $ids = is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
                $this->batchUpdates->start($ids);
                $this->session->flash('catalog_info', 'Die ausgewählten Modulupdates wurden sequenziell im Hintergrund gestartet.');
            } catch (Throwable $error) {$this->session->flash('catalog_error', $error->getMessage());}
            return Response::redirect('/admin/module-catalog?bereich=updates');
        }
        if ($action === 'install-selected') {
            try {
                if ($this->cleanInstall === null) throw new \RuntimeException('Aktuell ist kein verifizierter Katalog verfügbar.');
                $raw = $request->inputRaw('module_ids', []);
                $ids = is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
                $installed = $this->cleanInstall->installSelected($ids);
                $this->session->flash('catalog_info', count($installed) . ' ausgewählte Modul-v2-Pakete wurden installiert und aktiviert.');
            } catch (Throwable $error) {
                $this->session->flash('catalog_error', $error->getMessage());
            }
            return Response::redirect('/admin/module-catalog?bereich=installiert');
        }
        $redirect = '/admin/module-catalog/' . rawurlencode($id);
        try {
            $backgroundStarted = false;
            match ($action) {
                'install' => $this->install($id),
                'adopt' => $backgroundStarted = $this->adopt($id),
                'reinstall' => $this->reinstall($request, $id),
                'update' => $this->update($id),
                'activate' => $this->lifecycle->activate($id),
                'deactivate' => $this->lifecycle->deactivate($id),
                'uninstall' => $this->lifecycle->uninstall($id, false),
                'purge' => $this->purge($request, $id),
                default => throw new \RuntimeException('Unbekannte Modulaktion.'),
            };
            $this->session->flash('catalog_info', $backgroundStarted
                ? 'Die Adoption wurde sicher im Hintergrund gestartet. Der Fortschritt wird auf dieser Seite angezeigt.'
                : $this->success($action));
        } catch (Throwable $error) {
            $this->session->flash('catalog_error', $error->getMessage());
        }
        return Response::redirect($redirect);
    }

    /** @param list<array<string,mixed>> $modules @return list<array<string,mixed>> */
    private function withAdoptionPreflight(array $modules): array
    {
        foreach ($modules as &$module) {
            if (empty($module['adoption_candidate'])) continue;
            $preflight = $this->preflight((string) $module['id']);
            $module['adoption_preflight'] = $preflight;
            $module['adoptable'] = $preflight['eligible'];
            if (!$preflight['eligible']) {
                $replacement = $this->reinstallPreflight((string) $module['id']);
                $module['reinstall_preflight'] = $replacement;
                $module['reinstallable'] = $replacement['eligible'];
            }
        }
        unset($module);
        return $modules;
    }

    /** @return array{eligible:bool,status:string,message:string,technical_detail:?string,first_difference:?string} */
    private function preflight(string $id): array
    {
        if ($id === 'modulnest.wiki' && $this->wikiAdoption !== null) return $this->wikiAdoption->preflight();
        if ($this->legacyAdoption !== null && in_array($id, ['modulnest.logs', 'modulnest.systeminfo', 'modulnest.news', 'modulnest.pages', 'modulnest.homepage', 'modulnest.data-portability', 'modulnest.dashboard', 'modulnest.sneak-preview', 'modulnest.tools', 'modulnest.banking'], true)) {
            return $this->legacyAdoption->preflight($id);
        }
        return ['eligible' => false, 'status' => 'unavailable', 'message' => 'Der sichere Adoption-Preflight ist nicht verfügbar.', 'technical_detail' => null, 'first_difference' => null];
    }

    /** @return array{eligible:bool,status:string,message:string,technical_detail:?string,first_difference:?string} */
    private function reinstallPreflight(string $id): array
    {
        if ($id === 'modulnest.wiki' && $this->wikiAdoption !== null && method_exists($this->wikiAdoption, 'reinstallPreflight')) {
            return $this->wikiAdoption->reinstallPreflight();
        }
        if ($this->legacyAdoption !== null) {
            return $this->legacyAdoption->reinstallPreflight($id);
        }
        return ['eligible' => false, 'status' => 'unavailable', 'message' => 'Die sichere Neuinstallation ist in dieser Installation nicht verfügbar.', 'technical_detail' => null, 'first_difference' => null];
    }

    private function install(string $id): void
    {
        if ($this->installer === null) throw new \RuntimeException('Aktuell ist kein verifizierter Katalog verfügbar.');
        $this->installer->install($id, false);
    }

    private function update(string $id): void
    {
        if ($this->installer === null) throw new \RuntimeException('Aktuell ist kein verifizierter Katalog verfügbar.');
        $this->installer->update($id);
    }

    private function purge(Request $request, string $id): void
    {
        if (!hash_equals($id, (string) $request->input('confirm_module_id', ''))) {
            throw new \RuntimeException('Bestätigung für die endgültige Datenlöschung stimmt nicht.');
        }
        $this->lifecycle->uninstall($id, true);
    }

    private function adopt(string $id): bool
    {
        $preflight = $this->preflight($id);
        if (!$preflight['eligible']) throw new \RuntimeException($preflight['message']);
        if ($id === 'modulnest.wiki') {
            if ($this->wikiAdoption === null) throw new \RuntimeException('Wiki-Adoption ist in dieser Installation nicht verfügbar.');
            $this->wikiAdoption->adopt();
            return false;
        }
        if ($this->legacyAdoption === null) throw new \RuntimeException('Moduladoption ist in dieser Installation nicht verfügbar.');
        if ($id === 'modulnest.tools') {
            if ($this->adoptionOperations === null) throw new \RuntimeException('Die Hintergrundadoption ist nicht verfügbar.');
            $this->adoptionOperations->start($id);
            return true;
        }
        $this->legacyAdoption->adopt($id);
        return false;
    }

    private function reinstall(Request $request, string $id): void
    {
        if (!hash_equals($id, (string) $request->input('confirm_module_id', ''))) {
            throw new \RuntimeException('Bestätigung für die Neuinstallation stimmt nicht.');
        }
        $preflight = $this->preflight($id);
        if ($preflight['eligible']) {
            throw new \RuntimeException('Für diesen unveränderten Modul-v1-Stand ist die normale Umstellung vorgesehen.');
        }
        $replacement = $this->reinstallPreflight($id);
        if (!$replacement['eligible']) throw new \RuntimeException($replacement['message']);
        if ($id === 'modulnest.wiki') {
            if ($this->wikiAdoption === null || !method_exists($this->wikiAdoption, 'reinstall')) {
                throw new \RuntimeException('Die sichere Wiki-Neuinstallation ist nicht verfügbar.');
            }
            $this->wikiAdoption->reinstall();
            return;
        }
        if ($this->legacyAdoption === null) throw new \RuntimeException('Die sichere Modulneuinstallation ist nicht verfügbar.');
        $this->legacyAdoption->reinstall($id);
    }

    private function success(string $action): string
    {
        return match ($action) {
            'install' => 'Modul wurde installiert und bleibt zunächst deaktiviert.',
            'adopt' => 'Das bestehende Modul wurde ohne Datenverlust auf Modul v2 umgestellt.',
            'reinstall' => 'Das vertrauenswürdige Modul-v2-Paket wurde nach geprüftem Backup mit den vorhandenen Daten neu installiert.',
            'update' => 'Modul wurde erfolgreich aktualisiert.',
            'activate' => 'Modul wurde aktiviert.',
            'deactivate' => 'Modul wurde deaktiviert.',
            'uninstall' => 'Modulcode wurde entfernt; registrierte Moduldaten bleiben erhalten.',
            'purge' => 'Moduldaten und Lifecycle-Eintrag wurden endgültig gelöscht.',
            default => 'Aktion abgeschlossen.',
        };
    }
}
