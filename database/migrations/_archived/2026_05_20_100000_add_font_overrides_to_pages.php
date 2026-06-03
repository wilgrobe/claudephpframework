<?php
// database/migrations/2026_05_20_100000_add_font_overrides_to_pages.php
//
// Phase 43.32 — per-page font overrides on the framework-level `pages`
// table. Mirrors the central-side migration that adds the same six
// columns to `project_pages`. NULL = inherit site-level branding
// (the BrandingRenderer's three-slot output from Phase 43.31).
//
// Idempotent: per-column existence check so re-runs no-op.

use Core\Database\Migration;

return new class extends Migration {

    public function up(): void
    {
        $cols = $this->db->fetchAll("
            SELECT column_name
              FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = 'pages'
        ", []);
        $have = [];
        foreach ($cols as $r) {
            $have[strtolower((string) ($r['column_name'] ?? $r['COLUMN_NAME'] ?? ''))] = true;
        }

        $add = [];
        if (!isset($have['font_url_body']))       $add[] = "ADD COLUMN font_url_body       VARCHAR(1000) NULL COMMENT 'per-page override; NULL = inherit site-level branding'";
        if (!isset($have['font_family_body']))    $add[] = "ADD COLUMN font_family_body    VARCHAR(255)  NULL COMMENT 'per-page CSS font-family stack for body text'";
        if (!isset($have['font_url_heading']))    $add[] = "ADD COLUMN font_url_heading    VARCHAR(1000) NULL COMMENT 'per-page override; NULL = inherit site-level branding'";
        if (!isset($have['font_family_heading'])) $add[] = "ADD COLUMN font_family_heading VARCHAR(255)  NULL COMMENT 'per-page CSS font-family stack for h1/h2/h3'";
        if (!isset($have['font_url_mono']))       $add[] = "ADD COLUMN font_url_mono       VARCHAR(1000) NULL COMMENT 'per-page override; NULL = inherit site-level branding'";
        if (!isset($have['font_family_mono']))    $add[] = "ADD COLUMN font_family_mono    VARCHAR(255)  NULL COMMENT 'per-page CSS font-family stack for code/pre/kbd'";

        if (!empty($add)) {
            $this->db->query("ALTER TABLE pages " . implode(', ', $add));
        }
    }

    public function down(): void
    {
        $this->db->query("
            ALTER TABLE pages
                DROP COLUMN font_url_body,
                DROP COLUMN font_family_body,
                DROP COLUMN font_url_heading,
                DROP COLUMN font_family_heading,
                DROP COLUMN font_url_mono,
                DROP COLUMN font_family_mono
        ");
    }
};
