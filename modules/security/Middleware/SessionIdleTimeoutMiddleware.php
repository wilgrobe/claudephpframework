<?php
// modules/security/Middleware/SessionIdleTimeoutMiddleware.php
namespace Modules\Security\Middleware;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;

/**
 * Sliding session inactivity timeout. Distinct from PHP's absolute
 * session.gc_maxlifetime (which expires the session N seconds after
 * issuance regardless of activity). This expires the session N
 * minutes after the LAST request — every fresh request resets the
 * window.
 *
 * Hook: called from public/index.php as a static gate. Returns null
 * to allow the request through, or a Response to short-circuit and
 * send the user back to /login.
 *
 * Read order matters:
 *   1. The session handler reads the row at session_start(), so the
 *      `last_activity` value we read from the DB here is the prior
 *      request's stamp — exactly what we want.
 *   2. The handler will WRITE the new last_activity at session_write_close()
 *      at end of this request. So the next request will see THIS one's
 *      timestamp. Sliding window achieved.
 *
 * Setting: `session_idle_timeout_minutes` (site-scope, integer).
 *   0  = disabled (no idle timeout, fall back to PHP's absolute lifetime)
 *   1+ = minutes of inactivity before forced logout
 *
 * Default 0 (disabled) so installs that don't opt in see no behavior
 * change. SOC2 evaluators typically expect 15-30 min for high-trust
 * surfaces, 60-120 min for general SaaS.
 *
 * Allow-list (always pass through, never log out):
 *   /logout, /login, /register, /policies/accept, /auth/*, /uploads/*,
 *   /assets/*, /api/* — same shape as the policy-acceptance gate's
 *   allow-list.
 */
class SessionIdleTimeoutMiddleware
{
    private const ALLOW_EXACT = ['/logout', '/login', '/register', '/policies/accept'];
    private const ALLOW_PREFIX = ['/auth/', '/uploads/', '/assets/', '/api/'];

    /**
     * Returns null when the request is allowed; a Response to send
     * when the session has timed out.
     */
    public static function isExpired(Request $request): ?Response
    {
        $auth = Auth::getInstance();
        if ($auth->guest()) return null;

        $idleMinutes = (int) (setting('session_idle_timeout_minutes', 0) ?? 0);
        if ($idleMinutes <= 0) return null;

        $path = '/' . ltrim((string) parse_url($request->path(), PHP_URL_PATH), '/');
        if (in_array($path, self::ALLOW_EXACT, true)) return null;
        foreach (self::ALLOW_PREFIX as $p) {
            if (str_starts_with($path, $p)) return null;
        }

        // Read the session row's last_activity. The PHP session id is
        // available from session_id() at this point (session_start() has
        // already run via the bootstrap).
        $sid = session_id();
        if (!is_string($sid) || $sid === '') return null;

        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT last_activity FROM sessions WHERE id = ?",
                [$sid]
            );
        } catch (\Throwable) {
            return null;  // sessions table missing = not on DB sessions, skip
        }

        if (!$row || empty($row['last_activity'])) return null;

        $lastActive = strtotime((string) $row['last_activity']);
        if ($lastActive === false) return null;

        $idleSeconds = $idleMinutes * 60;
        if ((time() - $lastActive) <= $idleSeconds) {
            return null;
        }

        // Idle threshold exceeded — log out + redirect.
        try {
            $auth->auditLog('auth.session_idle_timeout', null, null, null, [
                'idle_minutes'    => $idleMinutes,
                'last_active_at'  => (string) $row['last_activity'],
            ]);
        } catch (\Throwable) {
            // Don't let audit failure block the logout.
        }

        $auth->logout();
        return Response::redirect('/login')->withFlash(
            'info',
            "You were signed out after {$idleMinutes} minutes of inactivity. Please sign in again."
        );
    }
}
