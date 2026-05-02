<?php
// database/migrations/2026_05_02_310000_add_discoverability_to_system_layouts.php
use Core\Database\Migration;

/**
 * Page-chrome Batch A — schema half 2.
 *
 * Adds four nullable columns to `system_layouts` so the admin
 * `/admin/system-layouts` index can show a categorised, friendly list
 * of every layout instead of just the raw `name` slug. None of these
 * columns are required to render a layout — they're purely UX
 * metadata so admins can locate the layout they want to edit.
 *
 *   friendly_name — human-readable label ("Messaging — Inbox view")
 *   module        — owning module slug, used to group rows in the index
 *   category      — optional sub-grouping within a module
 *   description   — sentence-long blurb for the editor header
 *
 * Module migrations seed these via SystemLayoutService::seedLayout()
 * (added in this batch); admins can also overwrite them directly via
 * the layout editor.
 *
 * Idempotent: each ADD COLUMN is wrapped in an information_schema check
 * so re-running the migration is safe.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->hasColumn('friendly_name')) {
            $this->db->query("
                ALTER TABLE system_layouts
                  ADD COLUMN friendly_name VARCHAR(255) NULL AFTER name
            ");
        }
        if (!$this->hasColumn('module')) {
            $this->db->query("
                ALTER TABLE system_layouts
                  ADD COLUMN module VARCHAR(64) NULL AFTER friendly_name
            ");
        }
        if (!$this->hasColumn('category')) {
            $this->db->query("
                ALTER TABLE system_layouts
                  ADD COLUMN category VARCHAR(64) NULL AFTER module
            ");
        }
        if (!$this->hasColumn('description')) {
            $this->db->query("
                ALTER TABLE system_layouts
                  ADD COLUMN description TEXT NULL AFTER category
            ");
        }

        // Index used by the admin index page's "group by module, sort by
        // category then friendly_name" ordering. Keep it cheap — these are
        // VARCHAR(64) so the prefix is small.
        $idx = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'system_layouts'
                AND INDEX_NAME   = 'idx_system_layouts_module_category'"
        );
        if ($idx === null || (int) $idx['c'] === 0) {
            $this->db->query("
                CREATE INDEX idx_system_layouts_module_category
                          ON system_layouts (module, category)
            ");
        }
    }

    public function down(): void
    {
        // Drop index first — column drop won't error on its absence in
        // MySQL, but the index drop will. Wrap in a check for both
        // operations to keep down() idempotent.
        $idx = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'system_layouts'
                AND INDEX_NAME   = 'idx_system_layouts_module_category'"
        );
        if ($idx !== null && (int) $idx['c'] > 0) {
            $this->db->query("DROP INDEX idx_system_layouts_module_category ON system_layouts");
        }

        foreach (['description', 'category', 'module', 'friendly_name'] as $col) {
            if ($this->hasColumn($col)) {
                $this->db->query("ALTER TABLE system_layouts DROP COLUMN `$col`");
            }
        }
    }

    private function hasColumn(string $column): bool
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'system_layouts'
                AND COLUMN_NAME  = ?",
            [$column]
        );
        return $row !== null && (int) $row['c'] > 0;
    }
};
