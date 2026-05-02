<?php
// modules/coppa/migrations/2026_05_01_200000_add_dob_to_users.php
use Core\Database\Migration;

/**
 * Add date_of_birth to users so COPPA / GDPR Art. 8 / UK Children's
 * Code age-gating has a value to check + a value to retain on the
 * record for any future age-restricted feature.
 *
 * Idempotent — skips the ALTER if the column is already present, so
 * the migration is safe to re-run on installs that added it by hand.
 *
 * Stored as DATE, nullable — existing users won't have a value, and
 * no code path requires it unless the COPPA module's age gate is on.
 */
return new class extends Migration {
    public function up(): void
    {
        $exists = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'users' AND column_name = 'date_of_birth'"
        );
        if ($exists > 0) return;

        $this->db->query("
            ALTER TABLE users
            ADD COLUMN date_of_birth DATE NULL
            COMMENT 'Optional birthdate; populated when COPPA / age-gate signup is on'
        ");
    }

    public function down(): void
    {
        // Don't drop. Removing this column would silently destroy
        // age-gate evidence + break any downstream age-restricted
        // feature that came to depend on it.
    }
};
