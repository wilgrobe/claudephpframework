<?php
// database/migrations/2026_05_30_000000_add_style_overrides_to_pages.php
//
// Phase 43.206 — per-page style overrides on the tenant-side pages table.
//
// Parallel to the central project_pages migration. TenantSeeder copies
// the JSON through at publish/republish; the tenant runtime emits it
// as :root CSS var overrides on each page render (alongside the existing
// color_overrides + per-page fonts).

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        if (!$this->columnExists('pages', 'style_overrides')) {
            $this->db->query("ALTER TABLE pages ADD COLUMN style_overrides JSON NULL");
        }
    }

    public function down(): void
    {
        if ($this->columnExists('pages', 'style_overrides')) {
            $this->db->query("ALTER TABLE pages DROP COLUMN style_overrides");
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
