<?php
// modules/auditchain/migrations/2026_04_30_600020_seed_auditchain_permission.php
use Core\Database\Migration;

/**
 * Seed `auditchain.manage` permission with inline admin grant.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage audit chain',
            'auditchain.manage',
            'auditchain',
            'View audit-chain verification status, run on-demand verification, acknowledge chain breaks.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?", ['auditchain.manage']
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
