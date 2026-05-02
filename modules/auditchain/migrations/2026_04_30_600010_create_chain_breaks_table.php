<?php
// modules/auditchain/migrations/2026_04_30_600010_create_chain_breaks_table.php
use Core\Database\Migration;

/**
 * audit_chain_breaks — append-only record of every detected tamper /
 * mismatch found during a chain verification run.
 *
 * The table stores BOTH expected and observed values so an admin can
 * see exactly what changed. Rows here are themselves NOT chained —
 * they're a separate forensic surface, not an extension of the
 * primary audit chain.
 *
 *   audit_chain_runs — each verification run, with totals + duration.
 *                      Powers the admin "is the chain healthy?" view.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE audit_chain_breaks (
                id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                audit_log_id    INT UNSIGNED NOT NULL,
                day_anchor      DATE NOT NULL
                                COMMENT 'Calendar day the broken row belongs to',
                expected_hash   CHAR(64) NULL,
                observed_hash   CHAR(64) NULL,
                expected_prev   CHAR(64) NULL,
                observed_prev   CHAR(64) NULL,
                reason          ENUM('hash_mismatch','prev_mismatch','missing_hash','row_missing','tampered_field') NOT NULL,
                detected_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                acknowledged_at DATETIME NULL,
                acknowledged_by INT UNSIGNED NULL,
                notes           TEXT NULL,

                KEY idx_day        (day_anchor, detected_at),
                KEY idx_unack      (acknowledged_at, detected_at),
                CONSTRAINT fk_chk_ack FOREIGN KEY (acknowledged_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE audit_chain_runs (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at    DATETIME NULL,
                day_from        DATE NULL,
                day_to          DATE NULL,
                rows_verified   INT UNSIGNED NULL,
                breaks_found    INT UNSIGNED NULL,
                duration_ms     INT UNSIGNED NULL,
                triggered_by    INT UNSIGNED NULL,
                error_message   TEXT NULL,

                KEY idx_started (started_at),
                CONSTRAINT fk_chrun_actor FOREIGN KEY (triggered_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS audit_chain_runs");
        $this->db->query("DROP TABLE IF EXISTS audit_chain_breaks");
    }
};
