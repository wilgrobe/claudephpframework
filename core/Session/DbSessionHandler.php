<?php
// core/Session/DbSessionHandler.php
namespace Core\Session;

use Core\Database\Database;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Database-backed session handler.
 *
 * Persists PHP sessions to the `sessions` table (defined in
 * database/schema.sql) instead of the default filesystem location.
 * Rationale:
 *   - Sessions survive across multiple web nodes behind a load
 *     balancer — no sticky sessions needed.
 *   - Admins can revoke a user's sessions with `DELETE FROM sessions
 *     WHERE user_id = ?` (emergency kick, compromised account).
 *   - `sessions.user_id` gives an audit surface for "who is currently
 *     logged in" and "how many active sessions does this user have."
 *
 * Session payload is stored opaque (PHP's serialized session string)
 * — the handler doesn't parse it. `user_id` / `ip_address` /
 * `user_agent` / `last_activity` are denormalized at write-time by
 * reading the still-populated `$_SESSION` super-global, which is
 * consistent during the write() call.
 *
 * Wired in from public/index.php via session_set_save_handler when
 * config('app.session.driver') === 'db'. Falling back to file-based
 * sessions (the PHP default) is a config change — no code edit.
 *
 * Implements SessionUpdateTimestampHandlerInterface so PHP 7.0+ can
 * skip a full write on unchanged sessions — they just touch
 * last_activity. Real write-cost savings on read-heavy workloads.
 */
class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function open(string $path, string $name): bool
    {
        // Nothing to initialize — DB is a singleton managed elsewhere.
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->db->fetchOne(
            "SELECT payload FROM sessions WHERE id = ?",
            [$id]
        );
        // PHP expects a string ('' for new session, not false).
        return $row ? (string) $row['payload'] : '';
    }

    public function write(string $id, string $data): bool
    {
        // Peek at $_SESSION (still populated during write) to
        // denormalize user_id / other audit columns. Opaque-payload
        // handlers don't have to do this — but populating user_id is
        // the whole reason we bothered with a DB handler.
        $userId    = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $ip        = $_SERVER['REMOTE_ADDR']     ?? null;
        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(strip_tags((string) $_SERVER['HTTP_USER_AGENT']), 0, 500)
            : null;

        try {
            // REPLACE semantics: one row per session id. INSERT ... ON
            // DUPLICATE KEY UPDATE would work too; REPLACE is simpler
            // and sufficient because sessions are keyed by id with no
            // incoming FK references worth preserving.
            $this->db->query(
                "INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                     user_id = VALUES(user_id),
                     ip_address = VALUES(ip_address),
                     user_agent = VALUES(user_agent),
                     payload = VALUES(payload),
                     last_activity = VALUES(last_activity)",
                [$id, $userId, $ip, $userAgent, $data]
            );
            return true;
        } catch (\Throwable $e) {
            // Session write failure must NEVER abort the response —
            // users get kicked to a broken login screen otherwise.
            // Log and return false; PHP treats that as a non-fatal
            // "session couldn't be saved," which at worst forces a
            // re-login on the next request.
            error_log('[DbSessionHandler] write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->db->delete('sessions', 'id = ?', [$id]);
            return true;
        } catch (\Throwable $e) {
            error_log('[DbSessionHandler] destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Garbage collection — delete sessions that haven't been touched
     * in $maxLifetime seconds. PHP calls this probabilistically based
     * on `session.gc_probability` / `session.gc_divisor`; for typical
     * 1-in-100 odds you'll see one GC per ~100 requests.
     *
     * Returns the number of deleted rows, per the PHP 7.1+ contract.
     */
    public function gc(int $maxLifetime): int|false
    {
        try {
            return (int) $this->db->delete(
                'sessions',
                'last_activity < (NOW() - INTERVAL ? SECOND)',
                [$maxLifetime]
            );
        } catch (\Throwable $e) {
            error_log('[DbSessionHandler] gc failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Idempotent touch when PHP's lazy-write mode notices nothing in
     * $_SESSION changed. Updates last_activity only so the GC clock
     * doesn't treat a still-active session as stale.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            $this->db->update(
                'sessions',
                ['last_activity' => date('Y-m-d H:i:s')],
                'id = ?',
                [$id]
            );
            return true;
        } catch (\Throwable $e) {
            error_log('[DbSessionHandler] updateTimestamp failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Required by SessionUpdateTimestampHandlerInterface. Returns true
     * if a session id exists in the store (i.e. should be accepted
     * when the client presents it as a cookie).
     */
    public function validateId(string $id): bool
    {
        $row = $this->db->fetchOne("SELECT 1 FROM sessions WHERE id = ?", [$id]);
        return (bool) $row;
    }
}
