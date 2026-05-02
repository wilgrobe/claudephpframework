<?php
// modules/ccpa/migrations/2026_04_30_900010_seed_ccpa_permission_and_settings.php
use Core\Database\Migration;

/**
 * Seed `ccpa.manage` permission with admin grant + the CCPA-feature
 * default settings.
 *
 * Settings:
 *   ccpa_enabled              master toggle. Off = footer link hidden,
 *                             /do-not-sell page 404, GPC ignored.
 *                             Default: true (CCPA applies to most US sites
 *                             that touch California residents — safer to
 *                             default-enable).
 *   ccpa_link_label           text shown in the footer link
 *   ccpa_disclosure_url       link target (typically a CMS page describing
 *                             what data is "sold" / "shared")
 *   ccpa_honor_gpc_signal     auto-record opt-out when the browser sends
 *                             Sec-GPC: 1 (Global Privacy Control). CPRA
 *                             expressly recognises GPC; default: true.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage CCPA / Do Not Sell',
            'ccpa.manage',
            'ccpa',
            'View opt-out records, configure CCPA / CPRA toggles + disclosure URL.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?", ['ccpa.manage']
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
            ['ccpa_enabled',           'true',                                                'boolean'],
            ['ccpa_link_label',        'Do Not Sell or Share My Personal Information',        'string'],
            ['ccpa_disclosure_url',    '/do-not-sell',                                        'string'],
            ['ccpa_honor_gpc_signal',  'true',                                                'boolean'],
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
