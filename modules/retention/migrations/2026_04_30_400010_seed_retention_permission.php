<?php
// modules/retention/migrations/2026_04_30_400010_seed_retention_permission.php
use Core\Database\Migration;

/**
 * Seed `retention.manage` permission and grant inline to the Admin role.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage data retention',
            'retention.manage',
            'retention',
            'Configure retention rules, run dry-run previews, manually trigger sweeps, view run history.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?", ['retention.manage']
        );
        $adminRoleId = (int) $this->db->fetchColumn(
            "SELECT id FROM roles WHERE slug = ?", ['admin']
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
