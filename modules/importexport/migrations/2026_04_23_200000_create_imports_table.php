<?php
// modules/import-export/migrations/2026_04_23_200000_create_imports_table.php
use Core\Database\Migration;

/**
 * Schema for import-export.
 *
 *   imports — one row per import job. Admin uploads a file, the row
 *             is created in status=`uploaded` with a column-mapping
 *             placeholder. On confirm the admin saves a mapping +
 *             kicks run() which processes rows sync (for small
 *             imports) or enqueues a job (deferred). Status
 *             lifecycle:
 *               uploaded → mapped → running → completed | failed
 *             Summary stats + per-row error log live in the JSON
 *             blobs so we don't maintain a sprawling audit table.
 *
 * Exports don't need a table — they're generated on demand and
 * streamed directly. Only imports need persistence (the upload may
 * be large, the admin may leave mid-map and come back).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE imports (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_type     VARCHAR(64)  NOT NULL,
                uploaded_by     INT UNSIGNED NULL,
                file_path       VARCHAR(500) NOT NULL,
                file_format     ENUM('csv','json','tsv') NOT NULL DEFAULT 'csv',
                status          ENUM('uploaded','mapped','running','completed','failed') NOT NULL DEFAULT 'uploaded',
                mapping_json    TEXT NULL,
                stats_json      TEXT NULL,
                errors_json     TEXT NULL,
                row_count       INT UNSIGNED NOT NULL DEFAULT 0,
                processed_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                KEY idx_entity_status (entity_type, status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS imports");
    }
};
