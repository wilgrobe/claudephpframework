<?php
// modules/feedback/migrations/2026_09_29_000000_add_abuse_reports.php
//
// Admits ABUSE reports — a member of the public reporting something a site
// publishes (a hosted page, a listing) — into feedback_submissions, beside
// feedback, testimonials and issue reports. The admin queue filters and counts
// kind='abuse', and a report's context carries `abuse: {source, ref}` naming
// the item, which the publishing module can take down through
// Modules\Feedback\Services\TakedownHandler.
//
// The feedback module owns this table, so it owns the enum value. MarketOtter
// added 'abuse' first from its own module (2026-09-03); this is idempotent, so
// a site that already has the value is untouched.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $type = (string) $this->db->fetchColumn(
            "SELECT COLUMN_TYPE FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'feedback_submissions'
                AND column_name = 'kind'"
        );
        // MODIFY is not conditional, so only run it when 'abuse' is absent —
        // and never on a table that is not there.
        if ($type === '' || str_contains($type, "'abuse'")) { return; }

        $values = ['feedback', 'testimonial', 'issue', 'abuse'];
        if (preg_match_all("/'([^']*)'/", $type, $m)) {
            // Keep any value an install already has, so this can only widen.
            $values = array_values(array_unique(array_merge($m[1], ['abuse'])));
        }
        $enum = implode(',', array_map(static fn($v) => "'" . str_replace("'", "''", $v) . "'", $values));
        $this->db->query("ALTER TABLE feedback_submissions MODIFY kind ENUM($enum) NOT NULL DEFAULT 'feedback'");
    }

    public function down(): void
    {
        // Narrowing an ENUM would destroy any abuse report already filed.
    }
};
