<?php
// modules/settings/migrations/2026_04_24_270000_seed_password_reset_ttl.php
use Core\Database\Migration;

/**
 * Seeds password_reset_ttl_minutes (integer) with a default of 120
 * minutes (2 hours). Before this setting existed the value was
 * hardcoded to 60 minutes in AuthController::resetPassword; the
 * shift to 120 intentionally softens the default UX (email delivery
 * lag, spam filters, distracted users) while staying comfortably
 * under the "a sniffed link is still dangerous" threshold.
 *
 * Idempotent — leaves any existing row alone so an admin's tuning
 * survives re-runs.
 */
return new class extends Migration {
    public function up(): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM settings
              WHERE scope = 'site' AND scope_key IS NULL
                AND `key` = 'password_reset_ttl_minutes'"
        );
        if ($existing) return;

        $this->db->insert('settings', [
            'scope'     => 'site',
            'scope_key' => null,
            'key'       => 'password_reset_ttl_minutes',
            'value'     => '120',
            'type'      => 'integer',
            'is_public' => 0,
        ]);
    }

    public function down(): void
    {
        // Don't delete — same rationale as the other seed migrations:
        // an admin may have edited this, and the app still reads it
        // defensively via a default so the row's absence isn't fatal.
    }
};
