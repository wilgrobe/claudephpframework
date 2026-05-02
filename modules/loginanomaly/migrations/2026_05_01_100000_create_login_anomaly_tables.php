<?php
// modules/loginanomaly/migrations/2026_05_01_100000_create_login_anomaly_tables.php
use Core\Database\Migration;

/**
 * Schema for login anomaly detection.
 *
 *   login_geo_cache  — per-IP geographic lookup cache. Lookups via the
 *                      configured provider (ip-api.com by default) get
 *                      cached for 30 days so the same IP doesn't hit
 *                      the rate-limited free API on every login.
 *
 *   login_anomalies  — append-only event log. One row per analysed
 *                      sign-in that triggered any flag (impossible
 *                      travel, country jump, etc). Powers the admin
 *                      review surface + retention sweeper.
 *
 * Severity is computed at write time from the implied speed:
 *   info   — different city, plausible time
 *   warn   — impossible travel by ground (>900km/h equivalent)
 *   alert  — impossibly fast (>2000km/h — definitely VPN / proxy hop)
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE login_geo_cache (
                ip_address      VARBINARY(16) NOT NULL PRIMARY KEY,
                country_code    CHAR(2) NULL,
                country_name    VARCHAR(100) NULL,
                region          VARCHAR(100) NULL,
                city            VARCHAR(120) NULL,
                latitude        DECIMAL(9,6) NULL,
                longitude       DECIMAL(9,6) NULL,
                provider        VARCHAR(40) NOT NULL DEFAULT 'ip-api',
                fetched_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at      DATETIME NOT NULL,
                lookup_failed   TINYINT(1) NOT NULL DEFAULT 0
                                COMMENT '1 = provider returned an error; cache the failure to avoid retry storms',
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE login_anomalies (
                id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id            INT UNSIGNED NOT NULL,
                ip_address         VARBINARY(16) NULL,
                user_agent         VARCHAR(500) NULL,
                country_code       CHAR(2) NULL,
                city               VARCHAR(120) NULL,
                prior_country_code CHAR(2) NULL,
                prior_city         VARCHAR(120) NULL,
                distance_km        INT UNSIGNED NULL,
                elapsed_minutes    INT UNSIGNED NULL,
                implied_kmh        INT UNSIGNED NULL
                                   COMMENT 'distance_km * 60 / elapsed_minutes',
                severity           ENUM('info','warn','alert') NOT NULL DEFAULT 'info',
                rule               VARCHAR(60) NOT NULL
                                   COMMENT 'Which detector flagged this: impossible_travel, country_jump, etc.',
                action_taken       VARCHAR(120) NULL
                                   COMMENT 'e.g. emailed_user, audit_only, blocked',
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                acknowledged_at    DATETIME NULL,
                acknowledged_by    INT UNSIGNED NULL,

                KEY idx_user_created  (user_id, created_at),
                KEY idx_severity      (severity, created_at),
                KEY idx_unack         (acknowledged_at, created_at),
                CONSTRAINT fk_la_user FOREIGN KEY (user_id)         REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_la_ack  FOREIGN KEY (acknowledged_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS login_anomalies");
        $this->db->query("DROP TABLE IF EXISTS login_geo_cache");
    }
};
