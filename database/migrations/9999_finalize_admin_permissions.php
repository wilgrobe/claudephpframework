<?php
// database/migrations/9999_finalize_admin_permissions.php
//
// FINALIZE pass — runs LAST (the 9999_ prefix sorts after every framework
// (0001/0500) and module (date-based) migration). Grants the `admin` role
// every permission except the SA-only set, so module migrations that ship
// their own permissions (knowledgebase.manage, events.manage, store.manage,
// …) don't leave an admin staring at a 403 on a nav link the module added.
//
// Replaces the old, mis-positioned 2026_04_28_grant_admin_orphaned_permissions
// migration (which sorted BEFORE most module migrations and so missed their
// permissions). Idempotent via NOT EXISTS — safe to re-run.
//
// Per-module migrations may still grant admin inline; this is the backstop
// that catches anything they don't.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $role = $this->db->fetchOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        if (!$role) return;
        $adminId = (int) $role['id'];

        $this->db->query(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT ?, p.id
               FROM permissions p
              WHERE p.slug NOT IN ('users.delete','roles.delete','audit.view')
                AND NOT EXISTS (
                      SELECT 1 FROM role_permissions rp
                       WHERE rp.role_id = ? AND rp.permission_id = p.id
                  )",
            [$adminId, $adminId]
        );
    }

    public function down(): void
    {
        // Don't revoke — see the original backfill migration's rationale.
    }
};
