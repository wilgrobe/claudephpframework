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
    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
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
                return new Response('CSRF token mismatch. Please go back and try again.', 419);
            }
        }
        return $next($request);
    }
}
