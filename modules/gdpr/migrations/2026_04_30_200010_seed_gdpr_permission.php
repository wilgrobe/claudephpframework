<?php
// modules/gdpr/migrations/2026_04_30_200010_seed_gdpr_permission.php
use Core\Database\Migration;

/**
 * Seed `gdpr.manage` permission and grant it inline to the Admin role.
 *
 * Per the framework's seed convention, install.sql only grants
 * permissions that existed at install time, so a new module must grant
 * itself to admin here or it'll be invisible on /admin/roles for any
 * existing install.
 *
 * Idempotent — INSERT IGNORE on every step.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage GDPR / DSAR',
            'gdpr.manage',
            'gdpr',
            'Work the DSAR queue, review per-user data, initiate erasure or restriction on behalf of users.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?",
            ['gdpr.manage']
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

    public function down(): void
    {
        // Don't delete the permission row — other roles may have been
        // granted it via /admin/roles in the meantime.
    }
};
