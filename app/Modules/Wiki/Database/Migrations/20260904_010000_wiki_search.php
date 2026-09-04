<?php

declare(strict_types=1);

use Modulon\Core\Database\{Migration, SchemaHelper};

return new class implements Migration {
    public function key(): string { return '20260904_010000_wiki_search'; }
    public function scope(): string { return 'module'; }
    public function moduleKey(): ?string { return 'wiki'; }
    public function description(): string { return 'Ergänzt den lokalen, fehlertoleranten Wiki-Suchindex.'; }

    public function up(\PDO $pdo, SchemaHelper $schema): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_state (
            source_id BIGINT UNSIGNED PRIMARY KEY,
            index_version INT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'current',
            indexed_pages INT UNSIGNED NOT NULL DEFAULT 0,
            term_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_wiki_search_state_source FOREIGN KEY (source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_documents (
            page_id BIGINT UNSIGNED PRIMARY KEY,
            source_id BIGINT UNSIGNED NOT NULL,
            content_hash CHAR(64) NOT NULL,
            title_text VARCHAR(255) NOT NULL,
            headings_text MEDIUMTEXT NOT NULL,
            body_text MEDIUMTEXT NOT NULL,
            code_text MEDIUMTEXT NOT NULL,
            path_text VARCHAR(500) NOT NULL,
            index_version INT UNSIGNED NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_wiki_search_documents_source (source_id),
            CONSTRAINT fk_wiki_search_documents_page FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,
            CONSTRAINT fk_wiki_search_documents_source FOREIGN KEY (source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_terms (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_id BIGINT UNSIGNED NOT NULL,
            term VARCHAR(191) NOT NULL,
            UNIQUE KEY uq_wiki_search_term (source_id, term),
            KEY idx_wiki_search_term_prefix (source_id, term),
            CONSTRAINT fk_wiki_search_terms_source FOREIGN KEY (source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_postings (
            term_id BIGINT UNSIGNED NOT NULL,
            page_id BIGINT UNSIGNED NOT NULL,
            title_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            heading_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            body_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            code_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            path_hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (term_id, page_id),
            KEY idx_wiki_search_postings_page (page_id),
            CONSTRAINT fk_wiki_search_postings_term FOREIGN KEY (term_id) REFERENCES wiki_search_terms(id) ON DELETE CASCADE,
            CONSTRAINT fk_wiki_search_postings_page FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_search_trigrams (
            source_id BIGINT UNSIGNED NOT NULL,
            trigram VARCHAR(12) NOT NULL,
            term_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (source_id, trigram, term_id),
            KEY idx_wiki_search_trigram_term (term_id),
            CONSTRAINT fk_wiki_search_trigrams_source FOREIGN KEY (source_id) REFERENCES wiki_sources(id) ON DELETE CASCADE,
            CONSTRAINT fk_wiki_search_trigrams_term FOREIGN KEY (term_id) REFERENCES wiki_search_terms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
};
