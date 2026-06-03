<?php
// modules/pages/migrations/2026_04_28_400000_add_featured_to_pages.php
use Core\Database\Migration;

/**
 * Adds a `featured` flag to pages so admins can curate a "featured
 * pages" set that the pages.featured block surfaces. Indexed because
 * the discovery query is `WHERE featured=1 AND status='published'
 * ORDER BY updated_at DESC` — selectivity is low (the featured set is
 * a tiny fraction of total rows), so the index pays off quickly.
 *
 * Default 0 means existing rows are not retroactively featured.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE pages
              ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public,
              ADD KEY idx_featured (featured)
        ");
    }

    public function down(): void
    {
        $this->db->query("
            ALTER TABLE pages
              DROP KEY idx_featured,
              DROP COLUMN featured
        ");
    }
};
