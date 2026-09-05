<?php
// 2026_06_13_000000_widen_page_sections_slot_left_right.php
//
// Widen page_sections.slot to add 'left' + 'right' alongside 'top'/'main'/'bottom'.
//
// The fixed 3-pane layout (a centre feed with side rails) composes rail content
// into left/right section slots: the page composer's per-section Slot picker
// writes those values, and SectionLayoutRenderer::renderSlot('left'|'right')
// renders each rail.
//
// The baseline schema declares only top/main/bottom. Without this migration the
// Slot picker still renders and still reports "Layout saved" -- the section
// insert catches the rejected value and retries without the slot column, so the
// rail assignment is silently dropped rather than erroring. A control that lies.
//
// MODIFY COLUMN is idempotent, and the whole migration is guarded on the column
// existing, so it is a no-op against a schema that predates page_sections.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $col = $this->db->fetchOne(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'page_sections' AND column_name = 'slot'"
        );
        if (!$col) return;

        $this->db->query(
            "ALTER TABLE page_sections
             MODIFY COLUMN slot ENUM('top','main','bottom','left','right') NOT NULL DEFAULT 'main'"
        );
    }

    public function down(): void
    {
        // No-op (wider enum is harmless; down would risk truncating left/right rows).
    }
};
