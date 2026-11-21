<?php
use Core\Database\Migration;

/**
 * Get found phase 3: a page can be hidden from search and can name its canonical address.
 *   pages.search_hidden  1 = "noindex, follow" on the page, left out of the sitemap, llms.txt and IndexNow
 *   pages.canonical_url  '' = the page's own address; else a path on this site or a full address elsewhere
 * Set on /admin/settings/search (Pages), and by the Builder wizard (step 7) for the pages it ships.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->db->fetchColumn("SHOW TABLES LIKE 'pages'")) { return; }
        $has = fn (string $c) => (bool) $this->db->fetchOne(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'pages' AND column_name = ?",
            [$c]
        );
        if (!$has('search_hidden')) {
            $this->db->query("ALTER TABLE pages ADD COLUMN search_hidden TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!$has('canonical_url')) {
            $this->db->query("ALTER TABLE pages ADD COLUMN canonical_url VARCHAR(1000) NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        if (!$this->db->fetchColumn("SHOW TABLES LIKE 'pages'")) { return; }
        foreach (['canonical_url', 'search_hidden'] as $c) {
            if ($this->db->fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'pages' AND column_name = ?",
                [$c]
            )) {
                $this->db->query("ALTER TABLE pages DROP COLUMN $c");
            }
        }
    }
};
