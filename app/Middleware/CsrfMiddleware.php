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
                // Regenerate token after mismatch
                unset($_SESSION['csrf_token']);
                return new Response('CSRF token mismatch. Please go back and try again.', 419);
            }
        }
        return $next($request);
    }
}
