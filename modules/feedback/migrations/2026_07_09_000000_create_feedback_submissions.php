<?php
// modules/feedback/migrations/2026_07_09_000000_create_feedback_submissions.php
//
// Standardized end-user feedback for every site — a public /feedback page
// where visitors leave feedback (anonymously or not, optionally requesting a
// reply) or submit a testimonial (never anonymous). Per-site table; on
// multi-tenant installs each tenant DB gets its own copy.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS feedback_submissions (
                id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kind             ENUM('feedback','testimonial') NOT NULL DEFAULT 'feedback',
                prompt           VARCHAR(160) NULL COMMENT 'which question the response addresses',
                message          TEXT NOT NULL,
                rating           TINYINT UNSIGNED NULL COMMENT '1-5, optional',
                is_anonymous     TINYINT(1) NOT NULL DEFAULT 1,
                name             VARCHAR(200) NULL,
                email            VARCHAR(254) NULL,
                request_response TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'submitter wants a reply',
                consent_display  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'testimonial: ok to publish',
                ip               VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 of submitter',
                user_agent       VARCHAR(500) NULL,
                status           ENUM('new','reviewed','published','archived') NOT NULL DEFAULT 'new',
                responded_at     TIMESTAMP NULL,
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_feedback_status (status),
                INDEX idx_feedback_kind (kind),
                INDEX idx_feedback_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS feedback_submissions");
    }
};
