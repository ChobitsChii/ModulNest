<?php
declare(strict_types=1);
use Modulon\Core\Database\Migration; use Modulon\Core\Database\SchemaHelper;
return new class implements Migration {
    public function key(): string{return '20260906_000200_module_system_v2';}
    public function scope(): string{return 'core';}
    public function moduleKey(): ?string{return null;}
    public function description(): string{return 'Additive Registry für unabhängige Modul-Releases und Lifecycle-Journal.';}
    public function up(PDO $pdo,SchemaHelper $schema): void {
        if(!$schema->columnExists('modules','module_key')) $pdo->exec('ALTER TABLE modules ADD COLUMN module_key VARCHAR(127) NULL AFTER id, ADD UNIQUE KEY uq_modules_module_key (module_key)');
        $pdo->exec('ALTER TABLE schema_migrations MODIFY COLUMN module_key VARCHAR(127) NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS module_installations (
          module_id VARCHAR(127) PRIMARY KEY, module_row_id BIGINT UNSIGNED NOT NULL, origin VARCHAR(20) NOT NULL DEFAULT 'local', installed_version VARCHAR(64) NULL,
          active_release_id VARCHAR(96) NULL, data_schema_version INT UNSIGNED NOT NULL DEFAULT 0, retained_data TINYINT(1) NOT NULL DEFAULT 0,
          health_status VARCHAR(20) NOT NULL DEFAULT 'unknown', package_sha256 CHAR(64) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_module_installations_row(module_row_id),
          CONSTRAINT fk_module_installations_row FOREIGN KEY(module_row_id) REFERENCES modules(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS module_releases (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_id VARCHAR(127) NOT NULL,version VARCHAR(64) NOT NULL,release_id VARCHAR(96) NOT NULL,
          release_path VARCHAR(512) NOT NULL,manifest_json LONGTEXT NOT NULL,sha256 CHAR(64) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'staged',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_module_release(module_id,release_id),INDEX idx_module_release_version(module_id,version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS module_dependencies (
          release_pk BIGINT UNSIGNED NOT NULL,target_module_id VARCHAR(127) NOT NULL,constraint_expr VARCHAR(255) NOT NULL,dependency_kind VARCHAR(16) NOT NULL,
          PRIMARY KEY(release_pk,target_module_id,dependency_kind),CONSTRAINT fk_module_dependency_release FOREIGN KEY(release_pk) REFERENCES module_releases(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS module_data_resources (
          module_id VARCHAR(127) NOT NULL,resource_type VARCHAR(20) NOT NULL,resource_key VARCHAR(255) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(module_id,resource_type,resource_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS module_operations (
          operation_id CHAR(36) PRIMARY KEY,module_id VARCHAR(127) NOT NULL,operation_type VARCHAR(20) NOT NULL,old_release_id VARCHAR(96) NULL,new_release_id VARCHAR(96) NULL,
          phase VARCHAR(40) NOT NULL,status VARCHAR(20) NOT NULL,error_message TEXT NULL,backup_reference VARCHAR(512) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_module_operations_module(module_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
