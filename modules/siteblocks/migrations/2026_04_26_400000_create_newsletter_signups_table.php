<?php
// modules/siteblocks/migrations/2026_04_26_400000_create_newsletter_signups_table.php
use Core\Database\Migration;

/**
 * `newsletter_signups` — captures emails posted from the
 * siteblocks.newsletter_signup block.
 *
 * For v1, this is just local storage. Integrating with a third-party
 * provider (Mailchimp, SendGrid, Buttondown, ConvertKit) is intentionally
 * deferred — see the external-services list. The table is the durable
 * record either way; an admin can dump CSV or wire a sync job later.
 *
 * Schema notes:
 *   - email is UNIQUE so an upsert on resubscribe just refreshes
 *     subscribed_at / clears unsubscribed_at.
 *   - source_url + user_agent + ip_address are forensics for spam
 *     attribution; no PII risk that's not already in users.
 *   - unsubscribed_at NULL means active.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS newsletter_signups (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email           VARCHAR(255) NOT NULL,
                source_url      VARCHAR(500) NULL,
                user_agent      VARCHAR(500) NULL,
                ip_address      VARCHAR(45)  NULL,
                subscribed_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                unsubscribed_at TIMESTAMP    NULL,
                UNIQUE KEY uq_email (email),
                INDEX idx_subscribed (subscribed_at),
                INDEX idx_active (unsubscribed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS newsletter_signups");
    }
};
