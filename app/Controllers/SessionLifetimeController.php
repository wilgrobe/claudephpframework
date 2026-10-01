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

    /** When the session cookie was last re-sent with a fresh expiry. */
    public const COOKIE_SENT = '_cookie_sent';

    /** Re-send at most this often: the expiry only has to stay ahead of the idle limit. */
    public const COOKIE_REFRESH_EVERY = 60;

    /**
     * Should the session cookie be re-sent now?
     *
     * ⚠ PHP sends the session cookie when a session STARTS (or its id is
     * regenerated) and never again — so `session.cookie_lifetime` is an absolute
     * limit counted from sign-in, not an idle one. The browser dropped the cookie
     * two hours after sign-in however busy the person was, with no warning, even
     * after "Keep me signed in" (2026-10-01). Sliding it on activity makes the
     * idle limit the only limit, which is what this controller's countdown
     * already promised.
     */
    public static function cookieRefreshDue(int $now, int $lastSent, int $cookieLifetime, bool $force = false): bool
    {
        if ($cookieLifetime <= 0) { return false; }   // a browser-session cookie has no expiry to slide
        if ($force || $lastSent <= 0) { return true; }
        return ($now - $lastSent) >= self::COOKIE_REFRESH_EVERY;
    }

    /**
     * Re-send the CURRENT session cookie (same id) with a fresh expiry.
     *
     * Same id, so several of these in flight together are harmless — unlike the
     * id rotation AuthMiddleware deliberately does only once, on a navigation.
     */
    public static function refreshCookie(bool $force = false): void
    {
        try {
            if (PHP_SAPI === 'cli' || headers_sent() || session_status() !== PHP_SESSION_ACTIVE || !ini_get('session.use_cookies')) {
                return;
            }
            $now      = time();
            $lifetime = (int) ini_get('session.cookie_lifetime');
            if (!self::cookieRefreshDue($now, (int) (Session::get(self::COOKIE_SENT) ?? 0), $lifetime, $force)) {
                return;
            }
            $p = session_get_cookie_params();
            setcookie(session_name(), (string) session_id(), [
                'expires'  => $now + $lifetime,
                'path'     => $p['path'] ?: '/',
                'domain'   => $p['domain'] ?? '',
                'secure'   => (bool) ($p['secure'] ?? false),
                'httponly' => (bool) ($p['httponly'] ?? true),
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
            Session::set(self::COOKIE_SENT, $now);
        } catch (\Throwable) { /* best-effort: never block a request over a cookie */ }
    }

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
        // …and the cookie, or "Keep me signed in" kept the server side alive while
        // the browser threw the cookie away on its original schedule anyway.
        self::refreshCookie(true);

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
