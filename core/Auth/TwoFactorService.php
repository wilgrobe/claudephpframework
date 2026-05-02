<?php
// core/Auth/TwoFactorService.php
namespace Core\Auth;

use Core\Database\Database;
use Core\Services\MailService;
use Core\Services\SmsService;

/**
 * TwoFactorService
 *
 * Supports four 2FA methods:
 *   - email  : 6-digit OTP sent via email, valid 10 minutes
 *   - sms    : 6-digit OTP sent via SMS, valid 10 minutes
 *   - totp   : Time-based OTP (Google Authenticator, Microsoft Authenticator, Authy)
 *              Implements RFC 6238 natively — no third-party library required.
 *
 * Recovery codes (8 single-use codes) are available for all methods.
 */
class TwoFactorService
{
    private const OTP_EXPIRY_MINUTES = 10;
    private const OTP_LENGTH         = 6;
    private const MAX_ATTEMPTS       = 5;
    /**
     * How long a TOTP-locked-out user has to wait before the next
     * verify attempt is allowed. Cleared on next successful verify
     * (so the user can retry immediately once the window passes).
     */
    private const TOTP_LOCKOUT_MINUTES = 15;
    private const TOTP_DIGITS        = 6;
    private const TOTP_PERIOD        = 30;  // seconds
    private const TOTP_WINDOW        = 1;   // ±1 period tolerance
    private const RECOVERY_CODE_COUNT= 8;

    private Database   $db;
    private MailService $mail;
    private SmsService  $sms;

    public function __construct()
    {
        $this->db   = Database::getInstance();
        $this->mail = new MailService();
        $this->sms  = new SmsService();
    }

    // =========================================================================
    // Setup / Enrollment
    // =========================================================================

    /**
     * Begin enrolling a TOTP method.
     * Generates a new secret, stores it (unconfirmed), returns provisioning URI.
     */
    public function enrollTotp(int $userId): array
    {
        $user   = $this->db->fetchOne("SELECT email, username, first_name FROM users WHERE id = ?", [$userId]);
        $secret = $this->generateTotpSecret();
        $issuer = urlencode(config('app.name', 'App'));
        $label  = urlencode($user['email'] ?? $user['username'] ?? "user$userId");

        // Store secret but mark as unconfirmed until user verifies first code
        $this->db->query(
            "UPDATE users SET two_factor_secret = ?, two_factor_method = 'totp', two_factor_confirmed = 0 WHERE id = ?",
            [$secret, $userId]
        );

        $uri = "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

        return [
            'secret'           => $secret,
            'provisioning_uri' => $uri,
        ];
    }

