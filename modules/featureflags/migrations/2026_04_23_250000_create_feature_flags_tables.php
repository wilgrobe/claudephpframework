<?php
// modules/featureflags/migrations/2026_04_23_250000_create_feature_flags_tables.php
use Core\Database\Migration;

/**
 * Schema for the feature-flags module.
 *
 *   feature_flags              — one row per flag key. `enabled` is
 *                                the global on/off. `rollout_percent`
 *                                (0-100) enables a deterministic-hash
 *                                percentage rollout on top; when <100,
 *                                users whose hash(user_id, key) falls
 *                                below the bar see it. `groups_json` is
 *                                an optional array of group_ids; users
 *                                in any listed group see the flag
 *                                regardless of percentage.
 *
 *   feature_flag_overrides     — per-user overrides (enabled or
 *                                disabled explicitly). PK (user_id,
 *                                flag_key) dedups. Takes precedence
 *                                over every other rule — useful for
 *                                forcing a flag on for a QA account or
 *                                off for a user who reported a bug.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE feature_flags (
                `key`           VARCHAR(120) NOT NULL PRIMARY KEY,
                label           VARCHAR(191) NOT NULL,
                description     TEXT NULL,
                enabled         TINYINT(1) NOT NULL DEFAULT 0,
                rollout_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
                groups_json     TEXT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_rollout_pct CHECK (rollout_percent BETWEEN 0 AND 100)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE feature_flag_overrides (
                user_id   INT UNSIGNED NOT NULL,
                flag_key  VARCHAR(120) NOT NULL,
                enabled   TINYINT(1) NOT NULL,
                note      VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (user_id, flag_key),
                KEY idx_flag (flag_key),
                CONSTRAINT fk_ffo_flag FOREIGN KEY (flag_key)
                    REFERENCES feature_flags (`key`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS feature_flag_overrides");
        $this->db->query("DROP TABLE IF EXISTS feature_flags");
    }
};
