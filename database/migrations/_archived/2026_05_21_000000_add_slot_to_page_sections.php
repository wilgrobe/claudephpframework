<?php
// database/migrations/2026_05_21_000000_add_slot_to_page_sections.php
//
// Phase 43.47 — tenant-side mirror of the central migration that
// adds `slot` to project_page_sections. The tenant's page_sections
// table gains the same column so:
//
//   - Static seed/custom pages render via SectionLayoutRenderer
//     filtering for slot='main' (default; existing rows backfill
//     here naturally).
//
//   - Dynamic module-shipped pages (e.g. /shop) render their main
//     content from the module's controller; UserSectionInjector
//     pulls page_sections WHERE slot IN ('top','bottom') and wraps
//     the controller's output with them.
//
// Idempotent — re-runs no-op via information_schema existence check.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $exists = $this->db->fetchColumn("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = 'page_sections'
               AND column_name  = 'slot'
        ", []);
        if ((int) $exists > 0) return;

        $this->db->query("
            ALTER TABLE page_sections
                ADD COLUMN slot ENUM('top','main','bottom') NOT NULL DEFAULT 'main'
                COMMENT 'Phase 43.47 — main = own-page composer; top/bottom = injection slot around a dynamic module-rendered page'
                AFTER sort_order
        ");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE page_sections DROP COLUMN slot");
    }
};
