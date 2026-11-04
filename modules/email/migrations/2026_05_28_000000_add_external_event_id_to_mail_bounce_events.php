<?php
// modules/email/migrations/2026_05_28_000000_add_external_event_id_to_mail_bounce_events.php
//
// Phase 43.184 — webhook idempotency. Pre-fix, mail_bounce_events had no
// dedup column; every webhook retry from the provider (delivery
// glitches, intentional duplicate-fire for HMAC re-test) created a new
// row in the log. Suppression itself is already idempotent (UNIQUE on
// (email, category_slug) in mail_suppressions), but the event log grew
// unbounded.
//
// Each provider emits a stable per-event id we can use:
//   - SES SNS:        outer payload's `MessageId` (SNS-level)
//   - SendGrid:       per-event `sg_event_id`
//   - Postmark:       per-event `MessageID` + `RecordType` disambiguator
//   - Mailgun:        `event-data.id`
//   - SMTP2GO:        per-event `event_id` (when present)
//
// New column + UNIQUE (provider, external_event_id) — the controller's
// logEvent uses INSERT IGNORE on conflict so retries silently dedupe.
// Existing rows have NULL external_event_id; UNIQUE on (provider, NULL)
// permits multiple NULLs so legacy data + future events with no id
// (rare) coexist without conflict.

return new class extends \Core\Database\Migration {
    public function up(): void
    {
        // Idempotent: check column existence first so re-runs (and the
        // catch-up sweep against existing tenant DBs) don't choke.
        $col = $this->db->fetchOne(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'mail_bounce_events'
               AND column_name = 'external_event_id' LIMIT 1",
            []
        );
        if ($col) return;

        $this->db->query("
            ALTER TABLE mail_bounce_events
                ADD COLUMN external_event_id VARCHAR(120) NULL
                    COMMENT 'Provider-emitted stable event id for dedup; NULL on legacy rows or events with no provider id'
                    AFTER provider
        ");

        // UNIQUE on (provider, external_event_id) — MySQL allows multiple
        // NULL values in a UNIQUE index (per SQL standard), which is the
        // desired behavior: legacy rows + future no-id events don't
        // collide.
        $this->db->query("
            ALTER TABLE mail_bounce_events
                ADD UNIQUE KEY uniq_provider_event (provider, external_event_id)
        ");
    }

    public function down(): void
    {
        // Defensive — drop the UNIQUE first, then the column.
        try {
            $this->db->query("ALTER TABLE mail_bounce_events DROP INDEX uniq_provider_event");
        } catch (\Throwable) { /* ignore */ }
        try {
            $this->db->query("ALTER TABLE mail_bounce_events DROP COLUMN external_event_id");
        } catch (\Throwable) { /* ignore */ }
    }
};
