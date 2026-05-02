<?php
// modules/security/migrations/2026_04_30_700020_seed_session_and_ip_settings.php
use Core\Database\Migration;

/**
 * Default values for the sliding session timeout + admin IP allowlist
 * settings. All four are off-by-default so installs see no behavior
 * change until an admin opts in.
 *
 *   session_idle_timeout_minutes    integer  0 = disabled, N>0 = forced logout after N minutes idle
 *   admin_ip_allowlist_enabled      boolean  master toggle for /admin/* IP gate
 *   admin_ip_allowlist              string   newline-or-comma-separated CIDR list
 */
return new class extends Migration {
    public function up(): void
    {
        $defaults = [
            ['session_idle_timeout_minutes', '0',     'integer'],
            ['admin_ip_allowlist_enabled',   'false', 'boolean'],
            ['admin_ip_allowlist',           '',      'text'],
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
