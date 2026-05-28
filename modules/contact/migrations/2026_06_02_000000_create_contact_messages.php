<?php
// modules/contact/migrations/2026_06_02_000000_create_contact_messages.php
//
// Default contact form for every site — closes the "scrape-bait contact
// page" problem where the seeded contact page exposes emails / phones /
// social handles directly to spammers.
//
// Per-site table; on multi-tenant installs each tenant DB gets its own
// copy (migrator runs in each tenant context). Status enum supports a
// minimal "new → read → replied → archived" admin queue lifecycle.
//
// IP + user_agent captured for abuse-pattern detection — kept here
// rather than punted to audit_log so admins can spot a single IP
// spamming submissions without grepping a separate table.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        if ($this->tableExists('contact_messages')) return;

        $this->db->query("
            CREATE TABLE contact_messages (
                id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name            VARCHAR(200) NOT NULL,
                email           VARCHAR(254) NOT NULL,
                phone           VARCHAR(40) NULL,
                subject         VARCHAR(255) NULL,
                body            TEXT NOT NULL,
                ip              VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 of submitter',
                user_agent      VARCHAR(500) NULL,
                status          ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at         TIMESTAMP NULL,
                replied_at      TIMESTAMP NULL,
                archived_at     TIMESTAMP NULL,

                KEY idx_status (status),
                KEY idx_created_at (created_at),
                KEY idx_email (email),
                KEY idx_ip (ip)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed sensible defaults so a fresh install has a working
        // contact form without touching settings. Recipients fall back
        // to site.contact_email at runtime when this is unset.
        // settings.type ENUM accepts 'string'|'integer'|'boolean'|'json'|'text'
        $this->seedSetting('contact_form_enabled',        '1',  'boolean');
        $this->seedSetting('contact_notify_enabled',      '1',  'boolean');
        $this->seedSetting('contact_autoreply_enabled',   '0',  'boolean');
        $this->seedSetting('contact_autoreply_body',      '',   'text');
        $this->seedSetting('contact_recipient_emails',    '',   'string');
        $this->seedSetting('contact_rate_limit_per_hour', '5',  'integer');
        $this->seedSetting('contact_min_seconds',         '3',  'integer');
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS contact_messages");
        // Settings rows are left in place — destructive-clean would
        // make a re-up forget admin's recipient list across a roll.
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->fetchColumn("
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ", [$table]);
    }

    private function seedSetting(string $key, string $value, string $type): void
    {
        $exists = $this->db->fetchColumn(
            "SELECT 1 FROM settings WHERE `key` = ? AND scope = 'site' LIMIT 1",
            [$key]
        );
        if ($exists) return;

        $this->db->query(
            "INSERT INTO settings (scope, scope_key, `key`, value, type)
             VALUES ('site', NULL, ?, ?, ?)",
            [$key, $value, $type]
        );
    }
};
