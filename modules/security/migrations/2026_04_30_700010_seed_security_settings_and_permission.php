<?php
// modules/security/migrations/2026_04_30_700010_seed_security_settings_and_permission.php
use Core\Database\Migration;

/**
 * Seed:
 *   - security.manage permission with admin grant
 *   - default settings for the breach-check toggle pair
 *
 *   password_breach_check_enabled   — master on/off. Default true so
 *                                     a fresh install has the protection.
 *   password_breach_check_block     — when true, registration / password
 *                                     change is REJECTED on a breach hit.
 *                                     When false, the user sees a warning
 *                                     but can proceed. Default true.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage security policies',
            'security.manage',
            'security',
            'Configure security toggles (password breach check, etc.) and view related logs.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?", ['security.manage']
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

        // Default site settings — only insert if not already present
        // (admin overrides win on subsequent runs).
        $defaults = [
            ['password_breach_check_enabled', 'true', 'boolean'],
            ['password_breach_check_block',   'true', 'boolean'],
        ];
        foreach ($defaults as [$key, $value, $type]) {
            $existing = $this->db->fetchOne(
                "SELECT id FROM settings WHERE scope = 'site' AND scope_key IS NULL AND `key` = ?",
                [$key]
            );
            if ($existing) continue;
            $this->db->insert('settings', [
                'scope'     => 'site',
                'scope_key' => null,
                'key'       => $key,
                'value'     => $value,
                'type'      => $type,
                'is_public' => 0,
            ]);
        }
    }

    public function down(): void {}
};
