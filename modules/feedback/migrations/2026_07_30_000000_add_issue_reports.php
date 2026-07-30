<?php
// modules/feedback/migrations/2026_07_30_000000_add_issue_reports.php
//
// Extends feedback_submissions to carry in-app ISSUE REPORTS filed from the
// corner widget (the "Report an issue" bubble / footer link), alongside the
// existing page-form feedback + testimonials.
//
// An issue report answers two questions instead of one, so it needs a second
// free-text column (`intent` = "what were you trying to do"; the existing
// `message` holds "what happened"). Everything else the reporter never types —
// who they are, where they were, what the browser saw — rides in `context`.
//
// `context` is LONGTEXT, not JSON: MariaDB aliases JSON to LONGTEXT anyway and
// some shared hosts (DreamHost) run MySQL/MariaDB builds where a JSON column
// plus its implicit CHECK is a portability risk for a column we only ever
// read back whole and json_decode() in PHP.
//
// Idempotent throughout — each ALTER is guarded on information_schema so the
// migration is safe to re-run and safe on installs that added a column by hand.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        // ── New columns ───────────────────────────────────────────────
        $cols = [
            // "What were you trying to do?" — the reporter's intent. The
            // existing `message` column holds "What happened instead?".
            'intent'    => "ADD COLUMN intent TEXT NULL COMMENT 'issue reports: what the user was trying to do' AFTER message",

            // Who filed it, when we know. Distinct from `name`/`email`, which
            // are self-typed and may be absent/anonymous on page-form feedback.
            'user_id'   => "ADD COLUMN user_id BIGINT UNSIGNED NULL COMMENT 'signed-in reporter, when known' AFTER is_anonymous",

            // The page they were on. Pulled out of `context` into its own
            // column because the admin queue lists and filters on it.
            'page_url'  => "ADD COLUMN page_url VARCHAR(500) NULL COMMENT 'URL the report was filed from' AFTER user_id",

            // Blocking vs annoying — the reporter's own call, used for triage
            // ordering and to mark the notification email urgent.
            'severity'  => "ADD COLUMN severity ENUM('normal','blocking') NOT NULL DEFAULT 'normal' AFTER page_url",

            // Everything captured silently: environment, viewport, JS errors,
            // failed requests, click breadcrumbs. JSON-encoded blob.
            'context'   => "ADD COLUMN context LONGTEXT NULL COMMENT 'JSON diagnostics captured with an issue report'",
        ];

        foreach ($cols as $name => $ddl) {
            if ($this->hasColumn('feedback_submissions', $name)) continue;
            $this->db->query("ALTER TABLE feedback_submissions {$ddl}");
        }

        // ── Widen the `kind` enum to admit 'issue' ────────────────────
        // MODIFY is not conditional, so only run it when 'issue' is absent.
        $type = (string) $this->db->fetchColumn(
            "SELECT COLUMN_TYPE FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'feedback_submissions'
                AND column_name = 'kind'"
        );
        if ($type !== '' && !str_contains($type, "'issue'")) {
            $this->db->query("
                ALTER TABLE feedback_submissions
                MODIFY kind ENUM('feedback','testimonial','issue')
                       NOT NULL DEFAULT 'feedback'
            ");
        }

        // ── Index for the admin queue's per-reporter lookups ──────────
        $idx = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = 'feedback_submissions'
                AND index_name = 'idx_feedback_user'"
        );
        if ($idx === 0) {
            $this->db->query("ALTER TABLE feedback_submissions ADD INDEX idx_feedback_user (user_id)");
        }
    }

    public function down(): void
    {
        // Don't drop. These columns hold filed customer reports; removing them
        // would destroy the only record of an issue someone took the time to
        // report. Rolling back the feature just stops new writes.
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = ? AND column_name = ?",
            [$table, $column]
        ) > 0;
    }
};
