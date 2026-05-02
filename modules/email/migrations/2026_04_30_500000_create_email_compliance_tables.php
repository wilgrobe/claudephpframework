<?php
// modules/email/migrations/2026_04_30_500000_create_email_compliance_tables.php
use Core\Database\Migration;

/**
 * Schema for email compliance — categories, suppressions, blocked-send log.
 *
 *   mail_categories         — registry of category slugs (transactional,
 *                             marketing, product_updates, social, etc.).
 *                             is_transactional=1 marks categories that
 *                             can ONLY be suppressed by hard bounce or
 *                             complaint, not by user opt-out. CAN-SPAM
 *                             § 5(a)(5)(B) carve-out (transactional or
 *                             relationship messages).
 *
 *   mail_suppressions       — one row per (email, category, source).
 *                             Suppression takes effect immediately; the
 *                             SuppressionService::isAllowed check at
 *                             send time consults this. user_id is
 *                             NULLed on GDPR erasure but the row stays
 *                             so the suppression remains effective.
 *
 *   mail_suppression_blocks — log of skipped sends. Useful for the
 *                             admin UI's "what got blocked recently"
 *                             view + for debugging when senders complain
 *                             that a transactional email never arrived.
 *
 *   mail_bounce_events      — raw provider webhook events. Stored
 *                             append-only for forensic / replay purposes;
 *                             the actual suppression is created by the
 *                             webhook handler after parsing.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE mail_categories (
                id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug              VARCHAR(64) NOT NULL UNIQUE,
                label             VARCHAR(191) NOT NULL,
                description       TEXT NULL,
                is_transactional  TINYINT(1) NOT NULL DEFAULT 0
                                  COMMENT '1 = exempt from user opt-out (still suppressed by hard bounce/complaint)',
                is_system         TINYINT(1) NOT NULL DEFAULT 0,
                sort_order        INT NOT NULL DEFAULT 0,
                created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE mail_suppressions (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email         VARCHAR(255) NOT NULL,
                category_slug VARCHAR(64)  NOT NULL
                              COMMENT 'matches mail_categories.slug; ALL suppresses every category',
                reason        ENUM('user_unsubscribe','hard_bounce','complaint','manual_admin','api','spam_report') NOT NULL,
                source_ip     VARBINARY(16) NULL
                              COMMENT 'IP that triggered the suppression (unsubscribe click, webhook IP, etc.)',
                user_id       INT UNSIGNED NULL,
                notes         TEXT NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_email_cat (email, category_slug),
                KEY idx_email   (email),
                KEY idx_user    (user_id),
                CONSTRAINT fk_msup_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE mail_suppression_blocks (
                id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email         VARCHAR(255) NOT NULL,
                category_slug VARCHAR(64)  NOT NULL,
                subject       VARCHAR(500) NULL,
                blocked_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_email_blocked (email, blocked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE mail_bounce_events (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider    VARCHAR(40) NOT NULL
                            COMMENT 'ses, sendgrid, postmark, mailgun, etc.',
                event_type  VARCHAR(40) NOT NULL
                            COMMENT 'bounce, complaint, delivered, dropped, etc.',
                email       VARCHAR(255) NOT NULL,
                payload     LONGTEXT NULL
                            COMMENT 'Full original webhook body for forensic replay',
                processed   TINYINT(1) NOT NULL DEFAULT 0,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                KEY idx_provider_received (provider, received_at),
                KEY idx_email_event (email, event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS mail_bounce_events");
        $this->db->query("DROP TABLE IF EXISTS mail_suppression_blocks");
        $this->db->query("DROP TABLE IF EXISTS mail_suppressions");
        $this->db->query("DROP TABLE IF EXISTS mail_categories");
    }
};
