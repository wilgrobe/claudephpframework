<?php
// database/migrations/2026_05_02_600000_add_chromed_url_to_system_layouts.php
use Core\Database\Migration;

/**
 * Page-chrome Batch C follow-up: explicit `chromed_url` column on
 * `system_layouts` so the /admin/system-layouts index can render a
 * "View ↗" button next to "Edit" for each layout that wraps a real
 * customer-facing page.
 *
 * The slug-to-URL inference from Batch C ("dot → slash, hyphen
 * passes through") works for every conversion we've shipped so far —
 * but it's brittle as a long-term contract: legacy layouts like
 * `dashboard_main` and `dashboard_stats` aren't standalone URLs (they're
 * partials inside /dashboard), and a future layout slug might
 * legitimately not map cleanly to a URL. Storing the URL explicitly
 * removes the ambiguity and lets each seed migration declare exactly
 * what page its layout chromes.
 *
 * `chromed_url` is intentionally NULL-able. NULL means "this layout
 * isn't a page" — the admin index hides the View button for that row.
 *
 * Batch C-shipped slugs are backfilled to their URLs in the same
 * up() so the user's existing install gets the View buttons without
 * needing to re-run any module migrations. Fresh installs get the
 * URLs through the updated seedLayout() helper that now persists
 * chromed_url alongside the other metadata.
 *
 * Idempotent: column-existence guarded; backfill UPDATE is restricted
 * to rows where chromed_url IS NULL (so a re-run after admin edits
 * preserves customisations).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->hasColumn('chromed_url')) {
            $this->db->query("
                ALTER TABLE system_layouts
                  ADD COLUMN chromed_url VARCHAR(255) NULL AFTER description
            ");
        }

        // Backfill — slug → URL for every layout shipped through Batch
        // C. Each UPDATE is gated on `chromed_url IS NULL` so this
        // doesn't overwrite an admin's manual edit (admin will be able
        // to set chromed_url through the editor in a future polish pass;
        // for now the editor leaves it alone).
        $backfill = [
            'account.data'                => '/account/data',
            'profile'                     => '/profile',
            'profile.edit'                => '/profile/edit',
            'faq'                         => '/faq',
            'account.email-preferences'   => '/account/email-preferences',
            'account.policies'            => '/account/policies',
            'search'                      => '/search',
            // Dashboard partials intentionally left unset — `dashboard_main`
            // and `dashboard_stats` aren't standalone URLs; they're
            // composed inside /dashboard. Setting chromed_url to /dashboard
            // for both would put two View buttons on the index linking
            // to the same place, which is more confusing than helpful.
        ];
        foreach ($backfill as $slug => $url) {
            $this->db->query(
                "UPDATE system_layouts SET chromed_url = ?
                  WHERE name = ? AND chromed_url IS NULL",
                [$url, $slug]
            );
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('chromed_url')) {
            $this->db->query("ALTER TABLE system_layouts DROP COLUMN chromed_url");
        }
    }

    private function hasColumn(string $column): bool
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'system_layouts'
                AND COLUMN_NAME  = ?",
            [$column]
        );
        return $row !== null && (int) $row['c'] > 0;
    }
};
