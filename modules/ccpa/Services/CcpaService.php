<?php
// modules/ccpa/Services/CcpaService.php
namespace Modules\Ccpa\Services;

use Core\Database\Database;

/**
 * CCPA / CPRA "Do Not Sell or Share My Personal Information" opt-out
 * record + lookup.
 *
 * Three opt-out paths feed the same store:
 *   1. Self-service form at /do-not-sell — user submits with email
 *      (guest) OR clicks while signed in (uses user_id).
 *   2. GPC (Global Privacy Control) signal — when the browser sends
 *      Sec-GPC: 1 and the honor toggle is on, we silently record an
 *      opt-out tied to the cookie + (if signed in) user_id. CPRA §
 *      1798.135(b) explicitly recognises GPC as a valid opt-out.
 *   3. Admin / API — operator-initiated.
 *
 * Lookups happen via isOptedOut() which checks (in order):
 *   - signed-in user has an active row by user_id
 *   - cookie token matches an active row
 *   - email matches an active row
 *   - request currently carries Sec-GPC: 1 (live header check; some
 *     opt-outs only exist as a header on this request and haven't
 *     been recorded yet — fail safe)
 *
 * "Active" = withdrawn_at IS NULL. CCPA opt-outs persist by default.
 *
 * The cookie used for guest device-level opt-outs is `ccpa_opt_out`
 * with a 1-year TTL, set HttpOnly + SameSite=Lax. The cookie value is
 * a 64-char random token that's matched against ccpa_opt_outs.cookie_token
 * — never a meaningful identifier.
 */
class CcpaService
{
    public const COOKIE_NAME = 'ccpa_opt_out';
    public const COOKIE_TTL  = 60 * 60 * 24 * 365; // 1 year

    public const SOURCE_SELF       = 'self_service';
    public const SOURCE_GPC        = 'gpc_signal';
    public const SOURCE_ADMIN      = 'admin';
    public const SOURCE_API        = 'api';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function isEnabled(): bool
    {
        return (bool) (setting('ccpa_enabled', true) ?? true);
    }

    public function honorsGpc(): bool
    {
        return (bool) (setting('ccpa_honor_gpc_signal', true) ?? true);
    }

    /**
     * Is this request opted out? Checks all four signal channels.
     */
    public function isOptedOut(?int $userId = null, ?string $email = null): bool
    {
        if (!$this->isEnabled()) return false;

        // Live GPC header — wins immediately if honor is on.
        if ($this->honorsGpc() && $this->requestHasGpcSignal()) return true;

        // Signed-in user has an active row?
        if ($userId !== null && $userId > 0) {
            $row = $this->db->fetchOne(
                "SELECT 1 FROM ccpa_opt_outs WHERE user_id = ? AND withdrawn_at IS NULL LIMIT 1",
                [$userId]
            );
            if ($row) return true;
        }

        // Cookie token from the device matches an active row?
        $cookieToken = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (is_string($cookieToken) && $cookieToken !== '') {
            $row = $this->db->fetchOne(
                "SELECT 1 FROM ccpa_opt_outs WHERE cookie_token = ? AND withdrawn_at IS NULL LIMIT 1",
                [$cookieToken]
            );
            if ($row) return true;
        }

        // Email match?
        if ($email !== null && $email !== '') {
            $row = $this->db->fetchOne(
                "SELECT 1 FROM ccpa_opt_outs WHERE email = ? AND withdrawn_at IS NULL LIMIT 1",
                [strtolower($email)]
            );
            if ($row) return true;
        }

        return false;
    }

    /**
     * Record an opt-out. Sets the device cookie if we're in a request
     * context. Idempotent — if a matching active row already exists for
     * the same identity, returns it instead of creating a duplicate.
     */
    public function recordOptOut(string $source, ?int $userId = null, ?string $email = null, ?string $notes = null): int
    {
        $email = $email !== null ? strtolower(trim($email)) : null;

        // Mint or carry forward the cookie token.
        $cookieToken = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($cookieToken) || $cookieToken === '') {
            $cookieToken = bin2hex(random_bytes(32));
        }

