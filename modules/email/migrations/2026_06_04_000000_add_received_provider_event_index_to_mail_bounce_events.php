<?php
// modules/email/migrations/2026_06_04_000000_add_received_provider_event_index_to_mail_bounce_events.php
//
// Perf (scan 2026-06-04, M3) — the deliverability bounce-rate rollup runs
//   SELECT provider, event_type, COUNT(*) FROM mail_bounce_events
//   WHERE received_at >= ? GROUP BY provider, event_type
// (DnsAuthChecker::aggregateBounceEvents, /admin/email-deliverability). The
// existing idx_provider_received leads with `provider`, so a WHERE on
// `received_at` alone can't use it → full-table scan + filesort-group. This is
// the highest-churn email table (one row per provider webhook event). Add a
// covering (received_at, provider, event_type) index: leading received_at drives
// the range, trailing columns let the GROUP BY run from the index. Idempotent +
// table-guarded.
return new class extends \Core\Database\Migration {
    public function up(): void
    {
        $tbl = $this->db->fetchOne(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'mail_bounce_events' LIMIT 1",
            []
        );
        if (!$tbl) return;

        $exists = $this->db->fetchOne(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'mail_bounce_events'
               AND index_name = 'idx_received_provider_event' LIMIT 1",
            []
        );
        if ($exists) return;

        $this->db->query("
            ALTER TABLE mail_bounce_events
                ADD KEY idx_received_provider_event (received_at, provider, event_type)
        ");
    }

    public function down(): void
    {
        try {
            $this->db->query("ALTER TABLE mail_bounce_events DROP INDEX idx_received_provider_event");
        } catch (\Throwable) { /* ignore */ }
    }
};
