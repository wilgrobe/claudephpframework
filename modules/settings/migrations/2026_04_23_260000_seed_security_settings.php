<?php
// modules/settings/migrations/2026_04_23_260000_seed_security_settings.php
use Core\Database\Migration;

/**
 * Seeds default values for the two toggles exposed at
 * /admin/settings/security:
 *
 *   account_sessions_enabled        — default 'true' (users get the
 *                                     self-service sessions page)
 *   new_device_login_email_enabled  — default 'false' (opt-in; requires
 *                                     MAIL_* config)
 *
 * Idempotent via ON DUPLICATE KEY UPDATE that preserves any existing
 * value — re-running the migration won't clobber an admin who's
 * already tuned these. The settings.scope + key combo has a unique
 * index; we write (scope='site', scope_key=NULL) which matches the
 * framework's convention for site-global booleans.
 */
return new class extends Migration {
    public function up(): void
    {
        // Use INSERT IGNORE so a re-run leaves existing admin
        // choices alone. If the row is missing, seed with the
        // default. Key on (scope, scope_key, key) — scope_key NULL
        // for site-wide, which the existing uq index handles via
        // the NULL-safe comparison.
        $this->seed('account_sessions_enabled',       'true');
        $this->seed('new_device_login_email_enabled', 'false');
    }

    public function down(): void
    {
        // Don't remove rows an admin may have edited since install.
        // If rollback is needed, admins can delete via /admin/settings.
    }

    private function seed(string $key, string $value): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM settings WHERE scope = 'site' AND scope_key IS NULL AND `key` = ?",
            [$key]
        );
        if ($existing) return;

        $this->db->insert('settings', [
            'scope'     => 'site',
            'scope_key' => null,
            'key'       => $key,
            'value'     => $value,
            'type'      => 'boolean',
            'is_public' => 0,
        ]);
    }
};
