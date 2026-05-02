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
            $token    = $request->post('_token', '');
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
