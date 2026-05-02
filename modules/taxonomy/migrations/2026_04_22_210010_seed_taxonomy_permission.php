<?php
// modules/taxonomy/migrations/2026_04_22_210010_seed_taxonomy_permission.php
use Core\Database\Migration;

/**
 * Seed the `taxonomy.manage` permission so admin roles can be granted
 * access to create vocabularies, add terms, and manage classifications.
 * Idempotent via INSERT IGNORE on the slug unique key.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage taxonomy',
            'taxonomy.manage',
            'taxonomy',
            'Create and edit taxonomy vocabularies (term sets) and their terms.',
        ]);
    }

    public function down(): void
    {
        // Don't delete — other roles may have been granted this permission
        // between install and rollback.
    }
};
