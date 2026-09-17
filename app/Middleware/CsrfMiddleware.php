<?php
// app/Middleware/CsrfMiddleware.php
namespace App\Middleware;

use Core\Request;
use Core\Response;

/**
 * Validates the CSRF token on all state-changing requests.
 * Token is compared with hash_equals to prevent timing attacks.
 */
class CsrfMiddleware
{
    /**
     * Guest forms that are safe to re-present at their OWN path after a
     * mismatch. Each has a GET route rendering the same form, so the
     * redirect always lands on something usable — unlike Referer, which
     * browsers omit on bfcache restores and under stricter privacy
     * settings, and unlike the '/' fallback, which drops a failed
     * sign-in on the home page with no form in sight.
     */
    private const SELF_HEAL_PATHS = [
        '/login',
        '/register',
        '/password/forgot',
        '/password/reset',
    ];

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            // Tell an oversized upload apart from a missing token, BEFORE the
            // verdict. PHP throws the whole body away over post_max_size, which
            // takes `_token` with it, so a too-large upload is indistinguishable
            // from a stale form unless it is checked for. Saying "your session
            // timed out" to someone who uploaded a 20MB file is false, costs
            // them the form, and files a security event against honest use.
            if (self::bodyWasDiscarded($request)) {
                $limit = (string) ini_get('post_max_size');
                if ($this->isAjaxCaller($request)) {
                    return new Response(
                        'Request body too large. The server accepts ' . $limit . ' per request.', 413
                    );
                }

                return Response::redirect($this->safeReferer($request))->withFlash(
                    'error',
                    'That was too large to send in one go — the server accepts ' . $limit
                    . ' per upload. Send fewer files at a time, or save long pasted text as a file.'
                );
            }

            // Accept the token from either the standard form field
            // (_token) or the X-CSRF-Token request header. The header
            // path is for AJAX/fetch callers posting JSON bodies that
            // can't carry a form field. Both surfaces are validated
            // with the same constant-time comparison.
            $token    = (string) $request->post('_token', '');
            if ($token === '') {
                $token = (string) ($request->header('X-CSRF-Token') ?? '');
            }
            $expected = csrf_token();

            if (!$token || !hash_equals($expected, $token)) {
                // Phase 43.195c M1 — harden the post-mismatch response.
                // Pre-fix: just cleared $_SESSION['csrf_token']. Now also
                // (a) write a security.csrf_mismatch audit row with IP +
                // UA + path so repeated mismatches surface in
                // /admin/audit-log + can be triaged as an attack signal;
                // (b) regenerate the session id so a stolen session can't
                // be reused for further attempts. Session_regenerate_id
                // is best-effort (might fail in test contexts without an
                // active session).
                unset($_SESSION['csrf_token']);
                try {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                    }
                } catch (\Throwable) { /* best-effort */ }
                try {
                    if (class_exists(\Core\Auth\Auth::class)) {
                        \Core\Auth\Auth::getInstance()->auditLog('security.csrf_mismatch', null, null, [
                            'path'   => $request->path(),
                            'method' => $request->method(),
                            'ip'     => $request->ip(),
                            'ua'     => substr((string) ($request->header('User-Agent') ?? ''), 0, 200),
                        ]);
                    }
                } catch (\Throwable) { /* best-effort */ }

