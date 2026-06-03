<?php
// database/migrations/2026_05_14_000000_drop_tenant_block_usage.php
//
// Phase 9k cleanup — drop the orphaned `tenant_block_usage` table.
//
// The table was created in 2026_05_04_100000_create_section_layout_tables
// as the rollup that Phase 7's metered-publish billing read from. Phase 11
// retired the publish-gate model in favor of one-time tier purchases,
// and Phase 22's follow-up commit removed the corresponding writes from
// PageComposerController. The table has had no readers and no writers
// since that commit landed.
//
// Idempotent: skips silently if the table is already gone.

use Core\Database\Migration;

return new class extends Migration {

    public function up(): void
    {
        $exists = $this->db->fetchColumn("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name   = 'tenant_block_usage'
        ", []);
        if ((int) $exists === 0) return;

        $this->db->query("DROP TABLE tenant_block_usage");
    }

    public function down(): void
    {
        // No down() — destructive cleanup. Restore from a backup or
        // re-run 2026_05_04_100000_create_section_layout_tables to
        // get the table back.
    }
};
