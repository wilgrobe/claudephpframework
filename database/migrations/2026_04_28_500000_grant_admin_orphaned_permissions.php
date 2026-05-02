<?php
// database/migrations/2026_04_28_500000_grant_admin_orphaned_permissions.php
use Core\Database\Migration;

/**
 * Backfill role_permissions for the `admin` role.
 *
 * Background: install.sql runs once at install time and grants `admin`
 * every existing permission (minus the SA-only set: users.delete,
 * roles.delete, audit.view). Per-module migrations that ship LATER
 * (knowledgebase.manage, events.manage, store.manage, helpdesk.manage,
 * reviews.manage, etc.) only INSERT into `permissions` — they never
 * grant the new permission to admin. Result: every module shipped after
 * install has a working /admin/* page that returns 403 for admins, even
 * though the admin nav advertises the link.
 *
 * Visible symptom: /admin/kb returns "Forbidden" for an admin user with
 * the KB module enabled.
 *
 * Fix: one-shot backfill. For every permission whose slug is NOT in the
 * SA-only exclude list, ensure admin has a role_permissions row. Uses
 * NOT EXISTS so re-running this migration against a partially-fixed DB
 * is harmless. The exclude list mirrors install.sql so admins keep the
 * same surface they had at install time.
 *
 * Going forward: the per-module seed_*_permission.php migrations should
 * also grant admin inline — see project_module_naming memory for the
 * updated pattern.
 */
return new class extends Migration {
    public function up(): void
    {
        // Find the admin role id. If no row exists (someone renamed
        // it?), skip gracefully — the migration is purely a backfill,
        // not a setup step.
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
        // Don't revoke. Removing rows here would clobber whatever the
        // admin role looks like by the time someone rolls this back —
        // they may have manually granted/revoked permissions in the
        // meantime, and there's no record of which rows this migration
        // added vs. which the admin manually granted.
    }
};
