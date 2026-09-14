<?php

declare(strict_types=1);

use Modulon\Core\Database\{Migration, SchemaHelper};

return new class implements Migration {
    public function key(): string { return '20260910_000100_catalog_sources'; }
    public function scope(): string { return 'core'; }
    public function moduleKey(): ?string { return null; }
    public function description(): string { return 'Persistente, vertrauensgebundene Modul-Katalogquellen.'; }

    public function up(PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalog_sources (
            id VARCHAR(120) PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            source_type VARCHAR(16) NOT NULL,
            location VARCHAR(2048) NOT NULL,
            location_hash CHAR(64) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 0,
            trusted_keys_json LONGTEXT NOT NULL,
            root_key_ids_json LONGTEXT NOT NULL,
            is_official TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(120) NOT NULL DEFAULT 'core',
            updated_by VARCHAR(120) NOT NULL DEFAULT 'core',
            last_refresh_at DATETIME(6) NULL,
            last_success_at DATETIME(6) NULL,
            last_error_at DATETIME(6) NULL,
            last_error_code VARCHAR(120) NULL,
            last_error_message VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            UNIQUE KEY uq_catalog_sources_name (name),
            UNIQUE KEY uq_catalog_sources_location (source_type, location_hash),
            INDEX idx_catalog_sources_enabled_priority (enabled, priority, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $trustedKeys = json_encode([
            'modulnest-root-2026-01' => 'Dpp7dUPvFHaGxC3csJ+g/SFLxbdgQMQSI5GgbPE/jm4=',
            'modulnest-release-2026-01' => 'YHRqRTiqkFxGec/mM7qkYBn9r1NEiMrRGBO+zNBlnPA=',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $rootKeyIds = json_encode(['modulnest-root-2026-01'], JSON_THROW_ON_ERROR);
        $location = 'https://repo.modulnest.de';
        $statement = $pdo->prepare(
            "INSERT IGNORE INTO catalog_sources
                (id,name,source_type,location,location_hash,enabled,priority,trusted_keys_json,root_key_ids_json,is_official,created_by,updated_by)
             VALUES (?,?,?,?,?,1,1000,?,?,1,'core-migration','core-migration')"
        );
        $statement->execute([
            'modulnest.official',
            'Offizieller ModulNest-Katalog',
            'https',
            $location,
            hash('sha256', $location),
            $trustedKeys,
            $rootKeyIds,
        ]);
    }
};
