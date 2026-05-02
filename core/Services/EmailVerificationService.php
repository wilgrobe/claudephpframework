<?php
// core/Services/EmailVerificationService.php
namespace Core\Services;

use Core\Database\Database;

/**
 * EmailVerificationService — issues "verify your email" links.
 *
 * Generates a fresh 256-bit single-use token, upserts it into
 * `email_verifications` with a 24-hour expiry, and dispatches the
 * `verify_email` template via MailService. Replaces any existing
 * unverified token for the user (a previous unused link is invalidated
 * on resend so an admin or self-service resend can't leave multiple
 * live tokens floating around).
 *
 * Used by:
 *   - AuthController::register             — first signup
 *   - AuthController::resendVerification   — user clicks "resend" in their dashboard
 *   - UserController::resendVerification   — admin clicks "Resend" on /admin/users/{id}
 *
 * Errors propagate. Callers that want best-effort dispatch (e.g. signup,
 * which shouldn't fail because the mail driver is misconfigured) should
 * wrap the call in try/catch themselves.
 */
class EmailVerificationService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Issue a new verification link for the given user and dispatch the
     * email. Any prior unused token for this user is replaced atomically.
     */
    public function send(int $userId, string $email): void
    {
        // SECURITY: Random token, hashed at rest. Old framework used
        // hash(email . created_at . key) which is guessable when those
        // inputs are known.
        $plainToken  = bin2hex(random_bytes(32)); // 64 hex chars, 256 bits entropy
        $hashedToken = hash('sha256', $plainToken);

        // Upsert: a re-issue replaces the previous token + clears used_at,
        // so the previous link stops working the moment a new one is sent.
        $this->db->query(
            "INSERT INTO email_verifications (user_id, token, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
             ON DUPLICATE KEY UPDATE
                token      = VALUES(token),
                expires_at = VALUES(expires_at),
                used_at    = NULL",
            [$userId, $hashedToken]
        );

        $user = $this->db->fetchOne("SELECT first_name FROM users WHERE id = ?", [$userId]);

        // Token-only URL — same rationale as /password/reset: no `&` in
        // the query string means no pitfalls when copying rendered text
        // out of email clients that render `&amp;` literally.
        $url = config('app.url') . "/verify-email?token=" . rawurlencode($plainToken);

        (new MailService())->sendTemplate($email, 'Verify Your Email', 'verify_email', [
            'verifyUrl' => $url,
            'user'      => $user,
        ]);
    }
}
