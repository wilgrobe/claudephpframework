<?php
// modules/ccpa/migrations/2026_04_30_900000_create_ccpa_opt_outs_table.php
use Core\Database\Migration;

/**
 * CCPA / CPRA opt-out record. One row per opt-out action — append-only,
 * just like cookie_consents. The opt-out persists indefinitely; lifting
 * it requires an explicit user action.
 *
 * Identity columns mirror the cookieconsent table:
 *   - email     present when the opt-out came from a guest who provided
 *               their email so we can match later sales-to-this-address
 *               (CCPA expects honoring opt-outs even from non-account
 *               users, which is why we accept a bare email).
 *   - user_id   present when the opt-out was triggered by a signed-in user.
 *   - cookie_token a cookie value for guest opt-outs without email; the
 *               cookie sticks to the device so subsequent visits without
 *               sign-in still see the opt-out honored.
 *
 * source values:
 *   self_service   — user clicked the opt-out form themselves
 *   gpc_signal     — auto-honored Sec-GPC: 1 header (CPRA expects this)
 *   admin          — admin-initiated on behalf of user
 *   api            — external API call (rare; future-proofing)
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE ccpa_opt_outs (
                id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email           VARCHAR(255) NULL,
                user_id         INT UNSIGNED NULL,
                cookie_token    CHAR(64) NULL
                                COMMENT 'For guest device-level opt-outs; matches the ccpa_opted_out cookie',
                source          ENUM('self_service','gpc_signal','admin','api') NOT NULL DEFAULT 'self_service',
                ip_address      VARBINARY(16) NULL,
                user_agent      VARCHAR(500) NULL,
                notes           TEXT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                withdrawn_at    DATETIME NULL
                                COMMENT 'Non-null when the user explicitly opted back IN (rare; recorded for audit)',

                KEY idx_email     (email),
                KEY idx_user      (user_id),
                KEY idx_cookie    (cookie_token),
                KEY idx_active    (withdrawn_at, created_at),
                CONSTRAINT fk_ccpa_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS ccpa_opt_outs");
    }
};
