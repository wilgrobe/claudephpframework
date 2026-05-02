<?php
// modules/gdpr/migrations/2026_04_30_200000_create_gdpr_tables.php
use Core\Database\Migration;

/**
 * Schema for the GDPR module — DSAR queue + data exports + the user-side
 * deletion lifecycle columns on the users table.
 *
 *   dsar_requests    — one row per Data Subject Access Request, regardless
 *                      of kind (access / export / erasure / restriction /
 *                      rectification / objection). The 30-day SLA clock
 *                      starts when the row is created and is enforced by
 *                      the admin queue's overdue badge.
 *
 *   data_exports     — long-running export jobs that produce a downloadable
 *                      zip. Created from /account/data → "Export my data" or
 *                      via a DSAR row of kind=export. The zip lives under
 *                      storage/gdpr/exports/ with a 7-day expires_at; a
 *                      signed download_token is the URL the user follows
 *                      so an expired or revoked export can't be redeemed.
 *
 *   users.*          — five new columns power the deletion lifecycle:
 *                      requested_at, grace_until, deletion_token,
 *                      processing_restricted_at, deleted_at. Soft-state
 *                      that keeps the FK graph intact during the grace
 *                      window; the actual purge fires from PurgeUserJob.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── DSAR queue ────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE dsar_requests (
                id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id             INT UNSIGNED NULL,
                requester_email     VARCHAR(255) NOT NULL,
                requester_name      VARCHAR(255) NULL,
                kind                ENUM('access','export','erasure','restriction','rectification','objection') NOT NULL,
                status              ENUM('pending','verified','in_progress','completed','denied','expired') NOT NULL DEFAULT 'pending',
                source              ENUM('self_service','admin','external') NOT NULL DEFAULT 'self_service',
                requested_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sla_due_at          DATETIME NOT NULL
                                    COMMENT '30 days after requested_at — GDPR Art. 12(3) deadline',
                verification_token  VARCHAR(64) NULL
                                    COMMENT 'NULL once consumed; one-time email-link verification',
                verified_at         DATETIME NULL,
                handled_by          INT UNSIGNED NULL,
                completed_at        DATETIME NULL,
                notes               TEXT NULL,
                ip_address          VARBINARY(16) NULL,

                KEY idx_status_due  (status, sla_due_at),
                KEY idx_user        (user_id),
                KEY idx_email       (requester_email),
                CONSTRAINT fk_dsar_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_dsar_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ── Data exports ─────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE data_exports (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id         INT UNSIGNED NOT NULL,
                dsar_id         INT UNSIGNED NULL,
                status          ENUM('pending','building','ready','expired','failed') NOT NULL DEFAULT 'pending',
                format          ENUM('zip','json') NOT NULL DEFAULT 'zip',
                file_path       VARCHAR(500) NULL,
                file_size       BIGINT UNSIGNED NULL,
                download_token  VARCHAR(64) NULL,
                error_message   TEXT NULL,
                requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at    DATETIME NULL,
                expires_at      DATETIME NULL
                                COMMENT 'After this, the file is purged + the row marked expired',

                KEY idx_user (user_id),
                KEY idx_status (status, expires_at),
                CONSTRAINT fk_dexp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_dexp_dsar FOREIGN KEY (dsar_id) REFERENCES dsar_requests (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ── Extend users with the deletion + restriction lifecycle ───
        // Idempotent: skip an ALTER if the column already exists. Lets
        // the migration co-exist with framework versions that have
        // already added one of the columns by hand.
        $this->addColumnIfMissing('users', 'deletion_requested_at',
            "DATETIME NULL COMMENT 'Set when the user clicks Delete my account'");
        $this->addColumnIfMissing('users', 'deletion_grace_until',
            "DATETIME NULL COMMENT '30-day window during which the user can cancel the erasure'");
        $this->addColumnIfMissing('users', 'deletion_token',
            "VARCHAR(64) NULL COMMENT 'One-time signed cancel link sent to the user email'");
        $this->addColumnIfMissing('users', 'processing_restricted_at',
            "DATETIME NULL COMMENT 'GDPR Art. 18 — non-essential writes blocked while non-NULL'");
        $this->addColumnIfMissing('users', 'deleted_at',
            "DATETIME NULL COMMENT 'Set by PurgeUserJob after the registry has run'");

        // Index on deletion_grace_until so the cron sweep that fires
        // PurgeUserJob can scan it efficiently.
        $existing = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'users'
               AND index_name = 'idx_deletion_grace_until'"
        );
        if ($existing === 0) {
            $this->db->query("
                CREATE INDEX idx_deletion_grace_until ON users (deletion_grace_until)
            ");
        }
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS data_exports");
        $this->db->query("DROP TABLE IF EXISTS dsar_requests");
        // Don't drop the users columns — values may have been written by
        // production traffic during the time the module was active. A
        // dropped DATETIME would erase the audit trail.
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $exists = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        if ($exists > 0) return;
        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
};
