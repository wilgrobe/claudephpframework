<?php

namespace App\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * How long until this session dies, and a way to say "not yet".
 *
 * ── The trap this is built around ──
 *
 * Every request touches `sessions.last_activity` — DbSessionHandler does it even
 * on a read, so the GC clock stays honest. That means a countdown that polled a
 * normal endpoint would keep the session alive by asking about it, and a tab
 * left open overnight would never time out at all. The warning would then be the
 * one thing guaranteeing it never fired.
 *
 * So idleness is tracked separately, in the session itself: AuthMiddleware
 * stamps `_last_seen` on every request EXCEPT this controller's status call.
 * Asking the question does not count as activity; only doing something does.
 *
 * `remaining` is therefore "seconds until the idle limit", not "seconds until
 * PHP's GC happens to sweep". The two are close but the first is the one a
 * person can act on.
 */
class SessionLifetimeController
{
    /** The session key AuthMiddleware stamps. Shared constant, not a copy. */
    public const LAST_SEEN = '_last_seen';

    /** Idle limit in seconds, from the same config the session handler uses. */
    private function lifetime(): int
    {
        return max(60, (int) config('app.session.lifetime', 120) * 60);
    }

    /**
     * Seconds left before the idle limit. Read-only: deliberately does NOT
     * stamp _last_seen, or polling would make the timeout unreachable.
     */
    public function status(Request $request): Response
    {
        $auth = Auth::getInstance();
        if ($auth->guest()) {
            return Response::json(['authenticated' => false, 'remaining' => 0], 200);
        }

        $lifetime = $this->lifetime();
        $seen     = (int) (Session::get(self::LAST_SEEN) ?? 0);
        // No stamp yet (a session that predates this feature) — treat as fresh
        // rather than as instantly expired. Erring the other way would sign
        // people out the moment this shipped.
        $remaining = $seen > 0 ? $lifetime - (time() - $seen) : $lifetime;

        return Response::json([
            'authenticated' => true,
            'remaining'     => max(0, $remaining),
            'lifetime'      => $lifetime,
        ], 200);
    }

    /** "Keep me signed in" — the one call that is allowed to reset the clock. */
    public function extend(Request $request): Response
    {
        $auth = Auth::getInstance();
        if ($auth->guest()) {
            return Response::json(['ok' => false, 'error' => 'session_expired'], 401);
        }
        Session::set(self::LAST_SEEN, time());

        return Response::json(['ok' => true, 'remaining' => $this->lifetime()]);
    }

    /**
     * End it deliberately, and leave nothing behind.
     *
     * The browser goes on presenting a session cookie long after the row is
     * gone, which is how a "signed out" tab still arrives carrying credentials.
     * Destroy the server side AND expire the cookie, then send them to /login
     * with something that explains itself.
     *
     * POST-only. A GET here would be a logout anyone could trigger with an
     * <img> tag — only an annoyance, but a free one to avoid.
     */
    public function expire(Request $request): Response
    {
        try {
            Auth::getInstance()->logout();
        } catch (\Throwable) { /* already gone is fine */ }

        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
                // Expire the cookie with the SAME attributes it was set with;
                // a mismatched path or domain leaves the original in place.
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', [
                        'expires'  => time() - 42000,
                        'path'     => $p['path'] ?: '/',
                        'domain'   => $p['domain'] ?? '',
                        'secure'   => (bool) ($p['secure'] ?? false),
                        'httponly' => (bool) ($p['httponly'] ?? true),
                        'samesite' => $p['samesite'] ?: 'Lax',
                    ]);
                }
                session_destroy();
            }
        } catch (\Throwable) { /* best-effort */ }

        return Response::json(['ok' => true, 'redirect' => '/login?expired=1']);
    }
}
