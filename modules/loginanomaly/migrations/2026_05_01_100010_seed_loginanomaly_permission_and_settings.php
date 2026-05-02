<?php
// modules/loginanomaly/migrations/2026_05_01_100010_seed_loginanomaly_permission_and_settings.php
use Core\Database\Migration;

/**
 * Seed loginanomaly.manage permission + setting defaults.
 *
 * Settings:
 *   login_anomaly_enabled              master toggle (default OFF — opt in
 *                                      because it makes outbound API calls
 *                                      to ip-api.com on every login)
 *   login_anomaly_email_enabled        send a "suspicious sign-in" email
 *                                      to the user when an anomaly fires
 *                                      (default true when module enabled)
 *   login_anomaly_threshold_kmh        speed above which travel is
 *                                      flagged "impossible". Default 900
 *                                      (~commercial flight cruise + 1h
 *                                      airport buffer)
 *   login_anomaly_alert_threshold_kmh  speed above which severity escalates
 *                                      to 'alert'. Default 2000.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage login anomaly detection',
            'loginanomaly.manage',
            'loginanomaly',
            'View detected login anomalies, configure thresholds, acknowledge alerts.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?", ['loginanomaly.manage']
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
            ['login_anomaly_enabled',             'false', 'boolean'],
            ['login_anomaly_email_enabled',       'true',  'boolean'],
            ['login_anomaly_threshold_kmh',       '900',   'integer'],
            ['login_anomaly_alert_threshold_kmh', '2000',  'integer'],
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
