<?php
// modules/cookieconsent/migrations/2026_04_30_100000_create_cookie_consents_table.php
use Core\Database\Migration;

/**
 * GDPR cookie-consent record table.
 *
 *   cookie_consents — one row per consent action (accept, reject,
 *                     customize, withdraw). We append rather than
 *                     update because GDPR Article 7(1) requires the
 *                     controller to *demonstrate* that consent was
 *                     given — so we keep the audit trail.
 *
 * Identity columns:
 *   user_id          — populated when the visitor was signed in.
 *   anon_id          — opaque random token written to the consent
 *                      cookie itself, so a guest's consent record can
 *                      be linked to their device without storing a
 *                      raw IP / fingerprint as the primary key. The
 *                      same anon_id sticks to the user when they later
 *                      sign in (we copy user_id forward on next save).
 *
 * Category columns are stored as four explicit booleans rather than a
 * JSON blob so reporting queries (`AVG(analytics) WHERE created_at >
 * ...`) stay trivial. `necessary` is always 1 — it's only persisted so
 * the row reads as a complete snapshot of what was accepted.
 *
 * `policy_version` is the version string of the cookie policy that was
 * shown at the time of consent. When the policy changes (new tracker
 * added, etc.) admins bump the version in /admin/cookie-consent and
 * the banner re-prompts every visitor automatically.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE cookie_consents (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id         INT UNSIGNED NULL,
                anon_id         CHAR(32) NOT NULL,
                action          ENUM('accept_all','reject_all','custom','withdraw') NOT NULL,
                necessary       TINYINT(1) NOT NULL DEFAULT 1,
                preferences     TINYINT(1) NOT NULL DEFAULT 0,
                analytics       TINYINT(1) NOT NULL DEFAULT 0,
                marketing       TINYINT(1) NOT NULL DEFAULT 0,
                policy_version  VARCHAR(32) NOT NULL DEFAULT '1',
                ip_address      VARBINARY(16) NULL,
                user_agent      VARCHAR(500) NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                KEY idx_anon       (anon_id, created_at),
                KEY idx_user       (user_id, created_at),
                KEY idx_created    (created_at),
                CONSTRAINT fk_cc_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS cookie_consents");
    }
};
