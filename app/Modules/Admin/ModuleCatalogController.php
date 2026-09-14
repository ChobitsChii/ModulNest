<?php

declare(strict_types=1);

namespace Modulon\Modules\Admin;

use InvalidArgumentException;
use Modulon\Core\DateTimeFormatter;
use Modulon\Core\Modules\Catalog\CatalogCache;
use Modulon\Core\Modules\Catalog\CatalogLoader;
use Modulon\Core\Modules\Catalog\CatalogPackageInstaller;
use Modulon\Core\Modules\Catalog\CatalogService;
use Modulon\Core\Modules\Catalog\CatalogSourceFactory;
use Modulon\Core\Modules\Catalog\CatalogSourceRegistry;
use Modulon\Core\Modules\Catalog\CatalogTrustStore;
use Modulon\Core\Modules\Catalog\CleanInstallModuleService;
use Modulon\Core\Modules\LegacyModuleAdoptionService;
use Modulon\Core\Modules\ModuleAdoptionOperationService;
use Modulon\Core\Modules\ModuleBatchUpdateService;
use Modulon\Core\Modules\ModuleLifecycleService;
use Modulon\Core\Modules\ModulePackageInspector;
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
        private ?LegacyModuleAdoptionService $adoption = null,
        private ?ModuleAdoptionOperationService $adoptionOperations = null,
        private ?CleanInstallModuleService $cleanInstall = null,
        private ?ModuleBatchUpdateService $batchUpdates = null,
        private ?CatalogSourceRegistry $catalogSources = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tab = (string) $request->query('bereich', 'updates');
        if (!in_array($tab, ['updates', 'entdecken', 'installiert', 'quellen'], true)) {
            $tab = 'updates';
        }
        $discover = $this->withAdoptionPreflight($this->catalog->discover());
        $installed = $this->withAdoptionPreflight($this->catalog->installed());
        $updates = $this->withAdoptionPreflight($this->catalog->updates());
        $items = match ($tab) {
            'entdecken' => $discover,
            'installiert' => $installed,
            'updates' => $updates,
            default => $discover,
        };

        $sources = $this->catalogSources !== null
            ? $this->enrichSources($this->catalogSources->list())
            : [];
        $editRaw = trim((string) $request->query('edit', ''));
        $editSource = null;
        if ($editRaw !== '' && $this->catalogSources !== null) {
            try {
                $found = $this->catalogSources->get($editRaw);
                if ($found === null) {
                    $this->session->flash('catalog_error', "Katalogquelle '{$editRaw}' wurde nicht gefunden.");
                } else {
                    $enriched = $this->enrichSources([$found]);
                    $editSource = $enriched[0] ?? $found;
                }
            } catch (Throwable $error) {
                $this->session->flash('catalog_error', 'Fehler beim Laden der Katalogquelle: ' . $error->getMessage());
            }
        }

        $latestBatch = $this->batchUpdates?->latest();
        $dismissedBatchId = (string) $this->session->get('dismissed_batch_update_id', '');
        $batchOperation = null;
        if (is_array($latestBatch) && ($latestBatch['operation_id'] ?? '') !== $dismissedBatchId) {
            $batchOperation = $latestBatch;
        }

        return new Response(View::render('admin/module-catalog/index', [
            'title' => 'Modul-Katalog',
            'admin_section' => 'module-catalog',
            'current_path' => $request->path(),
            'tab' => $tab,
            'modules' => $items,
            'sources' => $sources,
            'edit_source' => $editSource,
            'counts' => [
                'entdecken' => count($discover),
                'installiert' => count($installed),
                'updates' => count($updates),
                'quellen' => count($sources),
            ],
            'message' => $this->session->pullFlash('catalog_info'),
            'error' => $this->session->pullFlash('catalog_error'),
            'catalog_warning' => $this->catalogWarning,
            'test_key_active' => $this->testKeyActive,
            'clean_install' => $request->query('einrichtung') === '1',
            'clean_install_modules' => $this->cleanInstall?->availableModules() ?? [],
            'batch_update_plan' => $this->batchUpdates?->plan() ?? [],
            'batch_update_operation' => $batchOperation,
        ]));
    }

    public function detail(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if (preg_match('#^admin/module-catalog/([a-z][a-z0-9-]*\.[a-z][a-z0-9-]*)$#D', $path, $matches) !== 1) {
            return new Response(View::render('errors/404', ['title' => 'Nicht gefunden', 'current_path' => $request->path()]), 404);
        }

        $id = $matches[1];
        $module = $this->catalog->module($id);
        if ($module === null) {
            return new Response(View::render('errors/404', ['title' => 'Nicht gefunden', 'current_path' => $request->path()]), 404);
        }

        $modules = $this->withAdoptionPreflight([$module]);
        $module = $modules[0] ?? $module;
        $adoptionOperation = $this->adoptionOperations?->latest($id);

        return new Response(View::render('admin/module-catalog/detail', [
            'title' => (string) ($module['name'] ?? $id) . ' – Modul-Katalog',
            'admin_section' => 'module-catalog',
            'current_path' => $request->path(),
            'module' => $module,
            'message' => $this->session->pullFlash('catalog_info'),
            'error' => $this->session->pullFlash('catalog_error'),
            'catalog_warning' => $this->catalogWarning,
            'test_key_active' => $this->testKeyActive,
            'adoption_operation' => $adoptionOperation,
        ]));
    }

    public function operationStatus(Request $request): Response
    {
        $id = trim((string) $request->query('module_id', ''));
        if ($id === '' || $this->adoptionOperations === null) {
            return new Response('{"operation":null}', 200, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']);
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

    public function dismissBatchUpdate(Request $request): Response
    {
        $id = trim((string) $request->input('operation_id', ''));
        if ($id !== '') {
            $this->session->set('dismissed_batch_update_id', $id);
            $this->batchUpdates?->dismiss($id);
        }
        if ($request->expectsJson()) {
            return new Response('{"success":true}', 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $tab = (string) $request->query('bereich', 'updates');
        return Response::redirect('/admin/module-catalog?bereich=' . rawurlencode($tab));
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
                $result = $this->cleanInstall->installSelected($ids);
                $msg = count($result['installed']) . ' Modul(e) erfolgreich installiert.';
                if ($result['skipped'] !== []) $msg .= ' Übersprungen: ' . implode(', ', $result['skipped']) . '.';
                $this->session->flash('catalog_info', $msg);
            } catch (Throwable $error) {$this->session->flash('catalog_error', $error->getMessage());}
            return Response::redirect('/admin/module-catalog?bereich=entdecken');
        }

        try {
            $backgroundStarted = false;
            match ($action) {
                'install' => $this->install($id),
                'update' => $this->update($id),
                'adopt' => $backgroundStarted = $this->adopt($id),
                'reinstall' => $this->reinstall($request, $id),
                'activate' => $this->lifecycle->activate($id),
                'deactivate' => $this->lifecycle->deactivate($id),
                'uninstall' => $this->lifecycle->uninstall($id),
                'purge' => $this->purge($request, $id),
                default => throw new \RuntimeException('Unbekannte Aktion.'),
            };
            $this->session->flash('catalog_info', $backgroundStarted
                ? 'Die Adoption wurde sicher im Hintergrund gestartet. Der Fortschritt wird auf dieser Seite angezeigt.'
                : $this->success($action));
        } catch (Throwable $error) {
            $this->session->flash('catalog_error', $error->getMessage());
        }

        $redirect = (string) $request->input('redirect_to', '');
        if ($redirect !== '' && str_starts_with($redirect, '/admin/module-catalog')) {
            return Response::redirect($redirect);
        }
        return Response::redirect('/admin/module-catalog/' . rawurlencode($id));
    }

    public function addSource(Request $request): Response
    {
        if ($this->catalogSources === null) {
            $this->session->flash('catalog_error', 'Katalogquellen-Verwaltung ist nicht verfügbar.');
            return Response::redirect('/admin/module-catalog?bereich=quellen');
        }
        try {
            $trust = $this->extractTrust($request);
            $this->catalogSources->add(
                (string) $request->input('id', ''),
                (string) $request->input('name', ''),
                (string) $request->input('source_type', 'https'),
                (string) $request->input('location', ''),
                (string) $request->input('enabled', '') === '1',
                (int) $request->input('priority', '0'),
                $trust['trusted_keys'],
                $trust['root_key_ids'],
                'module-catalog-ui',
            );
            $this->session->flash('catalog_info', 'Katalogquelle hinzugefügt.');
        } catch (Throwable $error) {
            $this->session->flash('catalog_error', $error->getMessage());
        }
        return Response::redirect('/admin/module-catalog?bereich=quellen');
    }

    public function updateSource(Request $request): Response
    {
        if ($this->catalogSources === null) {
            $this->session->flash('catalog_error', 'Katalogquellen-Verwaltung ist nicht verfügbar.');
            return Response::redirect('/admin/module-catalog?bereich=quellen');
        }
        try {
            $id = (string) $request->input('id', '');
            $source = $this->catalogSources->get($id);
            if ($source === null) {
                throw new InvalidArgumentException('Katalogquelle wurde nicht gefunden.');
            }
            $changes = $this->collectSourceChanges($source, $request);
            if ($changes === []) {
                $this->session->flash('catalog_info', 'Keine Änderungen an der Katalogquelle vorgenommen.');
            } else {
                $this->catalogSources->update($id, $changes, 'module-catalog-ui');
                $this->session->flash('catalog_info', 'Katalogquelle aktualisiert.');
            }
        } catch (Throwable $error) {
            $this->session->flash('catalog_error', $error->getMessage());
        }
        return Response::redirect('/admin/module-catalog?bereich=quellen');
    }

    public function enableSource(Request $request): Response
    {
        if ($this->catalogSources === null) {
            $this->session->flash('catalog_error', 'Katalogquellen-Verwaltung ist nicht verfügbar.');
            return Response::redirect('/admin/module-catalog?bereich=quellen');
        }
        try {
            $this->catalogSources->enable((string) $request->input('id', ''), 'module-catalog-ui');
            $this->session->flash('catalog_info', 'Katalogquelle aktiviert.');
        } catch (Throwable $error) {
            $this->session->flash('catalog_error', $error->getMessage());
        }
        return Response::redirect('/admin/module-catalog?bereich=quellen');
    }

    public function disableSource(Request $request): Response
    {
        if ($this->catalogSources === null) {
            $this->session->flash('catalog_error', 'Katalogquellen-Verwaltung ist nicht verfügbar.');
            return Response::redirect('/admin/module-catalog?bereich=quellen');
        }
        try {
            $this->catalogSources->disable((string) $request->input('id', ''), 'module-catalog-ui');
            $this->session->flash('catalog_info', 'Katalogquelle deaktiviert.');
        } catch (Throwable $error) {
            $this->session->flash('catalog_error', $error->getMessage());
        }
        return Response::redirect('/admin/module-catalog?bereich=quellen');
    }

    public function deleteSource(Request $request): Response
    {
        if ($this->catalogSources === null) {
            $this->session->flash('catalog_error', 'Katalogquellen-Verwaltung ist nicht verfügbar.');
            return Response::redirect('/admin/module-catalog?bereich=quellen');
        }
        try {
            $id = (string) $request->input('id', '');
            $this->catalogSources->delete($id);
            $this->session->flash('catalog_info', "Katalogquelle '{$id}' gelöscht.");
        } catch (Throwable $error) {
            $this->session->flash('catalog_error', $error->getMessage());
        }
        return Response::redirect('/admin/module-catalog?bereich=quellen');
    }

    public function testSource(Request $request): Response
    {
        $id = trim((string) $request->input('id', ''));
        $isAjax = $request->expectsJson() || strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest';
        try {
            if ($this->catalogSources === null) {
                throw new InvalidArgumentException('Katalogquellen-Verwaltung ist nicht verfügbar.');
            }
            if ($id === '') {
                throw new InvalidArgumentException('Keine Quellen-ID angegeben.');
            }
            $sourceRecord = $this->catalogSources->get($id);
            if ($sourceRecord === null) {
                throw new InvalidArgumentException("Katalogquelle '{$id}' wurde nicht gefunden.");
            }

            $tempCacheDir = sys_get_temp_dir() . '/modulnest-test-catalog-' . bin2hex(random_bytes(6));
            $tempCache = new CatalogCache($tempCacheDir);
            try {
                $factory = new CatalogSourceFactory();
                $source = $factory->source($sourceRecord);
                $trust = $factory->trust($sourceRecord);
                $loader = new CatalogLoader($trust, $tempCache);
                $snapshot = $loader->refresh($source);

                $moduleCount = count($snapshot->modules);
                $sequence = (int) ($snapshot->root['sequence'] ?? 0);
                $expiresAt = (string) ($snapshot->root['expires_at'] ?? '');
                $expiresLocal = $expiresAt !== '' ? DateTimeFormatter::formatUserDateTime($expiresAt) : $expiresAt;

                $message = "Verbindung und Signaturprüfung erfolgreich! (Katalog-Sequenz: {$sequence}, {$moduleCount} Modul(e), gültig bis: {$expiresLocal})";
                if ($isAjax) {
                    return new Response(json_encode([
                        'success' => true,
                        'message' => $message,
                        'sequence' => $sequence,
                        'modules_count' => $moduleCount,
                        'expires_at' => $expiresAt,
                    ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                }
                $this->session->flash('catalog_info', $message);
            } finally {
                if (is_dir($tempCacheDir)) {
                    ModulePackageInspector::removeTree($tempCacheDir);
                }
            }
        } catch (Throwable $error) {
            $errorMessage = $error->getMessage();
            if ($isAjax) {
                return new Response(json_encode([
                    'success' => false,
                    'error' => $errorMessage,
                ], JSON_THROW_ON_ERROR), 400, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
            $this->session->flash('catalog_error', "Prüfung fehlgeschlagen: {$errorMessage}");
        }
        return Response::redirect('/admin/module-catalog?bereich=quellen');
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @return list<array<string,mixed>>
     */
    private function enrichSources(array $sources): array
    {
        foreach ($sources as &$source) {
            $source['is_official'] = ($source['id'] ?? '') === 'modulnest.official';
            $source['last_success_local'] = DateTimeFormatter::formatUserDateTime($source['last_success_at'] ?? '');
            $source['last_error_local'] = DateTimeFormatter::formatUserDateTime($source['last_error_at'] ?? '');
            $source['created_at_local'] = DateTimeFormatter::formatUserDateTime($source['created_at'] ?? '');
            $source['updated_at_local'] = DateTimeFormatter::formatUserDateTime($source['updated_at'] ?? '');

            $keys = is_array($source['trusted_keys'] ?? null) ? $source['trusted_keys'] : [];
            $roots = is_array($source['root_key_ids'] ?? null) ? array_values(array_map('strval', $source['root_key_ids'])) : [];
            $keyDetails = [];

            if ($keys !== []) {
                try {
                    $trustStore = new CatalogTrustStore($keys, $roots);
                    foreach ($keys as $keyId => $pubKey) {
                        $keyIdStr = (string) $keyId;
                        $fp = '';
                        try {
                            $fp = $trustStore->fingerprint($keyIdStr);
                        } catch (Throwable) {
                            $fp = 'Ungültig';
                        }
                        $keyDetails[] = [
                            'key_id' => $keyIdStr,
                            'public_key' => (string) $pubKey,
                            'is_root' => in_array($keyIdStr, $roots, true),
                            'fingerprint' => $fp,
                        ];
                    }
                } catch (Throwable) {
                    foreach ($keys as $keyId => $pubKey) {
                        $keyDetails[] = [
                            'key_id' => (string) $keyId,
                            'public_key' => (string) $pubKey,
                            'is_root' => in_array((string) $keyId, $roots, true),
                            'fingerprint' => '',
                        ];
                    }
                }
            }
            $source['key_details'] = $keyDetails;
        }
        unset($source);
        return $sources;
    }

    /**
     * @return array{trusted_keys: array<string,string>, root_key_ids: list<string>}
     */
    private function extractTrust(Request $request): array
    {
        $keyIds = $request->inputRaw('trust_key_id', []);
        $pubKeys = $request->inputRaw('trust_public_key', []);
        $isRoots = $request->inputRaw('trust_is_root', []);

        if (is_array($keyIds) && is_array($pubKeys) && count($keyIds) > 0) {
            $trustedKeys = [];
            $rootKeyIds = [];
            $rootIndices = is_array($isRoots) ? array_map('intval', $isRoots) : [];

            foreach ($keyIds as $idx => $rawKeyId) {
                $keyId = trim((string) $rawKeyId);
                $pubKey = trim((string) ($pubKeys[$idx] ?? ''));
                if ($keyId === '' || $pubKey === '') continue;
                $trustedKeys[$keyId] = $pubKey;
                if (in_array($idx, $rootIndices, true) || (isset($isRoots[$idx]) && $isRoots[$idx] === '1')) {
                    $rootKeyIds[] = $keyId;
                }
            }
            if ($trustedKeys !== []) {
                return [
                    'trusted_keys' => $trustedKeys,
                    'root_key_ids' => array_values(array_unique($rootKeyIds)),
                ];
            }
        }
        return ['trusted_keys' => [], 'root_key_ids' => []];
    }

    /**
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function collectSourceChanges(array $current, Request $request): array
    {
        $changes = [];
        $name = trim((string) $request->input('name', ''));
        if ($name !== '' && $name !== ($current['name'] ?? '')) {
            $changes['name'] = $name;
        }
        $sourceType = (string) $request->input('source_type', '');
        if ($sourceType !== '' && $sourceType !== ($current['source_type'] ?? '')) {
            $changes['source_type'] = $sourceType;
        }
        $location = trim((string) $request->input('location', ''));
        if ($location !== '' && $location !== ($current['location'] ?? '')) {
            $changes['location'] = $location;
        }
        if ($request->has('enabled_submitted') || $request->has('enabled')) {
            $enabled = (string) $request->input('enabled', '') === '1';
            if ($enabled !== (bool) ($current['enabled'] ?? false)) {
                $changes['enabled'] = $enabled;
            }
        }
        if ($request->has('priority')) {
            $priority = (int) $request->input('priority', '0');
            if ($priority !== (int) ($current['priority'] ?? 0)) {
                $changes['priority'] = $priority;
            }
        }
        $trust = $this->extractTrust($request);
        if ($trust['trusted_keys'] !== []) {
            $changes['trusted_keys'] = $trust['trusted_keys'];
            $changes['root_key_ids'] = $trust['root_key_ids'];
        }
        return $changes;
    }

    /**
     * @param list<array<string,mixed>> $modules
     * @return list<array<string,mixed>>
     */
    private function withAdoptionPreflight(array $modules): array
    {
        foreach ($modules as &$module) {
            $id = (string) ($module['id'] ?? '');
            if ($id === 'modulnest.wiki' && $this->wikiAdoption !== null) {
                $preflight = $this->wikiAdoption->preflight();
                $reinstall = $this->wikiAdoption->reinstallPreflight();
                $module['adoptable'] = $preflight['status'] === 'adoptable';
                $module['adoption_preflight'] = $preflight;
                $module['reinstallable'] = $reinstall['status'] === 'reinstallable';
                $module['reinstall_preflight'] = $reinstall;
                continue;
            }
            if (empty($module['adoption_candidate']) || $this->adoption === null) {
                continue;
            }
            $preflight = $this->adoption->preflight($id);
            $reinstall = $this->adoption->reinstallPreflight($id);
            $module['adoptable'] = $preflight['status'] === 'adoptable';
            $module['adoption_preflight'] = $preflight;
            $module['reinstallable'] = $reinstall['status'] === 'reinstallable';
            $module['reinstall_preflight'] = $reinstall;
        }
        unset($module);
        return $modules;
    }

    private function install(string $id): void
    {
        $module = $this->catalog->module($id);
        if ($module !== null && !empty($module['is_deprecated']) && empty($module['retained'])) {
            throw new \RuntimeException('Dieses Modul ist veraltet und kann nicht mehr neu installiert werden.');
        }
        if ($this->installer === null) {
            throw new \RuntimeException('Aktuell ist kein verifizierter Katalog verfügbar.');
        }
        $this->installer->install($id);
    }

    private function adopt(string $id): bool
    {
        if ($id === 'modulnest.tools') {
            if ($this->adoptionOperations === null) {
                throw new \RuntimeException('Die Hintergrundadoption ist nicht verfügbar.');
            }
            $this->adoptionOperations->start($id);
            return true;
        }
        if ($this->installer === null) {
            throw new \RuntimeException('Aktuell ist kein verifizierter Katalog verfügbar.');
        }
        if ($id === 'modulnest.wiki') {
            if ($this->wikiAdoption === null) throw new \RuntimeException('Wiki-Adoption ist in dieser Installation nicht verfügbar.');
            $this->installer->prepareAdoptionRelease($id);
            $this->wikiAdoption->adopt();
            return false;
        }
        if ($this->adoption === null) {
            throw new \RuntimeException('Adoptionsdienst ist nicht verfügbar.');
        }
        $this->installer->prepareAdoptionRelease($id);
        $this->adoption->adopt($id);
        return false;
    }

    private function reinstall(Request $request, string $id): void
    {
        if ($this->installer === null) {
            throw new \RuntimeException('Aktuell ist kein verifizierter Katalog verfügbar.');
        }
        $confirm = trim((string) $request->input('confirm_module_id', ''));
        if ($confirm !== $id) {
            throw new \RuntimeException('Die Bestätigung stimmt nicht mit der technischen Modul-ID überein.');
        }
        if ($id === 'modulnest.wiki' && $this->wikiAdoption !== null) {
            $this->installer->prepareAdoptionRelease($id);
            $this->wikiAdoption->reinstallFresh();
            return;
        }
        if ($this->adoption === null) {
            throw new \RuntimeException('Adoptionsdienst ist nicht verfügbar.');
        }
        $this->installer->prepareAdoptionRelease($id);
        $this->adoption->reinstallFresh($id);
    }

    private function update(string $id): void
    {
        if ($this->installer === null) {
            throw new \RuntimeException('Aktuell ist kein verifizierter Katalog verfügbar.');
        }
        $this->installer->update($id);
    }

    private function purge(Request $request, string $id): void
    {
        $confirm = trim((string) $request->input('confirm_module_id', ''));
        if ($confirm !== $id) {
            throw new \RuntimeException('Die Bestätigung stimmt nicht mit der Modul-ID überein.');
        }
        $this->lifecycle->uninstall($id, true);
    }

    private function success(string $action): string
    {
        return match ($action) {
            'install' => 'Modul wurde erfolgreich installiert.',
            'adopt' => 'Modul wurde erfolgreich auf Version 2 umgestellt.',
            'reinstall' => 'Modul v2 wurde erfolgreich frisch installiert.',
            'update' => 'Modul wurde erfolgreich aktualisiert.',
            'activate' => 'Modul wurde aktiviert.',
            'deactivate' => 'Modul wurde deaktiviert.',
            'uninstall' => 'Modul wurde deinstalliert. Vorhandene Daten wurden beibehalten.',
            'purge' => 'Modul und alle zugehörigen Daten wurden endgültig gelöscht.',
            default => 'Aktion erfolgreich ausgeführt.',
        };
    }
}
