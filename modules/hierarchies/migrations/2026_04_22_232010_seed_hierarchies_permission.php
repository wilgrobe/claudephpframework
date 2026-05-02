<?php
// modules/hierarchies/migrations/2026_04_22_232010_seed_hierarchies_permission.php
use Core\Database\Migration;

/**
 * Seed the `hierarchies.manage` permission so admin roles can be granted
 * access to the tree editor. Idempotent via INSERT IGNORE on the slug
 * unique key.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage hierarchies',
            'hierarchies.manage',
            'hierarchies',
            'Create and edit hierarchies (menus, nav trees, org charts, curated structures).',
        ]);
    }

    public function down(): void
    {
        // Keep permission rows intact — other roles may reference them.
    }
};