                // Self-heal for normal browser form posts. A returning user whose
                // session expired (idle timeout, cookie rotation, a bfcache-restored
                // login page) submits a token from the OLD session. Rather than a
                // dead-end 419 with no way forward, send them back to the form they
                // came from with a FRESH token + a clear message, so the retry
                // succeeds. csrf_token() below regenerates the token (we unset it
                // above), and the redirect's GET renders it bound to the current
                // session. AJAX / fetch callers (which send Accept: json, an
                // X-Requested-With header, or the X-CSRF-Token header) still get the
                // 419 so their JS can handle it explicitly.
                $isAjax = $this->isAjaxCaller($request);
                if (!$isAjax) {
                    csrf_token(); // regenerate now so the redirected GET has a valid token

                    // Prefer the form's OWN path over Referer for the known guest
                    // forms. Referer is the unreliable input here (absent on
                    // bfcache restores / with privacy settings), and its '/'
                    // fallback lands a failed sign-in on the home page — which is
                    // what forced the "refresh the page and try again" dance.
                    $path   = '/' . ltrim($request->path(), '/');
                    $onForm = in_array($path, self::SELF_HEAL_PATHS, true);
                    $target = $onForm ? $path : $this->safeReferer($request);

                    // Carry the identifier across so only the password is retyped.
                    // Same flash key the auth controller uses, so the views pick it
                    // up through old() with no view changes.
                    $email = (string) $request->post('email', '');
                    if ($email !== '') {
                        try {
                            \Core\Session::flash('old', ['email' => $email]);
                        } catch (\Throwable) { /* best-effort */ }
                    }

                    // On the sign-in form itself, "your session timed out" is both
                    // confusing and beside the point — there is no session to lose,
                    // you are trying to start one. What actually went stale is the
                    // form. Say that, and don't imply the credentials were wrong.
                    $onLogin = $path === '/login';
                    return Response::redirect($target)
                        ->withFlash('error', $onLogin
                            ? 'This sign-in form had been open too long, so it was refreshed for security. Please enter your password again.'
                            : 'Your session timed out for security — please try again.');
                }
                return new Response('CSRF token mismatch. Please go back and try again.', 419);
            }
        }
        return $next($request);
    }

    /**
     * Did PHP throw this request's body away for exceeding post_max_size?
     *
     * The signature is a form-encoded POST that arrived with a length but left
     * both $_POST and $_FILES empty — PHP populates those itself for exactly
     * those two content types, so empty-with-a-body means the parse never
     * happened. The superglobals are read directly on purpose: the question is
     * literally whether PHP's own parser filled them in.
     *
     * The content-type check is what keeps this narrow. A JSON or raw body
     * legitimately leaves $_POST empty, and those callers carry their token in
     * the X-CSRF-Token header, which survives because headers are not part of
     * the discarded body.
     *
     * Public and static so it can be tested on its own. It was written once
     * before as a private method reading Request::header('Content-Type'), which
     * could not return that header at all — the guard read correctly and never
     * once fired. A check that cannot be exercised directly is a check nobody
     * finds out is dead.
     */
    public static function bodyWasDiscarded(Request $request): bool
    {
        if ($request->method() !== 'POST' || $_POST || $_FILES) {
            return false;
        }
        if ((int) ($request->header('Content-Length') ?? 0) <= 0) {
            return false;
        }

        $type = strtolower((string) ($request->header('Content-Type') ?? ''));

        return str_contains($type, 'multipart/form-data')
            || str_contains($type, 'application/x-www-form-urlencoded');
    }

    /**
     * A caller that wants a status code rather than a redirect — fetch/XHR
     * posting JSON, or anything sending the token as a header.
     */
    private function isAjaxCaller(Request $request): bool
    {
        $accept = strtolower((string) ($request->header('Accept') ?? ''));

        return str_contains($accept, 'application/json')
            || strtolower((string) ($request->header('X-Requested-With') ?? '')) === 'xmlhttprequest'
            || $request->header('X-CSRF-Token') !== null;
    }
    /** Same-origin Referer path to bounce a failed form post back to, else '/'. */
    private function safeReferer(Request $request): string
    {
        $ref = (string) ($request->header('Referer') ?? '');
        if ($ref !== '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');   // includes :port
            $p = parse_url($ref);
            $refHost = ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
            if ($host !== '' && $refHost === $host) {
                $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
                if ($path !== '' && $path[0] === '/' && !str_starts_with($path, '//')) return $path;
            }
        }
        return '/';
    }
}
