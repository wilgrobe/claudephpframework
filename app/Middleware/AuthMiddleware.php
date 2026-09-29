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

            // A fetch/XHR caller must be TOLD it is signed out, not handed the
            // login page. A 302 is followed by the browser transparently, so the
            // caller receives 200 + the login page's HTML; JSON.parse then throws
            // and the JS is left holding an empty object that looks exactly like
            // a successful-but-empty response. On the schedule board that opened
            // the "Start now" dialog with every field blank — no title, no links,
            // no hint that the session had expired.
            //
            // 401 + JSON makes the failure legible so the caller can say what
            // actually happened.
            if (self::wantsMachineReadable($request)) {
                return Response::json([
                    'ok'       => false,
                    'error'    => 'session_expired',
                    'message'  => 'Your session timed out. Sign in again to continue.',
                    'login_url' => '/login',
                ], 401);
            }

            // Only a real browser navigation may rotate the cookie, and only
            // here -- AFTER the XHR branch above has returned. Doing it for every
            // guest request meant a single page refresh (navigation + the page's
            // own background calls, all in flight together) produced several
            // independent Set-Cookie headers. The browser keeps whichever landed
            // last, the login form's token was minted in a different session, and
            // the POST failed with "this form had been open too long" on a form
            // that had been open for seconds.
            self::discardStaleSession();

            Session::set('intended', $request->path());
            return Response::redirect('/login');
        }

        return $next($request);
    }

    /**
     * Is this a fetch/XHR rather than a browser navigation?
     *
     * Same three-way test CsrfMiddleware uses, and deliberately so — the two
     * middlewares have to agree about what a background request looks like or
     * one of them will answer the wrong way for the same caller.
     *
     * The X-CSRF-Token arm is the one that matters in practice: our own fetch
     * helpers send that header but do NOT set Accept: application/json, so
     * `wantsJson()` alone returns false for them and would miss exactly the
     * calls this exists to fix.
     *
     * Note we do NOT set Session 'intended' on this path. The path here is the
     * POST endpoint (/app/plan-tasks/157/start), which is not somewhere to land
     * after signing in. The caller sends the user to the page they are already
     * on; that GET sets 'intended' correctly on its own way through.
     */
    private static function wantsMachineReadable(Request $request): bool
    {
        $accept = strtolower((string) ($request->header('Accept') ?? ''));

        return str_contains($accept, 'application/json')
            || strtolower((string) ($request->header('X-Requested-With') ?? '')) === 'xmlhttprequest'
            || $request->header('X-CSRF-Token') !== null;
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
     * session_regenerate_id(true) deletes the old row and
     * issues a fresh id, so the login page's token is bound to a session that is
     * new as of this redirect. $_SESSION carries over, which is what we want —
     * `intended` is set immediately after and must survive.
     *
     * Only when a cookie was actually presented. A first-time visitor clicking a
     * protected link has a brand-new session already; rotating it would be churn
     * for nothing.
     *
     * CORRECTION (2026-09-03): rotating was also most of the bug it was added to
     * fix. This ran on EVERY guest request, background fetches included, so a
     * single page refresh emitted several competing Set-Cookie headers; the login
     * form's token was minted in one session while the browser kept another, and
     * the sign-in came back "this form had been open too long" on a form that had
     * been open for seconds. It now runs only on a browser navigation (after the
     * XHR branch has returned) and only when there is a dead login to discard.
     */
    private static function discardStaleSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $name = session_name();
        if (!is_string($name) || $name === '' || !isset($_COOKIE[$name])) return;

        // Never rotate out from under a live login — guest() is already true here,
        // but this makes the precondition explicit rather than inherited.
        if (!empty($_SESSION['user_id'])) return;

        // Nothing stale, nothing to discard. Two cases land here and neither
        // wants a new cookie:
        //
        //   the session row was garbage-collected -- $_SESSION is empty and the
        //   cookie is merely orphaned. PHP writes a fresh row under that id and
        //   everything works; rotating would only add a racy Set-Cookie.
        //
        //   an ordinary signed-out visitor who already has a login form open.
        //   Rotating here would invalidate the very token on that form -- this
        //   fix causing the bug it is meant to fix.
        if (!self::looksLikeDeadLogin()) return;

        try {
            session_regenerate_id(true);
        } catch (\Throwable) {
            // Best-effort. A failed rotation leaves the previous behaviour, which
            // is imperfect but not broken — never block the redirect over it.
        }
    }

    /**
     * Does this session carry the remains of a login that is now gone?
     *
     * A session that only holds anonymous scaffolding -- a CSRF token, a flash,
     * an intended path -- is a normal signed-out visitor, not a corpse. Only
     * keys that could not exist without a prior login mark it as dead.
     */
    private static function looksLikeDeadLogin(): bool
    {
        $benign = ['csrf_token', 'old', '_flash', 'flash', 'intended', '_previous'];
        foreach (array_keys($_SESSION) as $k) {
            if (!in_array($k, $benign, true)) return true;
        }
        return false;
    }
}
