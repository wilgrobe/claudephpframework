<?php
// modules/settings/migrations/2026_04_25_310000_seed_module_disabled_email_setting.php
use Core\Database\Migration;

/**
 * Seeds module_disabled_email_to_sa_enabled (boolean) with a default of
 * 'true'. ModuleRegistry::resolveDependencies fires a one-shot
 * notification when a module's state transitions to disabled; this
 * setting controls whether that notification carries the email channel
 * in addition to the in-app bell. Default-on so a freshly-deployed
 * framework with a half-installed module fails loudly to a place
 * superadmins are guaranteed to see (their inbox), not just a UI
 * surface they may not be looking at.
 *
 * Idempotent — re-running leaves an admin's existing choice alone.
 */
return new class extends Migration {
    public function up(): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM settings
              WHERE scope = 'site' AND scope_key IS NULL
                AND `key` = 'module_disabled_email_to_sa_enabled'"
        );
        if ($existing) return;

        $this->db->insert('settings', [
            'scope'     => 'site',
            'scope_key' => null,
            'key'       => 'module_disabled_email_to_sa_enabled',
            'value'     => 'true',
            'type'      => 'boolean',
            'is_public' => 0,
        ]);
    }

    public function down(): void
    {
        // Don't delete — admin may have flipped it; the runtime read
        // defaults true on missing-row anyway, so nothing breaks.
    }
};
