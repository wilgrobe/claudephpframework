<?php
// modules/security/migrations/2026_05_01_300000_add_totp_failed_attempt_tracking.php
use Core\Database\Migration;

/**
 * Adds per-user TOTP failed-attempt tracking to close a rate-limit gap.
 *
 * The framework's existing 2FA service has a MAX_ATTEMPTS counter on
 * the email/SMS OTP path (via two_factor_challenges.attempts), but the
 * TOTP path (verifyTotpForUser) checks codes purely against the
 * user's stored secret + the time window — there's no challenge row,
 * so no attempt counter, so no lockout. A user with a known secret
 * could be brute-forced indefinitely until they hit the right code.
 *
 *   two_factor_failed_attempts  rolling counter, reset on successful verify
 *   two_factor_locked_until     when set, every TOTP verify returns false
 *                               until this time passes (then counter resets)
 *
 * Idempotent — skips ALTER if columns are already present.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->addColumnIfMissing('users', 'two_factor_failed_attempts',
            "SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Rolling counter of consecutive failed 2FA attempts'");
        $this->addColumnIfMissing('users', 'two_factor_locked_until',
            "DATETIME NULL COMMENT 'When set, 2FA verification refuses until this passes'");
    }

    public function down(): void
    {
        // Don't drop. Removing these would silently undo the rate
        // limit on the next login attempt.
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $exists = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        if ($exists > 0) return;
        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
};
