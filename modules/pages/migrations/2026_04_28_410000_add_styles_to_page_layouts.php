<?php
// modules/pages/migrations/2026_04_28_410000_add_styles_to_page_layouts.php
use Core\Database\Migration;

/**
 * Adds per-row, per-cell, and per-placement style metadata to the page
 * composer. Three separate JSON columns so each level can carry its
 * own shape:
 *
 *   page_layouts.row_styles    — array indexed by row number:
 *     [
 *       0 => ['bg_color'=>'#fef3c7','bg_image'=>'','full_bleed'=>true,'content_padding_px'=>32],
 *       1 => [...]
 *     ]
 *
 *   page_layouts.cell_styles   — assoc keyed by "row-col":
 *     {
 *       "0-0": {"bg_color":"#ffffff","bg_image":"","padding_px":16},
 *       "1-2": {...}
 *     }
 *
 *   page_block_placements.style — per-placement wrapper styling:
 *     {"bg_color":"#fee","bg_image":"","padding_px":12,"radius_px":8}
 *
 * All nullable — existing rows continue to render unstyled. The
 * PageLayoutService sanitises the values on save so a pasted
 * `bg_color = "javascript:…"` can't slip through into rendered CSS.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE page_layouts
              ADD COLUMN row_styles  JSON NULL AFTER row_heights,
              ADD COLUMN cell_styles JSON NULL AFTER row_styles
        ");
        $this->db->query("
            ALTER TABLE page_block_placements
              ADD COLUMN style JSON NULL AFTER settings
        ");
    }

    public function down(): void
    {
        $this->db->query("
            ALTER TABLE page_layouts
              DROP COLUMN cell_styles,
              DROP COLUMN row_styles
        ");
        $this->db->query("ALTER TABLE page_block_placements DROP COLUMN style");
    }
};
