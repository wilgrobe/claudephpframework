<?php
// modules/cookieconsent/migrations/2026_04_30_100010_seed_cookieconsent_permission.php
use Core\Database\Migration;

/**
 * Seed the `cookieconsent.manage` permission and grant it to the Admin role
 * inline. Per the framework's permission-seed convention, install.sql only
 * grants permissions that existed at install time, so any new module's
 * permission must be granted to admin here or it'll be invisible to admins
 * who installed the framework before this module shipped.
 *
 * Idempotent — INSERT IGNORE on every step.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Permission row
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage cookie consent',
            'cookieconsent.manage',
            'cookieconsent',
            'Configure the GDPR cookie banner: copy, policy URL, category descriptions, ' .
            'policy version. View consent records.',
        ]);

        // 2. Grant to the Admin role inline. Look up the ids — INSERT IGNORE
        //    above may have skipped because the row already existed, in which
        //    case we still need the existing row's id.
        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?",
            ['cookieconsent.manage']
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
        // Don't delete — other roles may have been granted this permission.
        // Drop the role_permissions row only if the admin role still
        // exclusively holds it.
    }
};
