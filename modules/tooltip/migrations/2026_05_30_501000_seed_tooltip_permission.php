<?php
// modules/tooltip/migrations/2026_05_30_501000_seed_tooltip_permission.php
use Core\Database\Migration;

/**
 * Seed the `tooltips.manage` permission so admin roles can manage tooltips.
 * Idempotent via INSERT IGNORE on the slug unique key.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage tooltips',
            'tooltips.manage',
            'tooltip',
            'Create, edit, and organise managed help tooltips.',
        ]);
    }

    public function down(): void
    {
        // Keep permission rows intact — roles may reference them.
    }
};
