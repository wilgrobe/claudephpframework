<?php
// database/migrations/2026_06_02_000000_add_hide_title_to_pages.php
//
// Composer-redesign follow-up — opt-in per-page suppression of the
// framework's default `.page-hero` block (the H1 the public/page.php
// view renders unconditionally above the page body / composer output).
//
// Default 0 preserves existing behaviour. Admins flip this on for
// pages whose layout already contains its own hero/title (e.g. a
// landing page whose composer starts with siteblocks.hero, or a
// /shop module page that controllers its own H1).

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        if (!$this->columnExists('pages', 'hide_title')) {
            $this->db->query("ALTER TABLE pages ADD COLUMN hide_title TINYINT(1) NOT NULL DEFAULT 0 AFTER featured");
        }
    }

    public function down(): void
    {
        if ($this->columnExists('pages', 'hide_title')) {
            $this->db->query("ALTER TABLE pages DROP COLUMN hide_title");
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->db->fetchColumn("
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ", [$table, $column]);
    }
};
