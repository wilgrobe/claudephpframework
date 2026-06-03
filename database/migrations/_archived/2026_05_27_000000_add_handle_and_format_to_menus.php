<?php
// database/migrations/2026_05_27_000000_add_handle_and_format_to_menus.php
//
// Phase 43.157 — menus runtime + siteblocks.menu block.
//
// Adds two columns to the framework's `menus` table mirroring the
// Phase 43.152 columns on central's `project_menus`:
//   - handle VARCHAR(100) NULL — block-style identifier picked in the
//     wizard's step 6 menus modal. The new siteblocks.menu block
//     looks menus up by this handle.
//   - format_settings JSON NULL — presentation knobs (orientation /
//     bounded / spacing / child_display / link-state colors).
//
// Idempotent column-existence guards. TenantSeeder writes these
// columns when present + skips them when absent so the seeder runs
// cleanly against tenants pre-migration.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        if (!$this->columnExists('menus', 'handle')) {
            $this->db->query("
                ALTER TABLE menus
                    ADD COLUMN handle VARCHAR(100) NULL AFTER `name`,
                    ADD UNIQUE KEY uniq_menu_handle (handle)
            ");
        }
        if (!$this->columnExists('menus', 'format_settings')) {
            $this->db->query("
                ALTER TABLE menus
                    ADD COLUMN format_settings JSON NULL AFTER `description`
            ");
        }
    }

    public function down(): void
    {
        if ($this->columnExists('menus', 'format_settings')) {
            $this->db->query("ALTER TABLE menus DROP COLUMN format_settings");
        }
        if ($this->columnExists('menus', 'handle')) {
            $this->db->query("
                ALTER TABLE menus
                    DROP INDEX uniq_menu_handle,
                    DROP COLUMN handle
            ");
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
