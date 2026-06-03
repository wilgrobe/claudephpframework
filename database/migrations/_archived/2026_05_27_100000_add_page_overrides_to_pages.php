<?php
// database/migrations/2026_05_27_100000_add_page_overrides_to_pages.php
//
// Phase 43.164 — per-page color overrides + custom CSS on framework
// pages table (mirror of project_pages columns added by the central
// migration at the same date).
//
// Tenant runtime reads these to emit a page-specific <style> block.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        if (!$this->columnExists('pages', 'color_overrides')) {
            $this->db->query("ALTER TABLE pages ADD COLUMN color_overrides JSON NULL");
        }
        if (!$this->columnExists('pages', 'custom_css')) {
            $this->db->query("ALTER TABLE pages ADD COLUMN custom_css TEXT NULL");
        }
    }

    public function down(): void
    {
        if ($this->columnExists('pages', 'custom_css'))      $this->db->query("ALTER TABLE pages DROP COLUMN custom_css");
        if ($this->columnExists('pages', 'color_overrides')) $this->db->query("ALTER TABLE pages DROP COLUMN color_overrides");
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->db->fetchColumn("
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ", [$table, $column]);
    }
};
