<?php
// core/Services/SessionCleanupService.php
namespace Core\Services;

use Core\Database\Database;

/**
 * SessionCleanupService — purges stale records from time-sensitive tables.
 *
 * Call via a cron job every 15–60 minutes:
 *   php /var/www/artisan cleanup
 *
 * Tables cleaned:
 *   - sessions              : expired PHP sessions
 *   - two_factor_challenges : expired/used OTP challenges
 *   - login_attempts        : old rate-limit records past decay window
 *   - email_verifications   : expired tokens
 *   - password_resets       : tokens older than 1 hour
 */
class SessionCleanupService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function run(): array
    {
        $results = [];

        // Sessions: remove any session inactive for longer than the configured lifetime
        $sessionLifetime = (int) (config('app.session.lifetime', 120)) * 60;
        $n = $this->db->query(
            "DELETE FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$sessionLifetime]
        )->rowCount();
        $results['sessions_purged'] = $n;

        // 2FA challenges: remove used or expired challenges older than 24h
        $n = $this->db->query(
            "DELETE FROM two_factor_challenges
             WHERE (used_at IS NOT NULL OR expires_at < NOW())
               AND expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->rowCount();
        $results['2fa_challenges_purged'] = $n;

        // Login attempts: remove records past the decay window (30 min)
        $n = $this->db->query(
            "DELETE FROM login_attempts
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
               AND (locked_until IS NULL OR locked_until < NOW())"
        )->rowCount();
        $results['login_attempts_purged'] = $n;

        // Email verifications: remove expired or used tokens
        $n = $this->db->query(
            "DELETE FROM email_verifications
             WHERE (expires_at < NOW() OR used_at IS NOT NULL)
               AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        )->rowCount();
        $results['email_verifications_purged'] = $n;

        // Password resets: remove all tokens older than 1 hour (they expire)
        $n = $this->db->query(
            "DELETE FROM password_resets
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )->rowCount();
        $results['password_resets_purged'] = $n;

        return $results;
    }
}
