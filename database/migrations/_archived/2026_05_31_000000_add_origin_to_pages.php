<?php
// database/migrations/2026_05_31_000000_add_origin_to_pages.php
//
// Batch B1.2 — track which pages the wizard's TenantSeeder writes so
// republish can safely delete orphans without touching framework-default
// pages (about / terms / privacy / cookie-policy from
// 2026_05_02_200000_seed_default_policy_pages) or admin-authored pages
// added directly through the tenant admin shell.
//
// 'wizard'  — written by TenantSeeder::copyFromProjectTables on first
//             provision or republish from a wizard project.
// NULL      — legacy (any row written before this migration) OR any
//             non-wizard origin (framework default seed, tenant admin
//             handcraft, future API ingestion).
//
// The deletion sweep targets origin='wizard' only.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        if (!$this->columnExists('pages', 'origin')) {
            $this->db->query("ALTER TABLE pages ADD COLUMN origin VARCHAR(20) NULL DEFAULT NULL AFTER status");
        }
    }

    public function down(): void
    {
        if ($this->columnExists('pages', 'origin')) {
            $this->db->query("ALTER TABLE pages DROP COLUMN origin");
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
