<?php
// modules/retention/migrations/2026_04_30_400000_create_retention_tables.php
use Core\Database\Migration;

/**
 * Schema for the data-retention sweeper.
 *
 *   retention_rules — one row per active rule. Rules are seeded from
 *                     each module's `retentionRules()` declaration on
 *                     first sync, then editable from /admin/retention.
 *                     Once an admin edits days_keep / action / disables,
 *                     subsequent module declarations DON'T overwrite
 *                     (admin override wins).
 *
 *   retention_runs  — append-only execution history. One row per
 *                     (rule, run) including dry-run previews. Powers
 *                     the per-rule history view and gives auditors
 *                     proof that the sweep is actually running.
 *
 * The `key` column on rules is what's stable across module redeploys —
 * a module's `retentionRules()` declaration uses the same key every
 * time so admin edits stick. Format: `{module}.{table}.{purpose}` e.g.
 * `core.security.sessions.expired`.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE retention_rules (
                id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key`               VARCHAR(160) NOT NULL UNIQUE
                                    COMMENT 'Stable identifier; format: module.table.purpose',
                module              VARCHAR(80) NOT NULL
                                    COMMENT 'Owning module name (or core.* for framework defaults)',
                label               VARCHAR(191) NOT NULL,
                description         TEXT NULL,
                table_name          VARCHAR(120) NOT NULL,
                where_clause        TEXT NOT NULL
                                    COMMENT 'SQL WHERE fragment using {cutoff} placeholder, e.g. created_at < {cutoff}',
                date_column         VARCHAR(80) NULL
                                    COMMENT 'Name of the date column the rule operates on (info-only, for display)',
                days_keep           INT UNSIGNED NOT NULL DEFAULT 365,
                action              ENUM('purge','anonymize') NOT NULL DEFAULT 'purge',
                anonymize_columns   TEXT NULL
                                    COMMENT 'JSON map column->replacement value, used when action=anonymize',
                is_enabled          TINYINT(1) NOT NULL DEFAULT 1,
                source              ENUM('module_default','admin_custom') NOT NULL DEFAULT 'module_default',
                last_run_at         DATETIME NULL,
                last_run_rows       INT UNSIGNED NULL,
                last_run_status     ENUM('ok','dry_run','failed') NULL,
                created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_module     (module),
                KEY idx_enabled    (is_enabled, last_run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE retention_runs (
                id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_id         INT UNSIGNED NOT NULL,
                started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at    DATETIME NULL,
                dry_run         TINYINT(1) NOT NULL DEFAULT 0,
                rows_affected   INT UNSIGNED NULL,
                duration_ms     INT UNSIGNED NULL,
                error_message   TEXT NULL,
                triggered_by    INT UNSIGNED NULL
                                COMMENT 'NULL = scheduled run; otherwise admin who clicked Run now',

                KEY idx_rule_started (rule_id, started_at),
                CONSTRAINT fk_retrun_rule  FOREIGN KEY (rule_id)      REFERENCES retention_rules (id) ON DELETE CASCADE,
                CONSTRAINT fk_retrun_actor FOREIGN KEY (triggered_by) REFERENCES users (id)           ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS retention_runs");
        $this->db->query("DROP TABLE IF EXISTS retention_rules");
    }
};
