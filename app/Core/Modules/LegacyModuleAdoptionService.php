<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use Modulon\Core\Modules\Catalog\CatalogAdoptionMetadata;
use Modulon\Core\Modules\Catalog\CatalogPackageInstaller;
use Modulon\Core\RotatingFileLogger;
use PDO;
use RuntimeException;
use Throwable;

final readonly class LegacyModuleAdoptionService
{
    private const PROFILES = [
        'modulnest.logs' => ['route' => 'logs', 'legacy_key' => 'logs', 'directory' => 'logs', 'schema' => 0, 'tables' => [], 'historic' => [], 'package_migrations' => []],
        'modulnest.systeminfo' => ['route' => 'systeminfo', 'legacy_key' => 'systeminfo', 'directory' => 'systeminfo', 'schema' => 0, 'tables' => [], 'historic' => [], 'package_migrations' => []],
        'modulnest.news' => ['route' => 'news', 'legacy_key' => 'news', 'directory' => 'news', 'schema' => 1, 'tables' => ['news_entries'], 'historic' => ['20260510_000101_news_070_schema'], 'package_migrations' => ['001_schema.php', '002_seeds.php']],
        'modulnest.pages' => ['route' => 'pages', 'legacy_key' => 'pages', 'directory' => 'pages', 'schema' => 1, 'tables' => ['pages_entries'], 'historic' => ['20260521_000100_pages_schema', '20260521_000200_pages_header_footer_columns'], 'package_migrations' => ['001_baseline.php']],
        'modulnest.homepage' => ['route' => 'homepage', 'legacy_key' => 'homepage', 'directory' => 'homepage', 'schema' => 1, 'tables' => ['homepage_blocks', 'homepage_block_buttons', 'homepage_block_items'], 'historic' => ['20260514_000105_homepage_schema', '20260514_000110_homepage_block_flexibility', '20260514_000115_homepage_button_layout', '20260514_000120_homepage_show_title'], 'package_migrations' => ['001_baseline.php']],
        'modulnest.data-portability' => ['route' => 'data-portability', 'legacy_key' => 'data-portability', 'directory' => 'data-portability', 'schema' => 0, 'tables' => [], 'historic' => [], 'package_migrations' => [], 'storage' => 'storage/data-portability'],
        'modulnest.dashboard' => ['route' => 'dashboard', 'legacy_key' => 'dashboard', 'directory' => 'dashboard', 'schema' => 1, 'tables' => ['dashboard_widgets', 'dashboard_link_folders', 'dashboard_links', 'dashboard_tasks', 'dashboard_notes'], 'historic' => ['20260510_000102_dashboard_070_schema', '20260519_000130_dashboard_archive_tasks_notes'], 'package_migrations' => ['001_baseline.php'], 'storage_sources' => [['path' => 'storage/favicons', 'target' => 'favicons'], ['path' => 'public/assets/favicons', 'target' => 'favicons']]],
        'modulnest.sneak-preview' => ['route' => 'sneak-preview', 'legacy_key' => 'sneak-preview', 'directory' => 'sneak-preview', 'migration_directory' => 'SneakPreview', 'schema' => 1, 'tables' => ['sneak_preview_entries', 'sneak_preview_settings'], 'historic' => ['20260510_000104_sneak_preview_070_schema'], 'package_migrations' => ['001_baseline.php'], 'storage_sources' => [['path' => 'public/assets/sneak-preview/posters', 'target' => 'posters']]],
        'modulnest.tools' => ['route' => 'tools', 'legacy_key' => 'tools', 'directory' => 'tools', 'schema' => 0, 'tables' => [], 'historic' => [], 'package_migrations' => [], 'storage_sources' => [['path' => 'storage/tools/speech', 'target' => 'speech', 'transient' => ['worker.lock']]]],
        'modulnest.banking' => ['route' => 'banking', 'legacy_key' => 'banking', 'directory' => 'banking', 'schema' => 1, 'tables' => ['banking_migration_runs', 'banking_accounts', 'banking_categories', 'banking_import_batches', 'banking_transactions', 'banking_recurring_rules', 'banking_recurring_rule_conditions', 'banking_dashboard_cache'], 'historic' => ['20260510_000103_banking_070_schema'], 'package_migrations' => ['001_baseline.php'], 'preserve_access_level' => true],
        'modulnest.fantasy-cards' => ['route' => 'fantasy-cards', 'legacy_key' => 'fantasy-cards', 'directory' => 'fantasy-cards', 'migration_directory' => 'FantasyCards', 'schema' => 1, 'tables' => ['card_sets', 'cards', 'booster_types', 'user_booster_inventory', 'user_cards', 'fantasy_card_user_state', 'fantasy_card_booster_openings', 'fantasy_card_booster_opening_cards', 'fantasy_card_profile_settings', 'fantasy_card_profile_showcase_cards'], 'historic' => [], 'package_migrations' => ['001_schema.php', '002_seeds.php'], 'preserve_access_level' => true],
        'modulnest.mail' => ['route' => 'mail', 'legacy_key' => 'mail', 'directory' => 'mail', 'migration_directory' => 'Mail', 'schema' => 1, 'tables' => ['mail_accounts', 'mail_favorite_folders', 'mail_sender_whitelist', 'mail_sender_exclusions', 'mail_list_preferences', 'mail_message_index'], 'historic' => [], 'package_migrations' => ['001_schema.php'], 'preserve_access_level' => true],
    ];

    public function __construct(private PDO $pdo, private string $basePath, private CatalogPackageInstaller $installer) {}

    public function canAdopt(string $moduleId): bool
    {
        $profile = self::PROFILES[$moduleId] ?? null;
        if (!is_array($profile)) return false;
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM modules WHERE route_prefix=? AND module_key IS NULL AND handler='native'");
        $statement->execute([$profile['route']]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** @return array{eligible:bool,status:string,message:string,technical_detail:?string,first_difference:?string} */
    public function preflight(string $moduleId): array
    {
        $profile = self::PROFILES[$moduleId] ?? null;
        if (!is_array($profile)) return $this->blocked('unavailable', 'Für dieses Modul existiert kein sicherer Adoptionspfad.', null);
        if (!$this->canAdopt($moduleId)) return $this->blocked('unavailable', 'Es wurde kein eindeutiges Modul-v1 zur Übernahme gefunden.', null);

        try {
            $difference = $this->firstCodeDifference($moduleId);
        } catch (Throwable $error) {
            $this->logMetadataFailure($moduleId, $error);
            return $this->blocked(
                'metadata-unavailable',
                'Die signierten Adoptionsmetadaten sind nicht verfügbar. Die automatische Umstellung bleibt sicher blockiert.',
                'Adoptionsmetadaten fehlen oder sind ungültig.',
            );
        }
        if ($difference !== null) {
            $kind = is_file($this->basePath . '/' . $difference) ? 'Abweichende' : 'Fehlende';
            return $this->blocked(
                'changed',
                'Die Codebasis wurde lokal verändert und wird als Legacy/Manual belassen: ' . $difference,
                $kind . ' Datei: ' . $difference,
                $difference,
            );
        }

        $schemaIssue = $this->schemaIssue($profile);
        if ($schemaIssue !== null) return $this->blocked('baseline-conflict', $schemaIssue['message'], $schemaIssue['technical'], $schemaIssue['file']);
        $runtimeIssue = $this->runtimeIssue($moduleId, $profile);
        if ($runtimeIssue !== null) return $this->blocked('environment', $runtimeIssue, $runtimeIssue);
        return ['eligible' => true, 'status' => 'adoptable', 'message' => 'Der bekannte ModulNest-1.2.0-Stand kann sicher übernommen werden.', 'technical_detail' => null, 'first_difference' => null];
    }

    /** @return array{eligible:bool,status:string,message:string,technical_detail:?string,first_difference:?string} */
    public function reinstallPreflight(string $moduleId): array
    {
        $profile = self::PROFILES[$moduleId] ?? null;
        if (!is_array($profile) || !$this->canAdopt($moduleId)) {
            return $this->blocked('unavailable', 'Es wurde kein eindeutiges Modul-v1 für eine sichere Neuinstallation gefunden.', null);
        }
        $schemaIssue = $this->schemaIssue($profile);
        if ($schemaIssue !== null) {
            return $this->blocked('baseline-conflict', $schemaIssue['message'], $schemaIssue['technical'], $schemaIssue['file']);
        }
        $runtimeIssue = $this->runtimeIssue($moduleId, $profile);
        if ($runtimeIssue !== null) {
            return $this->blocked('environment', $runtimeIssue, $runtimeIssue);
        }
        try {
            $manifest = $this->installer->manifest($moduleId);
            $compatible = $manifest->raw['data']['compatible_schema'] ?? null;
            if ((int) $profile['schema'] > 0
                && (!is_string($compatible) || !DataSchemaConstraint::parse($compatible)->matches((int) $profile['schema']))) {
                return $this->blocked('schema-incompatible', 'Das vorhandene Datenschema ist mit dem v2-Paket nicht kompatibel.', 'Datenschema ' . $profile['schema'] . ' wird vom Paket nicht unterstützt.');
            }
            foreach ($profile['tables'] as $table) {
                if (!in_array($table, $manifest->ownership['tables'], true)) {
                    return $this->blocked('ownership-conflict', 'Die vorhandenen Moduldaten sind im v2-Paket nicht vollständig als Ownership deklariert.', 'Fehlende Tabellen-Ownership: ' . $table);
                }
            }
            foreach ($profile['storage_sources'] ?? [] as $source) {
                $target = (string) ($source['target'] ?? '');
                if ($target === '' || !in_array($target, $manifest->ownership['storage'], true)) {
                    return $this->blocked('ownership-conflict', 'Die vorhandenen persistenten Dateien sind im v2-Paket nicht vollständig als Ownership deklariert.', 'Fehlende Storage-Ownership: ' . ($target !== '' ? $target : '(leer)'));
                }
            }
        } catch (Throwable $error) {
            return $this->blocked('package-unavailable', 'Das vertrauenswürdige v2-Paket konnte nicht vollständig vorgeprüft werden.', $error->getMessage());
        }
        return [
            'eligible' => true,
            'status' => 'reinstallable',
            'message' => 'Das vertrauenswürdige v2-Paket kann nach Backup mit den vorhandenen Daten neu installiert werden.',
            'technical_detail' => null,
            'first_difference' => null,
        ];
    }

    /** @return array<string,mixed> */
    public function adopt(string $moduleId, ?callable $progress = null): array
    {
        $profile = self::PROFILES[$moduleId] ?? throw new RuntimeException('Für dieses Modul existiert kein sicherer Adoptionspfad.');
        $preflight = $this->preflight($moduleId);
        if (!$preflight['eligible']) throw new RuntimeException($preflight['message']);
        return $this->performAdoption($moduleId, $profile, false, $progress);
    }

    /** @return array<string,mixed> */
    public function reinstall(string $moduleId): array
    {
        $profile = self::PROFILES[$moduleId] ?? throw new RuntimeException('Für dieses Modul existiert kein sicherer Neuinstallationspfad.');
        $preflight = $this->reinstallPreflight($moduleId);
        if (!$preflight['eligible']) throw new RuntimeException($preflight['message']);
        return $this->performAdoption($moduleId, $profile, true);
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function performAdoption(string $moduleId, array $profile, bool $withBackup, ?callable $progress = null): array
    {
        $adoptionMetadata = $this->installer->adoptionMetadata($moduleId);
        $statement = $this->pdo->prepare('SELECT * FROM modules WHERE route_prefix=? AND module_key IS NULL LIMIT 1');
        $statement->execute([$profile['route']]);
        $module = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($module)) throw new RuntimeException('Legacy-Modul ist während der Adoption verschwunden.');
        $active = (int) $module['is_active'] === 1;
        $oldStorage = isset($profile['storage']) ? $this->basePath . '/' . $profile['storage'] : null;
        $newStorage = $this->basePath . '/storage/modules/' . $moduleId;
        $movedStorage = false;
        $createdStorage = false;
        $storageBackups = [];
        $backupProvider = $withBackup
            ? new PdoLogicalBackupProvider($this->pdo, $this->basePath . '/storage/backups/modules')
            : null;
        $backup = null;
        try {
            $this->report($progress, 'preflight_complete', 'Sicherheitsprüfung abgeschlossen.');
            if ($backupProvider !== null) {
                $backup = $backupProvider->backup($moduleId, $profile['tables']);
                $backupProvider->verify($backup);
            }
            if (!empty($profile['storage_sources'])) {
                if (is_dir($newStorage)) throw new RuntimeException('Der v2-Modulstorage existiert bereits.');
                $stage = $newStorage . '.adoption-' . bin2hex(random_bytes(6));
                mkdir($stage, 0770, true);
                foreach ($profile['storage_sources'] as $source) {
                    $sourcePath = $this->basePath . '/' . $source['path'];
                    if (!is_dir($sourcePath)) continue;
                    [$totalFiles, $totalBytes] = $this->treeStats($sourcePath);
                    $storageBackup = $this->basePath . '/storage/adoption-backups/'
                        . str_replace('.', '-', $moduleId) . '-' . gmdate('YmdHis') . '-'
                        . substr(hash('sha256', $source['path']), 0, 8) . '-' . bin2hex(random_bytes(3));
                    $backupStage = $storageBackup . '.stage';
                    if (!is_dir(dirname($storageBackup))) mkdir(dirname($storageBackup), 0770, true);
                    try {
                        $this->report($progress, 'backup_copy', 'Persistente Daten werden gesichert.', 0, $totalFiles, 0, $totalBytes);
                        $this->copyTree($sourcePath, $backupStage, $progress, 'backup_copy', $totalFiles, $totalBytes);
                        $this->report($progress, 'backup_verify', 'Sicherung wird verifiziert.', 0, $totalFiles, 0, $totalBytes);
                        $this->verifyTree($sourcePath, $backupStage, $progress, 'backup_verify', $totalFiles, $totalBytes);
                        if (!rename($backupStage, $storageBackup)) throw new RuntimeException('Verifizierte Storage-Sicherung konnte nicht bereitgestellt werden.');
                    } catch (Throwable $error) {
                        if (is_dir($backupStage)) ModulePackageInspector::removeTree($backupStage);
                        throw $error;
                    }
                    $storageBackups[$sourcePath] = $storageBackup;
                    $target = $stage . '/' . $source['target'];
                    $this->report($progress, 'storage_copy', 'Persistente Daten werden in den Modul-v2-Storage kopiert.', 0, $totalFiles, 0, $totalBytes);
                    $this->copyTree($sourcePath, $target, $progress, 'storage_copy', $totalFiles, $totalBytes);
                    $this->report($progress, 'storage_verify', 'Modul-v2-Storage wird verifiziert.', 0, $totalFiles, 0, $totalBytes);
                    $this->verifyTree($sourcePath, $target, $progress, 'storage_verify', $totalFiles, $totalBytes);
                    foreach ($source['transient'] ?? [] as $transient) {
                        if (!is_string($transient) || preg_match('#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#D', $transient) !== 1) {
                            throw new RuntimeException('Unsicherer temporärer Storagepfad im Adoptionsprofil.');
                        }
                        $transientTarget = $target . '/' . $transient;
                        if (is_file($transientTarget) && !unlink($transientTarget)) {
                            throw new RuntimeException('Temporäre Moduldatei konnte nicht vom persistenten Ziel getrennt werden.');
                        }
                    }
                }
                if (!is_dir(dirname($newStorage))) mkdir(dirname($newStorage), 0770, true);
                if (!rename($stage, $newStorage)) throw new RuntimeException('Modulstorage konnte nicht atomar bereitgestellt werden.');
                $createdStorage = true;
            }
            if (is_string($oldStorage) && is_dir($oldStorage)) {
                if (is_dir($newStorage)) throw new RuntimeException('Der v2-Modulstorage existiert bereits.');
                if (!is_dir(dirname($newStorage))) mkdir(dirname($newStorage), 0770, true);
                if (!rename($oldStorage, $newStorage)) throw new RuntimeException('Modulstorage konnte nicht atomar übernommen werden.');
                $movedStorage = true;
            }
            $deferredSwitch = $moduleId === 'modulnest.tools' && $progress !== null;
            if ($deferredSwitch) {
                // Große Adoptionsdaten laufen im CLI-Worker. Das fertige v2-Release wird
                // zuerst deaktiviert und vollständig geprüft, während v1 aktiv bleibt.
                $this->report($progress, 'package_install', 'Das verifizierte Modul-v2-Paket wird deaktiviert installiert und geprüft.');
                $prepared = $this->installer->prepareAdoptionRelease($moduleId);
                $this->report($progress, 'registry_switch', 'Das geprüfte Release wird atomar auf den bisherigen Moduldatensatz umgeschaltet.');
                $this->pdo->beginTransaction();
                $this->adoptMigrationHistory($moduleId, $profile, $adoptionMetadata);
                $this->pdo->prepare('UPDATE modules SET module_key=?,name=?,description=?,route_prefix=?,access_level=?,is_active=? WHERE id=?')->execute([
                    $moduleId, $prepared['module_name'], $prepared['description'], $prepared['route_prefix'],
                    $prepared['access_level'], $active ? 1 : 0, $module['id'],
                ]);
                $this->pdo->prepare("INSERT INTO module_installations(module_id,module_row_id,origin,catalog_source_id,catalog_sequence,installed_version,active_release_id,data_schema_version,retained_data,health_status,package_sha256) VALUES(?,?,'catalog-managed',?,?,?,?,?,0,'healthy',?)")->execute([
                    $moduleId, $module['id'], $this->installer->sourceId($moduleId), $this->installer->sequence($moduleId),
                    $prepared['version'], $prepared['release_id'], $profile['schema'], $prepared['sha256'],
                ]);
                $this->pdo->commit();
                $result = [
                    'module_id' => $moduleId, 'module_row_id' => $module['id'],
                    'installed_version' => $prepared['version'], 'active_release_id' => $prepared['release_id'],
                    'is_active' => $active ? 1 : 0,
                ];
            } else {
                $this->report($progress, 'registry_switch', 'Registry-Umschaltung wird vorbereitet.');
                $this->pdo->beginTransaction();
                $this->adoptMigrationHistory($moduleId, $profile, $adoptionMetadata);
                $this->pdo->prepare('UPDATE modules SET module_key=? WHERE id=?')->execute([$moduleId, $module['id']]);
                $this->pdo->prepare("INSERT INTO module_installations(module_id,module_row_id,origin,catalog_source_id,catalog_sequence,installed_version,active_release_id,data_schema_version,retained_data,health_status) VALUES(?,?,'catalog-managed',?,?,NULL,NULL,?,1,'adopting')")->execute([
                    $moduleId, $module['id'], $this->installer->sourceId($moduleId), $this->installer->sequence($moduleId), $profile['schema'],
                ]);
                $this->pdo->commit();
                $this->report($progress, 'package_install', 'Das verifizierte Modul-v2-Paket wird installiert.');
                $result = $this->installer->install($moduleId, $active);
                if (($profile['preserve_access_level'] ?? false) === true) {
                    $this->pdo->prepare('UPDATE modules SET access_level=? WHERE id=?')->execute([
                        (string) $module['access_level'],
                        $module['id'],
                    ]);
                }
            }
            $this->report($progress, 'legacy_cleanup', 'Der alte Runtime-Pfad wird nach erfolgreicher Installation bereinigt.');
            foreach ($storageBackups as $sourcePath => $storageBackup) {
                $this->removeRuntimeContents($sourcePath);
                if ($this->hasRuntimeContents($sourcePath)) {
                    throw new RuntimeException('Legacy-Modulstorage konnte nach erfolgreicher Installation nicht bereinigt werden.');
                }
            }
            $result['adopted'] = true;
            $result['reinstalled'] = $withBackup;
            $result['backup_reference'] = $backup;
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            try {
                $this->pdo->prepare('DELETE FROM module_installations WHERE module_id=?')->execute([$moduleId]);
                $this->pdo->prepare('DELETE FROM module_data_resources WHERE module_id=?')->execute([$moduleId]);
                $this->pdo->prepare('DELETE FROM module_releases WHERE module_id=?')->execute([$moduleId]);
                $this->pdo->prepare('DELETE FROM modules WHERE module_key=? AND id<>?')->execute([$moduleId, $module['id']]);
                if (($profile['preserve_access_level'] ?? false) === true) {
                    $this->pdo->prepare('UPDATE modules SET module_key=NULL,access_level=? WHERE id=?')->execute([
                        (string) $module['access_level'],
                        $module['id'],
                    ]);
                } else {
                    $this->pdo->prepare('UPDATE modules SET module_key=NULL WHERE id=?')->execute([$module['id']]);
                }
                foreach ($adoptionMetadata->baselineMigrations as $migration) {
                    $this->pdo->prepare('DELETE FROM schema_migrations WHERE migration_key=?')->execute([$migration['key']]);
                }
                foreach ($profile['historic'] as $migration) {
                    $this->pdo->prepare('UPDATE schema_migrations SET module_key=? WHERE migration_key=?')->execute([$profile['legacy_key'], $migration]);
                }
                foreach ([$this->basePath . '/modules/' . $moduleId, $this->basePath . '/public/assets/modules/' . $moduleId] as $path) {
                    if (is_dir($path)) ModulePackageInspector::removeTree($path);
                }
            } catch (Throwable) {}
            if ($movedStorage && is_string($oldStorage) && is_dir($newStorage) && !is_dir($oldStorage)) {
                @rename($newStorage, $oldStorage);
            }
            foreach ($storageBackups as $sourcePath => $storageBackup) {
                try {
                    $this->copyTree($storageBackup, $sourcePath);
                    $this->verifyTree($storageBackup, $sourcePath);
                } catch (Throwable) {
                }
            }
            if ($createdStorage && is_dir($newStorage)) ModulePackageInspector::removeTree($newStorage);
            if ($backupProvider !== null && is_string($backup)) {
                $backupProvider->restore($backup);
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $profile */
    private function adoptMigrationHistory(string $moduleId, array $profile, CatalogAdoptionMetadata $metadata): void
    {
        foreach ($profile['historic'] as $migration) {
            $this->pdo->prepare('UPDATE schema_migrations SET module_key=? WHERE migration_key=?')->execute([$moduleId, $migration]);
        }
        foreach ($metadata->baselineMigrations as $migration) {
            $this->pdo->prepare('INSERT INTO schema_migrations(migration_key,scope,module_key,description,checksum) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE module_key=VALUES(module_key)')->execute([
                $migration['key'], 'module', $moduleId, 'Adoptierter ' . $moduleId . '-v2-Baselinezustand', $migration['checksum'],
            ]);
        }
    }

    /** @return array<string,list<string>> */
    private function inventory(string $moduleId): array
    {
        return $this->installer->adoptionMetadata($moduleId)->fileHashes;
    }

    private function firstCodeDifference(string $moduleId): ?string
    {
        foreach ($this->inventory($moduleId) as $relative => $expectedHashes) {
            $file = $this->basePath . '/' . $relative;
            $hashes = is_string($expectedHashes) ? [$expectedHashes] : $expectedHashes;
            $currentHash = is_file($file) ? (hash_file('sha256', $file) ?: '') : '';
            $matches = false;
            if (is_array($hashes)) {
                foreach ($hashes as $hash) {
                    if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1 && hash_equals($hash, $currentHash)) {
                        $matches = true;
                        break;
                    }
                }
            }
            if (!$matches) {
                return (string) $relative;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $profile @return array{message:string,technical:string,file:?string}|null */
    private function schemaIssue(array $profile): ?array
    {
        foreach ($profile['tables'] as $table) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() !== 1) return ['message' => 'Legacy-Schema ist unvollständig: ' . $table, 'technical' => 'Fehlende Tabelle: ' . $table, 'file' => null];
        }
        foreach ($profile['historic'] as $migration) {
            $statement = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration_key=?');
            $statement->execute([$migration]);
            $stored = $statement->fetchColumn();
            $migrationDirectory = (string) ($profile['migration_directory'] ?? ucfirst((string) $profile['directory']));
            $relative = 'app/Modules/' . $migrationDirectory . '/Database/Migrations/' . $migration . '.php';
            if (!is_string($stored)) return ['message' => 'Historische Modulmigration fehlt: ' . $migration, 'technical' => 'Fehlender Migrationsdatensatz: ' . $migration, 'file' => $relative];
            $targetFile = $this->basePath . '/' . $relative;
            if (!is_file($targetFile) && is_file('/srv/http/modulon-v1/' . $relative)) {
                $targetFile = '/srv/http/modulon-v1/' . $relative;
            }
            $current = hash_file('sha256', $targetFile) ?: '';
            if ($stored !== '' && !hash_equals($stored, $current)) return ['message' => 'Historische Modulmigration hat einen abweichenden Checksum-Stand: ' . $migration, 'technical' => 'Checksum-Konflikt: ' . $relative, 'file' => $relative];
        }
        return null;
    }

    /** @param array<string,mixed> $profile */
    private function runtimeIssue(string $moduleId, array $profile): ?string
    {
        foreach (['modules', 'public/assets/modules'] as $relative) {
            $path = $this->basePath . '/' . $relative;
            while (!is_dir($path) && dirname($path) !== $path) $path = dirname($path);
            if (!is_writable($path)) return 'Runtime-Verzeichnis ist für den Webprozess nicht beschreibbar: ' . $relative;
        }
        if (isset($profile['storage'])
            && is_dir($this->basePath . '/' . $profile['storage'])
            && is_dir($this->basePath . '/storage/modules/' . $moduleId)) {
            return 'Der v2-Modulstorage existiert bereits.';
        }
        if (!empty($profile['storage_sources']) && is_dir($this->basePath . '/storage/modules/' . $moduleId)) {
            return 'Der v2-Modulstorage existiert bereits.';
        }
        foreach ($profile['storage_sources'] ?? [] as $source) {
            $sourcePath = $this->basePath . '/' . $source['path'];
            if (!is_dir($sourcePath)) continue;
            $issue = $this->storageTreeIssue($sourcePath);
            if ($issue !== null) return $issue;
        }

        return null;
    }

    /** @return array{eligible:false,status:string,message:string,technical_detail:?string,first_difference:?string} */
    private function blocked(string $status, string $message, ?string $technical, ?string $file = null): array
    {
        return ['eligible' => false, 'status' => $status, 'message' => $message, 'technical_detail' => $technical, 'first_difference' => $file];
    }

    private function logMetadataFailure(string $moduleId, Throwable $error): void
    {
        (new RotatingFileLogger($this->basePath))->write('module-lifecycle', [
            'event' => 'adoption_preflight_blocked',
            'module_id' => $moduleId,
            'phase' => 'metadata',
            'error_code' => 'adoption_metadata_unavailable',
            'error_type' => $error::class,
        ]);
    }

    private function copyTree(string $source, string $target, ?callable $progress = null, string $phase = 'storage_copy', int $totalFiles = 0, int $totalBytes = 0): void
    {
        if (!is_dir($target)) mkdir($target, 0770, true);
        $completedFiles = 0;
        $completedBytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if ($item->isLink()) throw new RuntimeException('Symlinks im Modulstorage verhindern die sichere Übernahme.');
            $destination = $target . '/' . substr($item->getPathname(), strlen($source) + 1);
            if ($item->isDir()) {
                if (!is_dir($destination)) mkdir($destination, 0770, true);
            } elseif (is_file($destination)) {
                $sourceHash = hash_file('sha256', $item->getPathname());
                $destinationHash = hash_file('sha256', $destination);
                if (!is_string($sourceHash) || !is_string($destinationHash) || !hash_equals($sourceHash, $destinationHash)) {
                    throw new RuntimeException('Kollidierende persistente Moduldateien verhindern die sichere Übernahme.');
                }
            } elseif (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException('Persistente Moduldatei konnte nicht übernommen werden.');
            }
            if ($item->isFile()) {
                $completedFiles++;
                $completedBytes += max(0, (int) $item->getSize());
                $this->report($progress, $phase, 'Persistente Dateien werden verarbeitet.', $completedFiles, $totalFiles, $completedBytes, $totalBytes);
            }
        }
    }

    private function verifyTree(string $source, string $target, ?callable $progress = null, string $phase = 'storage_verify', int $totalFiles = 0, int $totalBytes = 0): void
    {
        $completedFiles = 0;
        $completedBytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if (!$item->isFile() || $item->isLink()) continue;
            $destination = $target . '/' . substr($item->getPathname(), strlen($source) + 1);
            $sourceHash = hash_file('sha256', $item->getPathname());
            $destinationHash = is_file($destination) ? hash_file('sha256', $destination) : false;
            if (!is_string($sourceHash) || !is_string($destinationHash) || !hash_equals($sourceHash, $destinationHash)) {
                throw new RuntimeException('Persistente Moduldatei wurde nicht vollständig verifiziert.');
            }
            $completedFiles++;
            $completedBytes += max(0, (int) $item->getSize());
            $this->report($progress, $phase, 'Persistente Dateien werden verifiziert.', $completedFiles, $totalFiles, $completedBytes, $totalBytes);
        }
    }

    /** @return array{0:int,1:int} */
    private function treeStats(string $root): array
    {
        $files = 0;
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $files++;
                $bytes += max(0, (int) $item->getSize());
            }
        }
        return [$files, $bytes];
    }

    private function report(?callable $progress, string $phase, string $message, int $completedFiles = 0, int $totalFiles = 0, int $completedBytes = 0, int $totalBytes = 0): void
    {
        if ($progress === null) return;
        $progress([
            'phase' => $phase,
            'message' => $message,
            'completed_files' => $completedFiles,
            'total_files' => $totalFiles,
            'completed_bytes' => $completedBytes,
            'total_bytes' => $totalBytes,
        ]);
    }

    private function removeRuntimeContents(string $root): void
    {
        if (!is_dir($root)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            if ($item->isLink()) throw new RuntimeException('Symlinks im Legacy-Modulstorage verhindern die sichere Bereinigung.');
            if ($item->isFile()) {
                if ($item->getBasename() !== '.gitkeep' && !unlink($item->getPathname())) {
                    throw new RuntimeException('Legacy-Moduldatei konnte nicht bereinigt werden.');
                }
                continue;
            }
            $entries = scandir($item->getPathname());
            if (is_array($entries) && count($entries) === 2 && !rmdir($item->getPathname())) {
                throw new RuntimeException('Leeres Legacy-Modulverzeichnis konnte nicht bereinigt werden.');
            }
        }
    }

    private function hasRuntimeContents(string $root): bool
    {
        if (!is_dir($root)) return false;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item->isFile() && $item->getBasename() !== '.gitkeep') return true;
        }
        return false;
    }

    private function storageTreeIssue(string $root): ?string
    {
        if (!is_readable($root) || !is_writable($root)) {
            return 'Persistenter Legacy-Modulstorage ist für den Webprozess nicht sicher les- und bereinigbar: ' . substr($root, strlen($this->basePath) + 1);
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if ($item->isLink()) return 'Symlinks im Legacy-Modulstorage verhindern die sichere Übernahme.';
            if ($item->isDir() && (!is_readable($item->getPathname()) || !is_writable($item->getPathname()))) {
                return 'Ein Unterverzeichnis im Legacy-Modulstorage ist für den Webprozess nicht sicher les- und bereinigbar.';
            }
            if ($item->isFile() && !is_readable($item->getPathname())) {
                return 'Eine persistente Legacy-Moduldatei ist für den Webprozess nicht lesbar.';
            }
        }
        return null;
    }

}
