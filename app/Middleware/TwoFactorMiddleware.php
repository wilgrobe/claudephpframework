<?php
// app/Middleware/TwoFactorMiddleware.php
namespace App\Middleware;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * TwoFactorMiddleware
 *
 * If a user has passed credential validation but 2FA is still pending
 * (session has '2fa_pending_user_id' but no 'user_id'), redirect them
 * to the 2FA challenge instead of allowing access to protected routes.
 *
 * Apply this AFTER AuthMiddleware on any route that requires full auth.
 * It's already bundled into RequireAdmin, but for regular authenticated
 * routes it runs as a separate middleware in the stack.
 */
class TwoFactorMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        // If we have a pending 2FA session but no completed auth session,
        // force the user to complete the challenge.
        if (
            !empty($_SESSION['2fa_pending_user_id']) &&
            empty($_SESSION['user_id'])
        ) {
            return Response::redirect('/auth/2fa/challenge');
        }

        return $next($request);
    }
}
