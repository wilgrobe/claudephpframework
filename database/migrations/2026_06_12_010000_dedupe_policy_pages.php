<?php
// 2026_06_12_010000_dedupe_policy_pages.php
//
// Cleanup for installs seeded before the 0500 policy-seed fix. Older DBs got
// FOUR policy pages — full-content `terms`/`privacy` (from seedPolicyPages) AND
// one-liner stubs `terms-of-service`/`privacy-policy` (from seedDefaultPages) —
// with the footer menu pointing at the stubs. This drops the uncustomised stub
// pages and repoints their footer links to the full-content slugs. Runs on the
// apex DB (php artisan migrate) and every tenant DB (the per-tenant migrate
// sweep). Idempotent + safe: only acts when the EXACT seeded stub body is still
// present, so a customised page (and its links) is left untouched.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $stubs = [
            'privacy-policy'   => ['body' => '<h1>Privacy Policy</h1><p>Your privacy matters to us.</p>',                       'to' => '/privacy'],
            'terms-of-service' => ['body' => '<h1>Terms of Service</h1><p>By using this service you agree to these terms.</p>', 'to' => '/terms'],
        ];

        foreach ($stubs as $slug => $info) {
            // Only touch the page if it's still the uncustomised one-liner stub.
            $stub = $this->db->fetchOne(
                "SELECT id FROM pages WHERE slug = ? AND body = ?",
                [$slug, $info['body']]
            );
            if (!$stub) continue; // customised or absent — leave page + its links alone

            $this->db->query("DELETE FROM pages WHERE id = ?", [(int) $stub['id']]);
            // Repoint any menu items that linked to the stub → the full-content slug.
            $this->db->query(
                "UPDATE menu_items SET url = ? WHERE url = ?",
                [$info['to'], '/' . $slug]
            );
        }
    }

    public function down(): void
    {
        // No-op — this migration removes duplicate seed data; restoring the
        // one-liner stubs (and re-pointing the footer back at them) would
        // re-introduce the bug it fixes.
    }
};
