<?php
// app/Middleware/AuthMiddleware.php
namespace App\Middleware;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;

class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = Auth::getInstance();

        // Case 1: User has passed credentials but not yet completed 2FA
        if (!empty($_SESSION['2fa_pending_user_id']) && empty($_SESSION['user_id'])) {
            return Response::redirect('/auth/2fa/challenge');
        }

        // Case 2: No session at all — redirect to login
        if ($auth->guest()) {
            self::discardStaleSession();
            Session::set('intended', $request->path());
            return Response::redirect('/login');
        }

        return $next($request);
    }

    /**
     * Retire a dead session at the moment we bounce someone to /login, rather
     * than leaving it to be discovered on the next request.
     *
     * A browser keeps presenting its session cookie long after the server-side
     * session is gone. That stale id was carried onto the login page, so the CSRF
     * token rendered there belonged to a session already on its way out — and the
     * sign-in attempt came back "your session timed out", which is a confusing
     * thing to be told while trying to START a session.
     *
     * session_regenerate_id(true) is the whole fix: it deletes the old row and
     * issues a fresh id, so the login page's token is bound to a session that is
     * new as of this redirect. $_SESSION carries over, which is what we want —
     * `intended` is set immediately after and must survive.
     *
     * Only when a cookie was actually presented. A first-time visitor clicking a
     * protected link has a brand-new session already; rotating it would be churn
     * for nothing.
     */
    private static function discardStaleSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $name = session_name();
        if (!is_string($name) || $name === '' || !isset($_COOKIE[$name])) return;

        // Never rotate out from under a live login — guest() is already true here,
        // but this makes the precondition explicit rather than inherited.
        if (!empty($_SESSION['user_id'])) return;

        try {
            session_regenerate_id(true);
        } catch (\Throwable) {
            // Best-effort. A failed rotation leaves the previous behaviour, which
            // is imperfect but not broken — never block the redirect over it.
        }
    }
}
