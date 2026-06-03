<?php
// modules/menus/migrations/2026_04_29_700000_add_kind_to_menu_items.php
use Core\Database\Migration;

/**
 * Add an explicit `kind` column to menu_items so the public renderer
 * can tell holders (non-clickable parent labels used to group children)
 * apart from regular links and from "submenu parents with no children
 * yet". Before this column the schema let `url` be NULL but couldn't
 * distinguish intent.
 *
 * Backfill: any existing item with NULL url AND children gets kind=
 * 'holder'. Items with NULL url and no children stay 'link' (they're
 * placeholder rows that admins probably meant to fill in later).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE menu_items
              ADD COLUMN kind ENUM('link','holder') NOT NULL DEFAULT 'link'
                  AFTER url
        ");

        // Backfill: rows with NULL url that are referenced as a parent
        // by other rows are clearly holders.
        $this->db->query("
            UPDATE menu_items SET kind = 'holder'
            WHERE url IS NULL
              AND id IN (
                  SELECT parent_id FROM (
                      SELECT DISTINCT parent_id FROM menu_items WHERE parent_id IS NOT NULL
                  ) AS parents
              )
        ");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE menu_items DROP COLUMN kind");
    }
};
