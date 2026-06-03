<?php
// database/migrations/2026_05_23_999000_ensure_knowledgebase_apex_tables.php
use Core\Database\Migration;

/**
 * Apex-side catch-up — create the kb_articles + kb_article_revisions
 * tables on installs where `schema_migrations` records the original
 * `2026_04_23_210000_create_knowledgebase_tables` migration as applied
 * but the tables themselves are absent. The apex builder DB landed in
 * this state because the knowledge_base module was briefly allowlisted
 * during an earlier session, then removed before cleanup; the row in
 * schema_migrations stuck around but the tables were dropped.
 *
 * Phase 43.X (2026-05-29) re-enabled `knowledgebase` in the apex
 * allowlist so the real KB module powers `/kb` on the apex; this
 * migration is the schema half of that change.
 *
 * Idempotent — every operation guarded by an existence check so the
 * migration is safe to run against tenant DBs that already have the
 * tables (it's discovered everywhere the framework path is scanned,
 * but only applies where needed).
 *
 * Dated 2026_05_23_999000 so it sorts BEFORE any 2026_05_24 catch-up
 * migrations from other modules that might fail on apex (e.g. the
 * comments catch-up assumes `comments` exists, which it doesn't on
 * apex). My migration runs first, succeeds, gets recorded, then the
 * migrator hits the comments failure — but the KB work is already
 * done.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->tableExists('kb_articles')) {
            $this->db->query("
                CREATE TABLE kb_articles (
                    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    slug                 VARCHAR(191) NOT NULL,
                    title                VARCHAR(191) NOT NULL,
                    summary              VARCHAR(500) NULL,
                    current_revision_id  INT UNSIGNED NULL,
                    status               ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
                    featured             TINYINT(1) NOT NULL DEFAULT 0,
                    owner_user_id        INT UNSIGNED NULL,
                    owner_group_id       INT UNSIGNED NULL,
                    published_at         DATETIME NULL,
                    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_slug (slug),
                    KEY idx_status (status, published_at),
                    KEY idx_owner_user  (owner_user_id,  status),
                    KEY idx_owner_group (owner_group_id, status),
                    KEY idx_featured    (featured)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!$this->tableExists('kb_article_revisions')) {
            $this->db->query("
                CREATE TABLE kb_article_revisions (
                    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    article_id       INT UNSIGNED NOT NULL,
                    title            VARCHAR(191) NOT NULL,
                    summary          VARCHAR(500) NULL,
                    body             MEDIUMTEXT NOT NULL,
                    author_user_id   INT UNSIGNED NULL,
                    note             VARCHAR(255) NULL,
                    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_article_created (article_id, created_at),
                    CONSTRAINT fk_kbrev_article
                        FOREIGN KEY (article_id) REFERENCES kb_articles (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    public function down(): void
    {
        // No-op — the original create_knowledgebase_tables owns teardown
        // semantics; this is purely a catch-up so down would either
        // double-drop or do nothing meaningful.
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1 AS x FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
            [$table]
        );
    }
};