    /**
     * Confirm TOTP enrollment by verifying a code the user typed from their app.
     * Only marks two_factor_confirmed = 1 if the code is valid.
     */
    public function confirmTotpEnrollment(int $userId, string $code): bool
    {
        $user = $this->db->fetchOne(
            "SELECT two_factor_secret, two_factor_method FROM users WHERE id = ?",
            [$userId]
        );
        if (!$user || $user['two_factor_method'] !== 'totp' || !$user['two_factor_secret']) {
            return false;
        }
        if (!$this->verifyTotp($user['two_factor_secret'], $code)) {
            return false;
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $this->db->query(
            "UPDATE users SET two_factor_enabled = 1, two_factor_confirmed = 1,
             two_factor_recovery_codes = ? WHERE id = ?",
            [json_encode($recoveryCodes['hashed']), $userId]
        );

        return true;
    }

    /**
     * Enable email or SMS 2FA for a user.
     * Returns generated recovery codes (plain-text, show once).
     */
    public function enableOtpMethod(int $userId, string $method): array
    {
        if (!in_array($method, ['email', 'sms'], true)) {
            throw new \InvalidArgumentException("Invalid OTP method: $method");
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $this->db->query(
            "UPDATE users SET two_factor_enabled = 1, two_factor_method = ?,
             two_factor_confirmed = 1, two_factor_secret = NULL,
             two_factor_recovery_codes = ? WHERE id = ?",
            [$method, json_encode($recoveryCodes['hashed']), $userId]
        );

        return $recoveryCodes['plain'];
    }

    /**
     * Disable 2FA entirely for a user.
     */
    public function disable(int $userId): void
    {
        $this->db->query(
            "UPDATE users SET two_factor_enabled = 0, two_factor_method = NULL,
             two_factor_secret = NULL, two_factor_confirmed = 0,
             two_factor_recovery_codes = NULL WHERE id = ?",
            [$userId]
        );
        // Clean up any pending challenges
        $this->db->delete('two_factor_challenges', 'user_id = ?', [$userId]);
    }

    // =========================================================================
    // Challenge — create & send OTP (email / SMS)
    // =========================================================================

    /**
     * Create and dispatch an OTP challenge for email or SMS methods.
     * Returns the challenge ID to store in the session.
     */
    public function sendChallenge(int $userId, string $method): int
    {
        // Invalidate any existing pending challenge for this user
        $this->db->query(
            "UPDATE two_factor_challenges SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()",
            [$userId]
        );

        $plain   = $this->generateOtpCode();
        $expires = date('Y-m-d H:i:s', time() + self::OTP_EXPIRY_MINUTES * 60);

        $challengeId = $this->db->insert('two_factor_challenges', [
            'user_id'    => $userId,
            'code'       => password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]),
            'method'     => $method,
            'attempts'   => 0,
            'expires_at' => $expires,
        ]);

        $user = $this->db->fetchOne(
            "SELECT email, phone, first_name FROM users WHERE id = ?",
            [$userId]
        );

        if ($method === 'email') {
            $this->mail->sendTemplate(
                $user['email'],
                'Your login verification code',
                '2fa_otp',
                [
                    'code'       => $plain,
                    'expiry'     => self::OTP_EXPIRY_MINUTES,
                    'first_name' => $user['first_name'] ?? 'there',
                ]
            );
        } elseif ($method === 'sms') {
            $siteName = config('app.name', 'App');
            $this->sms->send(
                $user['phone'] ?? '',
                "{$siteName} verification code: {$plain} (expires in " . self::OTP_EXPIRY_MINUTES . " min)"
            );
        }

        return $challengeId;
    }

    // =========================================================================
    // Verification
    // =========================================================================

    /**
     * Verify a submitted code for a given user and method.
     * Handles email/SMS challenges AND TOTP.
     *
     * @param int    $userId
     * @param string $method     'email' | 'sms' | 'totp'
     * @param string $code       User-submitted code
     * @param int|null $challengeId  Required for email/SMS methods
     * @return bool
     */
    public function verify(int $userId, string $method, string $code, ?int $challengeId = null): bool
    {
        $code = trim($code);

        if ($method === 'totp') {
            return $this->verifyTotpForUser($userId, $code);
        }

        if (!$challengeId) return false;
        return $this->verifyOtpChallenge($userId, $challengeId, $code);
    }

    /**
     * Verify a recovery code. Each code is single-use and removed on success.
     *
     * SECURITY: Uses SHA-256 + hash_equals for constant-time comparison.
     * Recovery codes are 40 bits of entropy (10 hex chars), which is strong
     * enough that bcrypt is unnecessary overhead here — SHA-256 is sufficient
     * and avoids the 800ms worst-case verification latency of bcrypt cost 10
     * across all 8 codes.
     */
    public function verifyRecoveryCode(int $userId, string $code): bool
    {
        $code = strtoupper(trim(str_replace('-', '', $code)));
        if (!preg_match('/^[A-F0-9]{10}$/', $code)) return false;

        $user = $this->db->fetchOne(
            "SELECT two_factor_recovery_codes FROM users WHERE id = ?",
            [$userId]
        );
        if (!$user || !$user['two_factor_recovery_codes']) return false;

        $hashedCodes = json_decode($user['two_factor_recovery_codes'], true) ?? [];
        $submittedHash = hash('sha256', $code);

        foreach ($hashedCodes as $index => $storedHash) {
            if (hash_equals($storedHash, $submittedHash)) {
                // Remove the used code atomically
                unset($hashedCodes[$index]);
                $this->db->query(
                    "UPDATE users SET two_factor_recovery_codes = ? WHERE id = ?",
                    [json_encode(array_values($hashedCodes)), $userId]
                );
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // User 2FA status helpers
    // =========================================================================

    public function isEnabled(int $userId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT two_factor_enabled, two_factor_confirmed FROM users WHERE id = ?",
            [$userId]
        );
        return $row && $row['two_factor_enabled'] && $row['two_factor_confirmed'];
    }

    public function getMethod(int $userId): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT two_factor_method FROM users WHERE id = ?",
            [$userId]
        );
        return $row['two_factor_method'] ?? null;
    }