        // Idempotency check — same identity, still active?
        $existing = $this->db->fetchOne("
            SELECT id FROM ccpa_opt_outs
            WHERE withdrawn_at IS NULL
              AND ((? IS NOT NULL AND user_id = ?)
                OR (? IS NOT NULL AND cookie_token = ?)
                OR (? IS NOT NULL AND email = ?))
            LIMIT 1
        ", [$userId, $userId, $cookieToken, $cookieToken, $email, $email]);
        if ($existing) {
            $this->writeCookie($cookieToken);
            return (int) $existing['id'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ipPacked = ($ip && @inet_pton($ip) !== false) ? @inet_pton($ip) : null;
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(strip_tags($_SERVER['HTTP_USER_AGENT']), 0, 500)
            : null;

        $id = $this->db->insert('ccpa_opt_outs', [
            'email'        => $email,
            'user_id'      => $userId,
            'cookie_token' => $cookieToken,
            'source'       => in_array($source, [
                self::SOURCE_SELF, self::SOURCE_GPC, self::SOURCE_ADMIN, self::SOURCE_API,
            ], true) ? $source : self::SOURCE_API,
            'ip_address'   => $ipPacked,
            'user_agent'   => $ua,
            'notes'        => $notes,
        ]);

        $this->writeCookie($cookieToken);
        return (int) $id;
    }

    /**
     * Mark every active row matching the identity as withdrawn. Rare
     * — CCPA opt-outs are sticky by default; only the user can opt
     * back IN.
     */
    public function withdrawOptOut(?int $userId = null, ?string $email = null): int
    {
        $email = $email !== null ? strtolower(trim($email)) : null;
        $cookieToken = $_COOKIE[self::COOKIE_NAME] ?? null;

        $updated = (int) $this->db->query("
            UPDATE ccpa_opt_outs
               SET withdrawn_at = NOW()
             WHERE withdrawn_at IS NULL
               AND ((? IS NOT NULL AND user_id = ?)
                 OR (? IS NOT NULL AND cookie_token = ?)
                 OR (? IS NOT NULL AND email = ?))
        ", [$userId, $userId, $cookieToken, $cookieToken, $email, $email]);

        // Clear the cookie so the device starts fresh.
        $this->clearCookie();
        return $updated;
    }

    public function recentOptOuts(int $limit = 100): array
    {
        return $this->db->fetchAll("
            SELECT o.*, u.username AS user_username, u.email AS user_email
            FROM ccpa_opt_outs o
            LEFT JOIN users u ON u.id = o.user_id
            ORDER BY o.id DESC LIMIT ?
        ", [$limit]);
    }

    public function statsLast90Days(): array
    {
        return $this->db->fetchOne("
            SELECT
              COUNT(*) AS total,
              SUM(source = 'self_service') AS self_service,
              SUM(source = 'gpc_signal')   AS gpc_signal,
              SUM(source = 'admin')        AS admin,
              SUM(withdrawn_at IS NOT NULL) AS withdrawn
            FROM ccpa_opt_outs
            WHERE created_at > NOW() - INTERVAL 90 DAY
        ") ?: ['total'=>0,'self_service'=>0,'gpc_signal'=>0,'admin'=>0,'withdrawn'=>0];
    }

    /**
     * Detect the Sec-GPC: 1 request header. Some browsers send GPC: 1
     * instead, both should count.
     */
    public function requestHasGpcSignal(): bool
    {
        $hdr1 = $_SERVER['HTTP_SEC_GPC'] ?? '';
        $hdr2 = $_SERVER['HTTP_GPC']     ?? '';
        return $hdr1 === '1' || $hdr2 === '1';
    }

    private function writeCookie(string $token): void
    {
        $_COOKIE[self::COOKIE_NAME] = $token;
        if (headers_sent()) return;
        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE_NAME]);
        if (headers_sent()) return;
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
