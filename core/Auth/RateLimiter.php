<?php
// core/Auth/RateLimiter.php
namespace Core\Auth;

use Core\Database\Database;

/**
 * RateLimiter — database-backed rate limiting for authentication endpoints.
 *
 * Tracks failed attempts per (key, IP) combination in the login_attempts table.
 * After MAX_ATTEMPTS failures: exponential backoff penalty.
 * After LOCKOUT_THRESHOLD failures: full lockout for LOCKOUT_MINUTES.
 *
 * SECURITY:
 *  - Tracks both by IP and by email independently, blocking both vectors.
 *  - Uses DB storage so limits survive PHP process restarts.
 *  - Expired rows are cleaned periodically to prevent table bloat.
 */
class RateLimiter
{
    private const MAX_ATTEMPTS        = 5;    // failures before backoff kicks in
    private const LOCKOUT_THRESHOLD   = 10;   // failures before hard lockout
    private const LOCKOUT_MINUTES     = 15;   // lockout duration
    private const DECAY_MINUTES       = 10;   // sliding window for attempt counting

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Check whether the given key is currently locked out.
     * Returns false if allowed, or a Response-ready error string if blocked.
     */
    public function tooManyAttempts(string $key, string $ip): bool
    {
        $ipKey    = $this->key('ip', $ip);
        $emailKey = $this->key('email', $key);

        return $this->isLockedOut($ipKey) || $this->isLockedOut($emailKey);
    }

    /**
     * Record a failed attempt for the given key+IP combination.
     */
    public function hit(string $key, string $ip): void
    {
        $this->recordAttempt($this->key('ip', $ip));
        $this->recordAttempt($this->key('email', $key));
    }

    /**
     * Clear all attempts for this key+IP (called on successful login).
     */
    public function clear(string $key, string $ip): void
    {
        $this->db->delete('login_attempts', 'attempt_key = ?', [$this->key('ip', $ip)]);
        $this->db->delete('login_attempts', 'attempt_key = ?', [$this->key('email', $key)]);
    }

    /**
     * Return seconds remaining until this key is unlocked, or 0 if allowed.
     *
     * Covers BOTH lockout paths so the caller always has a truthful wait time:
     *   Hard lock: time until locked_until.
     *   Soft lock: time until the oldest NULL-lock row falls out of the
     *              DECAY_MINUTES window, which is when countRecent() will
     *              finally drop below LOCKOUT_THRESHOLD.
     */
    public function availableInSeconds(string $key, string $ip): int
    {
        $keys = [$this->key('ip', $ip), $this->key('email', $key)];
        $max  = 0;
        foreach ($keys as $k) {
            // Hard-lock wait
            $row = $this->db->fetchOne(
                "SELECT locked_until FROM login_attempts
                 WHERE attempt_key = ? AND locked_until > NOW()
                 ORDER BY locked_until DESC LIMIT 1",
                [$k]
            );
            if ($row) {
                // Min 1s: MySQL NOW() (used in the WHERE) and PHP time()
                // can drift by a second at boundary conditions, or a
                // timezone skew between PHP and MySQL can make the row
                // qualify as "locked" server-side while PHP's arithmetic
                // lands on 0. Showing "Please wait 0 seconds" is a
                // confusing UX, so clamp to 1 — same minimum the soft-
                // lock branch below uses for the identical reason.
                $max = max($max, max(1, strtotime($row['locked_until']) - time()));
                continue;
            }

            // Soft-lock wait — how long until NULL-rows fall out of window?
            // We're soft-locked when countRecent() >= LOCKOUT_THRESHOLD. The
            // block lifts when a row at position (count - threshold + 1) in
            // age-sorted order ages past DECAY_MINUTES.
            if ($this->countRecent($k) >= self::LOCKOUT_THRESHOLD) {
                $oldestInBlock = $this->db->fetchOne(
                    "SELECT attempted_at FROM login_attempts
                      WHERE attempt_key = ?
                        AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                        AND locked_until IS NULL
                      ORDER BY attempted_at ASC
                      LIMIT 1",
                    [$k, self::DECAY_MINUTES]
                );
                if ($oldestInBlock) {
                    $expiresAt = strtotime($oldestInBlock['attempted_at']) + self::DECAY_MINUTES * 60;
                    $max = max($max, max(1, $expiresAt - time())); // min 1s so we never show "0 seconds"
                }
            }
        }
        return $max;
    }

    /**
     * Return the number of remaining attempts before lockout.
     */
    public function remainingAttempts(string $key, string $ip): int
    {
        $ipKey    = $this->key('ip', $ip);
        $emailKey = $this->key('email', $key);
        $ipCount  = $this->countRecent($ipKey);
        $emCount  = $this->countRecent($emailKey);
        $used     = max($ipCount, $emCount);
        return max(0, self::MAX_ATTEMPTS - $used);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function isLockedOut(string $key): bool
    {
        // Hard lockout: a lock record still active
        $locked = $this->db->fetchOne(
            "SELECT id FROM login_attempts
             WHERE attempt_key = ? AND locked_until > NOW() LIMIT 1",
            [$key]
        );
        if ($locked) return true;

        // Soft lockout: too many attempts within the window
        return $this->countRecent($key) >= self::LOCKOUT_THRESHOLD;
    }

    private function countRecent(string $key): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM login_attempts
             WHERE attempt_key = ?
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND locked_until IS NULL",
            [$key, self::DECAY_MINUTES]
        );
    }

    private function recordAttempt(string $key): void
    {
        $count = $this->countRecent($key) + 1;

        $lockedUntil = null;
        if ($count >= self::LOCKOUT_THRESHOLD) {
            // Hard lockout
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
        }

        $this->db->insert('login_attempts', [
            'attempt_key' => $key,
            'attempted_at'=> date('Y-m-d H:i:s'),
            'locked_until'=> $lockedUntil,
        ]);

        // Periodic cleanup of old records (run ~1% of the time to avoid overhead)
        if (random_int(1, 100) === 1) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->db->query(
            "DELETE FROM login_attempts
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND (locked_until IS NULL OR locked_until < NOW())",
            [self::DECAY_MINUTES * 3]
        );
    }

    private function key(string $type, string $value): string
    {
        // Hash to normalise length and prevent injection into the key column
        return $type . ':' . hash('sha256', strtolower(trim($value)));
    }
}
