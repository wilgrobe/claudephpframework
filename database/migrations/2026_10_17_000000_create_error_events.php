<?php
// 2026_10_17_000000_create_error_events.php
//
// Errors recorded on the site's own server (Core\Services\ErrorRecorder): one row per distinct error — its
// fingerprint, how often and when it happened, and one redacted sample. The site's pulse reports new and
// recurring rows to the owner's Ops Assistant, which is what lets it stand in for Sentry.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS error_events (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fingerprint CHAR(40) NOT NULL,
            level       VARCHAR(8) NOT NULL DEFAULT 'error',
            class       VARCHAR(190) NOT NULL DEFAULT '',
            message     TEXT NULL,
            file        VARCHAR(255) NOT NULL DEFAULT '',
            line        INT UNSIGNED NOT NULL DEFAULT 0,
            trace       TEXT NULL,
            url         VARCHAR(500) NULL,
            method      VARCHAR(8) NULL,
            user_id     BIGINT UNSIGNED NULL,
            runtime     VARCHAR(8) NOT NULL DEFAULT 'web',
            count       INT UNSIGNED NOT NULL DEFAULT 1,
            first_seen  DATETIME NOT NULL,
            last_seen   DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_error_events_fingerprint (fingerprint),
            KEY idx_error_events_last_seen (last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS error_events');
    }
};
