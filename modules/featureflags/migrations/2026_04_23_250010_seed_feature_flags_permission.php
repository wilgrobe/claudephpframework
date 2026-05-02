<?php
// modules/featureflags/migrations/2026_04_23_250010_seed_feature_flags_permission.php
use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage feature flags',
            'featureflags.manage',
            'featureflags',
            'Create, edit, and toggle feature flags; manage per-user and per-group overrides.',
        ]);
    }

    public function down(): void {}
};
