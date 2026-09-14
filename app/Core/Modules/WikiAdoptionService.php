<?php

declare(strict_types=1);

namespace Modulon\Core\Modules;

use Modulon\Core\Modules\Catalog\CatalogPackageInstaller;
use Modulon\Core\RotatingFileLogger;
use PDO;
use RuntimeException;
use Throwable;

final readonly class WikiAdoptionService
{
    private const ID = 'modulnest.wiki';
    private const HISTORIC_MIGRATIONS = [
        '20260902_164244_wiki',
        '20260902_170000_wiki_main_navigation',
        '20260903_010000_wiki_local_source',
        '20260903_020000_wiki_active_source',
        '20260904_010000_wiki_search',
    ];
    private const TABLES = [
        'wiki_sources', 'wiki_pages', 'wiki_assets', 'wiki_sync_runs',
        'wiki_search_state', 'wiki_search_documents', 'wiki_search_terms',
        'wiki_search_postings', 'wiki_search_trigrams',
    ];

    public function __construct(
        private PDO $pdo,
        private string $basePath,
        private CatalogPackageInstaller $installer,
    ) {
    }

    public function canAdopt(): bool
    {
        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM modules WHERE route_prefix='wiki' AND module_key IS NULL AND handler='native'"
        );
        return (int) $statement->fetchColumn() === 1;
    }

    /** @return array{eligible:bool,status:string,message:string,technical_detail:?string,first_difference:?string} */
    public function preflight(): array
    {
        if (!$this->canAdopt()) {
            return $this->blocked('unavailable', 'Es wurde kein eindeutiges Modul-v1-Wiki gefunden.', null);
        }

        try {
            $difference = $this->firstCodeDifference();
        } catch (Throwable $error) {
            $this->logMetadataFailure($error);
            return $this->blocked(
                'metadata-unavailable',
                'Die signierten Wiki-Adoptionsmetadaten sind nicht verfügbar. Die automatische Umstellung bleibt sicher blockiert.',
                'Adoptionsmetadaten fehlen oder sind ungültig.',
            );
        }
        if ($difference !== null) {
            $kind = is_file($this->basePath . '/' . $difference) ? 'abweichende' : 'fehlende';
            return $this->blocked(
                'changed',
                'Die Wiki-Codebasis wurde lokal verändert und wird als Legacy/Manual belassen: ' . $difference,
                ucfirst($kind) . ' Datei: ' . $difference,
                $difference,
            );
        }

        $schemaIssue = $this->schemaIssue();
        if ($schemaIssue !== null) {
            return $this->blocked('baseline-conflict', $schemaIssue['message'], $schemaIssue['technical'], $schemaIssue['file']);
        }

        $runtimeIssue = $this->runtimeIssue();
        if ($runtimeIssue !== null) {
            return $this->blocked('environment', $runtimeIssue, $runtimeIssue);
        }

        return [
            'eligible' => true,
            'status' => 'adoptable',
            'message' => 'Der bekannte ModulNest-1.2.0-Wiki-Stand kann sicher übernommen werden.',
            'technical_detail' => null,
            'first_difference' => null,
        ];
    }

    /** @return array{eligible:bool,status:string,message:string,technical_detail:?string,first_difference:?string} */
    public function reinstallPreflight(): array
    {
        if (!$this->canAdopt()) {
            return $this->blocked('unavailable', 'Es wurde kein eindeutiges Modul-v1-Wiki gefunden.', null);
        }
        $schemaIssue = $this->schemaIssue();
        if ($schemaIssue !== null) {
            return $this->blocked('baseline-conflict', $schemaIssue['message'], $schemaIssue['technical'], $schemaIssue['file']);
        }
        $runtimeIssue = $this->runtimeIssue();
        if ($runtimeIssue !== null) {
            return $this->blocked('environment', $runtimeIssue, $runtimeIssue);
        }
        try {
            $manifest = $this->installer->manifest(self::ID);
            $compatible = $manifest->raw['data']['compatible_schema'] ?? null;
            if (!is_string($compatible) || !DataSchemaConstraint::parse($compatible)->matches(1)) {
                return $this->blocked('schema-incompatible', 'Das vorhandene Wiki-Datenschema ist mit dem v2-Paket nicht kompatibel.', 'Wiki-Datenschema 1 wird vom Paket nicht unterstützt.');
            }
            foreach (self::TABLES as $table) {
                if (!in_array($table, $manifest->ownership['tables'], true)) {
                    return $this->blocked('ownership-conflict', 'Die vorhandenen Wiki-Daten sind im v2-Paket nicht vollständig als Ownership deklariert.', 'Fehlende Tabellen-Ownership: ' . $table);
                }
            }
        } catch (Throwable $error) {
            return $this->blocked('package-unavailable', 'Das vertrauenswürdige Wiki-v2-Paket konnte nicht vollständig vorgeprüft werden.', $error->getMessage());
        }
        return ['eligible' => true, 'status' => 'reinstallable', 'message' => 'Das vertrauenswürdige Wiki-v2-Paket kann nach Backup mit den vorhandenen Daten neu installiert werden.', 'technical_detail' => null, 'first_difference' => null];
    }

    /** @return array<string,mixed> */
    public function adopt(): array
    {
        $preflight = $this->preflight();
        if (!$preflight['eligible']) {
            throw new RuntimeException($preflight['message']);
        }
        return $this->performAdoption(false);
    }

    /** @return array<string,mixed> */
    public function reinstall(): array
    {
        $preflight = $this->reinstallPreflight();
        if (!$preflight['eligible']) throw new RuntimeException($preflight['message']);
        return $this->performAdoption(true);
    }

    /** @return array<string,mixed> */
    private function performAdoption(bool $withBackup): array
    {
        $metadata = $this->installer->adoptionMetadata(self::ID);
        $baseline = null;
        foreach ($metadata->baselineMigrations as $migration) {
            if ($migration['key'] === 'modulnest.wiki_001_baseline') {
                $baseline = $migration;
                break;
            }
        }
        if (!is_array($baseline)) {
            throw new RuntimeException('Die signierten Wiki-Baseline-Migrationsmetadaten fehlen.');
        }

        $module = $this->pdo->query(
            "SELECT * FROM modules WHERE route_prefix='wiki' AND module_key IS NULL AND handler='native' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($module)) {
            throw new RuntimeException('Das Modul-v1-Wiki ist während der Adoption verschwunden.');
        }
        $active = (int) $module['is_active'] === 1;
        $oldStorage = $this->basePath . '/storage/wiki';
        $newStorage = $this->basePath . '/storage/modules/' . self::ID;
        $createdStorage = false;
        $storageBackup = null;
        $backupProvider = $withBackup
            ? new PdoLogicalBackupProvider($this->pdo, $this->basePath . '/storage/backups/modules')
            : null;
        $databaseBackup = null;
        if (is_dir($newStorage)) {
            throw new RuntimeException('Der v2-Wiki-Storage existiert bereits; automatische Adoption wird nicht fortgesetzt.');
        }
        $stage = $newStorage . '.adoption-' . bin2hex(random_bytes(6));

        try {
            if ($backupProvider !== null) {
                $databaseBackup = $backupProvider->backup(self::ID, self::TABLES);
                $backupProvider->verify($databaseBackup);
            }
            if (is_dir($oldStorage)) {
                $this->copyTree($oldStorage, $stage);
            } else {
                mkdir($stage, 0770, true);
            }
            if (!is_dir(dirname($newStorage))) mkdir(dirname($newStorage), 0770, true);
            if (!rename($stage, $newStorage)) throw new RuntimeException('Wiki-Storage konnte nicht atomar bereitgestellt werden.');
            $createdStorage = true;

            $this->pdo->beginTransaction();
            $this->pdo->prepare('UPDATE schema_migrations SET module_key=? WHERE migration_key IN (?,?,?,?,?)')
                ->execute([self::ID, ...self::HISTORIC_MIGRATIONS]);
            $this->pdo->prepare(
                "INSERT INTO schema_migrations(migration_key,scope,module_key,description,checksum)
                 VALUES('modulnest.wiki_001_baseline','module',?,'Adoptierter Wiki-v2-Baselinezustand',?)"
            )->execute([self::ID, $baseline['checksum']]);
            $this->pdo->prepare('UPDATE modules SET module_key=? WHERE id=?')->execute([self::ID, $module['id']]);
            $this->pdo->prepare(
                "INSERT INTO module_installations(module_id,module_row_id,origin,catalog_source_id,catalog_sequence,
                    installed_version,active_release_id,data_schema_version,retained_data,health_status)
                 VALUES(?,?,'catalog-managed',?,?,NULL,NULL,1,1,'adopting')"
            )->execute([self::ID, $module['id'], $this->installer->sourceId(self::ID), $this->installer->sequence(self::ID)]);
            $this->pdo->commit();

            $result = $this->installer->install(self::ID, $active);
            if (is_dir($oldStorage)) {
                $backup = $this->basePath . '/storage/adoption-backups/wiki-' . gmdate('YmdHis');
                if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0770, true);
                if (!rename($oldStorage, $backup)) {
                    throw new RuntimeException('Wiki-v1-Storage konnte nicht archiviert werden.');
                }
                $storageBackup = $backup;
            }
            $result['adopted'] = true;
            $result['reinstalled'] = $withBackup;
            $result['backup_reference'] = $databaseBackup;
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            try {
                $this->pdo->prepare('DELETE FROM module_installations WHERE module_id=?')->execute([self::ID]);
                $this->pdo->prepare('DELETE FROM module_data_resources WHERE module_id=?')->execute([self::ID]);
                $this->pdo->prepare('DELETE FROM module_releases WHERE module_id=?')->execute([self::ID]);
                $this->pdo->prepare('UPDATE modules SET module_key=NULL WHERE id=?')->execute([$module['id']]);
                $this->pdo->prepare("DELETE FROM schema_migrations WHERE migration_key='modulnest.wiki_001_baseline'")->execute();
                $this->pdo->prepare("UPDATE schema_migrations SET module_key='wiki' WHERE migration_key IN (?,?,?,?,?)")
                    ->execute(self::HISTORIC_MIGRATIONS);
            } catch (Throwable) {
            }
            if ($storageBackup !== null && is_dir($storageBackup) && !is_dir($oldStorage)) {
                @rename($storageBackup, $oldStorage);
            }
            if ($createdStorage && is_dir($newStorage)) ModulePackageInspector::removeTree($newStorage);
            foreach ([$this->basePath . '/modules/' . self::ID, $this->basePath . '/public/assets/modules/' . self::ID] as $path) {
                if (is_dir($path)) ModulePackageInspector::removeTree($path);
            }
            if (is_dir($stage)) ModulePackageInspector::removeTree($stage);
            if ($backupProvider !== null && is_string($databaseBackup)) {
                $backupProvider->restore($databaseBackup);
            }
            throw $error;
        }
    }

    /** @return array<string,list<string>> */
    private function inventory(): array
    {
        return $this->installer->adoptionMetadata(self::ID)->fileHashes;
    }

    private function firstCodeDifference(): ?string
    {
        foreach ($this->inventory() as $relative => $hashes) {
            $file = $this->basePath . '/' . $relative;
            $currentHash = is_file($file) ? (hash_file('sha256', $file) ?: '') : '';
            $matches = false;
            foreach ($hashes as $hash) {
                if (hash_equals($hash, $currentHash)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) return (string) $relative;
        }
        return null;
    }

    /** @return array{message:string,technical:string,file:?string}|null */
    private function schemaIssue(): ?array
    {
        foreach (self::TABLES as $table) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
            );
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() !== 1) {
                return ['message' => 'Wiki-Schema ist unvollständig: ' . $table, 'technical' => 'Fehlende Tabelle: ' . $table, 'file' => null];
            }
        }
        foreach (self::HISTORIC_MIGRATIONS as $key) {
            $statement = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration_key=?');
            $statement->execute([$key]);
            $stored = $statement->fetchColumn();
            $relative = 'app/Modules/Wiki/Database/Migrations/' . $key . '.php';
            if (!is_string($stored)) {
                return ['message' => 'Historische Wiki-Migration fehlt: ' . $key, 'technical' => 'Fehlender Migrationsdatensatz: ' . $key, 'file' => $relative];
            }
            $targetFile = $this->basePath . '/' . $relative;
            if (!is_file($targetFile) && is_file('/srv/http/modulon-v1/' . $relative)) {
                $targetFile = '/srv/http/modulon-v1/' . $relative;
            }
            $current = hash_file('sha256', $targetFile) ?: '';
            if ($stored !== '' && !hash_equals($stored, $current)) {
                return ['message' => 'Historische Wiki-Migration hat einen abweichenden Checksum-Stand: ' . $key, 'technical' => 'Checksum-Konflikt: ' . $relative, 'file' => $relative];
            }
        }
        return null;
    }

    private function runtimeIssue(): ?string
    {
        foreach (['modules', 'public/assets/modules', 'storage/modules'] as $relative) {
            $path = $this->basePath . '/' . $relative;
            while (!is_dir($path) && dirname($path) !== $path) $path = dirname($path);
            if (!is_writable($path)) {
                return 'Runtime-Verzeichnis ist für den Webprozess nicht beschreibbar: ' . $relative;
            }
        }
        if (!is_writable($this->basePath . '/storage')) {
            return 'Wiki-v1-Storage kann vom Webprozess nicht sicher verschoben werden.';
        }
        if (is_dir($this->basePath . '/storage/modules/' . self::ID)) {
            return 'Der v2-Wiki-Storage existiert bereits.';
        }

        return null;
    }

    /** @return array{eligible:false,status:string,message:string,technical_detail:?string,first_difference:?string} */
    private function blocked(string $status, string $message, ?string $technical, ?string $file = null): array
    {
        return ['eligible' => false, 'status' => $status, 'message' => $message, 'technical_detail' => $technical, 'first_difference' => $file];
    }

    private function logMetadataFailure(Throwable $error): void
    {
        (new RotatingFileLogger($this->basePath))->write('module-lifecycle', [
            'event' => 'adoption_preflight_blocked',
            'module_id' => self::ID,
            'phase' => 'metadata',
            'error_code' => 'adoption_metadata_unavailable',
            'error_type' => $error::class,
        ]);
    }

    private function copyTree(string $source, string $target): void
    {
        mkdir($target, 0770, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if ($item->isLink()) throw new RuntimeException('Symlinks im Wiki-Storage verhindern die Adoption.');
            $destination = $target . '/' . substr($item->getPathname(), strlen($source) + 1);
            if ($item->isDir()) mkdir($destination, 0770, true);
            elseif (!copy($item->getPathname(), $destination)) throw new RuntimeException('Wiki-Storage konnte nicht kopiert werden.');
        }
    }

}