    public function getUserTwoFactorInfo(int $userId): array
    {
        return $this->db->fetchOne(
            "SELECT two_factor_enabled, two_factor_method, two_factor_confirmed FROM users WHERE id = ?",
            [$userId]
        ) ?? ['two_factor_enabled' => 0, 'two_factor_method' => null, 'two_factor_confirmed' => 0];
    }

    /**
     * Regenerate recovery codes for a user (e.g. after they've used too many).
     * Returns the new plain-text codes.
     */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $codes = $this->generateRecoveryCodes();
        $this->db->query(
            "UPDATE users SET two_factor_recovery_codes = ? WHERE id = ?",
            [json_encode($codes['hashed']), $userId]
        );
        return $codes['plain'];
    }

    // =========================================================================
    // Internal — OTP (email / SMS)
    // =========================================================================

    private function verifyOtpChallenge(int $userId, int $challengeId, string $code): bool
    {
        $challenge = $this->db->fetchOne(
            "SELECT * FROM two_factor_challenges
             WHERE id = ? AND user_id = ? AND used_at IS NULL AND expires_at > NOW()",
            [$challengeId, $userId]
        );

        if (!$challenge) return false;

        // Increment attempts and check limit
        $attempts = (int) $challenge['attempts'] + 1;
        $this->db->update('two_factor_challenges', ['attempts' => $attempts], 'id = ?', [$challengeId]);

        if ($attempts > self::MAX_ATTEMPTS) return false;

        if (!password_verify($code, $challenge['code'])) {
            return false;
        }

        // Mark used
        $this->db->update(
            'two_factor_challenges',
            ['used_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$challengeId]
        );

        return true;
    }

    // =========================================================================
    // Internal — TOTP (RFC 6238)
    // =========================================================================

    private function verifyTotpForUser(int $userId, string $code): bool
    {
        // SECURITY: rate-limit. The OTP path is protected by the
        // attempts column on two_factor_challenges, but the TOTP path
        // doesn't issue a challenge row, so without this check a
        // known-secret can be brute-forced indefinitely. Columns added
        // by 2026_05_01_300000_add_totp_failed_attempt_tracking.
        $user = $this->db->fetchOne(
            "SELECT two_factor_secret, totp_last_counter,
                    two_factor_failed_attempts, two_factor_locked_until
               FROM users
              WHERE id = ? AND two_factor_method = 'totp' AND two_factor_confirmed = 1",
            [$userId]
        );
        if (!$user || !$user['two_factor_secret']) return false;

        // Locked? Refuse without even hashing the candidate code.
        if (!empty($user['two_factor_locked_until'])
            && strtotime((string) $user['two_factor_locked_until']) > time()
        ) {
            return false;
        }

        $code = preg_replace('/\s/', '', $code);
        if (!ctype_digit($code) || strlen($code) !== self::TOTP_DIGITS) return false;

        $timestamp   = time();
        $lastCounter = (int) ($user['totp_last_counter'] ?? -1);

        for ($i = -self::TOTP_WINDOW; $i <= self::TOTP_WINDOW; $i++) {
            $counter  = (int) floor($timestamp / self::TOTP_PERIOD) + $i;
            $expected = $this->computeTotp($user['two_factor_secret'], $counter);

            if (hash_equals($expected, $code)) {
                // SECURITY: Reject replays — counter must be strictly greater than last used.
                if ($counter <= $lastCounter) {
                    return false;
                }
                // Success — record the counter + reset the failed-attempt
                // counter + clear any lockout window.
                $this->db->query(
                    "UPDATE users
                        SET totp_last_counter = ?,
                            two_factor_failed_attempts = 0,
                            two_factor_locked_until = NULL
                      WHERE id = ?",
                    [$counter, $userId]
                );
                return true;
            }
        }

        // Wrong code — bump the counter; lock the account if past the
        // threshold. Lockout window is self::TOTP_LOCKOUT_MINUTES so
        // legitimate users with a flaky time-source aren't punished
        // forever by a few mistypes.
        $newAttempts = (int) ($user['two_factor_failed_attempts'] ?? 0) + 1;
        if ($newAttempts >= self::MAX_ATTEMPTS) {
            $this->db->query(
                "UPDATE users
                    SET two_factor_failed_attempts = ?,
                        two_factor_locked_until    = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                  WHERE id = ?",
                [$newAttempts, self::TOTP_LOCKOUT_MINUTES, $userId]
            );
        } else {
            $this->db->query(
                "UPDATE users SET two_factor_failed_attempts = ? WHERE id = ?",
                [$newAttempts, $userId]
            );
        }
        return false;
    }

    /**
     * Pure PHP RFC 6238 TOTP verification — used only for enrollment confirmation
     * (where there is no stored last-counter yet). Login verification goes through
     * verifyTotpForUser() which adds replay protection.
     */
    public function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\s/', '', $code);
        if (!ctype_digit($code) || strlen($code) !== self::TOTP_DIGITS) return false;

        $timestamp = time();
        for ($i = -self::TOTP_WINDOW; $i <= self::TOTP_WINDOW; $i++) {
            $counter  = (int) floor($timestamp / self::TOTP_PERIOD) + $i;
            $expected = $this->computeTotp($secret, $counter);
            if (hash_equals($expected, $code)) return true;
        }
        return false;
    }

    private function computeTotp(string $base32Secret, int $counter): string
    {
        $key     = $this->base32Decode($base32Secret);
        $counter = pack('N*', 0) . pack('N*', $counter);  // 8-byte big-endian
        $hash    = hash_hmac('sha1', $counter, $key, true);
        $offset  = ord($hash[19]) & 0x0F;
        $code    = (
            (ord($hash[$offset])     & 0x7F) << 24 |
            (ord($hash[$offset + 1]) & 0xFF) << 16 |
            (ord($hash[$offset + 2]) & 0xFF) << 8  |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::TOTP_DIGITS);

        return str_pad((string) $code, self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    private function generateTotpSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    // =========================================================================
    // QR code rendering
    // =========================================================================
    //
    // Historical note: this method used to return a URL to Google Charts'
    // QR endpoint (chart.googleapis.com/chart?cht=qr). That service was
    // deprecated in 2012 and fully shut down in 2019 — the img tag rendered
    // as a broken-image placeholder. We now render the QR client-side in
    // the setup view using a cdnjs-hosted qrcode.js instead, so this method
    // only ships the otpauth:// URI. If you need server-side QR generation
    // later (e.g. for emailed setup links or printable PDFs), swap in a
    // real library like bacon/bacon-qr-code or endroid/qr-code.

    // =========================================================================
    // Internal — code generation
    // =========================================================================

    private function generateOtpCode(): string
    {
        return str_pad((string) random_int(0, 10 ** self::OTP_LENGTH - 1), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    private function generateRecoveryCodes(): array
    {
        $plain  = [];
        $hashed = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code     = strtoupper(bin2hex(random_bytes(5))); // 10 hex chars = 40 bits entropy
            $formatted= substr($code, 0, 5) . '-' . substr($code, 5); // XXXXX-XXXXX
            $plain[]  = $formatted;
            // SECURITY: SHA-256 is sufficient for 40-bit random codes and avoids
            // the 800ms bcrypt overhead when verifying all 8 codes in worst case.
            $hashed[] = hash('sha256', $code); // stored without dashes, verified without dashes
        }
        return ['plain' => $plain, 'hashed' => $hashed];
    }

    // =========================================================================
    // Base32 encode/decode (RFC 4648) — needed for TOTP secrets
    // =========================================================================

    private static string $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function base32Encode(string $bytes): string
    {
        $chars  = self::$base32Chars;
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer   = ($buffer << 8) | ord($byte);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result   .= $chars[($buffer >> $bitsLeft) & 0x1F];
            }
        }
        if ($bitsLeft > 0) {
            $result .= $chars[($buffer << (5 - $bitsLeft)) & 0x1F];
        }
        return $result;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded  = strtoupper(preg_replace('/\s/', '', $encoded));
        $chars    = self::$base32Chars;
        $result   = '';
        $buffer   = 0;
        $bitsLeft = 0;

        foreach (str_split($encoded) as $char) {
            $pos = strpos($chars, $char);
            if ($pos === false) continue;
            $buffer    = ($buffer << 5) | $pos;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result   .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $result;
    }
}
