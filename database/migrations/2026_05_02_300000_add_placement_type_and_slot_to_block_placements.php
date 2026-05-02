<?php
// database/migrations/2026_05_02_300000_add_placement_type_and_slot_to_block_placements.php
use Core\Database\Migration;

/**
 * Page-chrome Batch A — schema half 1.
 *
 * Adds two columns to BOTH `system_block_placements` and the parallel
 * `page_block_placements` table so a placement can be either:
 *
 *   - a regular block (the existing behaviour — placement_type='block',
 *     block_key resolves through Core\Module\BlockRegistry), OR
 *   - a "content slot" placeholder (placement_type='content_slot',
 *     slot_name names which controller-rendered slot fills the cell at
 *     request time — typically just `primary`).
 *
 * `block_key` stays NOT NULL for backward compatibility with the existing
 * CHECK/index assumptions; content-slot rows store the sentinel value
 * `__slot__` so a casual SQL inspection makes the type immediately
 * obvious without needing to JOIN the placement_type column. Open
 * question 3 in the page-chrome plan was decided in favour of this
 * sentinel approach.
 *
 * `slot_name` defaults to NULL; the renderer treats NULL and the literal
 * 'primary' as equivalent. Keeping it nullable means the existing
 * placement rows don't need a backfill — they're block rows so their
 * slot_name simply remains NULL.
 *
 * Schema parity for `page_block_placements`: the `pages` module doesn't
 * currently consume content slots (every Page IS the content), but we
 * add the columns anyway so the page-composer partial can stay
 * single-source-of-truth for both data shapes. Discussed in plan §10.
 *
 * Idempotent: each ADD COLUMN is wrapped in an information_schema check
 * so re-running the migration after it's already applied is safe (the
 * sandbox + several QA workflows do this).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->addColumnsTo('system_block_placements');
        $this->addColumnsTo('page_block_placements');
    }

    public function down(): void
    {
        $this->dropColumnsFrom('system_block_placements');
        $this->dropColumnsFrom('page_block_placements');
    }

    private function addColumnsTo(string $table): void
    {
        if (!$this->hasColumn($table, 'placement_type')) {
            $this->db->query(
                "ALTER TABLE `$table`
                    ADD COLUMN placement_type ENUM('block','content_slot')
                        NOT NULL DEFAULT 'block' AFTER block_key"
            );
        }
        if (!$this->hasColumn($table, 'slot_name')) {
            $this->db->query(
                "ALTER TABLE `$table`
                    ADD COLUMN slot_name VARCHAR(64) NULL AFTER placement_type"
            );
        }
    }

    private function dropColumnsFrom(string $table): void
    {
        if ($this->hasColumn($table, 'slot_name')) {
            $this->db->query("ALTER TABLE `$table` DROP COLUMN slot_name");
        }
        if ($this->hasColumn($table, 'placement_type')) {
            $this->db->query("ALTER TABLE `$table` DROP COLUMN placement_type");
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = ?
                AND COLUMN_NAME  = ?",
            [$table, $column]
        );
        return $row !== null && (int) $row['c'] > 0;
    }
};
