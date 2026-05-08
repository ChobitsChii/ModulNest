-- Modulon Basis-Schema für Benutzer, Rollen und Berechtigungen
-- Zielsystem: MySQL 8+

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(40) NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    dashboard_auto_refresh_enabled TINYINT(1) NOT NULL DEFAULT 1,
    dashboard_auto_refresh_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    password_hash VARCHAR(255) NOT NULL,
    is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    totp_secret VARCHAR(255) NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    webauthn_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS username VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    ADD COLUMN IF NOT EXISTS dashboard_auto_refresh_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS dashboard_auto_refresh_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    ADD COLUMN IF NOT EXISTS is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS webauthn_enabled TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permission (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permission_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_role_permission_permission
        FOREIGN KEY (permission_id) REFERENCES permissions(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_role (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_role_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_role_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remember_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_remember_tokens_user_id (user_id),
    INDEX idx_remember_tokens_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    route_prefix VARCHAR(120) NOT NULL UNIQUE,
    access_level ENUM('public', 'user', 'admin') NOT NULL DEFAULT 'public',
    handler ENUM('native', 'placeholder', 'legacy') NOT NULL DEFAULT 'native',
    legacy_entry VARCHAR(255) NULL,
    admin_entry VARCHAR(255) NULL,
    enable_overlay TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    show_in_header TINYINT(1) NOT NULL DEFAULT 1,
    show_on_home TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE modules
    ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS handler ENUM('native', 'placeholder', 'legacy') NOT NULL DEFAULT 'native',
    ADD COLUMN IF NOT EXISTS legacy_entry VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS admin_entry VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS enable_overlay TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS show_in_header TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS show_on_home TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE modules
    MODIFY COLUMN handler ENUM('native', 'placeholder', 'legacy') NOT NULL DEFAULT 'native';

CREATE TABLE IF NOT EXISTS app_settings (
    `key` VARCHAR(120) PRIMARY KEY,
    `value` TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    credential_id VARCHAR(255) NOT NULL UNIQUE,
    public_key TEXT NOT NULL,
    sign_count BIGINT UNSIGNED NULL,
    transports VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_webauthn_credentials_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_webauthn_credentials_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recovery_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recovery_codes_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_recovery_codes_user_id (user_id),
    INDEX idx_recovery_codes_code_hash (code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    excerpt VARCHAR(400) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    type ENUM('news', 'update', 'release', 'note') NOT NULL DEFAULT 'news',
    version VARCHAR(30) NULL,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    published_at TIMESTAMP NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_news_entries_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_news_entries_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_news_entries_status_published (status, published_at),
    INDEX idx_news_entries_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail-Modul Fundament (multi-account, favorisierte Ordner, Whitelist, Listenpräferenzen)
CREATE TABLE IF NOT EXISTS mail_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    email_address VARCHAR(190) NOT NULL,
    imap_host VARCHAR(190) NOT NULL,
    imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    imap_encryption ENUM('tls', 'ssl', 'starttls') NOT NULL DEFAULT 'tls',
    imap_username VARCHAR(190) NOT NULL,
    smtp_host VARCHAR(190) NOT NULL,
    smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    smtp_encryption ENUM('tls', 'ssl', 'starttls') NOT NULL DEFAULT 'tls',
    smtp_username VARCHAR(190) NOT NULL,
    encrypted_password TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_accounts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_accounts_user (user_id),
    INDEX idx_mail_accounts_user_sort (user_id, sort_order),
    UNIQUE KEY uq_mail_accounts_user_email (user_id, email_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_favorite_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_account_id BIGINT UNSIGNED NOT NULL,
    folder_name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_favorite_folders_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_favorite_folders_account
        FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_favorite_folders_user (user_id),
    INDEX idx_mail_favorite_folders_account (mail_account_id),
    INDEX idx_mail_favorite_folders_sort (mail_account_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_sender_whitelist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    scope_type ENUM('sender', 'domain') NOT NULL,
    scope_value VARCHAR(255) NOT NULL,
    allow_external_images TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_sender_whitelist_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_sender_whitelist_user (user_id),
    INDEX idx_mail_sender_whitelist_scope (scope_type, scope_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_sender_exclusions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_account_id BIGINT UNSIGNED NOT NULL,
    folder_name VARCHAR(255) NOT NULL,
    sender_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_sender_exclusions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_sender_exclusions_account
        FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_sender_exclusions_user (user_id),
    INDEX idx_mail_sender_exclusions_context (user_id, mail_account_id, folder_name),
    UNIQUE KEY uq_mail_sender_exclusions_scope (user_id, mail_account_id, folder_name, sender_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_list_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_account_id BIGINT UNSIGNED NULL,
    folder_name VARCHAR(255) NULL,
    sort_field ENUM('date', 'sender', 'subject', 'size') NOT NULL DEFAULT 'date',
    sort_direction ENUM('asc', 'desc') NOT NULL DEFAULT 'desc',
    group_field ENUM('none', 'sender', 'date', 'folder') NOT NULL DEFAULT 'none',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_list_preferences_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_list_preferences_account
        FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_mail_list_preferences_user (user_id),
    INDEX idx_mail_list_preferences_account (mail_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_message_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mail_account_id BIGINT UNSIGNED NOT NULL,
    folder_name VARCHAR(255) NOT NULL,
    uid BIGINT UNSIGNED NOT NULL,
    message_id VARCHAR(255) NULL,
    sender_key VARCHAR(255) NOT NULL,
    sender_label VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message_timestamp BIGINT NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_message_index_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_mail_message_index_account
        FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_mail_message_index_scope_uid (user_id, mail_account_id, folder_name, uid),
    INDEX idx_mail_message_index_scope (user_id, mail_account_id, folder_name),
    INDEX idx_mail_message_index_scope_sender (user_id, mail_account_id, folder_name, sender_key),
    INDEX idx_mail_message_index_scope_ts (user_id, mail_account_id, folder_name, message_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Banking-Modul Zielstruktur (native, usergebundene Migration aus Legacy-Banking)
CREATE TABLE IF NOT EXISTS banking_migration_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_user_id BIGINT UNSIGNED NOT NULL,
    source_database VARCHAR(120) NOT NULL DEFAULT 'banking',
    source_snapshot_label VARCHAR(190) NULL,
    source_transactions_count INT UNSIGNED NOT NULL DEFAULT 0,
    source_rules_count INT UNSIGNED NOT NULL DEFAULT 0,
    source_conditions_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_transactions_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rules_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_conditions_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('planned', 'dry_run', 'running', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'planned',
    notes TEXT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_migration_runs_user
        FOREIGN KEY (target_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_banking_migration_runs_user (target_user_id),
    INDEX idx_banking_migration_runs_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    legacy_account_key VARCHAR(190) NULL,
    account_identifier VARCHAR(190) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    iban VARCHAR(34) NULL,
    bic VARCHAR(11) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_accounts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_accounts_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_accounts_user_identifier (user_id, account_identifier),
    INDEX idx_banking_accounts_user_active (user_id, is_active),
    INDEX idx_banking_accounts_user_iban (user_id, iban),
    INDEX idx_banking_accounts_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    normalized_name VARCHAR(120) NOT NULL,
    color VARCHAR(20) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_categories_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_categories_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_categories_user_normalized (user_id, normalized_name),
    INDEX idx_banking_categories_user_sort (user_id, sort_order),
    INDEX idx_banking_categories_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_import_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    source_type ENUM('legacy_migration', 'csv', 'manual', 'other') NOT NULL DEFAULT 'legacy_migration',
    original_filename VARCHAR(255) NULL,
    file_sha256 CHAR(64) NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary TEXT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_import_batches_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_import_batches_account
        FOREIGN KEY (account_id) REFERENCES banking_accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_import_batches_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_banking_import_batches_user_status (user_id, status),
    INDEX idx_banking_import_batches_account (account_id),
    INDEX idx_banking_import_batches_file_hash (user_id, file_sha256),
    INDEX idx_banking_import_batches_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    import_batch_id BIGINT UNSIGNED NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    legacy_id INT UNSIGNED NULL,
    booking_date DATE NOT NULL,
    value_date DATE NULL,
    booking_text VARCHAR(255) NULL,
    purpose TEXT NULL,
    counterparty_name VARCHAR(255) NULL,
    counterparty_iban VARCHAR(64) NULL,
    counterparty_bic VARCHAR(20) NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    raw_info TEXT NULL,
    legacy_category_name VARCHAR(120) NULL,
    transaction_hash CHAR(64) NULL,
    booking_status ENUM('gebucht', 'vorgemerkt') NOT NULL DEFAULT 'gebucht',
    legacy_created_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_transactions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_transactions_account
        FOREIGN KEY (account_id) REFERENCES banking_accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_transactions_category
        FOREIGN KEY (category_id) REFERENCES banking_categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_transactions_import_batch
        FOREIGN KEY (import_batch_id) REFERENCES banking_import_batches(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_transactions_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_transactions_user_legacy (user_id, legacy_id),
    UNIQUE KEY uq_banking_transactions_user_hash (user_id, transaction_hash),
    INDEX idx_banking_transactions_user_account_date (user_id, account_id, booking_date),
    INDEX idx_banking_transactions_user_value_date (user_id, value_date),
    INDEX idx_banking_transactions_user_status (user_id, booking_status),
    INDEX idx_banking_transactions_user_category (user_id, category_id),
    INDEX idx_banking_transactions_user_amount (user_id, amount),
    INDEX idx_banking_transactions_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_recurring_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    legacy_id INT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    interval_type VARCHAR(40) NOT NULL,
    notes TEXT NULL,
    rule_type VARCHAR(40) NULL,
    group_label VARCHAR(120) NULL,
    active_from DATE NULL,
    active_to DATE NULL,
    period_mode VARCHAR(40) NULL,
    due_day TINYINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    legacy_created_at TIMESTAMP NULL DEFAULT NULL,
    legacy_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_recurring_rules_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rules_account
        FOREIGN KEY (account_id) REFERENCES banking_accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rules_category
        FOREIGN KEY (category_id) REFERENCES banking_categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rules_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_recurring_rules_user_legacy (user_id, legacy_id),
    INDEX idx_banking_recurring_rules_user_active (user_id, is_active),
    INDEX idx_banking_recurring_rules_user_type (user_id, rule_type),
    INDEX idx_banking_recurring_rules_user_interval (user_id, interval_type),
    INDEX idx_banking_recurring_rules_user_group (user_id, group_label),
    INDEX idx_banking_recurring_rules_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_recurring_rule_conditions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    recurring_rule_id BIGINT UNSIGNED NOT NULL,
    migration_run_id BIGINT UNSIGNED NULL,
    legacy_id INT UNSIGNED NULL,
    field VARCHAR(80) NOT NULL,
    operator VARCHAR(40) NOT NULL,
    value VARCHAR(255) NOT NULL,
    legacy_created_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_recurring_rule_conditions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rule_conditions_rule
        FOREIGN KEY (recurring_rule_id) REFERENCES banking_recurring_rules(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_banking_recurring_rule_conditions_migration_run
        FOREIGN KEY (migration_run_id) REFERENCES banking_migration_runs(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_recurring_rule_conditions_user_legacy (user_id, legacy_id),
    UNIQUE KEY uq_banking_recurring_rule_conditions_rule_legacy (recurring_rule_id, legacy_id),
    INDEX idx_banking_recurring_rule_conditions_user (user_id),
    INDEX idx_banking_recurring_rule_conditions_rule (recurring_rule_id),
    INDEX idx_banking_recurring_rule_conditions_field_operator (field, operator),
    INDEX idx_banking_recurring_rule_conditions_migration_run (migration_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_dashboard_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    cache_scope VARCHAR(60) NOT NULL,
    period_key VARCHAR(20) NOT NULL,
    data_hash CHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banking_dashboard_cache_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_banking_dashboard_cache_scope (user_id, cache_scope, period_key),
    INDEX idx_banking_dashboard_cache_user_updated (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sneak_preview_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    legacy_id INT NULL,
    sneak_date DATE NOT NULL,
    title VARCHAR(255) NOT NULL,
    location VARCHAR(255) NOT NULL,
    release_date_de DATE NULL,
    poster_path VARCHAR(255) NULL,
    poster_tmdb_path VARCHAR(255) NULL,
    tmdb_id INT NULL,
    overview TEXT NULL,
    genres VARCHAR(255) NULL,
    runtime INT NULL,
    certification VARCHAR(20) NULL,
    original_language VARCHAR(20) NULL,
    production_countries VARCHAR(255) NULL,
    vote_average DECIMAL(3,1) NULL,
    trailer_key VARCHAR(32) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sneak_preview_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_sneak_preview_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uniq_sneak_preview_legacy_id (legacy_id),
    INDEX idx_sneak_preview_sneak_date (sneak_date),
    INDEX idx_sneak_preview_title (title),
    INDEX idx_sneak_preview_location (location),
    INDEX idx_sneak_preview_release_date (release_date_de),
    INDEX idx_sneak_preview_tmdb_id (tmdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sneak_preview_settings (
    `key` VARCHAR(120) PRIMARY KEY,
    `value` TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dashboard-Grundlage (usergebundene, mehrfach einbindbare Widgets)
CREATE TABLE IF NOT EXISTS dashboard_widgets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    widget_type ENUM('links', 'tasks', 'notes') NOT NULL,
    title VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    layout_width TINYINT UNSIGNED NOT NULL DEFAULT 6,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_widgets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_dashboard_widgets_user (user_id),
    INDEX idx_dashboard_widgets_user_sort (user_id, sort_order),
    INDEX idx_dashboard_widgets_user_type (user_id, widget_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_link_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_link_folders_widget
        FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_dashboard_link_folders_widget (widget_id),
    INDEX idx_dashboard_link_folders_widget_sort (widget_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    folder_id BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    favicon_url VARCHAR(2048) NULL,
    favicon_host VARCHAR(190) NULL,
    favicon_last_checked_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_links_widget
        FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_dashboard_links_folder
        FOREIGN KEY (folder_id) REFERENCES dashboard_link_folders(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_dashboard_links_widget (widget_id),
    INDEX idx_dashboard_links_widget_sort (widget_id, sort_order),
    INDEX idx_dashboard_links_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    link_url VARCHAR(2048) NULL,
    priority TINYINT UNSIGNED NOT NULL DEFAULT 0,
    due_at TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_at TIMESTAMP NULL DEFAULT NULL,
    repeat_type ENUM('none', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'none',
    repeat_time TIME NULL DEFAULT NULL,
    repeat_weekday TINYINT UNSIGNED NULL,
    repeat_month_mode ENUM('first_day', 'middle_day', 'last_day', 'fixed_day', 'ordinal_weekday') NULL,
    repeat_month_day TINYINT UNSIGNED NULL,
    repeat_month_ordinal TINYINT UNSIGNED NULL,
    repeat_month_weekday TINYINT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_tasks_widget
        FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_dashboard_tasks_widget (widget_id),
    INDEX idx_dashboard_tasks_widget_done_sort (widget_id, is_done, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE dashboard_tasks
    ADD COLUMN IF NOT EXISTS link_url VARCHAR(2048) NULL AFTER details,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER due_at,
    ADD COLUMN IF NOT EXISTS repeat_type ENUM('none', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'none' AFTER done_at,
    ADD COLUMN IF NOT EXISTS repeat_time TIME NULL DEFAULT NULL AFTER repeat_type,
    ADD COLUMN IF NOT EXISTS repeat_weekday TINYINT UNSIGNED NULL AFTER repeat_time,
    ADD COLUMN IF NOT EXISTS repeat_month_mode ENUM('first_day', 'middle_day', 'last_day', 'fixed_day', 'ordinal_weekday') NULL AFTER repeat_weekday,
    ADD COLUMN IF NOT EXISTS repeat_month_day TINYINT UNSIGNED NULL AFTER repeat_month_mode,
    ADD COLUMN IF NOT EXISTS repeat_month_ordinal TINYINT UNSIGNED NULL AFTER repeat_month_day,
    ADD COLUMN IF NOT EXISTS repeat_month_weekday TINYINT UNSIGNED NULL AFTER repeat_month_ordinal;

ALTER TABLE dashboard_tasks
    MODIFY COLUMN repeat_month_mode ENUM('first_day', 'middle_day', 'last_day', 'fixed_day', 'ordinal_weekday') NULL;

CREATE TABLE IF NOT EXISTS dashboard_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NULL,
    content MEDIUMTEXT NOT NULL,
    textarea_height INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dashboard_notes_widget
        FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_dashboard_notes_widget (widget_id),
    INDEX idx_dashboard_notes_widget_sort (widget_id, is_pinned, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE dashboard_notes
    ADD COLUMN IF NOT EXISTS textarea_height INT UNSIGNED NULL AFTER content;

-- Optionaler Standard-Seed für die Registrierung (attachRoleByName('user')).
INSERT IGNORE INTO roles (name) VALUES ('user');
INSERT IGNORE INTO roles (name) VALUES ('admin');
INSERT IGNORE INTO app_settings (`key`, `value`) VALUES ('public_registration_enabled', '1');

-- Fachmodule werden nicht im Core-Schema hart verdrahtet.
-- Native Module werden bei Bedarf in der Modulverwaltung per Auto-Discovery aus app/Modules/* initial angelegt.
-- Legacy-Module können weiterhin manuell in der Modulverwaltung registriert werden.


-- Fantasy Cards Modul: Sammelkarten-Sets, Karten und Booster-Grunddaten
CREATE TABLE IF NOT EXISTS card_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    slug VARCHAR(160) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    cover_image VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    available_in_free_packs TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_card_sets_active_sort (is_active, sort_order),
    INDEX idx_card_sets_free_packs (available_in_free_packs, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    set_id BIGINT UNSIGNED NOT NULL,
    card_number VARCHAR(40) NOT NULL DEFAULT '',
    slug VARCHAR(160) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    rarity ENUM('common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic') NOT NULL DEFAULT 'common',
    faction VARCHAR(120) NULL,
    element_name VARCHAR(120) NULL,
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    thumbnail_path VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('draft', 'active', 'retired') NOT NULL DEFAULT 'draft',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    available_in_boosters TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cards_set
        FOREIGN KEY (set_id) REFERENCES card_sets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_cards_set_slug (set_id, slug),
    INDEX idx_cards_set_active_sort (set_id, is_active, sort_order),
    INDEX idx_cards_set_status_sort (set_id, status, sort_order),
    INDEX idx_cards_rarity (rarity),
    INDEX idx_cards_boosters (available_in_boosters, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cards
    ADD COLUMN IF NOT EXISTS status ENUM('draft', 'active', 'retired') NOT NULL DEFAULT 'draft',
    ADD INDEX IF NOT EXISTS idx_cards_set_status_sort (set_id, status, sort_order);

UPDATE cards
SET status = 'active'
WHERE is_active = 1
  AND status = 'draft';

CREATE TABLE IF NOT EXISTS booster_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    set_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    cards_per_pack SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    is_free_pack TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_booster_types_set
        FOREIGN KEY (set_id) REFERENCES card_sets(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_booster_types_set (set_id),
    INDEX idx_booster_types_active_free (is_active, is_free_pack)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_booster_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    booster_type_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_booster_inventory_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_booster_inventory_booster
        FOREIGN KEY (booster_type_id) REFERENCES booster_types(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_user_booster_inventory (user_id, booster_type_id),
    INDEX idx_user_booster_inventory_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    card_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    first_obtained_at TIMESTAMP NULL DEFAULT NULL,
    last_obtained_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_cards_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_cards_card
        FOREIGN KEY (card_id) REFERENCES cards(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_user_cards (user_id, card_id),
    INDEX idx_user_cards_user (user_id),
    INDEX idx_user_cards_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_user_state (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    free_claims SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    last_free_claim_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_user_state_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_fantasy_card_user_state_claims (free_claims, last_free_claim_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_booster_openings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    booster_type_id BIGINT UNSIGNED NOT NULL,
    set_id BIGINT UNSIGNED NULL,
    cards_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_booster_openings_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_booster_openings_booster
        FOREIGN KEY (booster_type_id) REFERENCES booster_types(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_booster_openings_set
        FOREIGN KEY (set_id) REFERENCES card_sets(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_fantasy_card_booster_openings_user_opened (user_id, opened_at),
    INDEX idx_fantasy_card_booster_openings_booster (booster_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_booster_opening_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opening_id BIGINT UNSIGNED NOT NULL,
    card_id BIGINT UNSIGNED NOT NULL,
    reveal_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_booster_opening_cards_opening
        FOREIGN KEY (opening_id) REFERENCES fantasy_card_booster_openings(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_booster_opening_cards_card
        FOREIGN KEY (card_id) REFERENCES cards(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_fantasy_card_booster_opening_cards_opening (opening_id, reveal_order),
    INDEX idx_fantasy_card_booster_opening_cards_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_profile_settings (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    favorite_card_id BIGINT UNSIGNED NULL,
    showcase_mode ENUM('manual', 'rarest', 'latest', 'completed') NOT NULL DEFAULT 'manual',
    is_collection_public TINYINT(1) NOT NULL DEFAULT 0,
    is_progress_public TINYINT(1) NOT NULL DEFAULT 0,
    is_favorites_public TINYINT(1) NOT NULL DEFAULT 0,
    profile_background_key VARCHAR(120) NULL,
    card_frame_key VARCHAR(120) NULL,
    achievement_badge_key VARCHAR(120) NULL,
    seasonal_showcase_key VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_profile_settings_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_profile_settings_favorite_card
        FOREIGN KEY (favorite_card_id) REFERENCES cards(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fantasy_card_profile_showcase_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    card_id BIGINT UNSIGNED NOT NULL,
    slot SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fantasy_card_profile_showcase_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fantasy_card_profile_showcase_card
        FOREIGN KEY (card_id) REFERENCES cards(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_fantasy_card_profile_showcase_slot (user_id, slot),
    UNIQUE KEY uq_fantasy_card_profile_showcase_card (user_id, card_id),
    INDEX idx_fantasy_card_profile_showcase_user_slot (user_id, slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




