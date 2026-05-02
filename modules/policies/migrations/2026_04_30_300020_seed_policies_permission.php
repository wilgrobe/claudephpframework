<?php
// modules/policies/migrations/2026_04_30_300020_seed_policies_permission.php
use Core\Database\Migration;

/**
 * Seed `policies.manage` permission and grant inline to the Admin
 * role. Per framework convention, install.sql grants only what existed
 * at install time, so a new module must grant itself here or be
 * invisible on /admin/roles.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage policies',
            'policies.manage',
            'policies',
            'Manage policy kinds, bump versions, view acceptance reports.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?",
            ['policies.manage']
        );
        $adminRoleId = (int) $this->db->fetchColumn(
            "SELECT id FROM roles WHERE slug = ?",
            ['admin']
        );

        if ($permId > 0 && $adminRoleId > 0) {
            $this->db->query("
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                VALUES (?, ?)
            ", [$adminRoleId, $permId]);
        }
    }

    public function down(): void {}
};
