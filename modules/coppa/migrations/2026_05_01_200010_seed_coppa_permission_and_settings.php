<?php
// modules/coppa/migrations/2026_05_01_200010_seed_coppa_permission_and_settings.php
use Core\Database\Migration;

/**
 * Seed coppa.manage permission + setting defaults.
 *
 * Settings:
 *   coppa_enabled           master toggle (default OFF — only relevant
 *                           if marketing to under-13s; off avoids
 *                           collecting birthdate from users who don't
 *                           need to provide it)
 *   coppa_minimum_age       block-below threshold. Default 13 (US COPPA).
 *                           Set to 16 for GDPR Art. 8 strict default;
 *                           UK Children's Code uses 13.
 *   coppa_block_message     custom rejection message shown on the
 *                           "registration closed for under-N" page
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage COPPA / age gate',
            'coppa.manage',
            'coppa',
            'Configure the registration age gate and view rejection logs.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?", ['coppa.manage']
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
            ['coppa_enabled',       'false', 'boolean'],
            ['coppa_minimum_age',   '13',    'integer'],
            ['coppa_block_message',
                "Sorry — you must be at least {age} years old to create an account on this site.",
                'string'],
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
