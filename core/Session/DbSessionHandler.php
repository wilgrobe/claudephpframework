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

    /** Advisory lock currently held for this request, if any. */
    private ?string $lockName = null;

    /**
     * How long to wait for another request on the same session to finish.
     * Bounded on purpose: past this we proceed WITHOUT the lock, because a
     * page that hangs is worse than a rare lost write.
     */
    private const LOCK_TIMEOUT = 3;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Serialise concurrent requests that share a session id.
     *
     * PHP's default file handler locks the session file for the life of a
     * request, so two requests in the same session run one after the other.
     * This handler did not, and read()/write() are a plain read-modify-write
     * of the WHOLE payload — so the last writer won and everything the other
     * request had stored in between was silently discarded.
     *
     * That is not a theoretical race. Refreshing a page that also fires
     * background calls produced it reliably: the navigation renders /login and
     * stores the form's CSRF token, a background call that read the session
     * moments earlier finishes afterwards and writes the state it read, and the
     * token is gone. Signing in then fails with "this sign-in form had been
     * open too long" on a form that had been open for seconds. Two earlier
     * attempts at that bug changed cookie handling and could not have fixed it.
     *
     * GET_LOCK rather than SELECT ... FOR UPDATE deliberately: an advisory lock
     * holds no transaction open across the request, so it cannot interfere with
     * whatever the application does with its own transactions.
     */
    private function lock(string $id): void
    {
        if ($this->lockName !== null) return;   // one lock per request

        // Lock names are capped at 64 characters and the session id is opaque,
        // so hash it rather than trusting its length or alphabet.
        $name = 'sess_' . sha1($id);
        try {
            $got = $this->db->fetchOne("SELECT GET_LOCK(?, ?) AS ok", [$name, self::LOCK_TIMEOUT]);
            if ($got && (int) ($got['ok'] ?? 0) === 1) {
                $this->lockName = $name;
                return;
            }
            // Timed out or errored: proceed unlocked. Log it, because a site
            // seeing these regularly has a request holding sessions too long.
            error_log('[DbSessionHandler] session lock not acquired for ' . substr($name, 0, 16) . '...');
        } catch (\Throwable $e) {
            error_log('[DbSessionHandler] session lock failed: ' . $e->getMessage());
        }
    }

    private function unlock(): void
    {
        if ($this->lockName === null) return;
        try {
            $this->db->query("SELECT RELEASE_LOCK(?)", [$this->lockName]);
        } catch (\Throwable $e) {
            // The connection dropping releases it anyway; never fail a response
            // over letting go of a lock.
            error_log('[DbSessionHandler] session unlock failed: ' . $e->getMessage());
        }
        $this->lockName = null;
    }

    public function open(string $path, string $name): bool
    {
        // Nothing to initialize — DB is a singleton managed elsewhere.
        return true;
    }

    public function close(): bool
    {
        // Always the last thing PHP calls, so this is where the lock goes back
        // even when the request never wrote.
        $this->unlock();
        return true;
    }

    public function read(string $id): string|false
    {
        // Held until close(), so nothing else can read-modify-write this
        // session underneath us.
        $this->lock($id);

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
            // session_regenerate_id(true) destroys the old id and continues
            // under a new one; keeping the old lock would pin it for the rest
            // of the request and block nothing useful.
            if ($this->lockName === 'sess_' . sha1($id)) {
                $this->unlock();
            }
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
