<?php
// modules/accessibility/migrations/2026_04_30_800000_seed_accessibility_permission_and_settings.php
use Core\Database\Migration;

/**
 * Seed `accessibility.manage` permission with admin grant + the
 * accessibility-feature toggles.
 *
 * Settings:
 *   accessibility_skip_link_enabled  — render the skip-to-content
 *                                      link as the first body element
 *                                      (default: true)
 *   accessibility_focus_styles_enabled — emit the focus-visible CSS
 *                                       block in the master layout
 *                                       (default: true)
 *
 * Both default to ON because every framework user benefits and the
 * cost is essentially zero.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage accessibility',
            'accessibility.manage',
            'accessibility',
            'View accessibility lint results, configure WCAG-related toggles.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?", ['accessibility.manage']
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

        $defaults = [
            ['accessibility_skip_link_enabled',     'true', 'boolean'],
            ['accessibility_focus_styles_enabled',  'true', 'boolean'],
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
